<?php

/**
 * Whether an office should see/act on a recommendation: its own office, or one
 * of the offices/persons an admin assigned in_charge of accomplishing it.
 */
function recommendation_involves_office($row, $office){
    if(($row['office'] ?? '') === $office){
        return true;
    }
    $inCharge = json_decode($row['in_charge'] ?? '', true);
    return is_array($inCharge) && in_array($office, $inCharge, true);
}

/**
 * Compliance remarks, keyed by whichever office wrote them. A recommendation
 * passed to an In Charge office needs its own remarks slot so that office's
 * submission does not overwrite the owning office's.
 *
 * Returns null for a legacy plain-string value (written before this office
 * split existed) or an empty/missing value -- callers fall back to treating
 * that as the owning office's own remarks.
 */
function decode_office_remarks($raw){
    $decoded = json_decode($raw ?? '', true);
    if(is_array($decoded)){
        return array_filter($decoded, 'is_string');
    }
    return null;
}

/** This office's own remarks, whether stored as a legacy string or a map. */
function office_own_remarks($row, $office){
    $map = decode_office_remarks($row['remarks'] ?? '');
    if($map !== null){
        return $map[$office] ?? '';
    }
    return ($row['office'] ?? '') === $office ? ($row['remarks'] ?? '') : '';
}

/** Merge one office's remarks into the stored map without erasing others'. */
function merge_office_remarks($row, $office, $newRemarks){
    $map = decode_office_remarks($row['remarks'] ?? '');
    if($map === null){
        $map = [];
        $legacy = trim($row['remarks'] ?? '');
        if($legacy !== ''){
            $map[$row['office']] = $legacy;
        }
    }
    $map[$office] = $newRemarks;
    return json_encode($map);
}

/**
 * College Department compliance is between the submitting office and the admin
 * only. Even when several offices are In Charge of the same recommendation,
 * none of them sees another's documents, remarks, decisions or feedback.
 */
function college_department_is_private($recommendationOffice){
    return $recommendationOffice === 'College Department';
}

/**
 * What one office sees of a College Department recommendation: a status and
 * review feedback drawn only from its own submission, not the shared row.
 *
 * $ownDocs are the documents this office submitted for the recommendation.
 */
function private_office_view($row, $ownDocs, $ownRemarks){
    // Completed closes the whole recommendation, so every office sees it.
    if($row['status'] === 'Completed'){
        $status = 'Completed';
    } else {
        $status = ($ownRemarks !== '' || !empty($ownDocs)) ? 'Submitted' : 'Pending';
        $latest = '';
        foreach($ownDocs as $doc){
            $decision = $doc['review_status'] ?? '';
            if(in_array($decision, ['Approved', 'Rejected', 'Needs Revision'], true)
               && ($doc['reviewed_at'] ?? '') >= $latest){
                $latest = $doc['reviewed_at'] ?? '';
                $status = $decision;
            }
        }
    }

    $feedback = [];
    foreach($ownDocs as $doc){
        $text = trim($doc['review_remarks'] ?? '');
        if($text !== ''){
            $feedback[] = $text;
        }
    }
    return ['status' => $status, 'feedback' => implode("\n", $feedback)];
}

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
