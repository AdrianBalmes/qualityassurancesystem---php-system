<?php

function get_all_office_names($conn){
    $defaultOffices = ['Faculty', 'CSSAO', 'BED', 'SHS', 'Registrar', 'Finance', 'IRRPO', 'Marketing Office', 'External Relations', 'Guidance', 'EDU Hub', 'Praxis', 'Library', 'ITMSO', 'College Department'];
    $officeNames = $defaultOffices;
    $officeResult = mysqli_query($conn, "SELECT office FROM users WHERE office <> '' AND office <> 'Admin' UNION SELECT office FROM audit_recommendations WHERE office <> '' ORDER BY office ASC");
    if($officeResult){
        while($officeRow = mysqli_fetch_assoc($officeResult)){
            $officeName = trim($officeRow['office']);
            if($officeName !== '' && !in_array($officeName, $officeNames, true)){
                $officeNames[] = $officeName;
            }
        }
    }
    return $officeNames;
}
