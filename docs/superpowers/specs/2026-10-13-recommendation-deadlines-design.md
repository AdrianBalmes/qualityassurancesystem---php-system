# Deadlines on recommendations

**Date:** 2026-10-13
**Branch:** `feature/recommendation-deadlines`, branched from
`feature/add-email-notification` — **not from `main`**

## Why

A recommendation can sit at Pending for months. Nothing in the system says when
it is due, so nothing can say when it is late. QA staff chase offices by
memory, and an office that has forgotten a recommendation has no way to find
out until somebody asks.

This adds a date a QA officer sets, tells the responsible people before and
after it passes, and lets the board be read by urgency instead of by id.

### Success

A QA officer opens the board, filters to what is overdue, and sees the same
list they would otherwise have assembled by hand. An office that has missed a
deadline learns of it without being chased — and an office about to miss one
learns in time to act.

### Constraints

- Nothing in this system is time-based today. Every notification is a side
  effect of somebody's click, so a deadline is the first thing that must
  happen because a date passed rather than because a person did something.
- The server is a home PC behind Cloudflare Tunnel and is intermittently
  offline. A sweep that does not run must lose nothing when it next does.
- Registering a Windows scheduled task needs an Administrator and has already
  failed silently once on this machine: `QA System - Email Worker` did not
  register, and `QA System - Health Check` is still absent. **A design that
  needs another scheduled task is a design that may never run.**
- `audit_recommendations` has 95 rows and, before this work, no index but its
  primary key.
- The in-app inbox, the Activity Log and the email outbox all exist and are
  reused. No new delivery machinery.
- **This depends on the email-notifications feature, which is not merged.**
  `email_notify()`, `email_recipients_for()` and the `email_outbox` table
  live on `feature/add-email-notification`. Branching from `main` would leave
  every email reference in this design undefined, so the deadline branch
  starts from that one and the two merge in order.

### Decisions taken

| | |
|---|---|
| Who sets the date | QA staff only; offices see it, read-only |
| Missed notices | Once, the first time the sweep sees it overdue |
| Advance warning | Yes, a configurable number of days before |
| Board | Urgency buckets in the filter toolbar, plus a sortable Deadline column |
| Where the sweep runs | Inside the existing `tools/email_worker.bat`, no new task |

## The data

Four columns, added to the `$columns` array in `ensure_review_columns()`
([review_columns.php:20](../../../review_columns.php#L20)). That function is
already called from `home.php`, `office_dashboard.php`,
`office_recommendations_view.php` and `recommendations_api.php`, so every entry
point heals the schema and this needs no new `ensure_*` function.

```
deadline                     date      DEFAULT NULL
deadline_soon_notified_at    datetime  DEFAULT NULL
deadline_missed_notified_at  datetime  DEFAULT NULL
deadline_met_at              datetime  DEFAULT NULL
KEY deadline (deadline)
```

All nullable: most existing recommendations have no deadline, and "no deadline"
is a normal state, not missing data.

Two markers rather than one status field, so it is visible *when* each notice
went out — the question asked when somebody claims they were never told.

The index is the table's first secondary index. The existing helper only adds
columns, so the index needs its own `SHOW INDEX` check beside it.

### Setting, clearing and moving it

A QA officer edits the date in the grid, saved through
`save_office_recommendation`, the action the grid already posts to. Blanking the
cell clears it.

**The API ignores a posted `deadline` from any caller without
`recommendations.manage`.** This does not rely on offices using a different
endpoint, for the same reason `save_in_charge` filters posted names against the
acting office rather than trusting the form.

**Moving the date to a different one clears all three timestamps.** A new date
is a new promise: without this, extending a missed deadline could never be
flagged again, and a deadline met before being moved would stay met forever.

### A past date is allowed

QA staff will back-fill dates onto recommendations that already exist, and a
date already past is genuinely missed. So it is accepted and it notifies.

The consequence is a flood: back-filling across the 95 existing rows would
queue a missed notice for each at once, reaching all 22 addressed accounts.
The sweep is therefore **capped per run**, as `EMAIL_BATCH_SIZE` caps the mail
drain, so a bulk back-fill trickles out over several ticks. Suppressing notices
for back-dated deadlines was rejected: it hides exactly what this feature
exists to report.

## What a deadline means, and what closes it

A deadline means **submit by this date**.

| | |
|---|---|
| Closes it | `Submitted`, `Approved`, `Completed` |
| Leaves it open | `Pending`, `Not Submitted`, `Rejected`, `Needs Revision` |
| Custom office statuses | Open |

Custom statuses ([office_statuses.php](../../../office_statuses.php) lets an
office add its own) count as **open**. An office inventing "In Progress" has not
submitted anything, and treating an unrecognised status as closed would silence
the alert — failing in the direction this feature exists to prevent.

### Why `deadline_met_at` exists

An office submits on time. QA reviews a week later and sends it back as Needs
Revision. The status is open again and the date is now past, so a sweep reading
only status and date would email *"you missed your deadline"* to an office that
did not.

A false accusation is how people learn to ignore notifications. So the first
status change that closes a deadline stamps `deadline_met_at`, and the sweep
skips any row that has it. When QA sets a new deadline for the revision, moving
the date clears it and the new date stands on its own.

## The sweep

Logic in `deadlines.php`, the CLI in `deadline_sweep.php` — the same split as
`email_worker_lib.php` and `email_worker.php`, so there is one pattern to learn.

For each of the two events, one indexed query selects rows that are open, have
no marker for that event, no `deadline_met_at`, and are past the threshold
(`deadline < CURDATE()` for missed; `deadline` between today and today plus the
warning window for soon). Oldest deadline first, limited to the cap.

**Claim then notify.** For each row, write the marker and notify only if that
`UPDATE` matched one row. Two overlapping sweeps then cannot both notify, and
there is no stuck-row risk: here the marker is the permanent record of having
notified, not a transient lease. This is the opposite call from the mail queue,
where claiming rows would have needed a lease, a timeout and a reaper.

Dates are compared against the server's date (`CURDATE()`). The deadline is a
date, not an instant: a notice fires on the day after the deadline, whatever
the hour the sweep happens to run.

### Three channels

| Channel | How |
|---|---|
| In-app inbox | `notify_offices()`, with two new types in `notifications.php`: `NOTIFY_DEADLINE_SOON`, `NOTIFY_DEADLINE_MISSED` |
| Activity Log | `log_audit_event()`, with the new action added to `activity_log.php`'s known actions so it can be filtered |
| Email | `email_notify()` |

Going through `email_notify()` means **recipient resolution is reused, not
reinvented**: a named person takes precedence over their office, an
unresolvable name falls back to the office, the actor is excluded, duplicates
collapse, and a recipient nobody can reach is recorded in the outbox. Both
events are added to `EMAIL_EVENTS` and given wording in `email_render()`, which
must keep reading only the fields it reads today — never remarks, review
remarks, compliance responses or document names.

No notification type is referenced outside `notifications.php`,
`email_notifications.php` and `recommendations_api.php`, so adding two needs no
label or icon map changes.

`EMAIL_DRY_RUN` governs the email channel only, through the existing worker.
The in-app notice and the Activity Log entry are always written: they are not
deliveries and nothing queues them.

### Configuration

`DEADLINE_*`, not `EMAIL_DEADLINE_*`, because the warning window decides the
in-app notice and the Activity Log entry as well as the mail.

```
DEADLINE_WARN_DAYS=3      # how many days before the date the warning fires
DEADLINE_SWEEP_LIMIT=20   # most notices one sweep will send, per event
```

### Scheduling

One line added to `tools/email_worker.bat`, before the mail drain. **No new
Windows task, and no re-registration of the one being created now.** The sweep
needs no scheduling state of its own: the markers make it idempotent, so
running every five minutes notifies exactly once and costs one indexed query
per tick.

## The board

The filter toolbar and row rendering live in
[recommendation_view_rows.php](../../../recommendation_view_rows.php), which
`home.php` and `office_recommendations_view.php` both use — so the work lands on
the QA board and the office's own view together, and an office can see what it
owes and when.

**The column.** A Deadline column takes the grid from 8 columns to 9, and from
7 to 8 when the Office column is dropped, in
`recommendation_grid_column_count()`. A native date input in edit mode for QA; in
view mode the date, using the chip classes already in the stylesheet — red when
overdue, amber when inside the warning window, plain otherwise. Urgency is then
legible without opening a filter.

**The filter.** A new `deadline` key in `recommendation_filters_from_request()`,
its buckets as constants beside `RECOMMENDATION_FILTER_NONE`: overdue, within 7
days, within 30 days, later than 30 days, none set. These are fixed rather than
derived from the data, so unlike In Charge and Year they need no query. They are
**fixed at 7 and 30 days and are not** `DEADLINE_WARN_DAYS`: the buckets are for
reading the board, the warning window is for deciding when to send, and tying
them together would mean changing the notice timing to change how the board
reads. Matching
goes where the other filters already match, so one place decides what a row
matches.

**The sort.** A new `sort` key, applied after filtering in the same module.
The Deadline header toggles soonest-first and latest-first. The default stays today's order — by `id`, as
`fetch_office_recommendations()` returns them — so an untouched board looks
exactly as it does now. **Rows with
no deadline sort last in both directions**: with few rows dated at first,
latest-first would otherwise open on a wall of blanks.

## Testing

In order of what is most likely to be wrong:

1. **Bucket and window boundaries.** A deadline that is today, tomorrow,
   exactly `DEADLINE_WARN_DAYS` away, exactly 7 and exactly 30 days out. An
   off-by-one on "within 7 days" is invisible by eye and the likeliest bug here.
2. **Notified once.** Two sweeps in a row queue one notice, not two. Claiming
   works: a row whose marker is already set is skipped.
3. **`deadline_met_at`.** Submitted before the date, then sent back after it —
   no missed notice. Moving the date then re-misses it correctly.
4. **Closed statuses, including a custom one**, which must count as open.
5. **The cap**, with more overdue rows than `DEADLINE_SWEEP_LIMIT`: the rest
   are picked up by the next sweep and none is skipped.
6. **An office with no approved accounts**, and a named person with no address:
   the outbox records that nobody was reachable.
7. **The board**: each bucket, the sort in both directions, and that rows
   without a deadline come last.

Tests run against the live `ems_db` and must delete every row they create on
every exit path, abort rather than touch a row they do not own, and refuse to
run outside the CLI — the conventions `tests/` now follows.

## Out of scope

- A different deadline per office on a shared recommendation. One recommendation
  is one row owned by one office; the 95 existing rows have 92 distinct texts,
  so recommendations are not duplicated per office today.
- Recurring or repeating deadlines.
- Escalating to QA when an office ignores a missed deadline. Worth revisiting:
  the Activity Log entry is the only record today, and nobody is told that
  nobody acted.
- Offices proposing or requesting a deadline change in the system. They ask QA.
- Changing the mail cadence. A missed deadline notifies once, by decision; if
  that proves too quiet, the change is a reminder sweep, not a redesign.
