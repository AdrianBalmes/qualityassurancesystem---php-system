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
// revives EVERY failed one: that is their job. Against the live ems_db a
// queued row that is not ours would be marked dry-run (never emailed) without ever
// being delivered -- the silent non-delivery this feature exists to prevent.
// So refuse to start while anything else is waiting, and touch nothing. Every
// call below also passes its own row ids, which covers the other window: a
// real row queued on the live site while the test is running.
$ownEmail = T_WORKER_EMAIL;
$foreign = $conn->prepare("SELECT COUNT(*) c FROM email_outbox
                           WHERE status IN ('pending','failed') AND recipient_email <> ?");
$foreign->bind_param("s", $ownEmail);
$foreign->execute();
$foreignCount = (int) $foreign->get_result()->fetch_assoc()['c'];
if($foreignCount > 0){
    fwrite(STDERR, "ABORTED: {$foreignCount} pending or failed email_outbox row(s) are not this test's. "
        . "Running would dry-run them (marking them undelivered) or revive them. "
        . "Nothing was changed. Let the worker drain the queue, then run this again.\n");
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
$result = t_process($conn, $dry);
t_equals('dry run processed one', $result['dry_run'], 1);
t_equals('dry run reports no real send', $result['sent'], 0);
$row = t_fetch_row($conn, $id);
t_equals('marked dry-run', $row['status'], 'dry-run');
t_ok('never marked sent', $row['status'] !== 'sent');
t_equals('no send time stamped', $row['sent_at'], null);
t_equals('status reports the dry-run row', email_queue_status($conn)['counts']['dry-run'] ?? 0, 1);

// Those rows were never delivered, so they must be able to go back in the queue.
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
t_equals('retry reports one row revived', email_reset_failed($conn, t_own_ids($conn)), 1);
$row = t_fetch_row($conn, $id);
t_equals('retry puts it back', $row['status'], 'pending');
t_equals('retry clears attempts', (int) $row['attempts'], 0);

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
// can say so if a foreign row was swept up.
$swept = $conn->prepare("SELECT COUNT(*) c FROM email_outbox WHERE recipient_email <> ?");
$swept->bind_param("s", $ownEmail);
$swept->execute();
t_equals('no row that is not the test\'s appeared or was changed', (int) $swept->get_result()->fetch_assoc()['c'], 0);

t_summary();
