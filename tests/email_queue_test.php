<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: rendering and queueing\n";

/** The error log this file writes to, so a test can read what was logged. */
$GLOBALS['t_queue_log'] = null;

/**
 * Runs on every exit, including a fatal error part-way through, so a failed
 * run leaves neither the throwaway account nor its outbox rows in ems_db.
 */
function t_remove_queue_test_rows($conn){
    $user = 'zz_carol';
    $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();

    foreach([999001, 999003] as $rec){
        $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recommendation_id = ?");
        $stmt->bind_param("i", $rec);
        $stmt->execute();
    }

    if(!empty($GLOBALS['t_queue_log']) && file_exists($GLOBALS['t_queue_log'])){
        unlink($GLOBALS['t_queue_log']);
    }
}

// The outbox table must exist before the cleanup queries it.
ensure_user_account_columns($conn);
ensure_email_outbox_table($conn);
register_shutdown_function('t_remove_queue_test_rows', $conn);

// Leftovers from a run that died before its shutdown hook could run would make
// the insert below fail as a duplicate.
t_remove_queue_test_rows($conn);

// Logged failures go to a temporary file, not the XAMPP log, so the test can
// read back what was written and nothing lands in the real log.
$GLOBALS['t_queue_log'] = tempnam(sys_get_temp_dir(), 'emq');
ini_set('error_log', $GLOBALS['t_queue_log']);

// Created before the first recipient lookup: the staff map is cached per request.
$stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                        VALUES ('zz_carol',?, 'carol@example.invalid','','user','ZZ Test Office','Carol Tester',?,'office')");
$hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
$status = USER_STATUS_APPROVED;
$stmt->bind_param("ss", $hash, $status);
$stmt->execute();

$row = [
    'id' => 999001,
    'office' => 'ZZ Test Office',
    'recommendation' => 'Submit the updated inventory',
    'in_charge' => json_encode(['Carol Tester']),
    'status' => 'Needs Revision',
    // Canaries: what a shared recommendation carries that must never reach
    // another office's mail. Each is distinct, so a leak names its source.
    'remarks' => 'SECRET-REMARK',
    'review_remarks' => 'SECRET-REVIEW',
    'compliance_response' => 'SECRET-COMPLIANCE',
    'documents' => json_encode(['SECRET-DOC.pdf']),
];

$queued = email_notify($conn, $row, NOTIFY_ASSIGNED);
t_equals('one row queued', $queued, 1);

$out = mysqli_query($conn, "SELECT * FROM email_outbox WHERE recommendation_id = 999001")->fetch_assoc();
t_equals('addressed to the named person', $out['recipient_email'], 'carol@example.invalid');
t_equals('queued pending', $out['status'], 'pending');
t_equals('no attempts yet', (int) $out['attempts'], 0);
t_ok('subject names the office', strpos($out['subject'], 'ZZ Test Office') !== false);
t_ok('body carries the recommendation', strpos($out['body'], 'Submit the updated inventory') !== false);
t_ok('body links to the site', strpos($out['body'], 'https://') !== false);

// The spec forbids leaking one office's submission to another. Checked against
// the stored subject and body, so a renderer that dumps the row would fail here.
$stored = $out['subject'] . "\n" . $out['body'];
t_ok('review remarks stay out of the message', strpos($stored, 'SECRET-REMARK') === false);
t_ok('review decision text stays out of the message', strpos($stored, 'SECRET-REVIEW') === false);
t_ok('compliance responses stay out of the message', strpos($stored, 'SECRET-COMPLIANCE') === false);
t_ok('document names stay out of the message', strpos($stored, 'SECRET-DOC.pdf') === false);

// Events that must not email.
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999001");
t_equals('feedback does not email', email_notify($conn, $row, NOTIFY_FEEDBACK), 0);
$approved = array_merge($row, ['status' => 'Approved']);
t_equals('approved does not email', email_notify($conn, $approved, NOTIFY_STATUS), 0);
$revision = array_merge($row, ['status' => 'Needs Revision']);
t_equals('needs revision does email', email_notify($conn, $revision, NOTIFY_STATUS), 1);

// Added beyond the brief: the spec emails a newly written recommendation, and
// nothing else exercises that branch or the refusal of an unlisted event.
t_equals('new recommendation does email', email_notify($conn, $row, NOTIFY_NEW), 1);
t_equals('an unlisted event does not email', email_notify($conn, $row, 'something_else'), 0);

// A notification must never fail the save that triggered it. The recommendation
// fits its own TEXT column, but the mail body wraps it in a greeting and footer
// and overflows the outbox's TEXT column. Strict mode makes that an error on
// this connection; the server default truncates it silently, which is a
// different problem. The mode is set on this session only.
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999001");
mysqli_query($conn, "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES')");
$huge = array_merge($row, ['id' => 999003, 'recommendation' => str_repeat('A', 65500)]);
$overflowQueued = email_notify($conn, $huge, NOTIFY_ASSIGNED);
t_equals('an overflowing message returns instead of throwing', $overflowQueued, 0);

$logged = (string) file_get_contents($GLOBALS['t_queue_log']);
t_ok('the failure is logged against its recommendation', strpos($logged, 'recommendation 999003') !== false);
t_ok('the failure is logged against its recipient', strpos($logged, 'carol@example.invalid') !== false);

$partial = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id = 999003")->fetch_assoc();
t_equals('no partial row is left behind', (int) $partial['c'], 0);

t_summary();
