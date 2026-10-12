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

/**
 * The events that email. Anything not listed queues nothing, whatever its
 * status reads. Feedback, Approved and Completed stay in the in-app inbox,
 * or the mail becomes noise people filter.
 */
const EMAIL_EVENTS = [NOTIFY_NEW, NOTIFY_ASSIGNED, NOTIFY_STATUS];

/** Statuses that mean the office has to do something about it. */
const EMAIL_ACTION_STATUSES = ['Rejected', 'Needs Revision'];

/**
 * The most a message body may hold. email_outbox.body is TEXT (65,535 bytes);
 * this leaves headroom under it rather than sitting on the edge.
 */
const EMAIL_BODY_MAX_BYTES = 65000;

/**
 * Whether this event emails at all. New and assigned always do; a status
 * change only when it hands work back.
 */
function email_event_sends($event, $status = ''){
    if(!in_array($event, EMAIL_EVENTS, true)){
        return false;
    }
    if($event === NOTIFY_STATUS){
        return in_array(trim((string) $status), EMAIL_ACTION_STATUSES, true);
    }
    return true;
}

/**
 * Subject and body, rendered now rather than when the mail is sent: it should
 * describe the assignment as it was, the same reasoning notifications.php
 * records for its stored text.
 *
 * Deliberately absent: compliance responses, review remarks and document
 * names. On a recommendation shared between offices those would send one
 * office's submission to another. Only the fields read below ever reach the
 * body, so keep it that way when adding to this function.
 */
function email_render($row, $event, $recipient){
    $config = email_config();
    $office = trim((string) ($row['office'] ?? ''));
    $text = trim((string) ($row['recommendation'] ?? ''));
    $status = trim((string) ($row['status'] ?? ''));

    // The recipient set is deliberate (a named person, else the owner office
    // and any office named), but the wording has to fit who is reading. Telling
    // the owner office's staff they were "put in charge" of their own
    // recommendation, because another office was added, would be false -- and
    // so would telling a named person from another office that it was filed
    // "for your office".
    $named = array_map('trim', in_charge_decode($row['in_charge'] ?? ''));
    $recipientName = trim((string) $recipient['name']);
    $recipientOffice = trim((string) ($recipient['office'] ?? ''));
    $isNamed = $recipientName !== '' && in_array($recipientName, $named, true);
    $officeNamed = $recipientOffice !== '' && in_array($recipientOffice, $named, true);

    if($event === NOTIFY_STATUS){
        $subject = "{$status}: a {$office} recommendation needs your attention";
        $lead = "A recommendation you are responsible for is now marked {$status}.";
    } elseif($event === NOTIFY_ASSIGNED){
        if($isNamed){
            $subject = "You have been put in charge of a {$office} recommendation";
            $lead = "You have been put in charge of the recommendation below.";
        } elseif($officeNamed){
            $subject = "Your office has been put in charge of a {$office} recommendation";
            $lead = "Your office has been put in charge of the recommendation below.";
        } else {
            $who = !empty($named) ? implode(', ', $named) : 'someone else';
            $subject = "A {$office} recommendation has been assigned";
            $lead = "A recommendation for your office has been assigned to {$who}.";
        }
    } else {
        if($isNamed){
            $subject = "New {$office} recommendation: you have been put in charge";
            $lead = "A new recommendation has been filed, and you have been put in charge of it.";
        } elseif($officeNamed && $recipientOffice !== $office){
            $subject = "New {$office} recommendation: your office has been put in charge";
            $lead = "A new recommendation has been filed, and your office has been put in charge of it.";
        } elseif($recipientOffice === $office){
            $subject = "New recommendation for {$office}";
            $lead = "A new recommendation has been filed for your office.";
        } else {
            $subject = "New recommendation for {$office}";
            $lead = "A new recommendation has been filed for {$office}.";
        }
    }

    $name = trim((string) $recipient['name']);
    $head = "Hello {$name},\n\n{$lead}\n\nOffice: {$office}\nRecommendation: ";
    $tail = "\n\nOpen the dashboard to respond:\n{$config['site_url']}/\n\n"
          . "--\nSBC Quality Assurance\nThis is an automated message; replies are not read.";
    $text = $text !== '' ? $text : '(not written out yet)';

    // email_outbox.body is TEXT. A recommendation can fill a TEXT column of
    // its own, and wrapped in the greeting and footer it no longer fits; under
    // the server's default sql_mode MySQL then truncates the body without a
    // word, so the recipient would get a message that stops mid-sentence and
    // never says so. Cut the recommendation instead, at a word, and say so --
    // the head and tail (the dashboard link) always survive.
    $room = EMAIL_BODY_MAX_BYTES - strlen($head) - strlen($tail);
    if(strlen($text) > $room){
        $notice = "\n\n[This recommendation is too long for an email and was cut here. Open the dashboard for the full text.]";
        $cut = mb_strcut($text, 0, max(0, $room - strlen($notice)), 'UTF-8');
        // Back up to the last whitespace if one is near the end; a text with no
        // spaces at all is cut where it stands rather than emptied.
        if(preg_match('/^(.*\s)\S{0,200}$/su', $cut, $m)){
            $cut = $m[1];
        }
        $text = rtrim($cut) . $notice;
    }

    return ['subject' => mb_substr($subject, 0, 200), 'body' => $head . $text . $tail];
}

/**
 * The address of whoever is making the change, so rule 4 can exclude them.
 * Read from the rows email_notify() loads anyway -- no extra query -- and ''
 * when the actor has no account, no address, or the lookup failed.
 */
function email_actor_address($conn, $username){
    try {
        foreach(email_staff_rows($conn) as $row){
            if($row['username'] === $username){
                return trim((string) $row['email']);
            }
        }
    } catch(Throwable $e){
        return '';
    }
    return '';
}

/**
 * Record that an event should have emailed and there was nobody to send it to.
 *
 * Without this the failure is invisible: an office with no approved account, or
 * whose accounts have no address, is assigned and `email_worker.php status`
 * reads exactly like "everything was delivered". A 'failed' row with no
 * recipient_email is the record. It sits in the failed count and the failure
 * list, where an administrator already looks; last_error is what `status`
 * prints, so it names what was tried. email_reset_failed() leaves it alone,
 * because there is no address to retry.
 */
function email_record_unreachable($conn, array $row, $event){
    $entries = in_charge_decode($row['in_charge'] ?? '');
    $offices = notification_recipients($conn, $row);
    $office = trim((string) ($row['office'] ?? ''));
    $recId = isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null;

    $error = "Nobody could be reached for the {$event} mail"
        . ($recId !== null ? " on recommendation #{$recId}" : '') . ". "
        . "In charge: " . (empty($entries) ? '(nobody named)' : implode(', ', $entries)) . ". "
        . "Offices tried: " . (empty($offices) ? '(none)' : implode(', ', $offices)) . ". "
        . "No named person resolves to an approved account with an email address, and neither does "
        . "any approved account of those offices. Nothing was sent. Add an address to an account, "
        . "then assign again.";
    $subject = "NOT SENT: nobody to email about a {$office} recommendation ({$event})";

    $stmt = $conn->prepare("INSERT INTO email_outbox
        (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body, status, attempts, last_error)
        VALUES (?, '', '', ?, ?, ?, '', 'failed', 0, ?)");
    $officeColumn = mb_substr($office, 0, 100);
    $subject = mb_substr($subject, 0, 200);
    $error = mb_substr($error, 0, 2000);
    $stmt->bind_param("issss", $recId, $officeColumn, $event, $subject, $error);
    $stmt->execute();
}

/**
 * Queue one message per recipient. Returns how many rows were written --
 * deliverable ones only: the failed row that records "nobody reachable" is not
 * a message waiting to go, so it does not count.
 *
 * Never throws. Queueing is a side effect of the user's action, not part of
 * it: this runs after the recommendation has been saved, so an exception here
 * would show an error for a change that had already succeeded. A failure is
 * logged, and the rows written before it are still counted.
 */
function email_notify($conn, array $row, $event, $exceptEmail = ''){
    $config = email_config();
    if(!$config['enabled'] && !$config['dry_run']){
        // Off entirely: queueing would pile up rows nothing ever drains.
        return 0;
    }
    if(!email_event_sends($event, $row['status'] ?? '')){
        return 0;
    }

    $queued = 0;
    $where = 'resolving recipients';
    try {
        ensure_email_outbox_table($conn);
        $recipients = email_recipients_for($conn, $row, $exceptEmail);
        if(empty($recipients)){
            // Empty is not always a failure: someone assigning a recommendation
            // to themselves is deliberately not mailed (rule 4). Only when
            // there was nobody at all, actor included, has nobody been reached.
            if(trim((string) $exceptEmail) === '' || empty(email_recipients_for($conn, $row, ''))){
                $where = 'recording that nobody could be reached';
                email_record_unreachable($conn, $row, $event);
            }
            return 0;
        }

        $stmt = $conn->prepare("INSERT INTO email_outbox
            (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body)
            VALUES (?,?,?,?,?,?,?)");

        foreach($recipients as $recipient){
            $where = "recipient {$recipient['email']}";
            $message = email_render($row, $event, $recipient);
            $recId = isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null;
            $stmt->bind_param("issssss", $recId, $recipient['email'], $recipient['name'],
                $recipient['office'], $event, $message['subject'], $message['body']);
            $stmt->execute();
            $queued++;
        }
    } catch(Throwable $e){
        error_log("email_notify: recommendation " . ($row['id'] ?? '?') . ", event {$event}, {$where}: "
            . $e->getMessage() . " ({$queued} queued before the failure)");
    }
    return $queued;
}
