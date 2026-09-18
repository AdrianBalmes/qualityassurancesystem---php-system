<?php

/**
 * Accreditation programs and areas.
 *
 * External audit is organised per office. BED files straight into a flat list
 * of areas. The College Department is accredited per programme, so it files
 * into a programme first and an area within it.
 *
 * Area names repeat across programmes on purpose -- "Resource Management"
 * belongs to all three -- so an area alone does not identify where a
 * recommendation sits. Programme and area are stored together.
 */

/** Offices whose areas are grouped under programmes. */
const AUDIT_PROGRAMS = [
    'College Department' => [
        'Education and Business Programs' => [
            'accent' => 'blue',
            'areas'  => [
                'Leadership and Governance',
                'Quality Assurance',
                'Resource Management',
                'Teaching and Learning',
                'Student Services',
                'External Relations',
                'Research',
                'Results and Resource Management',
            ],
        ],
        'Social Work Program' => [
            'accent' => 'pink',
            'areas'  => [
                'Resource Management',
                'Teaching-Learning',
                'External Relations',
                'Research',
            ],
        ],
        'IT Program' => [
            'accent' => 'purple',
            'areas'  => [
                'Resource Management',
                'Teaching-Learning and Result',
                'Community Engagement',
            ],
        ],
    ],
];

/** Offices with a flat area list and no programmes. */
const AUDIT_AREAS = [
    'BED' => [
        'Leadership and Governance',
        'Quality Assurance',
        'Resource Management',
        'Teaching Learning',
        'Student Services',
        'External Relations',
        'Research',
        'Results',
    ],
];

/** Shown for recommendations filed before a programme or area was picked. */
const AUDIT_AREA_UNASSIGNED = 'Unassigned';

function office_has_programs($office){
    return isset(AUDIT_PROGRAMS[trim($office)]);
}

/** True for any office that files into areas, with or without programmes. */
function office_has_areas($office){
    $office = trim($office);
    return isset(AUDIT_AREAS[$office]) || isset(AUDIT_PROGRAMS[$office]);
}

/** ['Programme name' => ['accent' => ..., 'areas' => [...]], ...] */
function audit_programs_for_office($office){
    return AUDIT_PROGRAMS[trim($office)] ?? [];
}

function audit_program_is_valid($office, $program){
    return isset(AUDIT_PROGRAMS[trim($office)][trim($program)]);
}

/** Which colour a programme's cards and its areas use. */
function audit_program_accent($office, $program){
    return AUDIT_PROGRAMS[trim($office)][trim($program)]['accent'] ?? 'blue';
}

/**
 * The areas for an office. A programmed office needs the programme named;
 * asking without one returns [] rather than merging programmes together,
 * since the same area name can appear in several.
 */
function audit_areas_for_office($office, $program = ''){
    $office = trim($office);
    $program = trim($program);

    if(isset(AUDIT_PROGRAMS[$office])){
        return $program !== '' ? (AUDIT_PROGRAMS[$office][$program]['areas'] ?? []) : [];
    }
    return AUDIT_AREAS[$office] ?? [];
}

function audit_area_is_valid($office, $area, $program = ''){
    return in_array(trim($area), audit_areas_for_office($office, $program), true);
}

/**
 * How many recommendations sit under each programme, so the programme cards
 * can carry a count. Anything with no programme set collects in Unassigned.
 */
function audit_program_counts($conn, $office, $auditType){
    $counts = [];
    foreach(array_keys(audit_programs_for_office($office)) as $program){
        $counts[$program] = ['total' => 0, 'pending' => 0];
    }

    $stmt = $conn->prepare(
        "SELECT program, COUNT(*) AS total,
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending
           FROM audit_recommendations
          WHERE office = ? AND audit_type = ?
       GROUP BY program"
    );
    $stmt->bind_param("ss", $office, $auditType);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $program = trim((string) $row['program']);
        $key = $program !== '' && isset($counts[$program]) ? $program : AUDIT_AREA_UNASSIGNED;
        if(!isset($counts[$key])){
            $counts[$key] = ['total' => 0, 'pending' => 0];
        }
        $counts[$key]['total']   += (int) $row['total'];
        $counts[$key]['pending'] += (int) $row['pending'];
    }

    if(isset($counts[AUDIT_AREA_UNASSIGNED]) && $counts[AUDIT_AREA_UNASSIGNED]['total'] === 0){
        unset($counts[AUDIT_AREA_UNASSIGNED]);
    }
    return $counts;
}

/**
 * Counts per area, within a programme when the office uses them. Includes an
 * Unassigned bucket so a recommendation with no area set is never hidden.
 */
function audit_area_counts($conn, $office, $auditType, $program = ''){
    $counts = [];
    foreach(audit_areas_for_office($office, $program) as $area){
        $counts[$area] = ['total' => 0, 'pending' => 0];
    }

    if(office_has_programs($office)){
        $stmt = $conn->prepare(
            "SELECT area, COUNT(*) AS total,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending
               FROM audit_recommendations
              WHERE office = ? AND audit_type = ? AND program = ?
           GROUP BY area"
        );
        $stmt->bind_param("sss", $office, $auditType, $program);
    } else {
        $stmt = $conn->prepare(
            "SELECT area, COUNT(*) AS total,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending
               FROM audit_recommendations
              WHERE office = ? AND audit_type = ?
           GROUP BY area"
        );
        $stmt->bind_param("ss", $office, $auditType);
    }
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

    if(isset($counts[AUDIT_AREA_UNASSIGNED]) && $counts[AUDIT_AREA_UNASSIGNED]['total'] === 0){
        unset($counts[AUDIT_AREA_UNASSIGNED]);
    }
    return $counts;
}
