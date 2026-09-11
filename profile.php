<?php
require_once __DIR__ . "/session_bootstrap.php";

if(isset($_SESSION['admin_username']) && $_SESSION['admin_role'] === 'admin'){
    header("Location: admin_profile.php");
    exit();
}

if(isset($_SESSION['office_username']) && isset($_SESSION['office_name'])){
    header("Location: office_profile.php");
    exit();
}

header("Location: index.php");
exit();
