<?php

function classify_recommendation_status($row){
    if($row['status'] === 'Submitted'){
        return 'Completed';
    }
    $year = trim($row['year'] ?? '');
    if($year !== '' && ctype_digit($year) && (int) $year < (int) date('Y')){
        return 'Overdue';
    }
    return 'Ongoing';
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
