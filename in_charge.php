<?php

require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/user_columns.php";

/**
 * Who is in charge of carrying out a recommendation.
 *
 * Kept on audit_recommendations.in_charge as a JSON array of names. An entry is
 * a person -- an account of any office involved -- or an office, and an office
 * named here also sees the recommendation on its own dashboard
 * (recommendation_involves_office() in recommendation_rules.php). Anything else
 * is a name someone typed in for a person who has no account.
 *
 * The names are stored, not account ids, so the assignment still reads the
 * same after an account is removed -- the same reason the audit log keeps the
 * actor's name rather than joining users at display time.
 *
 * Three ways to see the cell, which render_in_charge_cell() takes as $mode:
 *
 *   full   the administrator, and the office the recommendation belongs to:
 *          every name, and the list of offices, can be changed
 *   staff  an office put in charge of another office's recommendation: it
 *          names its own people and nothing else, so it cannot remove itself
 *   view   no changes at all
 */

const IN_CHARGE_MAX_ENTRIES = 20;
const IN_CHARGE_MAX_LENGTH  = 100;

/**
 * Tolerates the plain string rows carried before this became a list, so an old
 * assignment still shows instead of silently disappearing.
 */
function in_charge_decode($raw){
    if(is_array($raw)){
        $raw = json_encode(array_values($raw));
    }

    $decoded = json_decode((string) $raw, true);
    if(is_array($decoded)){
        $names = [];
        foreach($decoded as $entry){
            if(is_string($entry) && trim($entry) !== ''){
                $names[] = trim($entry);
            }
        }
        return array_values(array_unique($names));
    }

    $plain = trim((string) $raw);
    return $plain !== '' ? [$plain] : [];
}

/** JSON for storage: trimmed, de-duplicated and bounded. */
function in_charge_encode($names){
    $clean = [];
    foreach((array) $names as $entry){
        if(!is_string($entry)){
            continue;
        }
        $entry = trim($entry);
        if($entry === '' || mb_strlen($entry) > IN_CHARGE_MAX_LENGTH){
            continue;
        }
        $clean[] = $entry;
    }
    return json_encode(array_slice(array_values(array_unique($clean)), 0, IN_CHARGE_MAX_ENTRIES));
}

/** Read a JSON array posted by the browser and give back storable JSON. */
function in_charge_encode_posted($raw){
    $decoded = json_decode((string) $raw, true);
    return in_charge_encode(is_array($decoded) ? $decoded : []);
}

/** The name an account is ticked and stored under. */
function in_charge_person_name($user){
    $fullName = trim((string) ($user['full_name'] ?? ''));
    return $fullName !== '' ? $fullName : trim((string) ($user['username'] ?? ''));
}

/** Approved accounts grouped by office: ['CSSAO' => [['name'=>…, 'username'=>…]]]. */
function in_charge_staff_by_office($conn){
    static $staff = null;
    if($staff !== null){
        return $staff;
    }

    ensure_user_account_columns($conn);
    $staff = [];

    $result = mysqli_query($conn, "SELECT username, full_name, office FROM users
                                    WHERE status = '" . USER_STATUS_APPROVED . "'
                                      AND office <> '' AND office <> 'Admin'
                                 ORDER BY full_name ASC, username ASC");
    if($result){
        while($row = $result->fetch_assoc()){
            $name = in_charge_person_name($row);
            if($name === ''){
                continue;
            }
            $staff[$row['office']][] = ['name' => $name, 'username' => $row['username']];
        }
    }
    return $staff;
}

/** Just the names, to decide which entries an office is allowed to change. */
function in_charge_office_staff_names($conn, $office){
    $staff = in_charge_staff_by_office($conn);
    return array_map(function($person){ return $person['name']; }, $staff[$office] ?? []);
}

/**
 * What the browser needs to draw the tick list: staff keyed by office, and the
 * office list itself.
 *
 * $onlyOffices keeps an office's own dashboard from carrying the whole staff
 * directory -- it is given its own people and those of the offices it actually
 * shares a recommendation with.
 */
function in_charge_options($conn, array $onlyOffices = null){
    $staff = in_charge_staff_by_office($conn);
    if($onlyOffices !== null){
        $staff = array_intersect_key($staff, array_flip($onlyOffices));
    }

    return [
        // Cast: an office with no staff at all would otherwise reach the
        // browser as [] rather than {}, and the lookups would fail.
        'staff'   => (object) $staff,
        'offices' => get_all_office_names($conn),
    ];
}

/**
 * The cell shared by the admin grid and an office's own dashboard.
 *
 * The tags and the tick list are drawn by assets/in_charge.js from data-json,
 * so a row added without reloading the page looks the same as one rendered
 * here. $viewerOffice is whose staff may be ticked in 'staff' mode.
 */
function render_in_charge_cell($office, $names, $mode, $viewerOffice = ''){
    $json = htmlspecialchars(json_encode(in_charge_decode($names)), ENT_QUOTES);
    $safeOffice = htmlspecialchars((string) $office, ENT_QUOTES);
    $safeViewer = htmlspecialchars((string) $viewerOffice, ENT_QUOTES);
    $mode = in_array($mode, ['full', 'staff', 'view'], true) ? $mode : 'view';

    $html = "<div class='incharge-cell' data-field='in_charge' data-office='{$safeOffice}'"
          . " data-mode='{$mode}' data-viewer-office='{$safeViewer}' data-json='{$json}'>"
          . "<div class='incharge-tags'></div>";
    if($mode !== 'view'){
        $html .= "<div class='incharge-add-row'>"
               . "<button type='button' class='incharge-pick-btn'><i class='bi bi-check2-square'></i> Assign</button>"
               . "</div>";
    }
    return $html . "</div>";
}
