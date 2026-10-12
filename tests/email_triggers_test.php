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

echo "recommendations_api: the email triggers\n";

const T_TRIGGER_REC = 999002;
const T_TRIGGER_OFFICE = 'ZZ Test Office';
const T_TRIGGER_OFFICE_B = 'ZZ Test Office B';
const T_TRIGGER_USERS = ['zz_dave', 'zz_erin', 'zz_frank'];

/**
 * Runs on every exit, including a failing assertion or a fatal error
 * part-way through, so a failed run leaves nothing behind in ems_db.
 */
function t_remove_trigger_test_rows($conn){
    $rec = T_TRIGGER_REC;
    // The API writes to each of these on its own, so each is cleaned by the
    // recommendation it was written for.
    foreach([
        "DELETE FROM email_outbox WHERE recommendation_id = ?",
        "DELETE FROM notifications WHERE recommendation_id = ?",
        "DELETE FROM audit_log WHERE recommendation_id = ? OR (entity_type = 'recommendation' AND entity_id = ?)",
        "DELETE FROM audit_recommendations WHERE id = ?",
    ] as $sql){
        $stmt = $conn->prepare($sql);
        if(substr_count($sql, '?') === 2){
            $stmt->bind_param("ii", $rec, $rec);
        } else {
            $stmt->bind_param("i", $rec);
        }
        $stmt->execute();
    }

    foreach(T_TRIGGER_USERS as $user){
        $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
    }
}

// Each table must exist before the cleanup queries it.
ensure_user_account_columns($conn);
ensure_email_outbox_table($conn);
ensure_notifications_table($conn);
register_shutdown_function('t_remove_trigger_test_rows', $conn);

// Leftovers from a run that died before its shutdown hook could run would make
// the inserts below fail as duplicates.
t_remove_trigger_test_rows($conn);

// Created before the API call that resolves recipients, so the lookup sees
// them. A fictitious office: the real ones have real staff with real addresses.
$hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
$status = USER_STATUS_APPROVED;
foreach([
    ['zz_dave',  'dave@example.invalid',  T_TRIGGER_OFFICE,   'Dave Tester'],
    ['zz_erin',  'erin@example.invalid',  T_TRIGGER_OFFICE,   'Erin Tester'],
    ['zz_frank', 'frank@example.invalid', T_TRIGGER_OFFICE_B, 'Frank Tester'],
] as $account){
    list($username, $email, $accountOffice, $fullName) = $account;
    $stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                            VALUES (?,?,?,'','user',?,?,?,'office')");
    $stmt->bind_param("ssssss", $username, $hash, $email, $accountOffice, $fullName, $status);
    $stmt->execute();
}

/**
 * Run one API action in a child process, as the browser would. Throws unless
 * the API answered ok: a crashed child or a refused call would otherwise look
 * exactly like "nothing was queued" and pass the assertions that expect that.
 */
function t_api($post, $actor = 'admin'){
    $script = __DIR__ . "/_api_call.php";
    // Base64, because on Windows escapeshellarg() turns every % and " into a
    // space, which would silently mangle a url-encoded JSON value.
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
         . escapeshellarg(base64_encode(http_build_query($post))) . ' ' . escapeshellarg($actor);
    $output = shell_exec($cmd);
    $reply = json_decode((string) $output, true);
    if(!is_array($reply) || empty($reply['ok'])){
        throw new RuntimeException("API call {$post['action']} failed: " . var_export($output, true));
    }
    return $reply;
}

/** Outbox rows currently queued for the test recommendation, oldest first. */
function t_trigger_outbox($conn){
    $rec = T_TRIGGER_REC;
    $stmt = $conn->prepare("SELECT recipient_email, event, subject FROM email_outbox WHERE recommendation_id = ? ORDER BY id");
    $stmt->bind_param("i", $rec);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Who was mailed, sorted so the assertion does not depend on insert order. */
function t_trigger_recipients($conn){
    $emails = array_column(t_trigger_outbox($conn), 'recipient_email');
    sort($emails);
    return $emails;
}

/** The distinct events queued, so one-event-per-save can be asserted. */
function t_trigger_events($conn){
    return array_values(array_unique(array_column(t_trigger_outbox($conn), 'event')));
}

function t_trigger_clear_outbox($conn){
    $rec = T_TRIGGER_REC;
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recommendation_id = ?");
    $stmt->bind_param("i", $rec);
    $stmt->execute();
}

$stmt = $conn->prepare("INSERT INTO audit_recommendations (id, audit_type, office, recommendation, year, status)
                        VALUES (?,'Internal',?,'', '2026','Pending')");
$rec = T_TRIGGER_REC;
$owner = T_TRIGGER_OFFICE;
$stmt->bind_param("is", $rec, $owner);
$stmt->execute();

/** The save the dashboard makes: text, status, year and who is in charge. */
function t_save($text, $status, array $inCharge){
    return t_api(['action' => 'save_office_recommendation', 'id' => T_TRIGGER_REC,
                  'recommendation' => $text, 'status' => $status,
                  'year' => '2026', 'in_charge' => json_encode($inCharge)]);
}

$dave = 'dave@example.invalid';
$erin = 'erin@example.invalid';
$frank = 'frank@example.invalid';
$text = 'Submit the updated inventory';

// The harness itself: a call that fails must raise, not look like "no mail".
$caught = false;
try {
    t_api(['action' => 'review_recommendation', 'id' => 0, 'decision' => 'approve']);
} catch(RuntimeException $e){
    $caught = true;
}
t_ok('a failed API call raises instead of passing silently', $caught);

// Writing the text for the first time is the moment it becomes real. Nobody is
// named, so the owning office hears about it.
t_save($text, 'Pending', []);
t_equals('new recommendation emailed the owning office', t_trigger_recipients($conn), [$dave, $erin]);
t_equals('and it was the new-recommendation mail', t_trigger_events($conn), [NOTIFY_NEW]);

t_trigger_clear_outbox($conn);

// Putting a person in charge emails that person, and only that person.
t_api(['action' => 'save_in_charge', 'id' => T_TRIGGER_REC, 'in_charge' => json_encode(['Dave Tester'])]);
t_equals('assignment emailed only the named person', t_trigger_recipients($conn), [$dave]);

t_trigger_clear_outbox($conn);

// Rule 4: whoever made the change is not told about it. Erin names herself.
t_api(['action' => 'save_in_charge', 'id' => T_TRIGGER_REC, 'in_charge' => json_encode(['Dave Tester', 'Erin Tester'])], 'zz_erin');
t_equals('the person who made the change is not emailed', t_trigger_recipients($conn), []);
t_equals('email_actor_address finds an account by username', email_actor_address($conn, 'zz_dave'), $dave);
t_equals('email_actor_address is empty for an unknown username', email_actor_address($conn, 'nobody_zz'), '');

t_trigger_clear_outbox($conn);

// A review that hands work back emails; one that approves does not.
t_api(['action' => 'review_recommendation', 'id' => T_TRIGGER_REC, 'decision' => 'needs_revision', 'review_remarks' => 'Attach the signed copy.']);
t_equals('needs revision emailed the people in charge', t_trigger_recipients($conn), [$dave, $erin]);

t_trigger_clear_outbox($conn);
$reply = t_api(['action' => 'review_recommendation', 'id' => T_TRIGGER_REC, 'decision' => 'approve', 'review_remarks' => 'Received.']);
t_equals('the approval was recorded', $reply['status'], 'Approved');
t_equals('approval queued nothing', t_trigger_recipients($conn), []);

// The dashboard's own status edit goes through save_office_recommendation.
t_save($text, 'Needs Revision', ['Dave Tester', 'Erin Tester']);
t_equals('a status edit to Needs Revision emailed the people in charge', t_trigger_recipients($conn), [$dave, $erin]);

t_trigger_clear_outbox($conn);
t_save($text, 'Approved', ['Dave Tester', 'Erin Tester']);
t_equals('a status edit to Approved queued nothing', t_trigger_recipients($conn), []);

// The dashboard also names who is in charge through that same save, so the
// assignment mail has to come from there too.
t_save($text, 'Approved', ['Dave Tester']);
t_equals('removing someone queued nothing', t_trigger_recipients($conn), []);
t_save($text, 'Approved', ['Dave Tester', 'Erin Tester']);
t_equals('adding a person through the grid emailed only that person', t_trigger_recipients($conn), [$erin]);
t_equals('and it was the assignment mail', t_trigger_events($conn), [NOTIFY_ASSIGNED]);

t_trigger_clear_outbox($conn);
t_save($text, 'Approved', ['Dave Tester', 'Erin Tester']);
t_equals('saving again with nothing new queued nothing', t_trigger_recipients($conn), []);

// Adding an office: the owner hears about it too, but worded as news and not
// as "you were put in charge of your own recommendation".
t_save($text, 'Approved', ['Dave Tester', 'Erin Tester', T_TRIGGER_OFFICE_B]);
t_equals('adding an office emailed the owner and the added office', t_trigger_recipients($conn), [$dave, $erin, $frank]);
$subjects = [];
foreach(t_trigger_outbox($conn) as $mail){
    $subjects[$mail['recipient_email']] = $mail['subject'];
}
t_ok('the added office is told it was put in charge', strpos($subjects[$frank] ?? '', 'Your office has been put in charge') === 0);
t_ok('the owner office is not told it was put in charge', strpos($subjects[$dave] ?? 'put in charge', 'put in charge') === false);

// One event per save: a status change and a new name in the same save is one
// message to each person, not two.
t_trigger_clear_outbox($conn);
t_save($text, 'Rejected', ['Dave Tester', 'Erin Tester', T_TRIGGER_OFFICE_B, 'Frank Tester']);
t_equals('a status change plus a new name sent only the status mail', t_trigger_events($conn), [NOTIFY_STATUS]);

t_summary();
