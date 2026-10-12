<?php

require_once __DIR__ . "/email_notifications.php";
require_once __DIR__ . "/email_sender.php";
require_once __DIR__ . "/audit_log_helper.php";

/**
 * A fault in the setup itself, as opposed to in one message: null when the
 * configuration can attempt a send.
 *
 * Kept apart from per-row failures because they need opposite handling. A bad
 * address is that row's problem and counts against its attempts. A missing
 * .env is every row's problem at once, and counting it would burn all the
 * attempts of everything queued in a few runs and mark mail that was never
 * tried as permanently failed.
 */
function email_config_fault(array $config){
    if(!empty($config['dry_run'])){
        return null;
    }
    if(trim((string) $config['username']) === '' || trim((string) $config['password']) === ''){
        return 'No SMTP credentials: set EMAIL_USERNAME and EMAIL_APP_PASSWORD in .env. '
            . 'Nothing was attempted and the queue is untouched.';
    }
    return null;
}

/**
 * Send what is queued.
 *
 * A row keeps its own subject and body, so a recommendation deleted since
 * queueing changes nothing here -- nothing in this function reads
 * audit_recommendations.
 *
 * Returns 'halted' (a message, or null) when the run stopped before touching
 * any row because of a configuration fault.
 */
function email_process_pending($conn, array $config){
    ensure_email_outbox_table($conn);

    $fault = email_config_fault($config);
    if($fault !== null){
        return ['sent' => 0, 'failed' => 0, 'halted' => $fault];
    }

    $limit = max(1, (int) $config['batch_size']);
    $rows = [];
    $result = mysqli_query($conn, "SELECT * FROM email_outbox WHERE status = 'pending'
                                   ORDER BY id ASC LIMIT {$limit}");
    if($result){
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    }

    $sent = 0;
    $failed = 0;
    foreach($rows as $row){
        // body and recipient_name can be NULL or empty; email_send() casts.
        $outcome = email_send($config, $row['recipient_email'], (string) $row['recipient_name'],
                              $row['subject'], (string) $row['body']);

        if($outcome['ok']){
            $update = $conn->prepare("UPDATE email_outbox SET status='sent', sent_at=NOW(), last_error=NULL WHERE id = ?");
            $update->bind_param("i", $row['id']);
            $update->execute();
            $sent++;
            continue;
        }

        $attempts = (int) $row['attempts'] + 1;
        $giveUp = $attempts >= max(1, (int) $config['max_attempts']);
        $status = $giveUp ? 'failed' : 'pending';

        $update = $conn->prepare("UPDATE email_outbox SET status=?, attempts=?, last_error=? WHERE id = ?");
        $update->bind_param("sisi", $status, $attempts, $outcome['error'], $row['id']);
        $update->execute();

        if($giveUp){
            $failed++;
            // Into the Activity Log administrators already read, rather than
            // a screen nobody opens. Silent non-delivery is the whole risk.
            // A NULL recommendation_id stays NULL: casting it would log 0.
            $recId = $row['recommendation_id'] === null ? null : (int) $row['recommendation_id'];
            log_audit_event($conn, 'system', 'admin', $row['recipient_office'],
                'email_failed', 'email', (int) $row['id'],
                "Could not email {$row['recipient_email']} after {$attempts} attempts: {$outcome['error']}",
                $recId);
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'halted' => null];
}

/** Put failed rows back in the queue, as od_cmd_run does for uploads. */
function email_reset_failed($conn){
    ensure_email_outbox_table($conn);
    mysqli_query($conn, "UPDATE email_outbox SET status='pending', attempts=0, last_error=NULL WHERE status='failed'");
    return mysqli_affected_rows($conn);
}

/** Counts by state, and the failures worth looking at. */
function email_queue_status($conn){
    ensure_email_outbox_table($conn);

    $counts = [];
    $result = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM email_outbox GROUP BY status");
    while($result && $row = $result->fetch_assoc()){
        $counts[$row['status']] = (int) $row['total'];
    }

    $errors = [];
    $result = mysqli_query($conn, "SELECT id, recipient_email, attempts, last_error FROM email_outbox
                                   WHERE status='failed' ORDER BY id DESC LIMIT 10");
    while($result && $row = $result->fetch_assoc()){
        $errors[] = $row;
    }

    return ['counts' => $counts, 'errors' => $errors];
}
