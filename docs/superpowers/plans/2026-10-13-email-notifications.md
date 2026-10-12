# Email Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a recommendation is assigned to an office or a named person, or comes back Rejected or Needs Revision, email the people who must act.

**Architecture:** Queue, then send. The web request resolves recipients and writes one `email_outbox` row per person, then returns. A CLI worker run every 5 minutes by Task Scheduler drains the queue through Gmail SMTP, recording `attempts` and `last_error`, and writes an `audit_log` entry when a row finally fails. The in-app inbox is untouched; email is a second channel fired from the same three call sites.

**Tech Stack:** PHP 8.2 (XAMPP), MariaDB, PHPMailer 6.9.1 vendored by hand (no Composer in this project), Gmail SMTP with an app password, Windows Task Scheduler.

**Spec:** [docs/superpowers/specs/2026-10-13-email-notifications-design.md](../specs/2026-10-13-email-notifications-design.md)

## Global Constraints

- No Composer. There is no `composer.json` and no `vendor/`. PHPMailer is committed under `lib/PHPMailer/`.
- `.env` is gitignored and must stay so. The app password never reaches git; `.env.example` carries blank keys only.
- Self-healing schema: every table is created by an `ensure_*()` function using `SHOW COLUMNS` / `CREATE TABLE IF NOT EXISTS` with a `static $done` guard, never a migration file. Never probe a column with `SELECT` — mysqli throws on PHP 8.1+.
- All SQL that takes user or row data uses `prepare()` + `bind_param()`.
- Comments explain *why*, not *what*, matching the surrounding files.
- Files use CRLF line endings, as the rest of the repository does.
- Tests must delete every row they create: this runs against the live `ems_db`.
- `php -l` must pass on every changed PHP file before each commit.

## Review Focus

Five failure modes the spec implies but which no obvious task would exercise. Each has its test placed in the task that owns the code.

1. **An approved account with a blank email address** — 3 accounts already have none. It must be skipped, never queued with an empty `To`, which Gmail rejects for the whole batch. *(Task 2)*
2. **The same person reachable twice** — named individually and also a member of a named office, or listed twice in `in_charge`. One address must receive one email per event. *(Task 2)*
3. **`in_charge` holding a legacy plain string** rather than a JSON array. `in_charge_decode()` tolerates it; recipient resolution must too, not fatal. *(Task 2)*
4. **Gmail refusing the credentials** (wrong app password, revoked access). The row must reach `failed` with `last_error` after `EMAIL_MAX_ATTEMPTS` and stop, not retry forever every 5 minutes. *(Task 6)*
5. **The recommendation deleted between queueing and sending.** The outbox row holds its own rendered subject and body, so the worker must send it without touching `audit_recommendations` and must not fatal on the missing row. *(Task 6)*

---

## File Structure

| File | Responsibility |
|---|---|
| `email_notifications.php` | **Create.** Config, outbox table, recipient resolution, message rendering, queueing. No network. |
| `email_sender.php` | **Create.** The only file that talks to SMTP. Wraps PHPMailer; honours dry-run. |
| `email_worker_lib.php` | **Create.** Draining the queue, retries, the failure trail. No CLI, so tests drive it directly. |
| `email_worker.php` | **Create.** CLI only: `run`, `status`, `retry`. Mirrors `onedrive_worker.php`. |
| `lib/PHPMailer/` | **Create.** Three vendored files. |
| `recommendations_api.php` | **Modify.** Three `email_notify()` calls beside the existing `notify_offices()` calls. |
| `tools/email_worker.bat` | **Create.** What Task Scheduler runs. |
| `tools/install_services.bat` | **Modify.** Register the 5-minute task. |
| `.env.example` | **Modify.** The `EMAIL_*` keys, blank. |
| `EMAIL_SETUP.md` | **Create.** How to get an app password and verify delivery. |
| `tests/_assert.php` | **Create.** Tiny assertion harness — this project has no test framework. |
| `tests/email_*_test.php` | **Create.** One file per task. |

Queueing and sending are separate files because only one of them needs the network: recipient resolution can be tested exhaustively against the real database with no credentials and nothing leaving the machine.

---

### Task 1: Test harness, config, and the outbox table

**Files:**
- Create: `tests/_assert.php`
- Create: `email_notifications.php`
- Test: `tests/email_outbox_test.php`

**Interfaces:**
- Consumes: `env_get()`, `env_bool()` from `env_helper.php`.
- Produces: `email_config()` returning the array below; `ensure_email_outbox_table($conn)`.

```php
// email_config() returns exactly these keys:
['enabled' => bool, 'dry_run' => bool, 'host' => string, 'port' => int,
 'username' => string, 'password' => string, 'from_name' => string,
 'max_attempts' => int, 'batch_size' => int, 'site_url' => string]
```

- [ ] **Step 1: Write the assertion harness**

Create `tests/_assert.php`:

```php
<?php

/**
 * The project has no test framework and no Composer, so this stands in for
 * PHPUnit. Each test file requires it, calls the helpers, then t_summary().
 */

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function t_ok($label, $condition){
    if($condition){
        $GLOBALS['t_pass']++;
        echo "  PASS  {$label}\n";
    } else {
        $GLOBALS['t_fail']++;
        echo "  FAIL  {$label}\n";
    }
}

function t_equals($label, $actual, $expected){
    $same = json_encode($actual) === json_encode($expected);
    t_ok($label, $same);
    if(!$same){
        echo "        expected " . json_encode($expected) . "\n";
        echo "        got      " . json_encode($actual) . "\n";
    }
}

/** Exit non-zero when anything failed, so a batch run stops. */
function t_summary(){
    echo "\n  {$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/email_outbox_test.php`:

```php
<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: config and outbox table\n";

$config = email_config();
t_equals('config has every key', array_keys($config), [
    'enabled', 'dry_run', 'host', 'port', 'username', 'password',
    'from_name', 'max_attempts', 'batch_size', 'site_url',
]);
t_ok('port is an int', is_int($config['port']));
t_ok('max_attempts defaults above zero', $config['max_attempts'] > 0);
t_ok('enabled is a bool', is_bool($config['enabled']));

ensure_email_outbox_table($conn);
ensure_email_outbox_table($conn);   // idempotent: safe on every page load

$columns = [];
$result = mysqli_query($conn, "SHOW COLUMNS FROM email_outbox");
while($row = $result->fetch_assoc()){
    $columns[] = $row['Field'];
}
t_equals('outbox columns', $columns, [
    'id', 'recommendation_id', 'recipient_email', 'recipient_name',
    'recipient_office', 'event', 'subject', 'body', 'status',
    'attempts', 'last_error', 'created_at', 'sent_at',
]);

t_summary();
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"C:/xampp/php/php.exe" tests/email_outbox_test.php`
Expected: FAIL — `Failed opening required '.../email_notifications.php'`

- [ ] **Step 4: Write the minimal implementation**

Create `email_notifications.php`:

```php
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
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `"C:/xampp/php/php.exe" tests/email_outbox_test.php`
Expected: PASS on all six assertions.

- [ ] **Step 6: Lint and commit**

```bash
"C:/xampp/php/php.exe" -l email_notifications.php
git add tests/_assert.php tests/email_outbox_test.php email_notifications.php
git commit -m "Add the email outbox table and its configuration"
```

---

### Task 2: Recipient resolution

The substance of the feature. Everything in Review Focus 1–3 is tested here.

**Files:**
- Modify: `email_notifications.php`
- Test: `tests/email_recipients_test.php`

**Interfaces:**
- Consumes: `in_charge_decode($raw)`, `in_charge_staff_by_office($conn)` from `in_charge.php`; `notification_recipients($conn, $row)` from `notifications.php`; `USER_STATUS_APPROVED` from `user_columns.php`.
- Produces:

```php
// email_recipients_for($conn, array $row, string $exceptEmail = ''): array
// $row needs 'office' and 'in_charge'. Returns, de-duplicated by email:
[['email' => string, 'name' => string, 'office' => string], ...]
```

- [ ] **Step 1: Write the failing test**

Create `tests/email_recipients_test.php`:

```php
<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: recipient resolution\n";

/** Two throwaway accounts in one office, so the test owns its data. */
function t_make_account($conn, $username, $fullName, $office, $email){
    $stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                            VALUES (?,?,?,'','user',?,?,?,'office')");
    $hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
    $status = USER_STATUS_APPROVED;
    $stmt->bind_param("sssssss", $username, $hash, $email, $office, $fullName, $status);
    $stmt->execute();
}

$office = 'ZZ Test Office';
t_make_account($conn, 'zz_alice', 'Alice Tester', $office, 'alice@example.invalid');
t_make_account($conn, 'zz_bob',   'Bob Tester',   $office, 'bob@example.invalid');
t_make_account($conn, 'zz_noma',  'Noma Tester',  $office, '');   // blank address

// A named person wins over their whole office.
$people = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode(['Alice Tester'])]);
t_equals('named person only', array_column($people, 'email'), ['alice@example.invalid']);

// Nobody named -> the whole office, minus the account with no address.
$all = email_recipients_for($conn, ['office' => $office, 'in_charge' => '[]']);
$addresses = array_column($all, 'email');
sort($addresses);
t_equals('office fallback reaches both addressable accounts',
    $addresses, ['alice@example.invalid', 'bob@example.invalid']);

// Review Focus 1: an account with no address is never queued.
t_ok('no empty recipient address', !in_array('', $addresses, true));

// Review Focus 2: named individually and via their office -> one email.
$both = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode([$office, 'Alice Tester'])]);
t_equals('named person de-duplicated against their office',
    array_column($both, 'email'), ['alice@example.invalid']);

// A name nobody holds must not swallow the assignment (the spec's trap).
$typed = email_recipients_for($conn, ['office' => $office, 'in_charge' => json_encode(['Maria Grace Andal'])]);
t_ok('unresolvable name falls back to the office', count($typed) === 2);

// Review Focus 3: a legacy plain string, not a JSON array.
$legacy = email_recipients_for($conn, ['office' => $office, 'in_charge' => 'Alice Tester']);
t_equals('legacy plain-string in_charge still resolves',
    array_column($legacy, 'email'), ['alice@example.invalid']);

// The person who caused the change is not told about their own action.
$except = email_recipients_for($conn, ['office' => $office, 'in_charge' => '[]'], 'alice@example.invalid');
t_equals('actor excluded', array_column($except, 'email'), ['bob@example.invalid']);

mysqli_query($conn, "DELETE FROM users WHERE username IN ('zz_alice','zz_bob','zz_noma')");
t_summary();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"C:/xampp/php/php.exe" tests/email_recipients_test.php`
Expected: FAIL — `Call to undefined function email_recipients_for()`

- [ ] **Step 3: Write the implementation**

Append to `email_notifications.php`:

```php
/** Approved accounts that have an address, keyed by full name. */
function email_accounts_by_name($conn){
    static $byName = null;
    if($byName !== null){
        return $byName;
    }

    ensure_user_account_columns($conn);
    $byName = [];
    $sql = "SELECT username, full_name, office, email FROM users
             WHERE status = '" . USER_STATUS_APPROVED . "'
               AND email <> '' AND office <> '' AND office <> 'Admin'";
    $result = mysqli_query($conn, $sql);
    if($result){
        while($row = $result->fetch_assoc()){
            $name = trim((string) $row['full_name']) !== ''
                ? trim($row['full_name'])
                : trim((string) $row['username']);
            if($name !== ''){
                $byName[$name] = $row;
            }
        }
    }
    return $byName;
}

/** Approved accounts of one office that have an address. */
function email_accounts_in_office($conn, $office){
    $found = [];
    foreach(email_accounts_by_name($conn) as $name => $row){
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
 * A name only counts as "named" when it resolves to an approved account with
 * an address. Six current assignments are typed-in names nobody holds --
 * "Leo", "Borbon" -- and read literally the rule would email nobody and never
 * fall back to the office, so the alert would vanish silently.
 */
function email_recipients_for($conn, array $row, $exceptEmail = ''){
    $named = [];
    $accounts = email_accounts_by_name($conn);

    foreach(in_charge_decode($row['in_charge'] ?? '') as $entry){
        if(isset($accounts[$entry])){
            $named[] = $accounts[$entry];
        }
    }

    $chosen = !empty($named) ? $named : [];
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `"C:/xampp/php/php.exe" tests/email_recipients_test.php`
Expected: PASS on all eight assertions, and the three `zz_*` accounts gone afterwards.

- [ ] **Step 5: Confirm the test cleaned up**

Run: `"C:/xampp/mysql/bin/mysql.exe" -u root ems_db -e "SELECT COUNT(*) FROM users WHERE username LIKE 'zz_%'"`
Expected: `0`

- [ ] **Step 6: Lint and commit**

```bash
"C:/xampp/php/php.exe" -l email_notifications.php
git add email_notifications.php tests/email_recipients_test.php
git commit -m "Resolve who an assignment emails, named people first"
```

---

### Task 3: Rendering and queueing

**Files:**
- Modify: `email_notifications.php`
- Test: `tests/email_queue_test.php`

**Interfaces:**
- Consumes: `email_recipients_for()`, `ensure_email_outbox_table()`, `email_config()` from Tasks 1–2.
- Produces:

```php
// EMAIL_EVENTS: const array of the events that email, mapping to a subject verb.
// email_notify($conn, array $row, string $event, string $exceptEmail = ''): int
//   $row needs 'id', 'office', 'recommendation', 'in_charge', and for a
//   status event 'status'. Returns how many rows were queued.
```

- [ ] **Step 1: Write the failing test**

Create `tests/email_queue_test.php`:

```php
<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

echo "email_notifications: rendering and queueing\n";

$stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                        VALUES ('zz_carol',?, 'carol@example.invalid','','user','ZZ Test Office','Carol Tester',?,'office')");
$hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
$status = USER_STATUS_APPROVED;
$stmt->bind_param("ss", $hash, $status);
$stmt->execute();

$row = [
    'id' => 999001,
    'office' => 'ZZ Test Office',
    'recommendation' => 'Submit the updated inventory',
    'in_charge' => json_encode(['Carol Tester']),
    'status' => 'Needs Revision',
];

$queued = email_notify($conn, $row, NOTIFY_ASSIGNED);
t_equals('one row queued', $queued, 1);

$out = mysqli_query($conn, "SELECT * FROM email_outbox WHERE recommendation_id = 999001")->fetch_assoc();
t_equals('addressed to the named person', $out['recipient_email'], 'carol@example.invalid');
t_equals('queued pending', $out['status'], 'pending');
t_equals('no attempts yet', (int) $out['attempts'], 0);
t_ok('subject names the office', strpos($out['subject'], 'ZZ Test Office') !== false);
t_ok('body carries the recommendation', strpos($out['body'], 'Submit the updated inventory') !== false);
t_ok('body links to the site', strpos($out['body'], 'https://') !== false);

// The spec forbids leaking one office's submission to another.
t_ok('body has no remarks or document names',
    stripos($out['body'], 'remark') === false && stripos($out['body'], '.pdf') === false);

// Events that must not email.
mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999001");
t_equals('feedback does not email', email_notify($conn, $row, NOTIFY_FEEDBACK), 0);
$approved = array_merge($row, ['status' => 'Approved']);
t_equals('approved does not email', email_notify($conn, $approved, NOTIFY_STATUS), 0);
$revision = array_merge($row, ['status' => 'Needs Revision']);
t_equals('needs revision does email', email_notify($conn, $revision, NOTIFY_STATUS), 1);

mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999001");
mysqli_query($conn, "DELETE FROM users WHERE username = 'zz_carol'");
t_summary();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"C:/xampp/php/php.exe" tests/email_queue_test.php`
Expected: FAIL — `Call to undefined function email_notify()`

- [ ] **Step 3: Write the implementation**

Append to `email_notifications.php`:

```php
/** Statuses that mean the office has to do something about it. */
const EMAIL_ACTION_STATUSES = ['Rejected', 'Needs Revision'];

/**
 * Whether this event emails at all. Assignments always do; a status change
 * only when it hands work back. Approved, Completed and feedback-only stay
 * in the inbox, or the mail becomes noise people filter.
 */
function email_event_sends($event, $status = ''){
    if($event === NOTIFY_NEW || $event === NOTIFY_ASSIGNED){
        return true;
    }
    if($event === NOTIFY_STATUS){
        return in_array(trim((string) $status), EMAIL_ACTION_STATUSES, true);
    }
    return false;
}

/**
 * Subject and body, rendered now rather than when the mail is sent: it should
 * describe the assignment as it was, the same reasoning notifications.php
 * records for its stored text.
 *
 * Deliberately absent: compliance responses, review remarks and document
 * names. On a recommendation shared between offices those would send one
 * office's submission to another.
 */
function email_render($row, $event, $recipient){
    $config = email_config();
    $office = trim((string) ($row['office'] ?? ''));
    $text = trim((string) ($row['recommendation'] ?? ''));
    $status = trim((string) ($row['status'] ?? ''));

    if($event === NOTIFY_STATUS){
        $subject = "{$status}: a {$office} recommendation needs your attention";
        $lead = "A recommendation you are responsible for is now marked {$status}.";
    } elseif($event === NOTIFY_ASSIGNED){
        $subject = "You have been put in charge of a {$office} recommendation";
        $lead = "You have been put in charge of the recommendation below.";
    } else {
        $subject = "New recommendation for {$office}";
        $lead = "A new recommendation has been filed for your office.";
    }

    $name = trim((string) $recipient['name']);
    $body = "Hello {$name},\n\n{$lead}\n\n"
          . "Office: {$office}\n"
          . "Recommendation: " . ($text !== '' ? $text : '(not written out yet)') . "\n\n"
          . "Open the dashboard to respond:\n{$config['site_url']}/\n\n"
          . "--\nSBC Quality Assurance\nThis is an automated message; replies are not read.";

    return ['subject' => mb_substr($subject, 0, 200), 'body' => $body];
}

/** Queue one message per recipient. Returns how many rows were written. */
function email_notify($conn, array $row, $event, $exceptEmail = ''){
    $config = email_config();
    if(!$config['enabled'] && !$config['dry_run']){
        // Off entirely: queueing would pile up rows nothing ever drains.
        return 0;
    }
    if(!email_event_sends($event, $row['status'] ?? '')){
        return 0;
    }

    ensure_email_outbox_table($conn);
    $recipients = email_recipients_for($conn, $row, $exceptEmail);
    if(empty($recipients)){
        return 0;
    }

    $stmt = $conn->prepare("INSERT INTO email_outbox
        (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body)
        VALUES (?,?,?,?,?,?,?)");

    $queued = 0;
    foreach($recipients as $recipient){
        $message = email_render($row, $event, $recipient);
        $recId = isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null;
        $stmt->bind_param("issssss", $recId, $recipient['email'], $recipient['name'],
            $recipient['office'], $event, $message['subject'], $message['body']);
        $stmt->execute();
        $queued++;
    }
    return $queued;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `"C:/xampp/php/php.exe" tests/email_queue_test.php`
Expected: PASS on all eleven assertions.

- [ ] **Step 5: Lint and commit**

```bash
"C:/xampp/php/php.exe" -l email_notifications.php
git add email_notifications.php tests/email_queue_test.php
git commit -m "Render and queue assignment emails"
```

---

### Task 4: Wire the three triggers

**Files:**
- Modify: `recommendations_api.php` (require at the top; three call sites)
- Test: `tests/email_triggers_test.php`

**Interfaces:**
- Consumes: `email_notify()` from Task 3.
- Produces: no new functions. After this task an assignment made through the API queues mail.

- [ ] **Step 1: Write the failing test**

Create `tests/email_triggers_test.php`. It drives the real API the way the browser does, with an admin session:

```php
<?php
require_once __DIR__ . "/_assert.php";

echo "recommendations_api: the three email triggers\n";

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_start();
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_role_slug'] = 'qa_head';

require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";

$stmt = $conn->prepare("INSERT INTO users (username,password,email,phone,role,office,full_name,status,role_slug)
                        VALUES ('zz_dave',?, 'dave@example.invalid','','user','CSSAO','Dave Tester',?,'office')");
$hash = password_hash('TempTest!2026', PASSWORD_DEFAULT);
$status = USER_STATUS_APPROVED;
$stmt->bind_param("ss", $hash, $status);
$stmt->execute();

/** Run one API action in a child process, as the browser would. */
function t_api($post){
    $script = __DIR__ . "/_api_call.php";
    $cmd = '"C:/xampp/php/php.exe" ' . escapeshellarg($script) . ' ' . escapeshellarg(http_build_query($post));
    return shell_exec($cmd);
}

mysqli_query($conn, "INSERT INTO audit_recommendations (id, audit_type, office, recommendation, year, status)
                     VALUES (999002,'Internal','CSSAO','', '2026','Pending')");

// Writing the text for the first time is the moment it becomes real.
t_api(['action' => 'save_office_recommendation', 'id' => 999002,
       'recommendation' => 'Submit the updated inventory', 'status' => 'Pending',
       'year' => '2026', 'in_charge' => '[]']);
$count = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id=999002")->fetch_assoc()['c'];
t_ok('new recommendation queued mail', (int) $count > 0);

mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999002");

// Putting a person in charge emails that person.
t_api(['action' => 'save_in_charge', 'id' => 999002, 'in_charge' => json_encode(['Dave Tester'])]);
$to = mysqli_query($conn, "SELECT recipient_email FROM email_outbox WHERE recommendation_id=999002")->fetch_assoc();
t_equals('assignment emailed the named person', $to['recipient_email'] ?? '', 'dave@example.invalid');

mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999002");

// A review that hands work back emails; one that approves does not.
t_api(['action' => 'review_recommendation', 'id' => 999002, 'decision' => 'needs_revision', 'review_remarks' => 'Attach the signed copy.']);
$c1 = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id=999002")->fetch_assoc()['c'];
t_ok('needs revision queued mail', (int) $c1 > 0);

mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999002");
t_api(['action' => 'review_recommendation', 'id' => 999002, 'decision' => 'approve', 'review_remarks' => 'Received.']);
$c2 = mysqli_query($conn, "SELECT COUNT(*) c FROM email_outbox WHERE recommendation_id=999002")->fetch_assoc()['c'];
t_equals('approval queued nothing', (int) $c2, 0);

mysqli_query($conn, "DELETE FROM email_outbox WHERE recommendation_id = 999002");
mysqli_query($conn, "DELETE FROM audit_recommendations WHERE id = 999002");
mysqli_query($conn, "DELETE FROM notifications WHERE recommendation_id = 999002");
mysqli_query($conn, "DELETE FROM audit_log WHERE recommendation_id = 999002");
mysqli_query($conn, "DELETE FROM users WHERE username = 'zz_dave'");
t_summary();
```

Also create `tests/_api_call.php`, the child process the test shells out to:

```php
<?php
/** Runs one recommendations_api.php action under an admin session. */
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
session_start();
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_role_slug'] = 'qa_head';
parse_str($argv[1] ?? '', $_POST);
include __DIR__ . "/../recommendations_api.php";
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"C:/xampp/php/php.exe" tests/email_triggers_test.php`
Expected: FAIL — "new recommendation queued mail" and the others, because nothing calls `email_notify()` yet.

- [ ] **Step 3: Require the helper**

In `recommendations_api.php`, after `require_once __DIR__ . "/notifications.php";` add:

```php
require_once __DIR__ . "/email_notifications.php";
```

- [ ] **Step 4: Wire the assignment trigger**

In the `save_in_charge` handler, immediately after the existing `notify_offices($conn, $newlyAssigned, NOTIFY_ASSIGNED, ...)` call, add:

```php
        // Email the people now responsible. Recipients differ from the
        // in-app rule -- a named person takes precedence over their office.
        email_notify($conn, [
            'id'             => $id,
            'office'         => $row['office'],
            'recommendation' => $recText,
            'in_charge'      => $inCharge,
        ], NOTIFY_ASSIGNED);
```

- [ ] **Step 5: Wire the new-recommendation and status triggers**

In the `save_office_recommendation` handler, immediately after the `if($wasBlank && ...) { ... } elseif(...) { ... }` block that calls `notify_offices()`, add:

```php
        $emailRow = [
            'id'             => $id,
            'office'         => $beforeRow['office'],
            'recommendation' => $recommendation,
            'in_charge'      => $inCharge,
            'status'         => $status,
        ];
        if($wasBlank && $recommendation !== ''){
            email_notify($conn, $emailRow, NOTIFY_NEW);
        } elseif($beforeRow['status'] !== $status){
            email_notify($conn, $emailRow, NOTIFY_STATUS);
        }
```

- [ ] **Step 6: Wire the review trigger**

In the `review_recommendation` handler, immediately after the `if($beforeRow['status'] !== $newStatus) { ... } elseif($reviewRemarks !== '') { ... }` block, add:

```php
    if($beforeRow['status'] !== $newStatus){
        email_notify($conn, [
            'id'             => $id,
            'office'         => $beforeRow['office'],
            'recommendation' => $beforeRow['recommendation'],
            'in_charge'      => $beforeRow['in_charge'],
            'status'         => $newStatus,
        ], NOTIFY_STATUS);
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `"C:/xampp/php/php.exe" tests/email_triggers_test.php`
Expected: PASS on all five assertions, and no `999002` rows left anywhere.

- [ ] **Step 8: Confirm the live grid still works**

Run:
```bash
curl -s -o /dev/null -H "Host: sbc-quality-assurance.com" -w "%{http_code}\n" http://127.0.0.1/home.php
```
Expected: `302` (redirect to the login form — the page still parses and runs).

- [ ] **Step 9: Lint and commit**

```bash
"C:/xampp/php/php.exe" -l recommendations_api.php
git add recommendations_api.php tests/email_triggers_test.php tests/_api_call.php
git commit -m "Queue email from the three assignment events"
```

---

### Task 5: The sender

**Files:**
- Create: `lib/PHPMailer/PHPMailer.php`, `lib/PHPMailer/SMTP.php`, `lib/PHPMailer/Exception.php`
- Create: `email_sender.php`
- Test: `tests/email_sender_test.php`

**Interfaces:**
- Consumes: `email_config()` from Task 1.
- Produces:

```php
// email_send(array $config, string $to, string $toName, string $subject, string $body): array
// Returns ['ok' => bool, 'error' => string]. Never throws.
```

- [ ] **Step 1: Vendor PHPMailer**

```bash
mkdir -p lib/PHPMailer
curl -L -o lib/PHPMailer/PHPMailer.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/PHPMailer.php
curl -L -o lib/PHPMailer/SMTP.php      https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/SMTP.php
curl -L -o lib/PHPMailer/Exception.php https://raw.githubusercontent.com/PHPMailer/PHPMailer/v6.9.1/src/Exception.php
"C:/xampp/php/php.exe" -l lib/PHPMailer/PHPMailer.php
```

Expected: `No syntax errors detected` for all three.

- [ ] **Step 2: Write the failing test**

Create `tests/email_sender_test.php`:

```php
<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../email_sender.php";

echo "email_sender\n";

// Dry run must report success without touching the network.
$dry = ['enabled' => true, 'dry_run' => true, 'host' => 'smtp.gmail.com', 'port' => 587,
        'username' => '', 'password' => '', 'from_name' => 'SBC Quality Assurance',
        'max_attempts' => 5, 'batch_size' => 20, 'site_url' => 'https://example.invalid'];
$result = email_send($dry, 'nobody@example.invalid', 'Nobody', 'Subject', 'Body');
t_equals('dry run succeeds', $result['ok'], true);
t_equals('dry run reports no error', $result['error'], '');

// Missing credentials must fail clearly rather than hang on a connection.
$live = array_merge($dry, ['dry_run' => false]);
$missing = email_send($live, 'nobody@example.invalid', 'Nobody', 'Subject', 'Body');
t_equals('no credentials fails', $missing['ok'], false);
t_ok('and says why', stripos($missing['error'], 'credential') !== false);

t_summary();
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"C:/xampp/php/php.exe" tests/email_sender_test.php`
Expected: FAIL — `Failed opening required '.../email_sender.php'`

- [ ] **Step 4: Write the implementation**

Create `email_sender.php`:

```php
<?php

require_once __DIR__ . "/lib/PHPMailer/Exception.php";
require_once __DIR__ . "/lib/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/lib/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * The only place that talks to SMTP.
 *
 * Always returns a result rather than throwing: the worker has to record the
 * reason against the row and carry on with the rest of the batch, not die
 * partway through and leave the queue half-drained.
 */
function email_send(array $config, $to, $toName, $subject, $body){
    if(!empty($config['dry_run'])){
        // Exercises everything but delivery -- the ONEDRIVE_DRY_RUN trick.
        return ['ok' => true, 'error' => ''];
    }

    if(trim((string) $config['username']) === '' || trim((string) $config['password']) === ''){
        return ['ok' => false, 'error' => 'No SMTP credentials: set EMAIL_USERNAME and EMAIL_APP_PASSWORD in .env'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->Port       = (int) $config['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 20;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($config['username'], $config['from_name']);
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch(Exception $e){
        return ['ok' => false, 'error' => $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()];
    } catch(Throwable $e){
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `"C:/xampp/php/php.exe" tests/email_sender_test.php`
Expected: PASS on all four assertions.

- [ ] **Step 6: Lint and commit**

```bash
"C:/xampp/php/php.exe" -l email_sender.php
git add lib/PHPMailer email_sender.php tests/email_sender_test.php
git commit -m "Add the SMTP sender, with PHPMailer vendored"
```

---

### Task 6: The worker

Review Focus 4 and 5 are tested here.

**Files:**
- Create: `email_worker_lib.php` (the logic)
- Create: `email_worker.php` (the CLI around it)
- Test: `tests/email_worker_test.php`

**Interfaces:**
- Consumes: `email_config()`, `ensure_email_outbox_table()` (Task 1); `email_send()` (Task 5); `log_audit_event()` from `audit_log_helper.php`.
- Produces:

```php
// email_process_pending($conn, array $config): array  ['sent' => int, 'failed' => int]
// Used by the CLI; separated so the test drives it without shelling out.
```

- [ ] **Step 1: Write the failing test**

Create `tests/email_worker_test.php`:

```php
<?php
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../database.php";
require_once __DIR__ . "/../email_notifications.php";
require_once __DIR__ . "/../email_worker_lib.php";

echo "email_worker\n";

ensure_email_outbox_table($conn);
mysqli_query($conn, "DELETE FROM email_outbox WHERE recipient_email = 'zz@example.invalid'");

function t_queue_row($conn){
    mysqli_query($conn, "INSERT INTO email_outbox
        (recommendation_id, recipient_email, recipient_name, recipient_office, event, subject, body)
        VALUES (999003,'zz@example.invalid','ZZ','ZZ Test Office','recommendation_new','S','B')");
    return $conn->insert_id;
}

// A dry run drains the queue and marks the row sent.
$id = t_queue_row($conn);
$dry = array_merge(email_config(), ['dry_run' => true, 'enabled' => true, 'batch_size' => 10]);
$result = email_process_pending($conn, $dry);
t_equals('dry run sent one', $result['sent'], 1);
$row = mysqli_query($conn, "SELECT * FROM email_outbox WHERE id = {$id}")->fetch_assoc();
t_equals('marked sent', $row['status'], 'sent');
t_ok('sent_at stamped', !empty($row['sent_at']));

// Review Focus 4: bad credentials must stop at the cap, not retry forever.
mysqli_query($conn, "DELETE FROM email_outbox WHERE recipient_email = 'zz@example.invalid'");
$id = t_queue_row($conn);
$broken = array_merge(email_config(), ['dry_run' => false, 'enabled' => true,
                                       'username' => '', 'password' => '', 'max_attempts' => 2, 'batch_size' => 10]);
email_process_pending($conn, $broken);
$row = mysqli_query($conn, "SELECT * FROM email_outbox WHERE id = {$id}")->fetch_assoc();
t_equals('first failure keeps it pending', $row['status'], 'pending');
t_equals('attempts counted', (int) $row['attempts'], 1);
t_ok('error recorded', trim((string) $row['last_error']) !== '');

email_process_pending($conn, $broken);
$row = mysqli_query($conn, "SELECT * FROM email_outbox WHERE id = {$id}")->fetch_assoc();
t_equals('gives up at the cap', $row['status'], 'failed');

$logged = mysqli_query($conn, "SELECT COUNT(*) c FROM audit_log WHERE action = 'email_failed' AND recommendation_id = 999003")->fetch_assoc()['c'];
t_ok('permanent failure reaches the Activity Log', (int) $logged > 0);

// A failed row is not retried until retry resets it.
$before = email_process_pending($conn, $broken);
t_equals('failed rows are left alone', $before['sent'] + $before['failed'], 0);
email_reset_failed($conn);
$row = mysqli_query($conn, "SELECT * FROM email_outbox WHERE id = {$id}")->fetch_assoc();
t_equals('retry puts it back', $row['status'], 'pending');
t_equals('retry clears attempts', (int) $row['attempts'], 0);

// Review Focus 5: the recommendation is gone; the row still sends from its
// own stored subject and body.
mysqli_query($conn, "DELETE FROM audit_recommendations WHERE id = 999003");
$result = email_process_pending($conn, $dry);
t_equals('sends without the recommendation row', $result['sent'], 1);

mysqli_query($conn, "DELETE FROM email_outbox WHERE recipient_email = 'zz@example.invalid'");
mysqli_query($conn, "DELETE FROM audit_log WHERE recommendation_id = 999003");
t_summary();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"C:/xampp/php/php.exe" tests/email_worker_test.php`
Expected: FAIL — `Failed opening required '.../email_worker_lib.php'`

- [ ] **Step 3: Write the worker library**

Create `email_worker_lib.php` — the logic, separated from the CLI so the test drives it directly:

```php
<?php

require_once __DIR__ . "/email_notifications.php";
require_once __DIR__ . "/email_sender.php";
require_once __DIR__ . "/audit_log_helper.php";

/**
 * Send what is queued.
 *
 * A row keeps its own subject and body, so a recommendation deleted since
 * queueing changes nothing here -- nothing in this function reads
 * audit_recommendations.
 */
function email_process_pending($conn, array $config){
    ensure_email_outbox_table($conn);

    $limit = max(1, (int) $config['batch_size']);
    $rows = [];
    $result = mysqli_query($conn, "SELECT * FROM email_outbox WHERE status = 'pending'
                                   ORDER BY id ASC LIMIT {$limit}");
    if($result){
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    }

    $sent = 0;
    $failed = 0;
    foreach($rows as $row){
        $outcome = email_send($config, $row['recipient_email'], $row['recipient_name'],
                              $row['subject'], $row['body']);

        if($outcome['ok']){
            $update = $conn->prepare("UPDATE email_outbox SET status='sent', sent_at=NOW(), last_error=NULL WHERE id = ?");
            $update->bind_param("i", $row['id']);
            $update->execute();
            $sent++;
            continue;
        }

        $attempts = (int) $row['attempts'] + 1;
        $giveUp = $attempts >= max(1, (int) $config['max_attempts']);
        $status = $giveUp ? 'failed' : 'pending';

        $update = $conn->prepare("UPDATE email_outbox SET status=?, attempts=?, last_error=? WHERE id = ?");
        $update->bind_param("sisi", $status, $attempts, $outcome['error'], $row['id']);
        $update->execute();

        if($giveUp){
            $failed++;
            // Into the Activity Log administrators already read, rather than
            // a screen nobody opens. Silent non-delivery is the whole risk.
            log_audit_event($conn, 'system', 'admin', $row['recipient_office'],
                'email_failed', 'email', (int) $row['id'],
                "Could not email {$row['recipient_email']} after {$attempts} attempts: {$outcome['error']}",
                (int) $row['recommendation_id']);
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/** Put failed rows back in the queue, as od_cmd_run does for uploads. */
function email_reset_failed($conn){
    ensure_email_outbox_table($conn);
    mysqli_query($conn, "UPDATE email_outbox SET status='pending', attempts=0, last_error=NULL WHERE status='failed'");
    return mysqli_affected_rows($conn);
}

/** Counts by state, and the failures worth looking at. */
function email_queue_status($conn){
    ensure_email_outbox_table($conn);

    $counts = [];
    $result = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM email_outbox GROUP BY status");
    while($result && $row = $result->fetch_assoc()){
        $counts[$row['status']] = (int) $row['total'];
    }

    $errors = [];
    $result = mysqli_query($conn, "SELECT id, recipient_email, attempts, last_error FROM email_outbox
                                   WHERE status='failed' ORDER BY id DESC LIMIT 10");
    while($result && $row = $result->fetch_assoc()){
        $errors[] = $row;
    }

    return ['counts' => $counts, 'errors' => $errors];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `"C:/xampp/php/php.exe" tests/email_worker_test.php`
Expected: PASS on all twelve assertions.

- [ ] **Step 5: Write the CLI**

Create `email_worker.php`, mirroring `onedrive_worker.php`:

```php
<?php

/**
 * CLI worker for the email outbox.
 *
 *   php email_worker.php status   Counts by state and recent failures
 *   php email_worker.php run      Send everything still pending
 *   php email_worker.php retry    Put failed rows back in the queue, then run
 *
 * Task Scheduler runs this every 5 minutes -- see EMAIL_SETUP.md. That is
 * what makes an offline PC delay mail rather than lose it.
 */

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . "/database.php";
require_once __DIR__ . "/email_worker_lib.php";

$command = $argv[1] ?? 'status';
$config = email_config();

switch($command){
    case 'status':
        $state = email_queue_status($conn);
        echo "Email outbox\n";
        foreach(['pending', 'sent', 'failed'] as $key){
            printf("  %-8s %d\n", $key, $state['counts'][$key] ?? 0);
        }
        if(!empty($state['errors'])){
            echo "\nRecent failures\n";
            foreach($state['errors'] as $row){
                printf("  #%-5d %-34s attempts %d: %s\n",
                    $row['id'], $row['recipient_email'], $row['attempts'], $row['last_error']);
            }
        }
        break;

    case 'run':
        if(!$config['enabled'] && !$config['dry_run']){
            exit("EMAIL_ENABLED is false and EMAIL_DRY_RUN is false; nothing to do.\n");
        }
        $result = email_process_pending($conn, $config);
        printf("sent %d, failed %d%s\n", $result['sent'], $result['failed'],
            $config['dry_run'] ? ' (dry run: nothing left the machine)' : '');
        break;

    case 'retry':
        printf("%d failed row(s) put back in the queue\n", email_reset_failed($conn));
        $result = email_process_pending($conn, $config);
        printf("sent %d, failed %d\n", $result['sent'], $result['failed']);
        break;

    default:
        exit("Unknown command '{$command}'. Use status, run or retry.\n");
}
```

- [ ] **Step 6: Exercise the CLI**

Run: `"C:/xampp/php/php.exe" email_worker.php status`
Expected: the three counts print, with no PHP warnings.

- [ ] **Step 7: Lint and commit**

```bash
"C:/xampp/php/php.exe" -l email_worker_lib.php
"C:/xampp/php/php.exe" -l email_worker.php
git add email_worker_lib.php email_worker.php tests/email_worker_test.php
git commit -m "Add the email worker, with retries and a failure trail"
```

---

### Task 7: Scheduling, configuration and documentation

**Files:**
- Create: `tools/email_worker.bat`
- Modify: `tools/install_services.bat`
- Modify: `.env.example`
- Create: `EMAIL_SETUP.md`

**Interfaces:**
- Consumes: `email_worker.php` from Task 6.
- Produces: nothing other code calls. This is what makes it run unattended.

- [ ] **Step 1: Add the `EMAIL_*` keys to `.env.example`**

Append to `.env.example`:

```
# ---------------------------------------------------------------------------
# Email notifications
#
# Assignments and "needs your attention" reviews are emailed to whoever has
# to act. See EMAIL_SETUP.md for getting a Gmail app password.
# ---------------------------------------------------------------------------

# Master switch. Leave false until the app password is in place.
EMAIL_ENABLED=false

# Queue and log without sending. Useful for testing the triggers before
# credentials exist, exactly as ONEDRIVE_DRY_RUN is.
EMAIL_DRY_RUN=true

EMAIL_SMTP_HOST=smtp.gmail.com
EMAIL_SMTP_PORT=587

# The Gmail address, and an app password -- not the account password.
EMAIL_USERNAME=
EMAIL_APP_PASSWORD=

# Shown as the sender's name; the address is EMAIL_USERNAME.
EMAIL_FROM_NAME=SBC Quality Assurance

# Where the "open the dashboard" link points.
EMAIL_SITE_URL=https://sbc-quality-assurance.com

# Give up after this many failed attempts, then show in `email_worker.php status`.
EMAIL_MAX_ATTEMPTS=5

# How many messages one worker run sends.
EMAIL_BATCH_SIZE=20
```

- [ ] **Step 2: Write the batch file**

Create `tools/email_worker.bat`:

```bat
@echo off
REM Drains the email outbox. Registered by install_services.bat to run every
REM 5 minutes, so an offline PC delays mail rather than losing it.

set "PROJECT=%~dp0.."
"C:\xampp\php\php.exe" "%PROJECT%\email_worker.php" run >> "%PROJECT%\qa-email.log" 2>&1
```

- [ ] **Step 3: Keep the log out of git**

Append to `.gitignore`:

```
/qa-email.log
```

- [ ] **Step 4: Register the scheduled task**

In `tools/install_services.bat`, immediately after the existing
`schtasks /Create /TN "QA System - Health Check" ...` line, add:

```bat
schtasks /Create /TN "QA System - Email Worker" /TR "\"%PROJECT%\tools\email_worker.bat\"" /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F >nul 2>&1
```

- [ ] **Step 5: Verify the batch file runs**

Run: `cmd /c "tools\email_worker.bat" && type qa-email.log`
Expected: the log ends with a `sent 0, failed 0 (dry run: ...)` line.

- [ ] **Step 6: Write the setup guide**

Create `EMAIL_SETUP.md`, covering in this order: turning on 2-Step Verification for the Gmail account; generating an app password at `myaccount.google.com/apppasswords`; filling `EMAIL_USERNAME` and `EMAIL_APP_PASSWORD` in `.env`; leaving `EMAIL_DRY_RUN=true` and running `php email_worker.php run` to confirm the queue drains; setting `EMAIL_DRY_RUN=false` and `EMAIL_ENABLED=true`; checking `php email_worker.php status`; and what to do when rows reach `failed` (`php email_worker.php retry`). Note Gmail's ~500 messages/day limit, and that the sender address is a `gmail.com` one until IT provides a school mailbox — at which point only the `EMAIL_*` values change.

- [ ] **Step 7: Run every test together**

```bash
for t in tests/email_*_test.php; do "C:/xampp/php/php.exe" "$t" || break; done
```
Expected: every file reports `0 failed`.

- [ ] **Step 8: Commit**

```bash
git add tools/email_worker.bat tools/install_services.bat .env.example .gitignore EMAIL_SETUP.md
git commit -m "Schedule the email worker and document the setup"
```

---

## Verification after the last task

- [ ] `git status --short` is clean.
- [ ] Every test file passes: `for t in tests/email_*_test.php; do "C:/xampp/php/php.exe" "$t"; done`
- [ ] No test data survives:
  `SELECT COUNT(*) FROM users WHERE username LIKE 'zz_%'` → 0;
  `SELECT COUNT(*) FROM email_outbox WHERE recipient_email LIKE '%example.invalid'` → 0;
  `SELECT COUNT(*) FROM audit_recommendations WHERE id BETWEEN 999001 AND 999003` → 0.
- [ ] The live site still serves: `curl -s -o /dev/null -w "%{http_code}" -H "Host: sbc-quality-assurance.com" http://127.0.0.1/` → `200`.
- [ ] One real send, done by hand with `EMAIL_DRY_RUN=false` and a real address, confirmed received.
