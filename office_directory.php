<?php

/**
 * The list of offices (departments).
 *
 * These used to be a hardcoded array here. They now live in the `offices`
 * table so an admin can maintain them, seeded with the original list so
 * nothing changes on first run.
 */

/** The original hardcoded list, used only to seed an empty table. */
const OFFICE_SEED = [
    'Faculty' => 'Internal',
    'CSSAO' => 'Internal',
    'BED' => 'External',
    'SHS' => 'Internal',
    'Registrar' => 'Internal',
    'Finance' => 'Internal',
    'IRRPO' => 'Internal',
    'Marketing Office' => 'Internal',
    'External Relations' => 'Internal',
    'Guidance' => 'Internal',
    'EDU Hub' => 'Internal',
    'Praxis' => 'Internal',
    'Library' => 'Internal',
    'ITMSO' => 'Internal',
    'College Department' => 'External',
];

function ensure_offices_table($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS offices (
        id int(11) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        audit_type varchar(20) NOT NULL DEFAULT 'Internal',
        created_at datetime DEFAULT current_timestamp(),
        created_by varchar(50) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $count = mysqli_query($conn, "SELECT COUNT(*) AS total FROM offices");
    $row = $count ? $count->fetch_assoc() : null;
    if(!$row || (int) $row['total'] > 0){
        return;
    }

    // First run: seed the original list, then adopt any office already in use
    // so existing data keeps a home.
    $stmt = $conn->prepare("INSERT IGNORE INTO offices (name, audit_type, created_by) VALUES (?,?,'system')");
    foreach(OFFICE_SEED as $name => $auditType){
        $stmt->bind_param("ss", $name, $auditType);
        $stmt->execute();
    }

    $inUse = mysqli_query($conn, "SELECT office FROM users WHERE office <> '' AND office <> 'Admin'
                                  UNION SELECT office FROM audit_recommendations WHERE office <> ''");
    if($inUse){
        while($useRow = $inUse->fetch_assoc()){
            $name = trim($useRow['office']);
            if($name === ''){
                continue;
            }
            $auditType = 'Internal';
            $stmt->bind_param("ss", $name, $auditType);
            $stmt->execute();
        }
    }
}

/** Full rows, for the management screen. */
function get_office_rows($conn){
    ensure_offices_table($conn);
    $result = mysqli_query($conn, "SELECT * FROM offices ORDER BY name ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Office names for dropdowns and dashboards. Anything referenced by existing
 * data is included even if it is not in the table, so a stray office name can
 * never make its recommendations unreachable.
 */
function get_all_office_names($conn){
    ensure_offices_table($conn);

    $officeNames = [];
    $result = mysqli_query($conn, "SELECT name FROM offices ORDER BY name ASC");
    if($result){
        while($row = $result->fetch_assoc()){
            $officeNames[] = $row['name'];
        }
    }

    $inUse = mysqli_query($conn, "SELECT office FROM users WHERE office <> '' AND office <> 'Admin'
                                  UNION SELECT office FROM audit_recommendations WHERE office <> '' ORDER BY office ASC");
    if($inUse){
        while($row = $inUse->fetch_assoc()){
            $name = trim($row['office']);
            if($name !== '' && !in_array($name, $officeNames, true)){
                $officeNames[] = $name;
            }
        }
    }

    return $officeNames;
}

/** How many users and recommendations point at an office. */
function office_usage($conn, $name){
    $stmt = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM users WHERE office = ?) AS users,
            (SELECT COUNT(*) FROM audit_recommendations WHERE office = ?) AS recommendations");
    $stmt->bind_param("ss", $name, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ['users' => (int) $row['users'], 'recommendations' => (int) $row['recommendations']];
}
