<?php
require_once __DIR__ . "/session_bootstrap.php";

$selectedAudit = isset($_GET['audit']) ? trim($_GET['audit']) : 'External';
if(!in_array($selectedAudit, ['External', 'Internal'], true)){
    $selectedAudit = 'External';
}

header("Location: home.php?audit=" . urlencode($selectedAudit) . "#manage-recommendations-grid");
exit();
