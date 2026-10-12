<?php

require_once __DIR__ . "/env_helper.php";
require_once __DIR__ . "/in_charge.php";
require_once __DIR__ . "/notifications.php";
require_once __DIR__ . "/user_columns.php";

/**
 * Email for the events that mean somebody has to act.
 *
 * Offices do not sign in often enough to see the in-app inbox, so an
 * assignment can sit unread for days. This queues a message per recipient;
 * email_worker.php sends it. Queueing and sending are separate because only
 * one of them needs the network -- recipient resolution is testable with no
 * credentials and nothing leaving the machine.
 */

/** Cached for the request, as od_config() is. */
function email_config(){
    static $config = null;
    if($config !== null){
        return $config;
    }

    $config = [
        'enabled'      => env_bool('EMAIL_ENABLED', false),
        'dry_run'      => env_bool('EMAIL_DRY_RUN', true),
        'host'         => env_get('EMAIL_SMTP_HOST', 'smtp.gmail.com'),
        'port'         => (int) env_get('EMAIL_SMTP_PORT', 587),
        'username'     => env_get('EMAIL_USERNAME', ''),
        'password'     => env_get('EMAIL_APP_PASSWORD', ''),
        'from_name'    => env_get('EMAIL_FROM_NAME', 'SBC Quality Assurance'),
        'max_attempts' => (int) env_get('EMAIL_MAX_ATTEMPTS', 5),
        'batch_size'   => (int) env_get('EMAIL_BATCH_SIZE', 20),
        'site_url'     => rtrim(env_get('EMAIL_SITE_URL', 'https://sbc-quality-assurance.com'), '/'),
    ];
    return $config;
}

function ensure_email_outbox_table($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS email_outbox (
        id int(11) NOT NULL AUTO_INCREMENT,
        recommendation_id int(11) DEFAULT NULL,
        recipient_email varchar(190) NOT NULL,
        recipient_name varchar(120) NOT NULL DEFAULT '',
        recipient_office varchar(100) NOT NULL DEFAULT '',
        event varchar(30) NOT NULL,
        subject varchar(200) NOT NULL,
        body text,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        last_error text,
        created_at datetime DEFAULT current_timestamp(),
        sent_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
