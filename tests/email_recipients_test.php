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

echo "email_notifications: recipient resolution\n";

/** Every username this file creates, so the cleanup and the pre-clean agree. */
function t_test_usernames(){
    return ['zz_alice','zz_bob','zz_noma','zz_pat1','zz_pat2','zz_kim1','zz_kim2'];
}

/**
 * Runs on every exit, including a fatal error part-way through. Without it a
 * failed run leaves zz_ accounts in the live ems_db: the RED run of this file
 * dies on an undefined function after the inserts below have already happened.
 */
function t_remove_test_accounts($conn){
    $stmt = $conn->prepare("DELETE FROM users WHERE username = ?");
    foreach(t_test_usernames() as $username){
        $stmt->bind_param("s", $username);
        $stmt->execute();
    }
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

/** Addresses of a recipient list, sorted, so a comparison does not depend on order. */
function t_sorted_emails($recipients){
    $emails = array_column($recipients, 'email');
    sort($emails);
    return $emails;
}

// The inserts need the columns that ensure_user_account_columns() adds, and
// that call does not resolve recipients, so it is safe before the first lookup.
ensure_user_account_columns($conn);

$office = 'ZZ Test Office';
t_make_account($conn, 'zz_alice', 'Alice Tester', $office, 'alice@example.invalid');
t_make_account($conn, 'zz_bob',   'Bob Tester',   $office, 'bob@example.invalid');
t_make_account($conn, 'zz_noma',  'Noma Tester',  $office, '');   // blank address

// A second office, for the cases that need a name held by two accounts.
$shared = 'ZZ Test Office B';
t_make_account($conn, 'zz_pat1', 'Pat Tester', $shared, 'pat1@example.invalid');
t_make_account($conn, 'zz_pat2', 'Pat Tester', $shared, 'pat2@example.invalid');
t_make_account($conn, 'zz_kim1', 'Kim Tester', $shared, 'kim@example.invalid');
t_make_account($conn, 'zz_kim2', 'Kim Tester', $shared, 'kim@example.invalid');

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

// Finding 1: a named person with no address is not reachable, so the office
// takes the alert instead of nobody being told. Pins the outcome, not the
// mechanism: the office is the deliberate fallback here.
$unreachable = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode(['Noma Tester'])]);
t_equals('named person with no address hands the office the alert',
    t_sorted_emails($unreachable), ['alice@example.invalid', 'bob@example.invalid']);

// A reachable named person still wins over an unreachable one beside them.
$mixed = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode(['Alice Tester', 'Noma Tester'])]);
t_equals('reachable named person wins over an unreachable one',
    t_sorted_emails($mixed), ['alice@example.invalid']);

// Review Focus 2, for real this time: two accounts hold one name and one
// address, so the name resolves to two rows and the de-duplication must
// collapse them to one email.
$kim = email_recipients_for($conn, ['office' => $shared, 'in_charge' => json_encode(['Kim Tester'])]);
t_equals('a name held twice at one address is emailed once',
    t_sorted_emails($kim), ['kim@example.invalid']);

// Finding 2: two accounts with one name at different addresses are both
// emailed. Keying by name alone used to keep only the last holder.
$pat = email_recipients_for($conn, ['office' => $shared, 'in_charge' => json_encode(['Pat Tester'])]);
t_equals('a shared name emails every holder',
    t_sorted_emails($pat), ['pat1@example.invalid', 'pat2@example.invalid']);

// Finding 2, fallback side: the office list is built from rows, so a shared
// name does not drop anyone from the office, and the repeated kim address
// still collapses to one email.
$sharedOffice = email_recipients_for($conn, ['office' => $shared, 'in_charge' => '[]']);
t_equals('office fallback keeps every holder of a shared name',
    t_sorted_emails($sharedOffice), ['kim@example.invalid', 'pat1@example.invalid', 'pat2@example.invalid']);

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
