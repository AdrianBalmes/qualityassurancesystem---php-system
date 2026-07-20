<?php
session_start();

$selectedAudit = isset($_GET['audit']) ? trim($_GET['audit']) : 'External';
if(!in_array($selectedAudit, ['External', 'Internal'], true)){
    $selectedAudit = 'External';
}

header("Location: home.php?audit=" . urlencode($selectedAudit) . "#manage-recommendations-grid");
exit();
