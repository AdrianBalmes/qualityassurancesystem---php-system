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

function log_audit_event($conn, $actorUsername, $actorRole, $office, $action, $entityType, $entityId, $description){
    ensure_audit_log_table($conn);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO audit_log (actor_username, actor_role, office, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssiss", $actorUsername, $actorRole, $office, $action, $entityType, $entityId, $description, $ip);
    $stmt->execute();
}
