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

function render_office_recommendation_rows($rows, $selectedOffice, $selectedAudit){
    $html = "";
    $count = count($rows);
    foreach($rows as $row){
        $recId = (int) $row['id'];
        $recYear = htmlspecialchars($row['year'], ENT_QUOTES);
        $recOfficeName = htmlspecialchars($row['office'], ENT_QUOTES);
        $recText = htmlspecialchars($row['recommendation'], ENT_QUOTES);
        $remarks = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);
        $statusOptions = "";
        foreach(['Pending', 'Submitted', 'Not Submitted'] as $option){
            $sel = $row['status'] === $option ? " selected" : "";
            $safeOption = htmlspecialchars($option, ENT_QUOTES);
            $statusOptions .= "<option value='{$safeOption}'{$sel}>{$safeOption}</option>";
        }
        $html .= "
        <tr data-id='{$recId}' data-mode='view'>
            <td class='cell-readonly'>{$recOfficeName}</td>
            <td><div class='cell-text grid-rec-cell' contenteditable='false' data-field='recommendation'>{$recText}</div></td>
            <td><select class='cell-select' data-field='status' disabled>{$statusOptions}</select></td>
            <td><div class='cell-text' contenteditable='false' data-field='remarks'>{$remarks}</div></td>
            <td><input type='text' class='cell-year' maxlength='4' data-field='year' value='{$recYear}' placeholder='e.g. 2026' disabled></td>
            <td>
                <div class='row-actions'>
                    <button type='button' class='row-edit-btn' title='Edit row'><i class='bi bi-pencil'></i> Edit</button>
                    <button type='button' class='row-save-btn' title='Save row'><i class='bi bi-check2'></i> Save</button>
                    <button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button>
                </div>
            </td>
        </tr>
        ";
    }
    if($count === 0){
        $emptyMessage = $selectedOffice === '' ? 'No audit recommendations submitted yet' : 'No recommendations available for this office';
        $html = "<tr><td colspan='6' class='empty-state'>" . htmlspecialchars($emptyMessage, ENT_QUOTES) . "</td></tr>";
    }
    return ['html' => $html, 'count' => $count];
}

function render_select_office_placeholder(){
    return "<tr><td colspan='6' class='empty-state'>Select an office below to view its recommendations.</td></tr>";
}
