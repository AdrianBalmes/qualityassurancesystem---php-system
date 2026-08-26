<?php

/**
 * CLI worker for the OneDrive repository queue.
 *
 *   php onedrive_worker.php status    Show queue counts and recent errors
 *   php onedrive_worker.php run       Upload everything still pending
 *   php onedrive_worker.php retry     Reset 'failed' rows back to pending, then run
 *   php onedrive_worker.php backfill  Queue documents uploaded before this feature existed
 *
 * Cron it so a Microsoft outage self-heals without anyone noticing. Run every
 * 10 minutes -- see ONEDRIVE_SETUP.md for the exact crontab line.
 */

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . "/database.php";
require_once __DIR__ . "/onedrive_sync.php";

$command = $argv[1] ?? 'status';
od_ensure_sync_table($conn);
$config = od_config();

switch($command){
    case 'status':
        od_cmd_status($conn, $config);
        break;

    case 'run':
        od_cmd_run($conn, $config);
        break;

    case 'retry':
        $conn->query("UPDATE onedrive_sync SET status = 'pending', attempts = 0 WHERE status = 'failed'");
        echo "Reset " . $conn->affected_rows . " failed row(s) to pending.\n";
        od_cmd_run($conn, $config);
        break;

    case 'backfill':
        od_cmd_backfill($conn);
        break;

    default:
        echo "Unknown command '{$command}'. Use: status | run | retry | backfill\n";
        exit(1);
}

function od_cmd_status($conn, $config){
    echo "OneDrive sync\n";
    echo "  enabled     : " . ($config['enabled'] ? 'yes' : 'no') . "\n";
    echo "  dry run     : " . ($config['dry_run'] ? 'yes' : 'no') . "\n";
    echo "  configured  : " . (od_is_configured() ? 'yes' : 'no') . "\n";
    echo "  mode        : {$config['mode']}\n";

    if(od_is_local_mode()){
        $exists = $config['local_path'] !== '' && is_dir($config['local_path']);
        echo "  local path  : " . ($config['local_path'] !== '' ? $config['local_path'] : '(not set)');
        echo $exists ? "\n" : "   << not found\n";
    } else {
        echo "  drive user  : " . ($config['drive_user'] !== '' ? $config['drive_user'] : '(not set)') . "\n";
    }

    echo "  root folder : {$config['root_folder']}\n\n";

    $result = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM onedrive_sync GROUP BY status ORDER BY status");
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    if(empty($rows)){
        echo "Queue is empty.\n";
        return;
    }

    echo "Queue:\n";
    foreach($rows as $row){
        printf("  %-10s %d\n", $row['status'], $row['total']);
    }

    $errors = mysqli_query($conn, "SELECT id, office, original_name, attempts, last_error FROM onedrive_sync
                                   WHERE last_error IS NOT NULL ORDER BY updated_at DESC LIMIT 5");
    if($errors && $errors->num_rows > 0){
        echo "\nRecent errors:\n";
        while($row = $errors->fetch_assoc()){
            printf("  #%d %s / %s (attempt %d)\n      %s\n",
                $row['id'], $row['office'], $row['original_name'], $row['attempts'], $row['last_error']);
        }
    }
}

function od_cmd_run($conn, $config){
    if(!od_is_configured()){
        echo "OneDrive sync is not configured. Set the ONEDRIVE_* values in .env first.\n";
        echo "Files stay queued, so nothing is lost -- rerun once credentials are in place.\n";
        exit(1);
    }

    $pending = mysqli_query($conn, "SELECT COUNT(*) AS total FROM onedrive_sync WHERE status = 'pending'")->fetch_assoc();
    echo "Pending: {$pending['total']}\n";

    if((int) $pending['total'] === 0){
        return;
    }

    $result = od_process_pending($conn, 200, 120);
    echo "Uploaded: {$result['ok']}   Still failing: {$result['failed']}\n";

    if($result['failed'] > 0){
        echo "Run 'php onedrive_worker.php status' to see the errors.\n";
        exit(1);
    }
}

/**
 * Queue supporting documents that were uploaded before this integration
 * existed, so the repository starts out complete rather than only holding
 * files added from today onward.
 */
function od_cmd_backfill($conn){
    $result = mysqli_query($conn, "SELECT d.id, d.office, d.file_name, d.original_name, r.year
                                   FROM recommendation_documents d
                                   LEFT JOIN audit_recommendations r ON r.id = d.recommendation_id
                                   ORDER BY d.id ASC");

    $queued = 0;
    $missing = 0;

    while($row = $result->fetch_assoc()){
        if(!is_readable(__DIR__ . "/uploads/" . $row['file_name'])){
            echo "  missing on disk, skipped: {$row['file_name']}\n";
            $missing++;
            continue;
        }

        od_queue_file($conn, 'recommendation_documents', (int) $row['id'], $row['office'],
                      $row['file_name'], $row['original_name'], $row['year'] ?? '');
        $queued++;
    }

    echo "Queued {$queued} existing document(s).";
    echo $missing > 0 ? " Skipped {$missing} with no file on disk.\n" : "\n";
    echo "Now run: php onedrive_worker.php run\n";
}
