<?php

require_once __DIR__ . "/office_directory.php";

/**
 * Whether an office falls under Internal or External audit.
 *
 * This is now a property of the office, editable in Settings. The signature
 * takes no connection because it is called from a dozen places; it reaches for
 * the global one and caches the whole table on first use.
 */
function audit_type_for_office($office){
    static $types = null;

    $office = trim($office);

    if($types === null){
        global $conn;
        $types = [];
        if($conn instanceof mysqli){
            ensure_offices_table($conn);
            $result = mysqli_query($conn, "SELECT name, audit_type FROM offices");
            if($result){
                while($row = $result->fetch_assoc()){
                    $types[$row['name']] = $row['audit_type'];
                }
            }
        }
    }

    if(isset($types[$office])){
        return $types[$office] === 'External' ? 'External' : 'Internal';
    }

    // An office not in the table (legacy data) keeps the original rule.
    return in_array($office, ['BED', 'College Department'], true) ? 'External' : 'Internal';
}
