<?php

/**
 * Accreditation areas.
 *
 * External audit of the College Department is organised by area rather than as
 * one flat list, so its recommendations are filed under one of these. Every
 * other office keeps a plain list.
 *
 * Adding an office here is all it takes to give it areas: the dashboard reads
 * this map rather than testing for a particular office by name.
 */
const AUDIT_AREAS = [
    'College Department' => [
        'Leadership and Governance',
        'Quality Assurance',
        'Resource Management',
        'Teaching and Learning',
        'Student Services',
        'External Relations',
        'Research',
        'Results and Resource Management',
    ],
];

/** Shown for recommendations filed before an area was picked for them. */
const AUDIT_AREA_UNASSIGNED = 'Unassigned';

function office_has_areas($office){
    return isset(AUDIT_AREAS[trim($office)]);
}

/** The areas for an office, or [] when it does not use them. */
function audit_areas_for_office($office){
    return AUDIT_AREAS[trim($office)] ?? [];
}

/** True when $area is one this office actually uses. */
function audit_area_is_valid($office, $area){
    return in_array(trim($area), audit_areas_for_office($office), true);
}

/**
 * How many recommendations sit in each area, including an Unassigned bucket so
 * a recommendation with no area set is never hidden from the dashboard.
 *
 * Returns ['Area name' => ['total' => n, 'pending' => n], ...] covering every
 * defined area, plus Unassigned only when something is actually in it.
 */
function audit_area_counts($conn, $office, $auditType){
    $counts = [];
    foreach(audit_areas_for_office($office) as $area){
        $counts[$area] = ['total' => 0, 'pending' => 0];
    }

    $stmt = $conn->prepare(
        "SELECT area, COUNT(*) AS total,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending
           FROM audit_recommendations
          WHERE office = ? AND audit_type = ?
       GROUP BY area"
    );
    $stmt->bind_param("ss", $office, $auditType);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $area = trim((string) $row['area']);
        $key = $area !== '' && isset($counts[$area]) ? $area : AUDIT_AREA_UNASSIGNED;

        if(!isset($counts[$key])){
            $counts[$key] = ['total' => 0, 'pending' => 0];
        }
        $counts[$key]['total']   += (int) $row['total'];
        $counts[$key]['pending'] += (int) $row['pending'];
    }

    // Drop the Unassigned bucket unless something landed in it.
    if(isset($counts[AUDIT_AREA_UNASSIGNED]) && $counts[AUDIT_AREA_UNASSIGNED]['total'] === 0){
        unset($counts[AUDIT_AREA_UNASSIGNED]);
    }

    return $counts;
}
