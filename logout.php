<?php
session_start();
require_once __DIR__ . "/session_scope.php";

/**
 * Signing out is scoped so that an admin and an office signed in from the same
 * browser do not evict each other.
 *
 *   logout.php?scope=admin           sign out of the admin account only
 *   logout.php?scope=office          sign out of every office
 *   logout.php?scope=office&office=X sign out of office X, keeping the others
 *   logout.php                       sign out of everything
 */
$scope = isset($_GET['scope']) ? $_GET['scope'] : 'all';

if($scope === 'admin'){
    session_scope_logout_admin();

    // Still signed in as an office? Go where that user can actually go.
    $destination = session_scope_has_office() ? "office_dashboard.php" : "admin_login.php";

} elseif($scope === 'office'){
    $office = isset($_GET['office']) ? trim($_GET['office']) : '';
    $remaining = session_scope_logout_office($office);

    if($remaining !== ''){
        $destination = "office_dashboard.php?office=" . rawurlencode($remaining);
    } else {
        $destination = session_scope_has_admin() ? "home.php" : "index.php";
    }

} else {
    session_scope_logout_all();
    $destination = "index.php";
}

header("Location: " . $destination);
exit();
