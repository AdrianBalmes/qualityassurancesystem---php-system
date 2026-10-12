<?php

// Command line only. tests/ is denied in .htaccess as well, for the same
// reason tools/ is: every file here runs against the live ems_db,
// so reached over the web they would let anyone alter it.
if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";
require_once __DIR__ . "/../audit_log_helper.php";
require_once __DIR__ . "/../email_worker_lib.php";

echo "email_worker\n";

const T_WORKER_EMAIL  = 'zz@example.invalid';
const T_WORKER_OFFICE = 'ZZ Test Office';
const T_WORKER_REC    = 999003;

ensure_email_outbox_table($conn);
ensure_audit_log_table($conn);

// email_process_pending() drains EVERY pending row and email_reset_failed()
// revives EVERY failed or dry-run one when called without ids: that is their
// job, and it is what the scheduler and `retry` do, so this test calls them
// that way too (see "The unscoped calls" below). Against the live ems_db a row
// that is not ours would be processed -- marked dry-run, never emailed -- or
// revived, without ever being delivered: the silent non-delivery this feature
// exists to prevent.
//
// So the test refuses to start while the outbox holds anything it does not
// own that those calls would touch, and changes nothing. That is the one thing
// that makes the unscoped calls safe, so keep it in step with them:
//   pending  - the unscoped run drains it
//   failed   - the unscoped reset revives it
//   dry-run  - the unscoped reset revives it too, since `retry` was widened
// 'sent' is not listed because neither call can reach it: the run reads only
// pending rows and the reset skips sent ones. A permanent history of
// deliveries must not stop the suite. A 'failed' row with no address is the
// record that nobody could be reached; the reset skips it, so it is not at risk
// and is not counted either.
//
// Every other call in this file passes its own row ids, which covers the
// other window: a real row queued on the live site while the test is running.

/** Rows in the outbox that this test does not own and the unscoped calls could touch. */
function t_foreign_count($conn){
    $ownEmail = T_WORKER_EMAIL;
    $foreign = $conn->prepare("SELECT COUNT(*) c FROM email_outbox
                               WHERE status IN ('pending','failed','dry-run')
                                 AND NOT (status = 'failed' AND recipient_email = '')
                                 AND recipient_email <> ?");
    $foreign->bind_param("s", $ownEmail);
    $foreign->execute();
    return (int) $foreign->get_result()->fetch_assoc()['c'];
}

$foreignCount = t_foreign_count($conn);
if($foreignCount > 0){
    fwrite(STDERR, "ABORTED: {$foreignCount} pending, failed or dry-run email_outbox row(s) are not this test's. "
        . "Running would dry-run them (marking them undelivered) or revive them. "
        . "Nothing was changed. Let the worker deliver them (or `retry` them once the settings are live), "
        . "then run this again.\n");
    exit(2);
}

// The test deletes recommendation 999003 to prove a row sends without it, so
// it must be the one that inserted it. Never delete a real record.
$recId = T_WORKER_REC;
$exists = $conn->prepare("SELECT COUNT(*) c FROM audit_recommendations WHERE id = ?");
$exists->bind_param("i", $recId);
$exists->execute();
if((int) $exists->get_result()->fetch_assoc()['c'] > 0){
    fwrite(STDERR, "ABORTED: audit_recommendations already holds id " . T_WORKER_REC
        . ", which this test would delete. Nothing was changed.\n");
    exit(2);
}

/** The error log the throw test writes to, so it can read back what was logged. */
$GLOBALS['t_worker_log'] = null;

/** Runs on every exit, including a fatal part-way through. Owned rows only. */
function t_remove_worker_test_rows($conn){
    $email = T_WORKER_EMAIL;
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recipient_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $office = T_WORKER_OFFICE;
    // The record of an unreachable recipient has no address, so it is found by
    // the office and recommendation it was written for.
    $rec = T_WORKER_REC;
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recipient_email = '' AND recipient_office = ? AND recommendation_id = ?");
    $stmt->bind_param("si", $office, $rec);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM audit_log WHERE action = 'email_failed' AND office = ?");
    $stmt->bind_param("s", $office);
    $stmt->execute();

    $rec = T_WORKER_REC;
    $stmt = $conn->prepare("DELETE FROM audit_recommendations WHERE id = ?");
    $stmt->bind_param("i", $rec);
    $stmt->execute();

    if(!empty($GLOBALS['t_worker_log']) && file_exists($GLOBALS['t_worker_log'])){
        unlink($GLOBALS['t_worker_log']);
    }
}

register_shutdown_function('t_remove_worker_test_rows', $conn);
// Leftovers from a run that died before its shutdown hook could run.
t_remove_worker_test_rows($conn);

$stmt = $conn->prepare("INSERT INTO audit_recommendations (id, audit_type, office, recommendation, year)
                        VALUES (?, 'Internal', ?, 'Throwaway recommendation for the worker test', '2026')");
$office = T_WORKER_OFFICE;
$stmt->bind_param("is", $recId, $office);
$stmt->execute();

function t_queue_row($conn){
    $stmt = $conn->prepare("INSERT INTO email_outbox
        (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body)
        VALUES (?,?,'ZZ',?,'recommendation_new','S','B')");
    $rec = T_WORKER_REC;
    $email = T_WORKER_EMAIL;
    $office = T_WORKER_OFFICE;
    $stmt->bind_param("iss", $rec, $email, $office);
    $stmt->execute();
    return $conn->insert_id;
}

function t_fetch_row($conn, $id){
    return mysqli_query($conn, "SELECT * FROM email_outbox WHERE id = " . (int) $id)->fetch_assoc();
}

/** Ids of the rows this test owns, passed to the worker so it can touch no others. */
function t_own_ids($conn){
    $stmt = $conn->prepare("SELECT id FROM email_outbox WHERE recipient_email = ?");
    $email = T_WORKER_EMAIL;
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
}

function t_process($conn, array $config){
    return email_process_pending($conn, $config, t_own_ids($conn));
}

function t_clear_own($conn){
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recipient_email = ?");
    $email = T_WORKER_EMAIL;
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

$base = array_merge(email_config(), ['enabled' => true, 'batch_size' => 10]);
$dry = array_merge($base, ['dry_run' => true]);

// A dry run drains the queue but must never claim delivery: nothing left the
// machine, so the row is marked 'dry-run', carries no send time, and is counted
// apart from real sends. Marking it 'sent' would make the audit trail say the
// opposite of what happened.
$id = t_queue_row($conn);
$dryRowsBefore = email_queue_status($conn)['counts']['dry-run'] ?? 0;
$result = t_process($conn, $dry);
t_equals('dry run processed one', $result['dry_run'], 1);
t_equals('dry run reports no real send', $result['sent'], 0);
$row = t_fetch_row($conn, $id);
t_equals('marked dry-run', $row['status'], 'dry-run');
t_ok('never marked sent', $row['status'] !== 'sent');
t_equals('no send time stamped', $row['sent_at'], null);
// A change in the count, not the count: the outbox is live, and rows from real
// use (a rehearsal, or deliveries since) are not this test's to assert about.
t_equals('status reports the dry-run row', (email_queue_status($conn)['counts']['dry-run'] ?? 0) - $dryRowsBefore, 1);

// Those rows were never delivered, so they must be able to go back in the queue.
// The error is set first, or "revival leaves no error" would be asserted of a
// column that was never written and could not fail.
mysqli_query($conn, "UPDATE email_outbox SET last_error='stale error from an earlier attempt' WHERE id = {$id}");
t_equals('a dry-run row can be revived', email_reset_failed($conn, [$id]), 1);
$row = t_fetch_row($conn, $id);
t_equals('revived to pending', $row['status'], 'pending');
t_equals('revival leaves no send time', $row['sent_at'], null);
t_equals('revival leaves no error', $row['last_error'], null);
$result = t_process($conn, $dry);
t_equals('and it can be dry-run again', $result['dry_run'], 1);

// A missing .env is a configuration fault, not a per-row one. If it counted as
// an attempt, one blank credential would burn every attempt on every queued row
// and strand mail that was never tried. The run must stop and say why.
t_clear_own($conn);
$id = t_queue_row($conn);
$noCredentials = array_merge($base, ['dry_run' => false, 'username' => '', 'password' => '', 'max_attempts' => 1]);
for($pass = 1; $pass <= 2; $pass++){
    $result = t_process($conn, $noCredentials);
    $row = t_fetch_row($conn, $id);
    t_equals("missing credentials, pass {$pass}: nothing sent or failed", [$result['sent'], $result['dry_run'], $result['failed']], [0, 0, 0]);
    t_ok("missing credentials, pass {$pass}: the run reports why it stopped",
         !empty($result['halted']) && strpos($result['halted'], 'EMAIL_USERNAME') !== false);
    t_equals("missing credentials, pass {$pass}: still pending", $row['status'], 'pending');
    t_equals("missing credentials, pass {$pass}: no attempt burned", (int) $row['attempts'], 0);
    t_equals("missing credentials, pass {$pass}: no error written to the row", $row['last_error'], null);
}
$noPassword = array_merge($noCredentials, ['username' => 'someone@example.invalid', 'password' => '   ']);
$result = t_process($conn, $noPassword);
t_ok('a blank password alone also halts the run', !empty($result['halted']));
t_equals('a blank password burns no attempt', (int) t_fetch_row($conn, $id)['attempts'], 0);

// Review Focus 4: a per-row failure must stop at the cap, not retry forever.
// Credentials are present here, so this is a genuine delivery failure. The host
// is loopback port 1: the connection is refused on the spot, and nothing can
// leave the machine however the test misbehaves.
$unreachable = array_merge($base, ['dry_run' => false, 'host' => '127.0.0.1', 'port' => 1,
                                   'username' => 'zz-sender@example.invalid', 'password' => 'not-a-real-password',
                                   'max_attempts' => 2]);
$result = t_process($conn, $unreachable);
$row = t_fetch_row($conn, $id);
t_ok('a delivery failure is not a halt', empty($result['halted']));
t_equals('first failure keeps it pending', $row['status'], 'pending');
t_equals('attempts counted', (int) $row['attempts'], 1);
t_ok('error recorded', trim((string) $row['last_error']) !== '');

$result = t_process($conn, $unreachable);
$row = t_fetch_row($conn, $id);
t_equals('gives up at the cap', $row['status'], 'failed');
t_equals('the run counts the permanent failure', $result['failed'], 1);

$stmt = $conn->prepare("SELECT COUNT(*) c FROM audit_log WHERE action = 'email_failed' AND recommendation_id = ?");
$stmt->bind_param("i", $recId);
$stmt->execute();
t_ok('permanent failure reaches the Activity Log', (int) $stmt->get_result()->fetch_assoc()['c'] > 0);

// A failed row is not retried until retry resets it.
$before = t_process($conn, $unreachable);
t_equals('failed rows are left alone', $before['sent'] + $before['dry_run'] + $before['failed'], 0);
t_ok('the failed row carries its error before retry', trim((string) t_fetch_row($conn, $id)['last_error']) !== '');
t_equals('retry reports one row revived', email_reset_failed($conn, t_own_ids($conn)), 1);
$row = t_fetch_row($conn, $id);
t_equals('retry puts it back', $row['status'], 'pending');
t_equals('retry clears attempts', (int) $row['attempts'], 0);
t_equals('retry clears the error', $row['last_error'], null);

// Review Focus 5: the recommendation is gone; the row still sends from its own
// stored subject and body. The recommendation is inserted above, so this really
// deletes something -- and only something this test created.
t_equals('the recommendation exists before', (int) mysqli_query($conn, "SELECT COUNT(*) c FROM audit_recommendations WHERE id = " . T_WORKER_REC)->fetch_assoc()['c'], 1);
mysqli_query($conn, "DELETE FROM audit_recommendations WHERE id = " . T_WORKER_REC);
t_equals('the recommendation is gone', (int) mysqli_query($conn, "SELECT COUNT(*) c FROM audit_recommendations WHERE id = " . T_WORKER_REC)->fetch_assoc()['c'], 0);
$result = t_process($conn, $dry);
t_equals('sends without the recommendation row', $result['dry_run'], 1);

// A row with no recommendation and no body (both columns are nullable) must
// neither crash the batch nor be logged against recommendation 0.
t_clear_own($conn);
$stmt = $conn->prepare("INSERT INTO email_outbox (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body)
                        VALUES (NULL,?,'ZZ',?,'recommendation_new','S',NULL)");
$email = T_WORKER_EMAIL;
$office = T_WORKER_OFFICE;
$stmt->bind_param("ss", $email, $office);
$stmt->execute();
$nullId = $conn->insert_id;

$result = t_process($conn, $dry);
t_equals('a NULL body and NULL recommendation still send', $result['dry_run'], 1);

mysqli_query($conn, "UPDATE email_outbox SET status='pending' WHERE id = {$nullId}");
$giveUpAtOnce = array_merge($unreachable, ['max_attempts' => 1]);
$result = t_process($conn, $giveUpAtOnce);
t_equals('a NULL-recommendation row can fail permanently', $result['failed'], 1);
$stmt = $conn->prepare("SELECT recommendation_id FROM audit_log WHERE action = 'email_failed' AND entity_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $nullId);
$stmt->execute();
$logged = $stmt->get_result()->fetch_assoc();
t_ok('its audit entry exists', $logged !== null);
// array_key_exists, not ??: the column is NULL, which ?? would read as absent.
t_ok('its audit entry has no recommendation, not 0', $logged !== null && array_key_exists('recommendation_id', $logged) && $logged['recommendation_id'] === null);

// The scope parameter: only the named ids are processed, an empty list is
// nothing, and the scoped reset revives only the named failed rows.
t_clear_own($conn);
$a = t_queue_row($conn);
$b = t_queue_row($conn);
$result = email_process_pending($conn, $dry, [$a]);
t_equals('a scoped run processes only the named row', $result['dry_run'], 1);
t_equals('the named row is marked dry-run', t_fetch_row($conn, $a)['status'], 'dry-run');
t_equals('the other row is left pending', t_fetch_row($conn, $b)['status'], 'pending');
$result = email_process_pending($conn, $dry, []);
t_equals('an empty scope processes nothing', $result['sent'] + $result['dry_run'], 0);
t_equals('and leaves the row pending', t_fetch_row($conn, $b)['status'], 'pending');
mysqli_query($conn, "UPDATE email_outbox SET status='failed', attempts=5 WHERE id IN ({$a}, {$b})");
t_equals('a scoped reset revives only the named row', email_reset_failed($conn, [$b]), 1);
t_equals('the named row is pending again', t_fetch_row($conn, $b)['status'], 'pending');
t_equals('the other failed row stays failed', t_fetch_row($conn, $a)['status'], 'failed');

// The same scoped revival covers dry-run rows, and leaves a genuinely sent row
// alone: a real delivery must never be put back to go out twice.
mysqli_query($conn, "UPDATE email_outbox SET status='dry-run' WHERE id = {$a}");
mysqli_query($conn, "UPDATE email_outbox SET status='sent', sent_at=NOW() WHERE id = {$b}");
t_equals('a scoped reset ignores a sent row', email_reset_failed($conn, [$b]), 0);
t_equals('the sent row stays sent', t_fetch_row($conn, $b)['status'], 'sent');
t_equals('a scoped reset revives a dry-run row', email_reset_failed($conn, [$a]), 1);
t_equals('the dry-run row is pending again', t_fetch_row($conn, $a)['status'], 'pending');

// The unscoped calls. email_worker.php `run` with no id (the scheduled task,
// every five minutes) and `retry` call email_process_pending() and
// email_reset_failed() with no ids, so those are the paths that matter most
// and every call above avoids them. They are safe to make here only because
// the entry guard proved the outbox holds nothing this test does not own; the
// check is repeated immediately before, to shrink the window in which a real
// assignment could arrive and be swept up.
t_clear_own($conn);
$late = t_foreign_count($conn);
if($late > 0){
    fwrite(STDERR, "ABORTED: {$late} pending, failed or dry-run email_outbox row(s) that are not this test's "
        . "appeared mid-run, so the unscoped calls were not made. Nothing foreign was changed.\n");
    exit(2);
}

function t_set_status($conn, $id, $status){
    $stmt = $conn->prepare("UPDATE email_outbox SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
}

// Run: oldest pending first, at most batch_size, and nothing that is not pending.
$p1 = t_queue_row($conn);
$p2 = t_queue_row($conn);
$p3 = t_queue_row($conn);
$wasFailed = t_queue_row($conn);
mysqli_query($conn, "UPDATE email_outbox SET status='failed', attempts=5, last_error='gave up' WHERE id = {$wasFailed}");
$wasSent = t_queue_row($conn);
mysqli_query($conn, "UPDATE email_outbox SET status='sent', sent_at='2026-01-01 00:00:00' WHERE id = {$wasSent}");
$wasDry = t_queue_row($conn);
t_set_status($conn, $wasDry, 'dry-run');

$result = email_process_pending($conn, array_merge($dry, ['batch_size' => 2]));
t_equals('an unscoped run stops at batch_size', $result['dry_run'], 2);
t_equals('and takes the oldest rows first', [t_fetch_row($conn, $p1)['status'], t_fetch_row($conn, $p2)['status']], ['dry-run', 'dry-run']);
t_equals('and leaves the newer one pending', t_fetch_row($conn, $p3)['status'], 'pending');

// A batch size of zero or less must still make progress, or the queue stalls.
$result = email_process_pending($conn, array_merge($dry, ['batch_size' => 0]));
t_equals('a batch size of 0 is clamped to one row', $result['dry_run'], 1);
t_equals('and that row was the pending one', t_fetch_row($conn, $p3)['status'], 'dry-run');
$p4 = t_queue_row($conn);
$p5 = t_queue_row($conn);
$result = email_process_pending($conn, array_merge($dry, ['batch_size' => -5]));
t_equals('a negative batch size is clamped to one row', $result['dry_run'], 1);
t_equals('and the other is left pending', t_fetch_row($conn, $p5)['status'], 'pending');

$result = email_process_pending($conn, $dry);
t_equals('the next unscoped run takes what is left', $result['dry_run'], 1);

// Only pending rows are ever selected: a failed, sent or dry-run row is not.
$failedRow = t_fetch_row($conn, $wasFailed);
t_equals('an unscoped run leaves a failed row failed', [$failedRow['status'], (int) $failedRow['attempts']], ['failed', 5]);
$sentRow = t_fetch_row($conn, $wasSent);
t_equals('an unscoped run leaves a sent row sent', [$sentRow['status'], $sentRow['sent_at']], ['sent', '2026-01-01 00:00:00']);
t_equals('an unscoped run does not touch a dry-run row', t_fetch_row($conn, $wasDry)['status'], 'dry-run');

// Reset: revives failed and dry-run, never sent, never a row with no address.
t_clear_own($conn);
$failedId = t_queue_row($conn);
mysqli_query($conn, "UPDATE email_outbox SET status='failed', attempts=5, last_error='gave up' WHERE id = {$failedId}");
$dryId = t_queue_row($conn);
mysqli_query($conn, "UPDATE email_outbox SET status='dry-run', last_error='stale' WHERE id = {$dryId}");
$sentId = t_queue_row($conn);
mysqli_query($conn, "UPDATE email_outbox SET status='sent', sent_at='2026-01-01 00:00:00' WHERE id = {$sentId}");
// The record email_record_unreachable() writes: failed, and no address to retry.
$stmt = $conn->prepare("INSERT INTO email_outbox
    (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body, status, attempts, last_error)
    VALUES (?, '', '', ?, 'recommendation_new', 'NOT SENT', '', 'failed', 0, 'nobody could be reached')");
$rec = T_WORKER_REC;
$office = T_WORKER_OFFICE;
$stmt->bind_param("is", $rec, $office);
$stmt->execute();
$nobodyId = $conn->insert_id;

t_equals('a scoped reset skips the nobody-reachable record', email_reset_failed($conn, [$nobodyId]), 0);
t_equals('an unscoped reset revives the failed and dry-run rows and no others', email_reset_failed($conn), 2);
$row = t_fetch_row($conn, $failedId);
t_equals('the failed row is pending with its attempts and error cleared', [$row['status'], (int) $row['attempts'], $row['last_error']], ['pending', 0, null]);
$row = t_fetch_row($conn, $dryId);
t_equals('the dry-run row is pending with its error cleared', [$row['status'], $row['last_error']], ['pending', null]);
$row = t_fetch_row($conn, $sentId);
t_equals('the sent row is not revived', [$row['status'], $row['sent_at']], ['sent', '2026-01-01 00:00:00']);
$row = t_fetch_row($conn, $nobodyId);
t_equals('the nobody-reachable record stays failed, untouched', [$row['status'], (int) $row['attempts'], $row['last_error']], ['failed', 0, 'nobody could be reached']);
t_clear_own($conn);

// One row whose database work throws must not end the batch. Forced honestly:
// the first row already holds the largest attempts value an int(11) column can
// store, so the increment after its delivery failure is out of range. Strict
// mode (this session only, restored below) makes that an error rather than a
// silent clamp. The second row then has to be processed all the same.
t_clear_own($conn);
$GLOBALS['t_worker_log'] = tempnam(sys_get_temp_dir(), 'emw');
$previousLog = ini_get('error_log');
ini_set('error_log', $GLOBALS['t_worker_log']);
$previousMode = mysqli_query($conn, "SELECT @@SESSION.sql_mode m")->fetch_assoc()['m'];
mysqli_query($conn, "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES')");

$first = t_queue_row($conn);
mysqli_query($conn, "UPDATE email_outbox SET attempts = 2147483647 WHERE id = {$first}");
$second = t_queue_row($conn);
$threw = false;
try {
    $result = email_process_pending($conn, array_merge($unreachable, ['max_attempts' => 1]), [$first, $second]);
} catch(Throwable $e){
    $threw = true;
    $result = ['errored' => -1, 'failed' => -1];
}
mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $previousMode) . "'");
ini_set('error_log', $previousLog);

t_ok('an exception does not escape the worker', !$threw);
t_equals('the throwing row is counted as errored', $result['errored'], 1);
t_equals('the rest of the batch is still processed', $result['failed'], 1);
t_equals('the throwing row stays pending', t_fetch_row($conn, $first)['status'], 'pending');
t_equals('the next row reached its own outcome', t_fetch_row($conn, $second)['status'], 'failed');
t_ok('the skipped row is logged by id', strpos((string) file_get_contents($GLOBALS['t_worker_log']), "outbox row {$first} skipped") !== false);

// Nothing else may have changed state while this ran. The up-front check
// cannot stop a recommendation being assigned on the live site mid-run, but it
// can say so if a foreign row was swept up. Same predicate as the guard: a
// 'sent' row from real use is history, not a casualty.
t_equals('no pending, failed or dry-run row that is not the test\'s appeared or was changed', t_foreign_count($conn), 0);

t_summary();
