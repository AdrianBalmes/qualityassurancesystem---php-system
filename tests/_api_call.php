<?php
/**
 * Runs one recommendations_api.php action under an admin session. The trigger
 * test shells out to this so each call is a fresh process, as a browser
 * request is, with no per-request caches carried over from the test itself.
 */
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_start();
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_role_slug'] = 'qa_head';
// Passed base64-encoded; see t_api() for why.
parse_str((string) base64_decode($argv[1] ?? ''), $_POST);
include __DIR__ . "/../recommendations_api.php";
