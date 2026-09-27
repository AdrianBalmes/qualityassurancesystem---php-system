<?php

/**
 * Status options for recommendations.
 *
 * Every office has the built-in statuses below. On top of those, each office
 * can keep statuses of its own. They are stored against the office, so one
 * office adding "Awaiting Board Approval" changes nothing for any other.
 */

const OFFICE_STATUS_BUILTIN = ['Pending', 'Submitted', 'Not Submitted', 'Approved', 'Needs Revision', 'Rejected', 'Completed'];
const OFFICE_STATUS_MAX_LENGTH = 40;

function ensure_office_statuses_table($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS office_statuses (
        id int(11) NOT NULL AUTO_INCREMENT,
        office varchar(100) NOT NULL,
        name varchar(60) NOT NULL,
        created_by varchar(50) DEFAULT NULL,
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY office_name (office, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // The column was sized for the built-in names. A custom one can be longer.
    $result = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE 'status'");
    $row = $result ? $result->fetch_assoc() : null;
    if($row && preg_match('/^varchar\((\d+)\)/i', $row['Type'], $m) && (int) $m[1] < 60){
        mysqli_query($conn, "ALTER TABLE audit_recommendations MODIFY status VARCHAR(60) NOT NULL DEFAULT 'Pending'");
    }
}

/** ['Status name', ...] this office added itself, in the order they were added. */
function office_custom_status_names($conn, $office){
    ensure_office_statuses_table($conn);
    $stmt = $conn->prepare("SELECT name FROM office_statuses WHERE office = ? ORDER BY id ASC");
    $stmt->bind_param("s", $office);
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
}

/** Custom statuses with how many recommendations use each, for the manager. */
function office_custom_status_rows($conn, $office){
    ensure_office_statuses_table($conn);
    $stmt = $conn->prepare(
        "SELECT s.id, s.name,
                (SELECT COUNT(*) FROM audit_recommendations r WHERE r.office = s.office AND r.status = s.name) AS in_use
           FROM office_statuses s WHERE s.office = ? ORDER BY s.id ASC"
    );
    $stmt->bind_param("s", $office);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Built-in statuses plus this office's own. */
function office_status_choices($conn, $office){
    return array_merge(OFFICE_STATUS_BUILTIN, office_custom_status_names($conn, $office));
}

/**
 * The choices for a view that spans offices ("All Offices"): the built-in
 * statuses plus every custom one used by an office in this audit type.
 */
function office_status_choices_for_audit($conn, $auditType){
    ensure_office_statuses_table($conn);
    $stmt = $conn->prepare(
        "SELECT DISTINCT s.name FROM office_statuses s
           JOIN offices o ON o.name = s.office
          WHERE o.audit_type = ? ORDER BY s.name ASC"
    );
    $stmt->bind_param("s", $auditType);
    $stmt->execute();
    $custom = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
    return array_values(array_unique(array_merge(OFFICE_STATUS_BUILTIN, $custom)));
}

/** ['Office' => ['custom', ...], ...] so a grid of many offices needs one query. */
function office_custom_status_map($conn){
    ensure_office_statuses_table($conn);
    $map = [];
    $result = mysqli_query($conn, "SELECT office, name FROM office_statuses ORDER BY id ASC");
    while($result && ($row = $result->fetch_assoc())){
        $map[$row['office']][] = $row['name'];
    }
    return $map;
}

/**
 * Empty string when $name is acceptable as a new custom status for $office, or
 * the reason it is not. $ignoreId lets a rename keep its own current name.
 */
function office_status_name_problem($conn, $office, $name, $ignoreId = 0){
    if($name === ''){
        return 'Enter a status name.';
    }
    if(mb_strlen($name) > OFFICE_STATUS_MAX_LENGTH){
        return 'Keep the status name to ' . OFFICE_STATUS_MAX_LENGTH . ' characters or fewer.';
    }
    foreach(OFFICE_STATUS_BUILTIN as $builtin){
        if(strcasecmp($builtin, $name) === 0){
            return "\"{$builtin}\" is already available to every office.";
        }
    }

    $stmt = $conn->prepare("SELECT id FROM office_statuses WHERE office = ? AND name = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("ssi", $office, $name, $ignoreId);
    $stmt->execute();
    if($stmt->get_result()->num_rows > 0){
        return "\"{$name}\" already exists for this office.";
    }
    return '';
}
