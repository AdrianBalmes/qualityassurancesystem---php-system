<?php

function classify_recommendation_status($row){
    if($row['status'] === 'Completed'){
        return 'Completed';
    }
    $year = trim($row['year'] ?? '');
    if($year !== '' && ctype_digit($year) && (int) $year < (int) date('Y')){
        return 'Overdue';
    }
    return 'Ongoing';
}

function review_status_chip_info($status){
    $map = [
        'Pending' => ['label' => 'Pending', 'class' => 'chip-steel'],
        'Not Submitted' => ['label' => 'Not Submitted', 'class' => 'chip-red'],
        'Submitted' => ['label' => 'Awaiting Review', 'class' => 'chip-yellow'],
        'Approved' => ['label' => 'Approved', 'class' => 'chip-blue'],
        'Needs Revision' => ['label' => 'Needs Revision', 'class' => 'chip-orange'],
        'Rejected' => ['label' => 'Rejected', 'class' => 'chip-red'],
        'Completed' => ['label' => 'Completed', 'class' => 'chip-green'],
    ];
    return $map[$status] ?? ['label' => $status, 'class' => 'chip-steel'];
}

function compute_recommendation_stats($rows){
    $stats = ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'overdue' => 0, 'compliance_pct' => 0];
    foreach($rows as $row){
        $stats['total']++;
        $label = classify_recommendation_status($row);
        if($label === 'Completed'){ $stats['completed']++; }
        elseif($label === 'Overdue'){ $stats['overdue']++; }
        else { $stats['ongoing']++; }
    }
    if($stats['total'] > 0){
        $stats['compliance_pct'] = (int) round(($stats['completed'] / $stats['total']) * 100);
    }
    return $stats;
}

function recommendation_status_chip_class($label){
    if($label === 'Completed'){ return 'chip-green'; }
    if($label === 'Overdue'){ return 'chip-red'; }
    return 'chip-yellow';
}
