<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: rendering and queueing\n";

/**
 * Runs on every exit, including a fatal error part-way through, so a failed
 * run leaves neither the throwaway account nor its outbox rows in ems_db.
 */
function t_remove_queue_test_rows($conn){
    $user = 'zz_carol';
    $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();

    $rec = 999001;
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recommendation_id = ?");
    $stmt->bind_param("i", $rec);
    $stmt->execute();
}
register_shutdown_function('t_remove_queue_test_rows', $conn);

// Leftovers from a run that died before its shutdown hook could run would make
// the insert below fail as a duplicate.
t_remove_queue_test_rows($conn);

// The insert needs the columns that ensure_user_account_columns() adds, and the
// outbox table must exist before the cleanup queries it.
ensure_user_account_columns($conn);
ensure_email_outbox_table($conn);

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

// The spec forbids leaking one office's submission to another.
t_ok('body has no remarks or document names',
    stripos($out['body'], 'remark') === false && stripos($out['body'], '.pdf') === false);

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

t_summary();
