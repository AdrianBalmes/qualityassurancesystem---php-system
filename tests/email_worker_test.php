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
// queued row that is not ours would be marked sent (dry run) without ever
// being delivered -- the silent non-delivery this feature exists to prevent.
// So refuse to start while anything else is waiting, and touch nothing.
$ownEmail = T_WORKER_EMAIL;
$foreign = $conn->prepare("SELECT COUNT(*) c FROM email_outbox
                           WHERE status IN ('pending','failed') AND recipient_email <> ?");
$foreign->bind_param("s", $ownEmail);
$foreign->execute();
$foreignCount = (int) $foreign->get_result()->fetch_assoc()['c'];
if($foreignCount > 0){
    fwrite(STDERR, "ABORTED: {$foreignCount} pending or failed email_outbox row(s) are not this test's. "
        . "Running would send them as a dry run (marking them sent undelivered) or revive them. "
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

function t_clear_own($conn){
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recipient_email = ?");
    $email = T_WORKER_EMAIL;
    $stmt->bind_param("s", $email);
    $stmt->execute();
}

$base = array_merge(email_config(), ['enabled' => true, 'batch_size' => 10]);
$dry = array_merge($base, ['dry_run' => true]);

// A dry run drains the queue and marks the row sent.
$id = t_queue_row($conn);
$result = email_process_pending($conn, $dry);
t_equals('dry run sent one', $result['sent'], 1);
$row = t_fetch_row($conn, $id);
t_equals('marked sent', $row['status'], 'sent');
t_ok('sent_at stamped', !empty($row['sent_at']));

// A missing .env is a configuration fault, not a per-row one. If it counted as
// an attempt, one blank credential would burn every attempt on every queued row
// and strand mail that was never tried. The run must stop and say why.
t_clear_own($conn);
$id = t_queue_row($conn);
$noCredentials = array_merge($base, ['dry_run' => false, 'username' => '', 'password' => '', 'max_attempts' => 1]);
for($pass = 1; $pass <= 2; $pass++){
    $result = email_process_pending($conn, $noCredentials);
    $row = t_fetch_row($conn, $id);
    t_equals("missing credentials, pass {$pass}: nothing sent or failed", [$result['sent'], $result['failed']], [0, 0]);
    t_ok("missing credentials, pass {$pass}: the run reports why it stopped",
         !empty($result['halted']) && strpos($result['halted'], 'EMAIL_USERNAME') !== false);
    t_equals("missing credentials, pass {$pass}: still pending", $row['status'], 'pending');
    t_equals("missing credentials, pass {$pass}: no attempt burned", (int) $row['attempts'], 0);
    t_equals("missing credentials, pass {$pass}: no error written to the row", $row['last_error'], null);
}
$noPassword = array_merge($noCredentials, ['username' => 'someone@example.invalid', 'password' => '   ']);
$result = email_process_pending($conn, $noPassword);
t_ok('a blank password alone also halts the run', !empty($result['halted']));
t_equals('a blank password burns no attempt', (int) t_fetch_row($conn, $id)['attempts'], 0);

// Review Focus 4: a per-row failure must stop at the cap, not retry forever.
// Credentials are present here, so this is a genuine delivery failure. The host
// is loopback port 1: the connection is refused on the spot, and nothing can
// leave the machine however the test misbehaves.
$unreachable = array_merge($base, ['dry_run' => false, 'host' => '127.0.0.1', 'port' => 1,
                                   'username' => 'zz-sender@example.invalid', 'password' => 'not-a-real-password',
                                   'max_attempts' => 2]);
$result = email_process_pending($conn, $unreachable);
$row = t_fetch_row($conn, $id);
t_ok('a delivery failure is not a halt', empty($result['halted']));
t_equals('first failure keeps it pending', $row['status'], 'pending');
t_equals('attempts counted', (int) $row['attempts'], 1);
t_ok('error recorded', trim((string) $row['last_error']) !== '');

$result = email_process_pending($conn, $unreachable);
$row = t_fetch_row($conn, $id);
t_equals('gives up at the cap', $row['status'], 'failed');
t_equals('the run counts the permanent failure', $result['failed'], 1);

$stmt = $conn->prepare("SELECT COUNT(*) c FROM audit_log WHERE action = 'email_failed' AND recommendation_id = ?");
$stmt->bind_param("i", $recId);
$stmt->execute();
t_ok('permanent failure reaches the Activity Log', (int) $stmt->get_result()->fetch_assoc()['c'] > 0);

// A failed row is not retried until retry resets it.
$before = email_process_pending($conn, $unreachable);
t_equals('failed rows are left alone', $before['sent'] + $before['failed'], 0);
t_equals('retry reports one row revived', email_reset_failed($conn), 1);
$row = t_fetch_row($conn, $id);
t_equals('retry puts it back', $row['status'], 'pending');
t_equals('retry clears attempts', (int) $row['attempts'], 0);

// Review Focus 5: the recommendation is gone; the row still sends from its own
// stored subject and body. The recommendation is inserted above, so this really
// deletes something -- and only something this test created.
t_equals('the recommendation exists before', (int) mysqli_query($conn, "SELECT COUNT(*) c FROM audit_recommendations WHERE id = " . T_WORKER_REC)->fetch_assoc()['c'], 1);
mysqli_query($conn, "DELETE FROM audit_recommendations WHERE id = " . T_WORKER_REC);
t_equals('the recommendation is gone', (int) mysqli_query($conn, "SELECT COUNT(*) c FROM audit_recommendations WHERE id = " . T_WORKER_REC)->fetch_assoc()['c'], 0);
$result = email_process_pending($conn, $dry);
t_equals('sends without the recommendation row', $result['sent'], 1);

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

$result = email_process_pending($conn, $dry);
t_equals('a NULL body and NULL recommendation still send', $result['sent'], 1);

mysqli_query($conn, "UPDATE email_outbox SET status='pending' WHERE id = {$nullId}");
$giveUpAtOnce = array_merge($unreachable, ['max_attempts' => 1]);
$result = email_process_pending($conn, $giveUpAtOnce);
t_equals('a NULL-recommendation row can fail permanently', $result['failed'], 1);
$stmt = $conn->prepare("SELECT recommendation_id FROM audit_log WHERE action = 'email_failed' AND entity_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $nullId);
$stmt->execute();
$logged = $stmt->get_result()->fetch_assoc();
t_ok('its audit entry exists', $logged !== null);
// array_key_exists, not ??: the column is NULL, which ?? would read as absent.
t_ok('its audit entry has no recommendation, not 0', $logged !== null && array_key_exists('recommendation_id', $logged) && $logged['recommendation_id'] === null);

// Nothing else may have changed state while this ran. The up-front check
// cannot stop a recommendation being assigned on the live site mid-run, but it
// can say so if a foreign row was swept up.
$swept = $conn->prepare("SELECT COUNT(*) c FROM email_outbox WHERE recipient_email <> ?");
$swept->bind_param("s", $ownEmail);
$swept->execute();
t_equals('no row that is not the test\'s appeared or was changed', (int) $swept->get_result()->fetch_assoc()['c'], 0);

t_summary();
