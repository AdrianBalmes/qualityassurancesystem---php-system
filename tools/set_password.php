<?php

/**
 * Change an account's password from the command line.
 *
 *     php tools/set_password.php <username>
 *
 * Needed because the seeded passwords (admin/admin@2026 among them) are in the
 * public GitHub history, and the Forgot Password flow cannot deliver codes yet
 * -- there is no SMS gateway behind it. Run this for every seeded account
 * before the site is reachable from the internet.
 *
 * Command line only, and tools/ is denied in .htaccess, so it is never a web
 * endpoint that could reset somebody's password.
 */

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . "/../database.php";

$username = $argv[1] ?? '';
if(trim($username) === ''){
    fwrite(STDERR, "Usage: php tools/set_password.php <username>\n");
    exit(1);
}

$lookup = $conn->prepare("SELECT id, username, role, office FROM users WHERE username = ? LIMIT 1");
$lookup->bind_param("s", $username);
$lookup->execute();
$user = $lookup->get_result()->fetch_assoc();

if(!$user){
    fwrite(STDERR, "No account named \"{$username}\".\n");
    exit(1);
}

echo "Account : {$user['username']} ({$user['role']}" . ($user['office'] !== '' ? ", {$user['office']}" : "") . ")\n";
echo "New password (at least 8 characters): ";

$password = trim((string) fgets(STDIN));

if(strlen($password) < 8){
    // Same minimum the registration, reset and profile pages enforce.
    fwrite(STDERR, "\nPassword must be at least 8 characters. Nothing changed.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update->bind_param("si", $hash, $user['id']);
$update->execute();

echo "\nPassword updated for \"{$user['username']}\".\n";
