<?php

require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/in_charge.php";

/**
 * What an office is told about its own recommendations.
 *
 * A row per office per event, so each office keeps its own read/unread state
 * even when several are in charge of the same recommendation. The text is
 * stored rather than rebuilt at display time: a notification should still read
 * the same after the recommendation it describes has moved on again.
 *
 * The Activity Board on office_dashboard.php is what reads these.
 */

const NOTIFY_NEW       = 'recommendation_new';
const NOTIFY_STATUS    = 'status_changed';
const NOTIFY_FEEDBACK  = 'review_feedback';
const NOTIFY_ASSIGNED  = 'assigned';

function ensure_notifications_table($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
        id int(11) NOT NULL AUTO_INCREMENT,
        office varchar(100) NOT NULL,
        recommendation_id int(11) DEFAULT NULL,
        type varchar(30) NOT NULL,
        title varchar(180) NOT NULL,
        body text,
        created_at datetime DEFAULT current_timestamp(),
        read_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY office_created (office, created_at),
        KEY office_unread (office, read_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * Every office that should hear about a recommendation: the one it belongs to,
 * plus any office put in charge of it. A person named in In Charge is a label
 * on the row, not an inbox, so only names that match an office count.
 */
function notification_recipients($conn, $row){
    $offices = [];
    $owner = trim((string) ($row['office'] ?? ''));
    if($owner !== ''){
        $offices[] = $owner;
    }

    $known = get_all_office_names($conn);
    foreach(in_charge_decode($row['in_charge'] ?? '') as $name){
        if(in_array($name, $known, true) && !in_array($name, $offices, true)){
            $offices[] = $name;
        }
    }
    return $offices;
}

/**
 * $exceptOffice is whoever caused the change -- an office does not need to be
 * told about something it just did itself.
 */
function notify_offices($conn, array $offices, $type, $recommendationId, $title, $body = '', $exceptOffice = ''){
    ensure_notifications_table($conn);

    $stmt = $conn->prepare("INSERT INTO notifications (office, recommendation_id, type, title, body) VALUES (?,?,?,?,?)");
    $recId = $recommendationId > 0 ? (int) $recommendationId : null;

    foreach(array_unique($offices) as $office){
        $office = trim((string) $office);
        if($office === '' || $office === $exceptOffice || strcasecmp($office, 'Admin') === 0){
            continue;
        }
        $stmt->bind_param("sisss", $office, $recId, $type, $title, $body);
        $stmt->execute();
    }
}

/** Newest first, for the Activity Board. */
function fetch_office_notifications($conn, $office, $limit = 12){
    ensure_notifications_table($conn);

    $limit = max(1, min(50, (int) $limit));
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE office = ? ORDER BY created_at DESC, id DESC LIMIT {$limit}");
    $stmt->bind_param("s", $office);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function count_unread_notifications($conn, $office){
    ensure_notifications_table($conn);

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE office = ? AND read_at IS NULL");
    $stmt->bind_param("s", $office);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['total'] : 0;
}

/** Empties the office's inbox for good. Only that office's own rows. */
function clear_notifications($conn, $office){
    ensure_notifications_table($conn);

    $stmt = $conn->prepare("DELETE FROM notifications WHERE office = ?");
    $stmt->bind_param("s", $office);
    $stmt->execute();
    return $conn->affected_rows;
}

function mark_notifications_read($conn, $office){
    ensure_notifications_table($conn);

    $stmt = $conn->prepare("UPDATE notifications SET read_at = NOW() WHERE office = ? AND read_at IS NULL");
    $stmt->bind_param("s", $office);
    $stmt->execute();
    return $conn->affected_rows;
}

/** The icon and colour each kind of notice is shown with. */
function notification_style($type){
    $styles = [
        NOTIFY_NEW      => ['icon' => 'bi-clipboard-plus',  'class' => 'note-blue'],
        NOTIFY_STATUS   => ['icon' => 'bi-arrow-repeat',    'class' => 'note-orange'],
        NOTIFY_FEEDBACK => ['icon' => 'bi-chat-left-text',  'class' => 'note-purple'],
        NOTIFY_ASSIGNED => ['icon' => 'bi-person-check',    'class' => 'note-green'],
    ];
    return $styles[$type] ?? ['icon' => 'bi-bell', 'class' => 'note-steel'];
}

/** "3 minutes ago", falling back to a date once it stops being recent. */
function notification_time_ago($datetime){
    $then = strtotime((string) $datetime);
    if(!$then){
        return '';
    }

    $seconds = time() - $then;
    if($seconds < 60){ return 'just now'; }
    if($seconds < 3600){ $m = (int) floor($seconds / 60); return $m . ($m === 1 ? ' minute ago' : ' minutes ago'); }
    if($seconds < 86400){ $h = (int) floor($seconds / 3600); return $h . ($h === 1 ? ' hour ago' : ' hours ago'); }
    if($seconds < 604800){ $d = (int) floor($seconds / 86400); return $d . ($d === 1 ? ' day ago' : ' days ago'); }
    return date('M j, Y', $then);
}
