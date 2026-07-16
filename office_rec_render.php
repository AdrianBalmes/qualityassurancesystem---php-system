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

function render_office_recommendation_rows($rows, $selectedOffice, $selectedAudit, $anchor = '#office-recommendations'){
    $html = "";
    $count = count($rows);
    foreach($rows as $row){
        $recId = (int) $row['id'];
        $recYear = htmlspecialchars($row['year'], ENT_QUOTES);
        $recOfficeName = htmlspecialchars($row['office'], ENT_QUOTES);
        $recText = nl2br(htmlspecialchars($row['recommendation'], ENT_QUOTES));
        $recStatusClass = $row['status'] === 'Submitted' ? 'chip-green' : ($row['status'] === 'Not Submitted' ? 'chip-red' : 'chip-yellow');
        $recStatusOptions = "";
        foreach(['Pending', 'Submitted', 'Not Submitted'] as $option){
            $sel = $row['status'] === $option ? " selected" : "";
            $safeOption = htmlspecialchars($option, ENT_QUOTES);
            $recStatusOptions .= "<option value='{$safeOption}'{$sel}>{$safeOption}</option>";
        }
        $safeRemarksValue = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);
        $safeAuditParam = htmlspecialchars($row['audit_type'], ENT_QUOTES);
        $safeOfficeParam = htmlspecialchars($selectedOffice, ENT_QUOTES);
        $safeAnchor = htmlspecialchars($anchor, ENT_QUOTES);
        $html .= "
        <tr>
            <td>{$recOfficeName}</td>
            <td class='rec-cell'>{$recText}</td>
            <td><span class='status-chip {$recStatusClass}'>" . htmlspecialchars($row['status'], ENT_QUOTES) . "</span></td>
            <td class='rec-cell'>
                <form method='POST' action='home.php?audit={$safeAuditParam}&office=" . urlencode($selectedOffice) . "{$safeAnchor}' class='rec-update-form'>
                    <input type='hidden' name='recommendation_id' value='{$recId}'>
                    <input type='hidden' name='audit_type' value='{$safeAuditParam}'>
                    <input type='hidden' name='redirect_office' value='{$safeOfficeParam}'>
                    <select name='rec_status' class='form-select form-select-sm status-select'>{$recStatusOptions}</select>
                    <input type='text' name='rec_remarks' class='form-control form-control-sm' placeholder='Remarks' value='{$safeRemarksValue}'>
                    <button type='submit' name='update_recommendation' class='btn btn-secondary btn-xs align-self-start'>Update</button>
                </form>
            </td>
            <td>{$recYear}</td>
        </tr>
        ";
    }
    if($count === 0){
        $emptyMessage = $selectedOffice === '' ? 'No audit recommendations submitted yet' : 'No recommendations available for this office';
        $html = "<tr><td colspan='5' class='empty-state'>" . htmlspecialchars($emptyMessage, ENT_QUOTES) . "</td></tr>";
    }
    return ['html' => $html, 'count' => $count];
}

function render_select_office_placeholder(){
    return "<tr><td colspan='5' class='empty-state'>Select an office below to view its recommendations.</td></tr>";
}
