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

/**
 * Approved staff accounts, one row each, whether or not they have an address.
 *
 * Name resolution must not depend on deliverability: a person named in charge
 * is still named when they have no address. Only the sending side filters on
 * the address, so the office-fallback decision stays a decision and not an
 * accident of a SQL filter.
 */
function email_staff_rows($conn){
    static $rows = null;
    if($rows !== null){
        return $rows;
    }

    ensure_user_account_columns($conn);
    $rows = [];
    $sql = "SELECT username, full_name, office, email FROM users
             WHERE status = '" . USER_STATUS_APPROVED . "'
               AND office <> '' AND office <> 'Admin'";
    $result = mysqli_query($conn, $sql);
    if($result){
        while($row = $result->fetch_assoc()){
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Staff keyed by the name an in_charge entry is stored under. A name can be
 * held by more than one account, so each key lists every holder: keying by
 * name alone would keep the last holder and drop the rest without a trace,
 * which is the silent loss this feature exists to prevent.
 */
function email_accounts_by_name($conn){
    static $byName = null;
    if($byName !== null){
        return $byName;
    }

    $byName = [];
    foreach(email_staff_rows($conn) as $row){
        $name = trim((string) $row['full_name']) !== ''
            ? trim($row['full_name'])
            : trim((string) $row['username']);
        if($name !== ''){
            $byName[$name][] = $row;
        }
    }
    return $byName;
}

/**
 * Every approved account of one office. Read from the rows rather than the
 * name map, so a shared name cannot remove anyone from the office fallback.
 */
function email_accounts_in_office($conn, $office){
    $found = [];
    foreach(email_staff_rows($conn) as $row){
        if($row['office'] === $office){
            $found[] = $row;
        }
    }
    return $found;
}

/**
 * Who hears about this recommendation, by the rule the QA Head chose: a named
 * person takes precedence over their whole office.
 *
 * A name only counts as "named" when it resolves to an approved account. Six
 * current assignments are typed-in names nobody holds -- "Leo", "Borbon" -- and
 * read literally the rule would email nobody and never fall back to the office,
 * so the alert would vanish silently. A named person with no address is dropped
 * from the chosen set, and when that leaves nobody the office takes over.
 */
function email_recipients_for($conn, array $row, $exceptEmail = ''){
    $accounts = email_accounts_by_name($conn);

    $named = [];
    foreach(in_charge_decode($row['in_charge'] ?? '') as $entry){
        foreach($accounts[$entry] ?? [] as $account){
            $named[] = $account;
        }
    }

    $chosen = [];
    foreach($named as $account){
        if(trim((string) $account['email']) !== ''){
            $chosen[] = $account;
        }
    }

    // Deliberate: when every named person is unreachable, the office gets the
    // alert rather than nobody. Work that has to get done is better mailed to
    // the office than lost, and the outbox is the only record that the named
    // person could not be reached.
    if(empty($chosen)){
        foreach(notification_recipients($conn, $row) as $office){
            foreach(email_accounts_in_office($conn, $office) as $account){
                $chosen[] = $account;
            }
        }
    }

    $recipients = [];
    $seen = [];
    foreach($chosen as $account){
        $email = strtolower(trim((string) $account['email']));
        if($email === '' || $email === strtolower(trim((string) $exceptEmail)) || isset($seen[$email])){
            continue;
        }
        $seen[$email] = true;
        $recipients[] = [
            'email'  => $account['email'],
            'name'   => trim((string) $account['full_name']) !== '' ? $account['full_name'] : $account['username'],
            'office' => $account['office'],
        ];
    }
    return $recipients;
}
