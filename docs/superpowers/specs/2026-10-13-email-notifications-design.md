# Email notifications for assigned recommendations

**Date:** 2026-10-13
**Branch:** `feature/add-email-notification`

## Why

Offices do not sign in often enough to see the in-app inbox. A recommendation
can be assigned to an office or to a named person and sit unread for days,
because nothing reaches the person outside the system.

This adds email as a second channel for the events that mean *somebody has to
do something*. The in-app inbox and Activity Board are unchanged; email rides
alongside them.

### Success

A person named in charge of a recommendation, or a member of an office
assigned one, receives an email within minutes telling them they have a task
and where to find it — and if that email cannot be delivered, an administrator
can see that it failed rather than assuming it arrived.

### Constraints

- The server is a home PC behind Cloudflare Tunnel. It is intermittently
  offline: the tunnel was down twice in three weeks, OneDrive stopped
  silently, and a Windows service reported "Running" while doing nothing.
  **Delivery must survive the machine being unavailable.**
- No mail capability exists anywhere in the codebase today.
- No Composer: there is no `composer.json` and no `vendor/`.
- `openssl` is available (SMTP over TLS works). The `sockets` extension is
  absent, which does not matter — PHPMailer uses core stream functions.

### Decisions taken

| | |
|---|---|
| Transport | Gmail account with an app password |
| Recipients | Named people take precedence; the office is the fallback |
| Events | Assignments, plus Rejected and Needs Revision |
| Delivery | Queue to an outbox, drained by a worker |

## Triggers and recipients

`email_notify()` is called at the same three points in
[recommendations_api.php](../../../recommendations_api.php) where
`notify_offices()` already fires.

| Event | In-app | Email |
|---|---|---|
| Recommendation text first written (`NOTIFY_NEW`) | yes | yes |
| Office put in charge (`NOTIFY_ASSIGNED`) | yes | yes |
| Status to **Rejected** or **Needs Revision** | yes | yes |
| Status to Approved / Completed / Submitted | yes | no |
| Review feedback with no status change | yes | no |

### Resolving recipients

1. Take the names in `in_charge` that resolve to an **approved user account**,
   via `in_charge_staff_by_office()` in
   [in_charge.php](../../../in_charge.php).
2. If there are any, email those people and nobody else.
3. If there are none, email every approved account of the owning office and of
   any office named in `in_charge` — the set `notification_recipients()`
   already computes.
4. Never email the person who caused the change.
5. Collapse duplicates: one address receives one email per event.

### A name only counts if it resolves to an account

Six current assignments are typed-in names with no account —
`Maria Grace Andal`, `Leo`, `Borbon`. Read literally, "named people take
precedence" would mean a recommendation assigned only to such names emails
nobody and never falls back to the office.

So an unresolvable name does **not** count as a named person: those
assignments fall through to the office, and the outbox records that the named
person had no address. Silent non-delivery is the failure mode this whole
design exists to avoid.

## The outbox

`email_outbox`, created by `ensure_email_outbox_table()` — self-healing on
first use, as `ensure_notifications_table()` and `ensure_offices_table()` are,
so nothing needs running on another machine.

```
id                int, auto increment
recommendation_id int, nullable
recipient_email   varchar(190)
recipient_name    varchar(120)
recipient_office  varchar(100)
event             varchar(30)
subject           varchar(200)
body              text
status            varchar(20)   pending | sent | failed
attempts          int, default 0
last_error        text
created_at        datetime
sent_at           datetime, nullable
KEY status_created (status, created_at)
```

**One row per recipient**, not per event, so one undeliverable address cannot
block the others.

**Subject and body are rendered when queued, not when sent.** This matches the
reasoning already recorded in [notifications.php](../../../notifications.php):
the message should describe the assignment as it was, even if the
recommendation has changed by the time the mail goes out.

## The worker

`email_worker.php`, a CLI script modelled on
[onedrive_worker.php](../../../onedrive_worker.php), with the same sub-commands
so there is one pattern to learn:

- **`run`** — send pending rows, oldest first, at most `EMAIL_BATCH_SIZE`
- **`status`** — counts by state, and each failure with its `last_error`
- **`retry`** — reset `failed` rows to `pending`

On success: `status = 'sent'`, `sent_at` stamped. On failure: `attempts`
increments and `last_error` is recorded; the row stays `pending` until
`attempts` reaches `EMAIL_MAX_ATTEMPTS`, then becomes `failed` so it stops
consuming attempts and appears in `status`.

**Scheduling:** `tools/email_worker.bat`, run every 5 minutes by Task
Scheduler and registered by `tools/install_services.bat` beside the existing
`QA System - Health Check` task. Five minutes is immaterial for "you have a
new task", and it means an offline PC delays mail rather than losing it.

## Sending

PHPMailer is **vendored** at `lib/PHPMailer/` — `PHPMailer.php`, `SMTP.php`,
`Exception.php`, committed to the repository, because there is no Composer
here. It handles AUTH, STARTTLS, headers and encoding; hand-writing an SMTP
client would be the wrong economy.

### Configuration

Read through `env_helper.php`, following the `ONEDRIVE_*` convention.
`.env.example` gains these keys; `.env` is already gitignored, so the app
password never reaches git.

```
EMAIL_ENABLED=false            # master switch, as ONEDRIVE_ENABLED
EMAIL_DRY_RUN=true             # queue and log, send nothing
EMAIL_SMTP_HOST=smtp.gmail.com
EMAIL_SMTP_PORT=587
EMAIL_USERNAME=
EMAIL_APP_PASSWORD=
EMAIL_FROM_NAME=SBC Quality Assurance   # the From address is EMAIL_USERNAME
EMAIL_MAX_ATTEMPTS=5
EMAIL_BATCH_SIZE=20
```

`EMAIL_DRY_RUN` exercises triggers, recipient resolution and the queue with no
credentials and nothing leaving the machine, exactly as `ONEDRIVE_DRY_RUN` did
for uploads.

### What the message contains

Who it is for, the recommendation text, what happened ("assigned to you",
"needs revision"), and a link to the dashboard.

Deliberately excluded: compliance responses, review remarks, and document
names. On a recommendation shared between offices, including them would send
one office's submission to another — the boundary
[recommendation_history.php](../../../recommendation_history.php) already
enforces for the activity modal.

### When delivery fails for good

Once a row exhausts its attempts, the worker writes an `audit_log` entry. That
surfaces the failure in the Activity Log administrators already read, instead
of adding a screen nobody opens. `email_worker.php status` gives the detail.

## Testing

In order of what is most likely to be wrong:

1. **Recipient resolution** against the real data: named-people precedence,
   the typed-in-name fallback to the office, actor excluded, duplicates
   collapsed, offices with no approved accounts.
2. **Dry run through the real triggers**: assign a throwaway recommendation,
   confirm the expected rows queue with the expected addresses and that
   nothing is sent.
3. **Worker retry**: force a failure, confirm `attempts` climbs, the row flips
   to `failed` at the cap, `retry` resets it, and the audit entry is written.
4. **One real send** to a single address with `EMAIL_DRY_RUN=false`.
5. **Events that must not email** — Approved, Completed, feedback-only —
   queue nothing.

All test data is removed afterwards: the throwaway recommendation, its outbox
rows, and any audit entries the test produced.

## Out of scope

- Per-user preferences for opting out of email.
- Digests or batching several events into one message.
- Email to administrators; the Activity Log already covers that side.
- Replacing the Gmail sender with a school address. Worth revisiting — mail
  from a `gmail.com` address looks unofficial and is likelier to be filtered —
  but it needs IT to provide a mailbox, and nothing here blocks on it: only
  `EMAIL_*` values in `.env` would change.
