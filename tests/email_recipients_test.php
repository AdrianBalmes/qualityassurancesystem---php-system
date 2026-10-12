<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: recipient resolution\n";

/**
 * Runs on every exit, including a fatal error part-way through. Without it a
 * failed run leaves zz_ accounts in the live ems_db: the RED run of this file
 * dies on an undefined function after the inserts below have already happened.
 */
function t_remove_test_accounts($conn){
    mysqli_query($conn, "DELETE FROM users WHERE username IN ('zz_alice','zz_bob','zz_noma')");
}
register_shutdown_function('t_remove_test_accounts', $conn);

// Leftovers from a run that died before its shutdown hook could run would make
// the inserts below fail as duplicates.
t_remove_test_accounts($conn);

/** Two throwaway accounts in one office, so the test owns its data. */
function t_make_account($conn, $username, $fullName, $office, $email){
    $stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                            VALUES (?,?,?,'','user',?,?,?,'office')");
    $hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
    $status = USER_STATUS_APPROVED;
    $stmt->bind_param("ssssss", $username, $hash, $email, $office, $fullName, $status);
    $stmt->execute();
}

// The inserts need the columns that ensure_user_account_columns() adds, and
// that call does not resolve recipients, so it is safe before the first lookup.
ensure_user_account_columns($conn);

$office = 'ZZ Test Office';
t_make_account($conn, 'zz_alice', 'Alice Tester', $office, 'alice@example.invalid');
t_make_account($conn, 'zz_bob',   'Bob Tester',   $office, 'bob@example.invalid');
t_make_account($conn, 'zz_noma',  'Noma Tester',  $office, '');   // blank address

// A named person wins over their whole office.
$people = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode(['Alice Tester'])]);
t_equals('named person only', array_column($people, 'email'), ['alice@example.invalid']);

// Nobody named -> the whole office, minus the account with no address.
$all = email_recipients_for($conn, ['office' => $office, 'in_charge' => '[]']);
$addresses = array_column($all, 'email');
sort($addresses);
t_equals('office fallback reaches both addressable accounts',
    $addresses, ['alice@example.invalid', 'bob@example.invalid']);

// Review Focus 1: an account with no address is never queued.
t_ok('no empty recipient address', !in_array('', $addresses, true));

// Review Focus 2: named individually and via their office -> one email.
$both = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode([$office, 'Alice Tester'])]);
t_equals('named person de-duplicated against their office',
    array_column($both, 'email'), ['alice@example.invalid']);

// A name nobody holds must not swallow the assignment (the spec's trap).
$typed = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode(['Maria Grace Andal'])]);
t_ok('unresolvable name falls back to the office', count($typed) === 2);

// Review Focus 3: a legacy plain string, not a JSON array.
$legacy = email_recipients_for($conn, ['office' => $office, 'in_charge' => 'Alice Tester']);
t_equals('legacy plain-string in_charge still resolves',
    array_column($legacy, 'email'), ['alice@example.invalid']);

// The person who caused the change is not told about their own action.
$except = email_recipients_for($conn, ['office' => $office, 'in_charge' => '[]'], 'alice@example.invalid');
t_equals('actor excluded', array_column($except, 'email'), ['bob@example.invalid']);

// exit() in t_summary() runs the shutdown hook, so the accounts are removed.
t_summary();
