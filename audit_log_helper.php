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

function log_audit_event($conn, $actorUsername, $actorRole, $office, $action, $entityType, $entityId, $description){
    ensure_audit_log_table($conn);
    $ip = audit_client_ip();
    // Stored rather than joined at display time: the log has to stay readable
    // after someone renames their account or the account is removed.
    $fullName = audit_actor_full_name($conn, $actorUsername);
    $stmt = $conn->prepare("INSERT INTO audit_log (actor_username, actor_full_name, actor_role, office, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("ssssssiss", $actorUsername, $fullName, $actorRole, $office, $action, $entityType, $entityId, $description, $ip);
    $stmt->execute();
}
