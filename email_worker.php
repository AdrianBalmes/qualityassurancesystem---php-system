<?php

/**
 * CLI worker for the email outbox.
 *
 *   php email_worker.php status    Counts by state and recent failures
 *   php email_worker.php run       Send everything still pending
 *   php email_worker.php run <id>  Resend one row, e.g. after fixing its
 *                                  address; a failed or dry-run row is put
 *                                  back first
 *   php email_worker.php retry     Put back every row that was not actually
 *                                  delivered (failed, or drained by a dry run),
 *                                  then run
 *
 * Under EMAIL_DRY_RUN rows are marked 'dry-run', not 'sent': nothing was
 * emailed, and retry is how they get sent once the settings are live.
 *
 * Task Scheduler runs this every 5 minutes -- see EMAIL_SETUP.md. That is
 * what makes an offline PC delay mail rather than lose it.
 */

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . "/database.php";
require_once __DIR__ . "/email_worker_lib.php";

/**
 * Only one sending run at a time. A batch of failing rows can take longer than
 * the 5 minutes between scheduled runs, and a second run would select the same
 * pending rows and email each person twice. A skipped run costs nothing: the
 * next one picks the rows up, so this exits 0 rather than fill Task Scheduler's
 * history with errors.
 *
 * The lock file ends in .log only because *.log is already gitignored. The OS
 * drops the lock when the process ends, so no exit path can leave it held.
 */
function email_worker_take_lock(){
    $handle = fopen(__DIR__ . "/email_worker.lock.log", "c");
    if(!$handle){
        fwrite(STDERR, "Cannot open the lock file, so a run cannot be made safe. Nothing was sent.\n");
        exit(1);
    }
    if(!flock($handle, LOCK_EX | LOCK_NB)){
        echo "Another email worker run is already in progress; skipping this one.\n";
        exit(0);
    }
    register_shutdown_function(function() use ($handle){
        flock($handle, LOCK_UN);
        fclose($handle);
    });
}

/** Stops quietly when the master switches are both off. */
function email_worker_require_enabled(array $config){
    if(!$config['enabled'] && !$config['dry_run']){
        exit("EMAIL_ENABLED is false and EMAIL_DRY_RUN is false; nothing to do.\n");
    }
}

/** What a run did, worded so a dry run cannot be read as a delivery. */
function email_worker_summary(array $result, array $config){
    if(!empty($config['dry_run'])){
        $line = sprintf('DRY RUN: %d row(s) marked dry-run, NOT emailed (nothing left the machine); failed %d',
            $result['dry_run'], $result['failed']);
    } else {
        $line = sprintf('sent %d, failed %d', $result['sent'], $result['failed']);
    }
    return $line . email_worker_skipped_note($result);
}

/** The tail of a result line when rows were skipped on a database error. */
function email_worker_skipped_note(array $result){
    return $result['errored'] > 0
        ? ", {$result['errored']} skipped on a database error (see the PHP error log)"
        : '';
}

$command = $argv[1] ?? 'status';
$config = email_config();

switch($command){
    case 'status':
        $state = email_queue_status($conn);
        echo "Email outbox\n";
        foreach(['pending', 'dry-run', 'sent', 'failed'] as $key){
            printf("  %-8s %d\n", $key, $state['counts'][$key] ?? 0);
        }
        if(!empty($state['counts']['dry-run'])){
            // A bare count would read as healthy; say what it means.
            echo "\n  dry-run rows were never emailed. `retry` puts them back in the queue.\n";
        }
        if(!empty($state['errors'])){
            echo "\nRecent failures\n";
            foreach($state['errors'] as $row){
                // A blank address is the record that nobody could be reached.
                printf("  #%-5d %-34s attempts %d: %s\n",
                    $row['id'], $row['recipient_email'] !== '' ? $row['recipient_email'] : '(no address)',
                    $row['attempts'], $row['last_error']);
            }
        }
        break;

    case 'run':
        email_worker_take_lock();
        email_worker_require_enabled($config);

        $onlyIds = null;
        if(isset($argv[2])){
            if(!ctype_digit($argv[2]) || (int) $argv[2] < 1){
                exit("'{$argv[2]}' is not a row id. Use: run <id>\n");
            }
            $id = (int) $argv[2];
            $stmt = $conn->prepare("SELECT status, recipient_email FROM email_outbox WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            if(!$found){
                exit("No outbox row #{$id}.\n");
            }
            if($found['status'] === 'sent'){
                exit("Row #{$id} was already sent; not sending it again.\n");
            }
            if(trim((string) $found['recipient_email']) === ''){
                exit("Row #{$id} records that nobody could be reached, so it has no address to send to. "
                    . "Add an address to the account, then assign the recommendation again.\n");
            }
            $onlyIds = [$id];

            // Checked before the reset, as retry does, so a missing .env
            // leaves a failed row as it is.
            $fault = email_config_fault($config);
            if($fault !== null){
                fwrite(STDERR, "Stopped: {$fault}\n");
                exit(2);
            }
            email_reset_failed($conn, $onlyIds);
        }

        $result = email_process_pending($conn, $config, $onlyIds);
        if($result['halted'] !== null){
            // Non-zero so Task Scheduler's history shows the run as failed.
            fwrite(STDERR, "Stopped: {$result['halted']}\n");
            exit(2);
        }
        echo email_worker_summary($result, $config), "\n";
        break;

    case 'retry':
        email_worker_take_lock();
        email_worker_require_enabled($config);
        // Checked before the reset, so a missing .env leaves failed rows as
        // they are instead of reviving them into a run that cannot send.
        $fault = email_config_fault($config);
        if($fault !== null){
            fwrite(STDERR, "Stopped: {$fault}\n");
            exit(2);
        }
        printf("%d undelivered row(s) (failed or dry-run) put back in the queue\n", email_reset_failed($conn));
        $result = email_process_pending($conn, $config);
        echo email_worker_summary($result, $config), "\n";
        break;

    default:
        exit("Unknown command '{$command}'. Use status, run or retry.\n");
}
