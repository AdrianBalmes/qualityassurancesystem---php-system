<?php

function fetch_office_recommendations($conn, $selectedOffice, $selectedAudit){
    if($selectedOffice !== ''){
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
    foreach($rows as $row){
        $recId = (int) $row['id'];
        $recYear = htmlspecialchars($row['year'], ENT_QUOTES);
        $recOfficeName = htmlspecialchars($row['office'], ENT_QUOTES);
        $recText = htmlspecialchars($row['recommendation'], ENT_QUOTES);
        $remarks = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);
        $reviewRemarks = htmlspecialchars($row['review_remarks'] ?? '', ENT_QUOTES);
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
                    'url' => "uploads/" . rawurlencode($doc['file_name']),
                    'label' => $doc['original_name'],
                ];
            }
        }
        $docsJson = htmlspecialchars(json_encode($docsForJs), ENT_QUOTES);
        $docCount = count($docsForJs);

        $filesHtml = "<span class='muted-copy'>No document submitted</span>";
        if($docCount > 0){
            $filesHtml = "<button type='button' class='doc-trigger-btn' data-doc-trigger data-rec-text='{$recText}' data-docs='{$docsJson}'><i class='bi bi-folder2-open'></i> Documents <span class='doc-count-badge'>{$docCount}</span></button>";
        }

        $html .= "
        <tr data-id='{$recId}' data-mode='view'>
            <td class='cell-readonly'>{$recOfficeName}</td>
            <td><div class='cell-text grid-rec-cell' contenteditable='false' data-field='recommendation'>{$recText}</div></td>
            <td><select class='cell-select' data-field='status' disabled>{$statusOptions}</select></td>
            <td><div class='cell-text' contenteditable='false' data-field='remarks'>{$remarks}</div></td>
            <td>{$filesHtml}</td>
            <td><input type='text' class='cell-year' maxlength='4' data-field='year' value='{$recYear}' placeholder='e.g. 2026' disabled></td>
            <td>
                <div class='row-actions'>
                    <button type='button' class='row-edit-btn' title='Edit row'><i class='bi bi-pencil'></i> Edit</button>
                    <button type='button' class='row-save-btn' title='Save row'><i class='bi bi-check2'></i> Save</button>
                    <button type='button' class='row-review-btn' title='Review submission' data-review-trigger data-rec-id='{$recId}' data-office='{$recOfficeName}' data-rec-text='{$recText}' data-status='" . htmlspecialchars($row['status'], ENT_QUOTES) . "' data-remarks='{$remarks}' data-review-remarks='{$reviewRemarks}' data-docs='{$docsJson}'><i class='bi bi-clipboard-check'></i> Review</button>
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

/** Heading for the recommendations grid. An empty office means every office. */
function office_recommendations_title($selectedOffice){
    return $selectedOffice === ''
        ? 'All Offices Recommendations'
        : $selectedOffice . ' Recommendations';
}
