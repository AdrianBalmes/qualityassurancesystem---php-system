<?php

require_once __DIR__ . "/audit_areas.php";
require_once __DIR__ . "/recommendation_rules.php";

/**
 * $selectedArea narrows an office that files its recommendations by area.
 * AUDIT_AREA_UNASSIGNED collects the ones with no area set, so they stay
 * reachable rather than disappearing between the area cards.
 */
function fetch_office_recommendations($conn, $selectedOffice, $selectedAudit, $selectedArea = '', $selectedProgram = ''){
    if($selectedOffice !== '' && $selectedArea !== ''){
        // A programmed office needs both: the same area name appears under
        // more than one programme, so area alone would mix them together.
        $programClause = $selectedProgram !== '' ? " AND program = ?" : "";

        if($selectedArea === AUDIT_AREA_UNASSIGNED){
            $stmt = $conn->prepare("SELECT * FROM audit_recommendations
                                     WHERE office = ? AND audit_type = ?
                                       AND (area IS NULL OR area = ''){$programClause}
                                  ORDER BY year DESC, id DESC");
            if($selectedProgram !== ''){
                $stmt->bind_param("sss", $selectedOffice, $selectedAudit, $selectedProgram);
            } else {
                $stmt->bind_param("ss", $selectedOffice, $selectedAudit);
            }
        } else {
            $stmt = $conn->prepare("SELECT * FROM audit_recommendations
                                     WHERE office = ? AND audit_type = ? AND area = ?{$programClause}
                                  ORDER BY year DESC, id DESC");
            if($selectedProgram !== ''){
                $stmt->bind_param("ssss", $selectedOffice, $selectedAudit, $selectedArea, $selectedProgram);
            } else {
                $stmt->bind_param("sss", $selectedOffice, $selectedAudit, $selectedArea);
            }
        }
        $stmt->execute();
        $result = $stmt->get_result();
    } elseif($selectedOffice !== ''){
        $stmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE office = ? AND audit_type = ? ORDER BY year DESC, id DESC");
        $stmt->bind_param("ss", $selectedOffice, $selectedAudit);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $stmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE audit_type = ? ORDER BY office ASC, year DESC, id DESC");
        $stmt->bind_param("s", $selectedAudit);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
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

function render_office_recommendation_rows($rows, $selectedOffice, $selectedAudit, $docsByRecommendation = []){
    $html = "";
    $count = count($rows);
    $statusChoices = ['Pending', 'Submitted', 'Not Submitted', 'Approved', 'Needs Revision', 'Rejected', 'Completed'];
    // In Charge is an External Audit feature only (BED, College Department);
    // Internal Audit offices keep the plain Office column even when one
    // office is selected, same as the "All Offices" view.
    $showOfficeColumn = $selectedOffice === '' || $selectedAudit !== 'External';

    foreach($rows as $row){
        $recId = (int) $row['id'];
        $recYear = htmlspecialchars($row['year'], ENT_QUOTES);
        $recOfficeName = htmlspecialchars($row['office'], ENT_QUOTES);
        $recText = htmlspecialchars($row['recommendation'], ENT_QUOTES);
        $inCharge = htmlspecialchars($row['in_charge'] ?? '', ENT_QUOTES);
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
                                                        ];
            }
        }
        $docsJson = htmlspecialchars(json_encode($docsForJs), ENT_QUOTES);
        $docCount = count($docsForJs);

        $filesHtml = "<span class='muted-copy'>No document submitted</span>";
        if($docCount > 0){
            $filesHtml = "<button type='button' class='doc-trigger-btn' data-doc-trigger data-rec-text='{$recText}' data-office='{$recOfficeName}' data-docs='{$docsJson}'><i class='bi bi-folder2-open'></i> Documents <span class='doc-count-badge'>{$docCount}</span></button>";
        }

        $leadCells = $showOfficeColumn
            ? "<td class='cell-readonly'>{$recOfficeName}</td>"
              . "<td><div class='cell-text grid-rec-cell' contenteditable='false' data-field='recommendation'>{$recText}</div></td>"
            : "<td><div class='cell-text grid-rec-cell' contenteditable='false' data-field='recommendation'>{$recText}</div></td>"
              . "<td><div class='incharge-cell' data-field='in_charge' data-json='{$inCharge}'>"
              . "<div class='incharge-tags'></div>"
              . "<div class='incharge-add-row'>"
              . "<div class='incharge-person-row'>"
              . "<input type='text' class='incharge-person-input' placeholder='Add in charge'>"
              . "<button type='button' class='incharge-add-btn' title='Add'><i class='bi bi-plus-lg'></i></button>"
              . "</div></div></div></td>";

        $html .= "
        <tr data-id='{$recId}' data-mode='view'>
            {$leadCells}
            <td><select class='cell-select' data-field='status' disabled>{$statusOptions}</select></td>
            <td>{$remarksCellHtml}</td>
            <td>{$filesHtml}</td>
            <td><input type='text' class='cell-year' maxlength='4' data-field='year' value='{$recYear}' placeholder='e.g. 2026' disabled></td>
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
        $emptyMessage = $selectedOffice === '' ? 'No audit recommendations submitted yet' : 'No recommendations available for this office';
        $html = "<tr><td colspan='7' class='empty-state'>" . htmlspecialchars($emptyMessage, ENT_QUOTES) . "</td></tr>";
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

    return "<tr><td colspan='7' class='area-cell'><div class='prog-grid'>{$cards}</div></td></tr>";
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

    return "<tr><td colspan='7' class='area-cell'><div class='area-grid'>{$cards}</div></td></tr>";
}

/** Heading for the recommendations grid. An empty office means every office. */
function office_recommendations_title($selectedOffice){
    return $selectedOffice === ''
        ? 'All Offices Recommendations'
        : $selectedOffice . ' Recommendations';
}
