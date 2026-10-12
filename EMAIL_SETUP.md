# Email Notifications - Setup

When a recommendation is assigned to an office, or handed back for another look,
the people who have to act on it get an email. This guide is for the person who
administers the QA system: you need Windows, a Gmail account and a text editor.
You do not need to know PHP.

The code is finished and tested. It stays quiet until you finish the steps
below, and nothing is lost while you do them.

**Two things are still waiting on you:**

- The Gmail app password (steps 1 to 3). Nothing can be sent without it.
- The Windows scheduled task that sends mail by itself (step 6). **It is not
  registered yet.** Until you register it, queued mail only goes out when you
  run the worker by hand.

---

## How it works, in one paragraph

Saving an assignment never waits on email. It writes one row per recipient into
a waiting list (the *outbox*) and carries on. A small program, the *worker*,
empties that list: every 5 minutes once step 6 is done. If the PC is off or
offline, the rows simply wait, so mail is delayed, not lost. A message that
keeps failing is tried up to 5 times, then marked `failed`, and a line appears
in the **Activity Log** so you will see it.

---

## Running the worker by hand

Several steps below run the worker yourself. Open **Command Prompt**, then:

```
cd /d "D:\Users\Gab\Desktop\Quality Assurance\qualityassurancesystem---php-system"
C:\xampp\php\php.exe email_worker.php status
```

Whenever this guide shows `email_worker.php ...`, it means that second line with
the last word changed (`status`, `run`, `retry`).

---

## 1. Turn on 2-Step Verification

Google only gives out app passwords to accounts that have 2-Step Verification.

1. Sign in to the Gmail account that will send the mail.
2. Go to <https://myaccount.google.com/security>.
3. Under *How you sign in to Google*, choose **2-Step Verification** and follow
   the prompts.

## 2. Create an app password

An app password is a 16-character password that works for one program only. It
is not the Gmail password, and it can be revoked without changing that.

1. Go to <https://myaccount.google.com/apppasswords>.
2. Name it `SBC QA System` and choose **Create**.
3. Google shows 16 letters, in four groups. Copy them now: Google never shows
   them again. (If you lose it, delete it on the same page and make a new one.)

## 3. Put it in `.env`

`.env` is the private settings file in the project folder. It is deliberately
kept out of git, so the password never leaves this PC. If you do not have one
yet, copy `.env.example` to `.env`.

Add or fill in these two lines, **typing the app password without spaces**:

```
EMAIL_USERNAME=your.address@gmail.com
EMAIL_APP_PASSWORD=abcdefghijklmnop
```

Leave the other `EMAIL_*` lines as `.env.example` has them. Save the file.

## 4. Rehearse with dry run

Make sure `.env` says:

```
EMAIL_ENABLED=false
EMAIL_DRY_RUN=true
```

Then assign a recommendation to an office in the system, and run:

```
email_worker.php run
```

You should see something like:

```
DRY RUN: 1 row(s) marked dry-run, NOT emailed (nothing left the machine); failed 0
```

That confirms the whole chain works: the assignment queued a row, and the
worker processed it. **Nothing was emailed.** A dry run marks each row
`dry-run`, never `sent`, so the record does not claim a delivery that did not
happen. The row is not lost either: once you have switched to live (step 5),
`email_worker.php retry` puts every `dry-run` row back in the queue and sends it.

## 5. Switch it on for real

Change these two lines in `.env`:

```
EMAIL_ENABLED=true
EMAIL_DRY_RUN=false
```

If you rehearsed first, `email_worker.php status` will show a number against
`dry-run`: those messages were never emailed. Run `email_worker.php retry` to
send them (see step 8).

To test, assign a recommendation to an office whose recipient is an address you
can check, then run `email_worker.php run` and look for `sent 1, failed 0`.
Check that inbox, and the spam folder the first time.

If you get `Stopped: No SMTP credentials`, `EMAIL_USERNAME` or
`EMAIL_APP_PASSWORD` is blank or missing. That is deliberate: the worker stops
before touching the queue, so a missing `.env` can never mark waiting mail as
failed when it was never tried. Fix `.env` and run it again.

### What the two switches mean together

| `EMAIL_ENABLED` | `EMAIL_DRY_RUN` | What happens |
|---|---|---|
| `false` | `true` | **The state this ships in.** Assignments still queue rows. Nothing is sent. Until the worker runs, rows simply wait. If the worker does run, it processes the queue as a rehearsal: rows become `dry-run` (not `sent`) and no email leaves the PC. They can be sent later with `retry`. |
| `true` | `false` | **Live.** Assignments queue rows and the worker sends them. |
| `true` | `true` | Same as the first row: dry run wins, nothing is sent. |
| `false` | `false` | **Fully off.** Assignments queue nothing and the worker does nothing. Mail for assignments made in this state cannot be recovered later, because it was never queued. |

So with the shipped settings no mail is lost. If you register step 6 before
switching to the second row, the worker will move every queued row to `dry-run`
every 5 minutes and nobody is emailed. Those rows are not gone: after you switch
to the second row, `email_worker.php retry` sends them. But nobody is told in the
meantime, so switch the settings first, then register the task.

## 6. Register the scheduled task (required, not yet done)

> **Status: not registered.** The task `QA System - Email Worker` does not exist
> on this PC yet. Registering it is a change to Windows, it needs an
> Administrator, and it is yours to run.

The task runs `tools\email_worker.bat` every 5 minutes as the SYSTEM account,
whether or not anybody is signed in. Open **Command Prompt as administrator**
(right-click it, *Run as administrator*) and run this one command:

```
schtasks /Create /TN "QA System - Email Worker" /TR "\"D:\Users\Gab\Desktop\Quality Assurance\qualityassurancesystem---php-system\tools\email_worker.bat\"" /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F
```

Windows answers `SUCCESS: The scheduled task "QA System - Email Worker" has
been successfully created.`

Alternatively, `tools\install_services.bat` registers it along with the health
check, but that script also stops and restarts Apache, MySQL and the tunnel, so
use the single command above unless you mean to reinstall those too.

Check it later in **Task Scheduler**, or with:

```
schtasks /Query /TN "QA System - Email Worker"
```

Each run adds to `qa-email.log` in the project folder. It is a plain text file:
open it in Notepad to see what happened and when. The log trims itself, so it
will not grow forever.

Things you may see there, none of them a problem:

- `Another email worker run is already in progress; skipping this one.` A
  previous run was still going. The next run picks up whatever it left.
- `sent 0, failed 0` is an empty queue. A line starting `DRY RUN:` means the
  settings are still on rehearsal and nothing is being emailed (step 5).

One thing that is a problem:

- `Stopped: No SMTP credentials ...` means `.env` is missing the Gmail address
  or app password (step 3). Task Scheduler shows that run as failed, on purpose,
  so you will notice.

## 7. Check on it: `status`

```
email_worker.php status
```

shows how many rows are in each state:

```
Email outbox
  pending  0
  dry-run  0
  sent     14
  failed   1

Recent failures
  #23    someone@example.com   attempts 5: <the reason>
```

- **pending**: waiting to go out. A few is normal; a number that keeps growing
  means the worker is not running (step 6) or cannot reach Gmail.
- **dry-run**: processed while `EMAIL_DRY_RUN` was on. **These were never
  emailed.** A number here means people are waiting for a message they have not
  had; `retry` (step 8) sends them once the settings are live.
- **sent**: really emailed.
- **failed**: gave up after 5 attempts. The reason is shown next to it, and the
  same event is in the **Activity Log**.
- **failed, `(no address)`**: nothing was ever sent, because nobody could be
  reached. A recommendation was assigned to an office with no approved account,
  or whose accounts have no email address, and the reason names who was tried.
  Give one of those accounts an address, then assign the recommendation again.
  `retry` and `run` leave these rows alone: there is no address to send to.

## 8. When rows reach `failed`

First read the reason in `status`. Then:

**A bad address on one row** (a typo in someone's email): fix the address in the
system, then send that single row again, using its number (`#23` in the example
above):

```
email_worker.php run 23
```

That puts the failed row back in the queue and sends it. It refuses a row that
was already sent (so nobody gets the same email twice), and tells you if the
number does not exist.

**A general problem** (Gmail was unreachable, or the app password was wrong and
you have fixed it), **or rows sitting in `dry-run`**: put every row that was not
actually delivered (`failed` and `dry-run`) back and send them all:

```
email_worker.php retry
```

Rows that were really `sent` are never put back, so nobody gets a message twice.

`retry` and `run` both wait their turn: if the scheduled run is working at that
moment, you will see `Another email worker run is already in progress; skipping
this one.` Just run the command again in a minute.

---

## Good to know

- **The test suite (`tests/`) keeps running while mail is waiting, but two
  assertions sit out.** `tests/email_worker_test.php` also exercises the unscoped
  `run` and `retry` the scheduler uses. If the outbox holds a `pending`, `failed`
  or `dry-run` row that is not the test's own (after a dry-run rehearsal, say),
  it skips just that section and prints a `SKIP` line saying so; the summary
  counts the skips apart from passes. Everything else runs and touches none of
  those rows. Deliver them first (step 5) and the full suite runs again. `sent`
  rows and the `(no address)` records never cause a skip.
- **Gmail allows roughly 500 messages a day** from one ordinary account. Each
  assignment is one message per recipient, so this is plenty for now. If you ever
  come close, rows will start failing with a quota message, and `retry` will send
  them once the limit resets.
- **The sender is a `gmail.com` address** until IT provides a school mailbox.
  Some recipients may see it land in spam at first.
- **Moving to a school mailbox needs no code change.** Edit the `EMAIL_*` lines
  in `.env` (`EMAIL_SMTP_HOST`, `EMAIL_SMTP_PORT`, `EMAIL_USERNAME`,
  `EMAIL_APP_PASSWORD`, `EMAIL_FROM_NAME`) with the values IT gives you. Port
  `587` uses STARTTLS and port `465` uses implicit TLS; the right one is chosen
  from the port number automatically.
- **Changes to `.env` take effect on the next run or next page load.** There is
  nothing to restart.
- **If the app password leaks**, delete it at
  <https://myaccount.google.com/apppasswords> and make a new one (steps 2 and 3).

## All the `.env` settings

| Setting | Default | Meaning |
|---|---|---|
| `EMAIL_ENABLED` | `false` | Master switch, see the table in step 5 |
| `EMAIL_DRY_RUN` | `true` | Run everything but the actual sending |
| `EMAIL_SMTP_HOST` | `smtp.gmail.com` | Mail server |
| `EMAIL_SMTP_PORT` | `587` | `587` (STARTTLS) or `465` (implicit TLS) |
| `EMAIL_USERNAME` | blank | The sending address; required to send |
| `EMAIL_APP_PASSWORD` | blank | The app password; required to send |
| `EMAIL_FROM_NAME` | `SBC Quality Assurance` | Sender name shown in the inbox |
| `EMAIL_SITE_URL` | `https://sbc-quality-assurance.com` | Where the "open the dashboard" link points |
| `EMAIL_MAX_ATTEMPTS` | `5` | Tries before a row is marked `failed` |
| `EMAIL_BATCH_SIZE` | `20` | Most messages one `run` sends (the rest wait for the next run) |
