<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "recommendations_api: the three email triggers\n";

const T_TRIGGER_REC = 999002;
const T_TRIGGER_USER = 'zz_dave';

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

    $user = T_TRIGGER_USER;
    $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();
}

// The outbox table must exist before the cleanup queries it.
ensure_user_account_columns($conn);
ensure_email_outbox_table($conn);
register_shutdown_function('t_remove_trigger_test_rows', $conn);

// Leftovers from a run that died before its shutdown hook could run would make
// the inserts below fail as duplicates.
t_remove_trigger_test_rows($conn);

// Created before the API call that resolves recipients, so the lookup sees it.
$stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                        VALUES ('zz_dave',?, 'dave@example.invalid','','user','CSSAO','Dave Tester',?,'office')");
$hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
$status = USER_STATUS_APPROVED;
$stmt->bind_param("ss", $hash, $status);
$stmt->execute();

/** Run one API action in a child process, as the browser would. */
function t_api($post){
    $script = __DIR__ . "/_api_call.php";
    // Base64, because on Windows escapeshellarg() turns every % and " into a
    // space, which would silently mangle a url-encoded JSON value.
    $cmd = '"C:/xampp/php/php.exe" ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode(http_build_query($post)));
    return shell_exec($cmd);
}

/** Outbox rows currently queued for the test recommendation. */
function t_trigger_outbox($conn){
    $rec = T_TRIGGER_REC;
    $stmt = $conn->prepare("SELECT recipient_email FROM email_outbox WHERE recommendation_id = ? ORDER BY id");
    $stmt->bind_param("i", $rec);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function t_trigger_clear_outbox($conn){
    $rec = T_TRIGGER_REC;
    $stmt = $conn->prepare("DELETE FROM email_outbox WHERE recommendation_id = ?");
    $stmt->bind_param("i", $rec);
    $stmt->execute();
}

$stmt = $conn->prepare("INSERT INTO audit_recommendations (id, audit_type, office, recommendation, year, status)
                        VALUES (?,'Internal','CSSAO','', '2026','Pending')");
$rec = T_TRIGGER_REC;
$stmt->bind_param("i", $rec);
$stmt->execute();

// Writing the text for the first time is the moment it becomes real.
t_api(['action' => 'save_office_recommendation', 'id' => T_TRIGGER_REC,
       'recommendation' => 'Submit the updated inventory', 'status' => 'Pending',
       'year' => '2026', 'in_charge' => '[]']);
t_ok('new recommendation queued mail', count(t_trigger_outbox($conn)) > 0);

t_trigger_clear_outbox($conn);

// Putting a person in charge emails that person.
t_api(['action' => 'save_in_charge', 'id' => T_TRIGGER_REC, 'in_charge' => json_encode(['Dave Tester'])]);
$queued = t_trigger_outbox($conn);
t_equals('assignment emailed the named person', $queued[0]['recipient_email'] ?? '', 'dave@example.invalid');

t_trigger_clear_outbox($conn);

// A review that hands work back emails; one that approves does not.
t_api(['action' => 'review_recommendation', 'id' => T_TRIGGER_REC, 'decision' => 'needs_revision', 'review_remarks' => 'Attach the signed copy.']);
t_ok('needs revision queued mail', count(t_trigger_outbox($conn)) > 0);

t_trigger_clear_outbox($conn);
t_api(['action' => 'review_recommendation', 'id' => T_TRIGGER_REC, 'decision' => 'approve', 'review_remarks' => 'Received.']);
t_equals('approval queued nothing', count(t_trigger_outbox($conn)), 0);

t_summary();
