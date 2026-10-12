<?php

/**
 * CLI worker for the email outbox.
 *
 *   php email_worker.php status   Counts by state and recent failures
 *   php email_worker.php run      Send everything still pending
 *   php email_worker.php retry    Put failed rows back in the queue, then run
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

$command = $argv[1] ?? 'status';
$config = email_config();

switch($command){
    case 'status':
        $state = email_queue_status($conn);
        echo "Email outbox\n";
        foreach(['pending', 'sent', 'failed'] as $key){
            printf("  %-8s %d\n", $key, $state['counts'][$key] ?? 0);
        }
        if(!empty($state['errors'])){
            echo "\nRecent failures\n";
            foreach($state['errors'] as $row){
                printf("  #%-5d %-34s attempts %d: %s\n",
                    $row['id'], $row['recipient_email'], $row['attempts'], $row['last_error']);
            }
        }
        break;

    case 'run':
        if(!$config['enabled'] && !$config['dry_run']){
            exit("EMAIL_ENABLED is false and EMAIL_DRY_RUN is false; nothing to do.\n");
        }
        $result = email_process_pending($conn, $config);
        if($result['halted'] !== null){
            // Non-zero so Task Scheduler's history shows the run as failed.
            fwrite(STDERR, "Stopped: {$result['halted']}\n");
            exit(2);
        }
        printf("sent %d, failed %d%s\n", $result['sent'], $result['failed'],
            $config['dry_run'] ? ' (dry run: nothing left the machine)' : '');
        break;

    case 'retry':
        if(!$config['enabled'] && !$config['dry_run']){
            exit("EMAIL_ENABLED is false and EMAIL_DRY_RUN is false; nothing to do.\n");
        }
        // Checked before the reset, so a missing .env leaves failed rows as
        // they are instead of reviving them into a run that cannot send.
        $fault = email_config_fault($config);
        if($fault !== null){
            fwrite(STDERR, "Stopped: {$fault}\n");
            exit(2);
        }
        printf("%d failed row(s) put back in the queue\n", email_reset_failed($conn));
        $result = email_process_pending($conn, $config);
        printf("sent %d, failed %d\n", $result['sent'], $result['failed']);
        break;

    default:
        exit("Unknown command '{$command}'. Use status, run or retry.\n");
}
