<?php

/**
 * Create the first administrator on a fresh install.
 *
 *     php tools/create_admin.php <username>
 *
 * ems_db.sql ships structure only -- no accounts -- so a new clone has nobody
 * to sign in as. Registration cannot fill the gap either: every registration
 * stays "pending" until an administrator approves it, and there is no
 * administrator yet. This breaks that circle without shipping a default
 * password in the repository.
 *
 * Command line only, and tools/ is denied in .htaccess, so this can never be
 * reached over the web to mint an admin account.
 */

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../user_columns.php";

// A database imported from ems_db.sql already has these, but an older one
// upgraded in place may not.
ensure_user_account_columns($conn);

$username = trim($argv[1] ?? '');
if($username === ''){
    fwrite(STDERR, "Usage: php tools/create_admin.php <username>\n");
    exit(1);
}

// Same rule register.php applies, so the account can also be managed there.
if(!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)){
    fwrite(STDERR, "Username must be 3-50 characters: letters, numbers, dot, underscore or hyphen.\n");
    exit(1);
}

$taken = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$taken->bind_param("s", $username);
$taken->execute();
if($taken->get_result()->num_rows > 0){
    fwrite(STDERR, "\"{$username}\" already exists. Use tools/set_password.php to change its password.\n");
    exit(1);
}

echo "Creating administrator \"{$username}\".\n";
echo "Password (at least 8 characters): ";
$password = trim((string) fgets(STDIN));

if(strlen($password) < 8){
    fwrite(STDERR, "\nPassword must be at least 8 characters. Nothing created.\n");
    exit(1);
}

echo "Full name (optional): ";
$fullName = trim((string) fgets(STDIN));

$hash = password_hash($password, PASSWORD_DEFAULT);
$role = 'admin';
$office = 'Admin';
$status = USER_STATUS_APPROVED;
$email = '';
$phone = '';

$insert = $conn->prepare("INSERT INTO users (username, password, email, phone, role, office, full_name, status) VALUES (?,?,?,?,?,?,?,?)");
$insert->bind_param("ssssssss", $username, $hash, $email, $phone, $role, $office, $fullName, $status);
$insert->execute();

echo "\nAdministrator \"{$username}\" created and approved. Sign in at admin_login.php.\n";
