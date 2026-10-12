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
 * $onlyIds null drains every pending row, which is what the scheduler wants.
 * A list restricts the run to those ids, so a caller that owns particular rows
 * (the test, or an administrator resending one) cannot sweep up a row that
 * arrives while it runs.
 *
 * Returns 'sent' (really delivered) and 'dry_run' (processed under a dry run,
 * delivered to nobody) separately, so no caller can read one as the other.
 * 'halted' is a message, or null, when the run stopped before touching any row
 * because of a configuration fault; 'errored' counts rows whose database work
 * threw. An errored row is skipped, not allowed to end the batch.
 */
function email_process_pending($conn, array $config, ?array $onlyIds = null){
    ensure_email_outbox_table($conn);

    $fault = email_config_fault($config);
    if($fault !== null){
        return ['sent' => 0, 'dry_run' => 0, 'failed' => 0, 'errored' => 0, 'halted' => $fault];
    }

    $rows = [];
    if($onlyIds === null){
        $limit = max(1, (int) $config['batch_size']);
        $result = mysqli_query($conn, "SELECT * FROM email_outbox WHERE status = 'pending'
                                       ORDER BY id ASC LIMIT {$limit}");
        if($result){
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        $ids = array_values(array_unique(array_map('intval', $onlyIds)));
        if(!empty($ids)){
            // The caller named these rows, so batch_size does not apply.
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT * FROM email_outbox WHERE status = 'pending' AND id IN ({$marks}) ORDER BY id ASC");
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    $sent = 0;
    $dryRun = 0;
    $failed = 0;
    $errored = 0;
    foreach($rows as $row){
        // email_send() never throws, but the database work around it can
        // (mysqli throws on PHP 8.1+). One bad row must not end the batch.
        try {
            // body and recipient_name can be NULL or empty; email_send() casts.
            $outcome = email_send($config, $row['recipient_email'], (string) $row['recipient_name'],
                                  $row['subject'], (string) $row['body']);

            if($outcome['ok']){
                // At-least-once, deliberately: the row is marked sent only
                // after the message has gone. If this UPDATE fails the row
                // stays pending and is sent again -- a duplicate email is
                // better than the loss that marking it first would risk.
                //
                // A dry run reaches here too, because email_send() reports it
                // as ok. Nothing left the machine, so the row is 'dry-run' and
                // has no send time -- never 'sent', which would put a false
                // delivery into the record and strand the mail. Same split as
                // onedrive_sync.php ('dry-run' versus 'uploaded'). The retry
                // command puts these rows back in the queue.
                if(!empty($config['dry_run'])){
                    $update = $conn->prepare("UPDATE email_outbox SET status='dry-run', sent_at=NULL, last_error=NULL WHERE id = ?");
                    $update->bind_param("i", $row['id']);
                    $update->execute();
                    $dryRun++;
                    continue;
                }

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
        } catch(Throwable $e){
            $errored++;
            error_log("email_process_pending: outbox row {$row['id']} skipped: " . $e->getMessage());
        }
    }

    return ['sent' => $sent, 'dry_run' => $dryRun, 'failed' => $failed, 'errored' => $errored, 'halted' => null];
}

/**
 * Put back in the queue every row that was not actually delivered: the
 * 'failed' ones, as od_cmd_run does for uploads, and the 'dry-run' ones, which
 * were drained by a rehearsal and emailed to nobody. A 'sent' row is never
 * revived, or its recipient would get the message twice.
 *
 * A row with no recipient_email is never revived either. That is the record
 * email_record_unreachable() writes when nobody could be reached; reviving it
 * would only fail again on the empty address, burn its attempts and file a
 * second failure for the same thing.
 *
 * $onlyIds limits it to those rows; null revives all of them.
 */
function email_reset_failed($conn, ?array $onlyIds = null){
    ensure_email_outbox_table($conn);

    if($onlyIds === null){
        mysqli_query($conn, "UPDATE email_outbox SET status='pending', attempts=0, sent_at=NULL, last_error=NULL WHERE status IN ('failed','dry-run') AND recipient_email <> ''");
        return mysqli_affected_rows($conn);
    }

    $ids = array_values(array_unique(array_map('intval', $onlyIds)));
    if(empty($ids)){
        return 0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE email_outbox SET status='pending', attempts=0, sent_at=NULL, last_error=NULL WHERE status IN ('failed','dry-run') AND recipient_email <> '' AND id IN ({$marks})");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    return $stmt->affected_rows;
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
