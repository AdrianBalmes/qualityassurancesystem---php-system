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

echo "email_notifications: rendering and queueing\n";

/** The error log this file writes to, so a test can read what was logged. */
$GLOBALS['t_queue_log'] = null;

/**
 * Runs on every exit, including a fatal error part-way through, so a failed
 * run leaves neither the throwaway account nor its outbox rows in ems_db.
 */
function t_remove_queue_test_rows($conn){
    $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
    foreach(['zz_carol', 'zz_quiet'] as $user){
        $stmt->bind_param("s", $user);
        $stmt->execute();
    }

    // 99999999999 is the id the strict-mode test queues under; 2147483647 is
    // what a non-strict server would clamp it to if that test ever wrote a row.
    foreach([999001, 999003, 999004, 999005, 99999999999, 2147483647] as $rec){
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

// An approved account with no address: reachable by name, not by mail.
$stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                        VALUES ('zz_quiet',?, '','','user','ZZ Silent Office','Quiet Tester',?,'office')");
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

// A new recommendation must read true to whoever receives it. A named person
// from another office was not "filed for your office"; the owner office was.
$newRow = ['id' => 999001, 'office' => 'ZZ Test Office', 'recommendation' => 'Submit the updated inventory',
           'in_charge' => json_encode(['Quiet Tester', 'ZZ Other Office']), 'status' => 'Pending'];
$person  = email_render($newRow, NOTIFY_NEW, ['name' => 'Quiet Tester', 'office' => 'ZZ Silent Office', 'email' => 'x@example.invalid']);
$office  = email_render($newRow, NOTIFY_NEW, ['name' => 'Olive Other', 'office' => 'ZZ Other Office', 'email' => 'x@example.invalid']);
$owner   = email_render($newRow, NOTIFY_NEW, ['name' => 'Carol Tester', 'office' => 'ZZ Test Office', 'email' => 'x@example.invalid']);
t_ok('a named person is told they were put in charge', strpos($person['subject'], 'put in charge') !== false && strpos($person['body'], 'you have been put in charge') !== false);
t_ok('a named person is not told it was filed for their office', strpos($person['body'], 'filed for your office') === false);
t_ok('a named office is told its office was put in charge', strpos($office['body'], 'your office has been put in charge') !== false);
t_ok('a named office is not told it was filed for it', strpos($office['body'], 'filed for your office') === false);
t_ok('the owner office is told it was filed for its office', strpos($owner['body'], 'filed for your office') !== false);
t_ok('the owner office is not told it was put in charge', strpos($owner['subject'] . $owner['body'], 'put in charge') === false);

// The recommendation can fill its own TEXT column; wrapped in the greeting and
// footer it would overflow the outbox's. Cut, say so, and keep the footer.
$long = array_merge($newRow, ['recommendation' => str_repeat('alpha beta gamma ', 6000)]);
$rendered = email_render($long, NOTIFY_ASSIGNED, ['name' => 'Carol Tester', 'office' => 'ZZ Test Office', 'email' => 'x@example.invalid']);
t_ok('a long body is capped', strlen($rendered['body']) <= EMAIL_BODY_MAX_BYTES);
t_ok('and says it was cut', strpos($rendered['body'], 'was cut here') !== false);
t_ok('and keeps the dashboard link and footer', strpos($rendered['body'], email_config()['site_url'] . '/') !== false && substr($rendered['body'], -strlen('replies are not read.')) === 'replies are not read.');
t_ok('and is cut at a word, not inside one', preg_match('/(alpha|beta|gamma)\n\n\[This recommendation/', $rendered['body']) === 1);
$wide = email_render(array_merge($newRow, ['recommendation' => str_repeat("\xc3\xa9", 40000)]), NOTIFY_ASSIGNED, ['name' => 'Carol Tester', 'office' => 'ZZ Test Office', 'email' => 'x@example.invalid']);
t_ok('multibyte text is capped in bytes, not characters', strlen($wide['body']) <= EMAIL_BODY_MAX_BYTES);
t_ok('and is never cut inside a character', mb_check_encoding($wide['body'], 'UTF-8'));
$short = email_render($newRow, NOTIFY_ASSIGNED, ['name' => 'Carol Tester', 'office' => 'ZZ Test Office', 'email' => 'x@example.invalid']);
t_ok('a normal body is left alone', strpos($short['body'], 'was cut here') === false && strpos($short['body'], 'Submit the updated inventory') !== false);

// Through the real queue on this server's non-strict sql_mode, the mode in which
// MySQL truncates an oversize body without a word. The stored body must be the
// whole message, footer included.
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999001");
t_equals('a very long recommendation still queues', email_notify($conn, array_merge($row, ['recommendation' => str_repeat('alpha beta gamma ', 6000)]), NOTIFY_ASSIGNED), 1);
$stored = mysqli_query($conn, "SELECT body, LENGTH(body) n FROM email_outbox WHERE recommendation_id = 999001")->fetch_assoc();
t_ok('the stored body is not truncated by the database', (int) $stored['n'] <= EMAIL_BODY_MAX_BYTES && substr($stored['body'], -strlen('replies are not read.')) === 'replies are not read.');
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999001");

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

// Nobody to email is a failure that has to be visible: an office with no
// approved account, or whose only account has no address, is assigned and
// `status` would otherwise read as if everything had been delivered. One failed
// row with no address is the record, and it is not a queued message.
$nobody = ['id' => 999004, 'office' => 'ZZ Empty Office', 'recommendation' => 'Nobody can read this',
           'in_charge' => json_encode(['Typed Name']), 'status' => 'Needs Revision'];
t_equals('an office nobody can be reached in queues nothing', email_notify($conn, $nobody, NOTIFY_ASSIGNED), 0);
$record = mysqli_query($conn, "SELECT * FROM email_outbox WHERE recommendation_id = 999004")->fetch_assoc();
t_ok('but records that nobody could be reached', $record !== null);
t_equals('the record is failed, with no address and no attempts', [$record['status'], $record['recipient_email'], (int) $record['attempts']], ['failed', '', 0]);
t_equals('and is filed under the owning office and the event', [$record['recipient_office'], $record['event']], ['ZZ Empty Office', NOTIFY_ASSIGNED]);
t_ok('it says what was tried', strpos($record['last_error'], 'Typed Name') !== false && strpos($record['last_error'], 'ZZ Empty Office') !== false
    && strpos($record['last_error'], '#999004') !== false);
t_ok('and its subject says it was not sent', strpos($record['subject'], 'NOT SENT') === 0);
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999004");

// An account with no address is not reachable either, by name or as the office.
$silent = ['id' => 999004, 'office' => 'ZZ Silent Office', 'recommendation' => 'Nobody can read this',
           'in_charge' => json_encode(['Quiet Tester']), 'status' => 'Needs Revision'];
t_equals('a named person and an office with no address queue nothing', email_notify($conn, $silent, NOTIFY_STATUS), 0);
$record = mysqli_query($conn, "SELECT status, event, last_error FROM email_outbox WHERE recommendation_id = 999004")->fetch_assoc();
t_equals('and that is recorded too', [$record['status'], $record['event']], ['failed', NOTIFY_STATUS]);
t_ok('naming the person who could not be reached', strpos($record['last_error'], 'Quiet Tester') !== false);
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999004");

// Whoever made the change is deliberately not mailed. When they were the only
// one, that is not a failure to reach anybody and must not be recorded as one.
$selfOnly = array_merge($row, ['id' => 999005]);
t_equals('assigning yourself queues nothing', email_notify($conn, $selfOnly, NOTIFY_ASSIGNED, 'carol@example.invalid'), 0);
$noise = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id = 999005")->fetch_assoc();
t_equals('and records no failure', (int) $noise['c'], 0);

// An event that does not email records nothing either, whoever is reachable.
t_equals('feedback to an unreachable office queues nothing', email_notify($conn, $nobody, NOTIFY_FEEDBACK), 0);
$noise = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id = 999004")->fetch_assoc();
t_equals('and records no failure', (int) $noise['c'], 0);

// A notification must never fail the save that triggered it. Strict mode makes
// a bad value an error on this connection (the server default would clamp it
// silently); the id below is out of range for the column, so the insert
// really throws. The mode is set on this session only, and put back.
$previousMode = mysqli_query($conn, "SELECT @@SESSION.sql_mode m")->fetch_assoc()['m'];
mysqli_query($conn, "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES')");
$outOfRange = array_merge($row, ['id' => 99999999999]);
$overflowQueued = email_notify($conn, $outOfRange, NOTIFY_ASSIGNED);
mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $previousMode) . "'");
t_equals('a failing insert returns instead of throwing', $overflowQueued, 0);

$logged = (string) file_get_contents($GLOBALS['t_queue_log']);
t_ok('the failure is logged against its recommendation', strpos($logged, 'recommendation 99999999999') !== false);
t_ok('the failure is logged against its recipient', strpos($logged, 'carol@example.invalid') !== false);

$partial = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id IN (99999999999, 2147483647)")->fetch_assoc();
t_equals('no partial row is left behind', (int) $partial['c'], 0);

t_summary();
