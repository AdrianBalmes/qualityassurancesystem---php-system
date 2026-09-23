<?php

require_once __DIR__ . "/audit_areas.php";
require_once __DIR__ . "/recommendation_rules.php";
require_once __DIR__ . "/in_charge.php";

/**
 * How wide a full-width row has to be to span the grid. The Office column is
 * dropped only when a single External office is open; In Charge is always
 * there.
 */
function recommendation_grid_column_count($selectedOffice, $selectedAudit){
    $showOfficeColumn = $selectedOffice === '' || $selectedAudit !== 'External';
    // Recommendation, In Charge, Status, Remarks, Documents, Year, Actions.
    return $showOfficeColumn ? 8 : 7;
}

/** The dropdown value standing for "blank" -- no year, or nobody in charge. */
const RECOMMENDATION_FILTER_NONE = '__none__';

/**
 * The search box and the three dropdowns above the grid, read off a request.
 * An empty value means no narrowing, so an untouched toolbar changes nothing.
 */
function recommendation_filters_from_request($source){
    return [
        'search'    => trim((string) ($source['search'] ?? '')),
        'in_charge' => trim((string) ($source['in_charge'] ?? '')),
        'status'    => trim((string) ($source['status'] ?? '')),
        'year'      => trim((string) ($source['year'] ?? '')),
    ];
}

/** Whether the toolbar is narrowing anything at all. */
function recommendation_filters_active($filters){
    foreach($filters as $value){
        if(trim((string) $value) !== ''){
            return true;
        }
    }
    return false;
}

/**
 * The In Charge names and years actually in use, so neither dropdown offers a
 * choice that would come back empty.
 */
function recommendation_filter_options($conn, $auditType){
    $years = [];
    $names = [];
    $hasBlankYear = false;
    $hasUnassigned = false;

    $stmt = $conn->prepare("SELECT year, in_charge FROM audit_recommendations WHERE audit_type = ?");
    $stmt->bind_param("s", $auditType);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $year = trim((string) $row['year']);
        if($year === ''){
            $hasBlankYear = true;
        } elseif(!in_array($year, $years, true)){
            $years[] = $year;
        }

        $rowNames = in_charge_decode($row['in_charge'] ?? '');
        if(empty($rowNames)){
            $hasUnassigned = true;
        }
        foreach($rowNames as $name){
            if(!in_array($name, $names, true)){
                $names[] = $name;
            }
        }
    }

    rsort($years);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'years'      => $years,
        'blank_year' => $hasBlankYear,
        'in_charge'  => $names,
        'unassigned' => $hasUnassigned,
    ];
}

/**
 * $selectedArea narrows an office that files its recommendations by area.
 * AUDIT_AREA_UNASSIGNED collects the ones with no area set, so they stay
 * reachable rather than disappearing between the area cards.
 *
 * $filters is the toolbar above the grid. A search deliberately reaches across
 * an office's areas and programmes -- the caller drops those before asking,
 * rather than making someone open the right card to find what they searched
 * for.
 */
function fetch_office_recommendations($conn, $selectedOffice, $selectedAudit, $selectedArea = '', $selectedProgram = '', $filters = []){
    $filters = array_merge(['search' => '', 'in_charge' => '', 'status' => '', 'year' => ''], $filters);

    $where  = ["audit_type = ?"];
    $types  = "s";
    $params = [$selectedAudit];

    if($selectedOffice !== ''){
        $where[]  = "office = ?";
        $types   .= "s";
        $params[] = $selectedOffice;
    }

    if($selectedOffice !== '' && $selectedArea !== ''){
        if($selectedArea === AUDIT_AREA_UNASSIGNED){
            $where[] = "(area IS NULL OR area = '')";
        } else {
            $where[]  = "area = ?";
            $types   .= "s";
            $params[] = $selectedArea;
        }
        // A programmed office needs both: the same area name appears under
        // more than one programme, so area alone would mix them together.
        if($selectedProgram !== ''){
            $where[]  = "program = ?";
            $types   .= "s";
            $params[] = $selectedProgram;
        }
    }

    if($filters['search'] !== ''){
        $where[] = "(recommendation LIKE ? OR office LIKE ? OR area LIKE ? OR program LIKE ?)";
        $like = '%' . $filters['search'] . '%';
        $types .= "ssss";
        array_push($params, $like, $like, $like, $like);
    }

    if($filters['status'] !== ''){
        $where[]  = "status = ?";
        $types   .= "s";
        $params[] = $filters['status'];
    }

    if($filters['year'] !== ''){
        if($filters['year'] === RECOMMENDATION_FILTER_NONE){
            $where[] = "(year IS NULL OR year = '')";
        } else {
            $where[]  = "year = ?";
            $types   .= "s";
            $params[] = $filters['year'];
        }
    }

    $order = $selectedOffice === '' ? "office ASC, year DESC, id DESC" : "year DESC, id DESC";
    $stmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE " . implode(' AND ', $where) . " ORDER BY {$order}");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // in_charge holds a JSON list, so it is matched here rather than in SQL: a
    // LIKE would also match a name that is only part of a longer one.
    if($filters['in_charge'] !== ''){
        $wanted = $filters['in_charge'];
        $rows = array_values(array_filter($rows, function($row) use ($wanted){
            $names = in_charge_decode($row['in_charge'] ?? '');
            return $wanted === RECOMMENDATION_FILTER_NONE ? empty($names) : in_array($wanted, $names, true);
        }));
    }

    return $rows;
}

function fetch_recommendation_documents_map($conn, $recIds){
    $docsByRecommendation = [];
    $recIds = array_values(array_unique(array_map('intval', $recIds)));
    if(empty($recIds)){
        return $docsByRecommendation;
    }
    $placeholders = implode(',', array_fill(0, count($recIds), '?'));
    $types = str_repeat('i', count($recIds));
    $stmt = $conn->prepare("SELECT * FROM recommendation_documents WHERE recommendation_id IN ($placeholders) ORDER BY uploaded_at DESC");
    $stmt->bind_param($types, ...$recIds);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()){
        $docsByRecommendation[(int) $row['recommendation_id']][] = $row;
    }
    return $docsByRecommendation;
}

function render_office_recommendation_rows($rows, $selectedOffice, $selectedAudit, $docsByRecommendation = [], $filtersActive = false){
    $html = "";
    $count = count($rows);
    $statusChoices = ['Pending', 'Submitted', 'Not Submitted', 'Approved', 'Needs Revision', 'Rejected', 'Completed'];
    // A single External office drops the Office column -- every row in that
    // view belongs to it. Internal Audit keeps the column even with one office
    // selected, same as the "All Offices" view.
    $showOfficeColumn = $selectedOffice === '' || $selectedAudit !== 'External';
    $columnCount = recommendation_grid_column_count($selectedOffice, $selectedAudit);

    foreach($rows as $row){
        $recId = (int) $row['id'];
        $recYear = htmlspecialchars($row['year'], ENT_QUOTES);
        $recOfficeName = htmlspecialchars($row['office'], ENT_QUOTES);
        $recText = htmlspecialchars($row['recommendation'], ENT_QUOTES);
        $reviewRemarks = htmlspecialchars($row['review_remarks'] ?? '', ENT_QUOTES);

        // A recommendation passed to more than one office keeps each one's
        // remarks separate; grouping them by office only matters for College
        // Department, the one office that hands work off via In Charge.
        $remarksMap = decode_office_remarks($row['remarks'] ?? '');
        $remarksIsGrouped = $remarksMap !== null && count($remarksMap) > 1 && $selectedOffice === 'College Department';
        $remarksPlain = $remarksMap !== null ? (reset($remarksMap) ?: '') : ($row['remarks'] ?? '');
        $remarks = htmlspecialchars($remarksPlain, ENT_QUOTES);

        $remarksForReview = $remarksMap !== null
            ? implode("\n\n", array_map(function($remOffice, $remText){ return "{$remOffice}: {$remText}"; }, array_keys($remarksMap), $remarksMap))
            : $remarksPlain;
        $safeRemarksForReview = htmlspecialchars($remarksForReview, ENT_QUOTES);

        if($remarksIsGrouped){
            $remarksCellHtml = "<div class='remarks-grouped'>";
            foreach($remarksMap as $remOffice => $remText){
                $safeRemOffice = htmlspecialchars($remOffice, ENT_QUOTES);
                $safeRemText = nl2br(htmlspecialchars($remText, ENT_QUOTES));
                $remarksCellHtml .= "<div class='remarks-office-block'><span class='remarks-office-label'>{$safeRemOffice}</span><div class='remarks-office-text'>{$safeRemText}</div></div>";
            }
            $remarksCellHtml .= "</div>";
        } else {
            $remarksCellHtml = "<div class='cell-text' contenteditable='false' data-field='remarks'>{$remarks}</div>";
        }
        // Same colour the office sees on its own dashboard for this status.
        $statusChipClass = review_status_chip_info($row['status'])['class'];
        $statusOptions = "";
        foreach($statusChoices as $option){
            $sel = $row['status'] === $option ? " selected" : "";
            $safeOption = htmlspecialchars($option, ENT_QUOTES);
            $statusOptions .= "<option value='{$safeOption}'{$sel}>{$safeOption}</option>";
        }

        $docsForJs = [];
        if(!empty($docsByRecommendation[$recId])){
            foreach($docsByRecommendation[$recId] as $doc){
                $docsForJs[] = [
                    'id' => (int) $doc['id'],
                    'url' => "serve_upload.php?id=" . (int) $doc['id'],
                    'label' => $doc['original_name'],
                    'office' => $doc['office'] ?? '',
                    'review_status' => $doc['review_status'] ?? '',
                    'review_remarks' => $doc['review_remarks'] ?? '',
                ];
            }
        }
        $docsJson = htmlspecialchars(json_encode($docsForJs), ENT_QUOTES);
        $docCount = count($docsForJs);

        $filesHtml = "<span class='muted-copy'>No document submitted</span>";
        if($docCount > 0){
            $filesHtml = "<button type='button' class='doc-trigger-btn' data-doc-trigger data-rec-text='{$recText}' data-office='{$recOfficeName}' data-docs='{$docsJson}'><i class='bi bi-folder2-open'></i> Documents <span class='doc-count-badge'>{$docCount}</span></button>";
        }

        $leadCells = ($showOfficeColumn ? "<td class='cell-readonly'>{$recOfficeName}</td>" : "")
            . "<td><div class='cell-text grid-rec-cell' contenteditable='false' data-field='recommendation'>{$recText}</div></td>"
            . "<td>" . render_in_charge_cell($row['office'], $row['in_charge'] ?? '', 'full') . "</td>";

        $html .= "
        <tr data-id='{$recId}' data-mode='view'>
            {$leadCells}
            <td><select class='cell-select status-select {$statusChipClass}' data-field='status' disabled>{$statusOptions}</select></td>
            <td>{$remarksCellHtml}</td>
            <td>{$filesHtml}</td>
            <td><input type='text' class='cell-year' maxlength='9' data-field='year' value='{$recYear}' placeholder='2026 or 2026-2027' disabled></td>
            <td>
                <div class='row-actions'>
                    <button type='button' class='row-edit-btn' title='Edit row'><i class='bi bi-pencil'></i> Edit</button>
                    <button type='button' class='row-save-btn' title='Save row'><i class='bi bi-check2'></i> Save</button>
                    <button type='button' class='row-review-btn' title='Review submission' data-review-trigger data-rec-id='{$recId}' data-office='{$recOfficeName}' data-rec-text='{$recText}' data-status='" . htmlspecialchars($row['status'], ENT_QUOTES) . "' data-remarks='{$safeRemarksForReview}' data-review-remarks='{$reviewRemarks}' data-docs='{$docsJson}'><i class='bi bi-clipboard-check'></i> Review</button>
                    <button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button>
                </div>
            </td>
        </tr>
        ";
    }
    if($count === 0){
        $emptyMessage = $filtersActive
            ? 'No recommendations match this search'
            : ($selectedOffice === '' ? 'No audit recommendations submitted yet' : 'No recommendations available for this office');
        $html = "<tr><td colspan='{$columnCount}' class='empty-state'>" . htmlspecialchars($emptyMessage, ENT_QUOTES) . "</td></tr>";
    }
    return ['html' => $html, 'count' => $count];
}

/**
 * The area cards an office-with-areas shows instead of a recommendation list.
 *
 * They live inside one full-width table cell so the dashboard can swap them in
 * and out of the existing grid without rebuilding the table around them.
 */
function render_program_cards($conn, $office, $auditType){
    $counts = audit_program_counts($conn, $office, $auditType);
    $programs = audit_programs_for_office($office);
    $safeOffice = htmlspecialchars($office, ENT_QUOTES);

    $cards = "";
    foreach($counts as $program => $tally){
        // College Department shows only its real programmes -- no Unassigned card.
        if($program === AUDIT_AREA_UNASSIGNED && $office === 'College Department'){
            continue;
        }
        $safeProgram = htmlspecialchars($program, ENT_QUOTES);
        $accent = $program === AUDIT_AREA_UNASSIGNED ? 'steel' : ($programs[$program]['accent'] ?? 'blue');
        $areaCount = count($programs[$program]['areas'] ?? []);
        $total = (int) $tally['total'];
        $pending = (int) $tally['pending'];

        $meta = $areaCount > 0
            ? "<span class='prog-areas'>{$areaCount} area" . ($areaCount === 1 ? '' : 's') . "</span>"
            : "";
        $meta .= $total > 0
            ? "<span class='area-total'>{$total} recommendation" . ($total === 1 ? '' : 's') . "</span>"
              . ($pending > 0 ? "<span class='area-pending'>{$pending} pending</span>" : "")
            : "<span class='area-empty'>No recommendations yet</span>";

        $cards .= "<button type='button' class='prog-card prog-{$accent}' data-program='{$safeProgram}' data-office='{$safeOffice}'>"
                . "<span class='prog-icon'><i class='bi bi-folder-fill'></i></span>"
                . "<span class='prog-body'><span class='prog-name'>{$safeProgram}</span>"
                . "<span class='area-tally'>{$meta}</span></span>"
                . "<i class='bi bi-chevron-right prog-go'></i>"
                . "</button>";
    }

    $columnCount = recommendation_grid_column_count($office, $auditType);
    return "<tr><td colspan='{$columnCount}' class='area-cell'><div class='prog-grid'>{$cards}</div></td></tr>";
}

/** $program is '' for an office whose areas are not grouped, such as BED. */
function render_area_cards($conn, $office, $auditType, $program = ''){
    $counts = audit_area_counts($conn, $office, $auditType, $program);
    $accent = $program !== '' ? audit_program_accent($office, $program) : 'blue';
    $safeOffice = htmlspecialchars($office, ENT_QUOTES);
    $safeProgram = htmlspecialchars($program, ENT_QUOTES);

    $cards = "";
    foreach($counts as $area => $tally){
        if($area === AUDIT_AREA_UNASSIGNED && $office === 'College Department'){
            continue;
        }
        $safeArea = htmlspecialchars($area, ENT_QUOTES);
        $total = (int) $tally['total'];
        $pending = (int) $tally['pending'];
        $unassigned = $area === AUDIT_AREA_UNASSIGNED ? ' area-card-unassigned' : '';

        $tally = $total === 0
            ? "<span class='area-empty'>No recommendations yet</span>"
            : "<span class='area-total'>{$total} recommendation" . ($total === 1 ? '' : 's') . "</span>"
              . ($pending > 0 ? "<span class='area-pending'>{$pending} pending</span>" : "");

        $cards .= "<button type='button' class='area-card area-{$accent}{$unassigned}' data-area='{$safeArea}' data-office='{$safeOffice}' data-program='{$safeProgram}'>"
                . "<span class='area-name'>{$safeArea}</span>"
                . "<span class='area-tally'>{$tally}</span>"
                . "</button>";
    }

    $columnCount = recommendation_grid_column_count($office, $auditType);
    return "<tr><td colspan='{$columnCount}' class='area-cell'><div class='area-grid'>{$cards}</div></td></tr>";
}

/** Heading for the recommendations grid. An empty office means every office. */
function office_recommendations_title($selectedOffice){
    return $selectedOffice === ''
        ? 'All Offices Recommendations'
        : $selectedOffice . ' Recommendations';
}
// restored 1789807128
