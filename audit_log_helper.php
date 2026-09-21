<?php

function ensure_audit_log_table($conn){
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS audit_log (
        id int(11) NOT NULL AUTO_INCREMENT,
        actor_username varchar(50) NOT NULL,
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
    $stmt = $conn->prepare("INSERT INTO audit_log (actor_username, actor_role, office, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssiss", $actorUsername, $actorRole, $office, $action, $entityType, $entityId, $description, $ip);
    $stmt->execute();
}
