<?php

function ensure_audit_log_table($conn){
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS audit_log (
        id int(11) NOT NULL AUTO_INCREMENT,
        actor_username varchar(50) NOT NULL,
        actor_full_name varchar(120) NOT NULL DEFAULT '',
        actor_role varchar(20) NOT NULL,
        office varchar(100) DEFAULT '',
        action varchar(50) NOT NULL,
        entity_type varchar(30) DEFAULT '',
        entity_id int(11) DEFAULT NULL,
        description text,
        ip_address varchar(45) DEFAULT '',
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY action (action),
        KEY office (office),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // A log written before names were recorded keeps its rows; fill them in
    // once from the accounts, so old entries read the same as new ones.
    $result = mysqli_query($conn, "SHOW COLUMNS FROM audit_log LIKE 'actor_full_name'");
    if($result && $result->num_rows === 0){
        mysqli_query($conn, "ALTER TABLE audit_log ADD COLUMN actor_full_name varchar(120) NOT NULL DEFAULT '' AFTER actor_username");
        mysqli_query($conn, "UPDATE audit_log l JOIN users u ON u.username = l.actor_username
                             SET l.actor_full_name = u.full_name
                             WHERE u.full_name <> ''");
    }

    // Which recommendation an entry belongs to, so one recommendation's own
    // history can be pulled out without reading the description text.
    // entity_id already holds it for recommendation entries; a document entry
    // points at the document, so its recommendation is looked up once.
    $recColumn = mysqli_query($conn, "SHOW COLUMNS FROM audit_log LIKE 'recommendation_id'");
    if($recColumn && $recColumn->num_rows === 0){
        mysqli_query($conn, "ALTER TABLE audit_log ADD COLUMN recommendation_id int(11) DEFAULT NULL AFTER entity_id");
        mysqli_query($conn, "ALTER TABLE audit_log ADD KEY recommendation_id (recommendation_id)");
        mysqli_query($conn, "UPDATE audit_log SET recommendation_id = entity_id WHERE entity_type = 'recommendation'");

        $docTable = mysqli_query($conn, "SHOW TABLES LIKE 'recommendation_documents'");
        if($docTable && $docTable->num_rows > 0){
            mysqli_query($conn, "UPDATE audit_log l JOIN recommendation_documents d ON d.id = l.entity_id
                                    SET l.recommendation_id = d.recommendation_id
                                  WHERE l.entity_type = 'document'");
        }
    }
}

/**
 * The account's full name, or '' when it has none -- or when the username
 * belongs to no account at all, which happens on a failed sign-in attempt.
 *
 * Looked up once per name per request: a page that writes several entries
 * (a compliance submission writes two) should not query users each time.
 */
function audit_actor_full_name($conn, $username){
    static $cache = [];
    if(array_key_exists($username, $cache)){
        return $cache[$username];
    }

    $stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $cache[$username] = $row ? trim((string) $row['full_name']) : '';
    return $cache[$username];
}

/**
 * The visitor's own address, seeing through a proxy in front of this server.
 *
 * Behind Cloudflare Tunnel every request reaches Apache from cloudflared on
 * this machine, so REMOTE_ADDR is always loopback and the whole Activity Log
 * would read 127.0.0.1. Cloudflare puts the real address in CF-Connecting-IP.
 *
 * That header is only trusted when the request came from loopback: a visitor
 * reaching this server directly could otherwise set it to anything and forge
 * the audit trail.
 */
function audit_client_ip(){
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if(!in_array($remote, ['127.0.0.1', '::1'], true)){
        return $remote;
    }

    foreach(['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header){
        $value = trim((string) ($_SERVER[$header] ?? ''));
        if($value === ''){
            continue;
        }
        // X-Forwarded-For may be a list; the first entry is the visitor.
        $candidate = trim(explode(',', $value)[0]);
        if(filter_var($candidate, FILTER_VALIDATE_IP)){
            return $candidate;
        }
    }

    return $remote;
}

/**
 * $recommendationId ties an entry to one recommendation's own history. A
 * recommendation entry already says which through $entityId; a document entry
 * points at the document, so the caller passes the recommendation itself.
 */
function log_audit_event($conn, $actorUsername, $actorRole, $office, $action, $entityType, $entityId, $description, $recommendationId = null){
    ensure_audit_log_table($conn);
    $ip = audit_client_ip();
    // Stored rather than joined at display time: the log has to stay readable
    // after someone renames their account or the account is removed.
    $fullName = audit_actor_full_name($conn, $actorUsername);

    if($recommendationId === null && $entityType === 'recommendation'){
        $recommendationId = $entityId;
    }
    $recommendationId = $recommendationId > 0 ? (int) $recommendationId : null;

    $stmt = $conn->prepare("INSERT INTO audit_log (actor_username, actor_full_name, actor_role, office, action, entity_type, entity_id, recommendation_id, description, ip_address) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("ssssssiiss", $actorUsername, $fullName, $actorRole, $office, $action, $entityType, $entityId, $recommendationId, $description, $ip);
    $stmt->execute();
}

/** How one recommendation's own entries are labelled in its history modal. */
function audit_action_label($action){
    $labels = [
        'recommendation_created' => ['label' => 'Created',          'icon' => 'bi-plus-circle',      'class' => 'hist-blue'],
        'recommendation_updated' => ['label' => 'Edited',           'icon' => 'bi-pencil',           'class' => 'hist-steel'],
        'recommendation_reviewed' => ['label' => 'Reviewed',        'icon' => 'bi-clipboard-check',  'class' => 'hist-purple'],
        'in_charge_updated'      => ['label' => 'In Charge changed','icon' => 'bi-person-check',     'class' => 'hist-green'],
        'compliance_submitted'   => ['label' => 'Compliance sent',  'icon' => 'bi-send',             'class' => 'hist-orange'],
        'document_uploaded'      => ['label' => 'Document added',   'icon' => 'bi-paperclip',        'class' => 'hist-blue'],
        'document_deleted'       => ['label' => 'Document removed', 'icon' => 'bi-trash3',           'class' => 'hist-red'],
        'recommendation_deleted' => ['label' => 'Deleted',          'icon' => 'bi-trash3',           'class' => 'hist-red'],
    ];
    return $labels[$action] ?? ['label' => ucfirst(str_replace('_', ' ', $action)), 'icon' => 'bi-dot', 'class' => 'hist-steel'];
}

/**
 * Whether one history entry is an office's business.
 *
 * Its own row -- what it did, or what was done to it -- always is. Beyond
 * that, only entries describing the recommendation itself, so another
 * office's compliance response and document names stay between that office
 * and the administrator, exactly as they do on the dashboard.
 */
function recommendation_history_entry_is_visible($entry, $office){
    if(trim((string) $entry['office']) === $office){
        return true;
    }

    return $entry['entity_type'] === 'recommendation' && in_array($entry['action'], [
        'recommendation_created',
        'recommendation_updated',
        'recommendation_reviewed',
        'in_charge_updated',
    ], true);
}

/** Every entry for one recommendation, oldest first -- it reads as a story. */
function fetch_recommendation_history($conn, $recommendationId){
    ensure_audit_log_table($conn);

    $stmt = $conn->prepare("SELECT * FROM audit_log WHERE recommendation_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->bind_param("i", $recommendationId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
