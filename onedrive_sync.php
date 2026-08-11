<?php

require_once __DIR__ . "/onedrive_helper.php";

/**
 * Durable queue for the OneDrive repository.
 *
 * Every department upload is recorded here first, then pushed to OneDrive. If
 * Microsoft is unreachable the row stays 'pending' and onedrive_worker.php
 * retries it later -- the department's submission is never blocked or lost.
 */

function od_ensure_sync_table($conn){
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS onedrive_sync (
        id int(11) NOT NULL AUTO_INCREMENT,
        source_table varchar(50) NOT NULL,
        source_id int(11) NOT NULL,
        office varchar(100) NOT NULL DEFAULT '',
        local_file_name varchar(255) NOT NULL,
        original_name varchar(255) NOT NULL DEFAULT '',
        remote_path varchar(600) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        last_error text DEFAULT NULL,
        onedrive_item_id varchar(120) DEFAULT NULL,
        onedrive_web_url text DEFAULT NULL,
        created_at datetime DEFAULT current_timestamp(),
        updated_at datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY source_file (source_table, source_id, local_file_name),
        KEY status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * Record a file for upload. Safe to call even when sync is switched off -- the
 * row is queued so that turning OneDrive on later picks up the backlog.
 *
 * Returns the onedrive_sync row id, or 0 if it could not be queued.
 */
function od_queue_file($conn, $sourceTable, $sourceId, $office, $localFileName, $originalName, $year = ''){
    od_ensure_sync_table($conn);

    $remotePath = od_build_remote_path($office, $year, $localFileName);

    $stmt = $conn->prepare("INSERT INTO onedrive_sync (source_table, source_id, office, local_file_name, original_name, remote_path)
                            VALUES (?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE remote_path = VALUES(remote_path), updated_at = NOW()");
    $stmt->bind_param("sissss", $sourceTable, $sourceId, $office, $localFileName, $originalName, $remotePath);
    $stmt->execute();

    return $conn->insert_id ?: od_find_queue_id($conn, $sourceTable, $sourceId, $localFileName);
}

function od_find_queue_id($conn, $sourceTable, $sourceId, $localFileName){
    $stmt = $conn->prepare("SELECT id FROM onedrive_sync WHERE source_table = ? AND source_id = ? AND local_file_name = ? LIMIT 1");
    $stmt->bind_param("sis", $sourceTable, $sourceId, $localFileName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : 0;
}

/**
 * Queue a file and, when configured to, immediately try to push it.
 *
 * Any failure is swallowed on purpose: the caller is mid-way through a
 * department's compliance submission, and a OneDrive problem must never turn
 * into a user-facing error or a lost file. The row stays pending for retry.
 */
function od_queue_and_sync($conn, $sourceTable, $sourceId, $office, $localFileName, $originalName, $year = ''){
    try {
        $queueId = od_queue_file($conn, $sourceTable, $sourceId, $office, $localFileName, $originalName, $year);
    } catch(Throwable $e){
        error_log("[onedrive] could not queue {$localFileName}: " . $e->getMessage());
        return 0;
    }

    $config = od_config();
    if($queueId > 0 && $config['sync_inline'] && od_is_configured()){
        try {
            od_process_queue_row($conn, $queueId, $config['inline_timeout']);
        } catch(Throwable $e){
            error_log("[onedrive] inline sync deferred for {$localFileName}: " . $e->getMessage());
        }
    }

    return $queueId;
}

/**
 * Upload one queued row. Returns true on success. Marks the row 'failed' once
 * it has burned through max_attempts so the worker stops retrying forever.
 */
function od_process_queue_row($conn, $queueId, $timeout = 60){
    od_ensure_sync_table($conn);
    $config = od_config();

    $stmt = $conn->prepare("SELECT * FROM onedrive_sync WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $queueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if(!$row || $row['status'] === 'uploaded'){
        return true;
    }

    $conn->query("UPDATE onedrive_sync SET attempts = attempts + 1 WHERE id = " . (int) $queueId);
    $attempts = (int) $row['attempts'] + 1;

    $localPath = __DIR__ . "/uploads/" . $row['local_file_name'];

    try {
        if(!is_readable($localPath)){
            throw new RuntimeException("Local file is missing: uploads/{$row['local_file_name']}");
        }

        $item = od_upload_file($conn, $localPath, $row['remote_path'], $timeout);

        $status = $item['dry_run'] ? 'dry-run' : 'uploaded';
        $update = $conn->prepare("UPDATE onedrive_sync SET status = ?, onedrive_item_id = ?, onedrive_web_url = ?, last_error = NULL WHERE id = ?");
        $update->bind_param("sssi", $status, $item['id'], $item['web_url'], $queueId);
        $update->execute();

        od_write_back_url($conn, $row['source_table'], (int) $row['source_id'], $row['local_file_name'], $item['web_url']);

        return true;
    } catch(Throwable $e){
        $message = $e->getMessage();
        $status = $attempts >= $config['max_attempts'] ? 'failed' : 'pending';

        $update = $conn->prepare("UPDATE onedrive_sync SET status = ?, last_error = ? WHERE id = ?");
        $update->bind_param("ssi", $status, $message, $queueId);
        $update->execute();

        throw $e;
    }
}

/**
 * Mirror the OneDrive URL onto the originating record so the rest of the app
 * can link straight to the repository copy.
 */
function od_write_back_url($conn, $sourceTable, $sourceId, $localFileName, $webUrl){
    if($webUrl === '' || $sourceTable !== 'recommendation_documents'){
        return;
    }

    od_ensure_document_url_column($conn);

    $stmt = $conn->prepare("UPDATE recommendation_documents SET onedrive_url = ? WHERE id = ?");
    $stmt->bind_param("si", $webUrl, $sourceId);
    $stmt->execute();
}

function od_ensure_document_url_column($conn){
    static $checked = false;
    if($checked){
        return;
    }
    $checked = true;

    // SHOW COLUMNS returns an empty result set rather than throwing when the
    // column is absent, unlike a SELECT probe against a missing column.
    $result = mysqli_query($conn, "SHOW COLUMNS FROM recommendation_documents LIKE 'onedrive_url'");
    if($result && $result->num_rows === 0){
        mysqli_query($conn, "ALTER TABLE recommendation_documents ADD COLUMN onedrive_url text DEFAULT NULL");
    }
}

/**
 * Process every pending row. Used by the CLI worker.
 * Returns ['ok' => int, 'failed' => int].
 */
function od_process_pending($conn, $limit = 25, $timeout = 60){
    od_ensure_sync_table($conn);

    $result = mysqli_query($conn, "SELECT id FROM onedrive_sync WHERE status = 'pending' ORDER BY id ASC LIMIT " . (int) $limit);
    $ids = [];
    while($row = $result->fetch_assoc()){
        $ids[] = (int) $row['id'];
    }

    $ok = 0;
    $failed = 0;
    foreach($ids as $id){
        try {
            od_process_queue_row($conn, $id, $timeout);
            $ok++;
        } catch(Throwable $e){
            $failed++;
            error_log("[onedrive] sync row {$id} failed: " . $e->getMessage());
        }
    }

    return ['ok' => $ok, 'failed' => $failed];
}
