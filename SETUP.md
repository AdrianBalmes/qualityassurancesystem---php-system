# Running this project on another device

## First time on a new machine

```bash
git clone https://github.com/AdrianBalmes/qualityassurancesystem---php-system.git
cd qualityassurancesystem---php-system
```

**1. Create the database**

```bash
mysql -u root -e "CREATE DATABASE ems_db;"
mysql -u root ems_db < ems_db.sql
```

`ems_db.sql` is **structure only** -- no accounts, no recommendations. The app
fills in the rest by itself: the office list seeds on first page load, and any
column an older database is missing is added the same way.

**2. Create the first administrator**

```bash
php tools/create_admin.php <username>
```

It asks for a password. Nothing can sign in before you do this: the database
ships with no accounts, and registrations stay "pending" until an
administrator approves them.

**3. Create `.env`** (only needed for OneDrive sync)

```bash
copy .env.example .env
```

`.env` is gitignored, so it never travels with the repo -- real credentials
must never be committed. See [ONEDRIVE_SETUP.md](ONEDRIVE_SETUP.md).

**4. Start it**

```bash
php -S localhost:8080 router.php
```

Open <http://localhost:8080>. For the hosted setup behind Cloudflare Tunnel,
Apache serves it instead -- see "Hosting it for real" at the end of this file.

> Always pass `router.php`. Without it the built-in server hands out every file
> in the folder to anyone who asks -- `.env`, the `.git` history and every
> uploaded document. Under Apache, `.htaccess` does the same job.

**5. Bring over the real data** (optional)

A fresh clone has an empty system. Recommendations, accounts and uploaded
documents are deliberately **not** in git -- see "What does not travel through
git" below for how to copy them.

### If `database.php` doesn't match your setup

It hardcodes `localhost` / `root` / no password / `ems_db`. Edit it if your
MySQL differs — but don't commit that change, or you'll break it for the other
device.

---

## Day-to-day: moving work between devices

**Before you stop working on a device**

```bash
git add -A
git commit -m "Describe what changed"
git push
```

**When you sit down at the other device**

```bash
git pull
php setup.php      # only if the schema changed; harmless otherwise
```

The golden rule is to **push before you switch machines**. If you forget and
edit the same file on both, you get a conflict to untangle by hand.

### Switching to a branch that only exists on the remote

```bash
git fetch origin
git checkout feature/onedrive-repository-sync
```

Modern git links it to the remote branch automatically.

---

## What does not travel through git

| Not in git | How to get it on the other device |
|---|---|
| `.env` | `copy .env.example .env`, then fill in |
| Accounts | `php tools/create_admin.php <username>`, then register the rest |
| Recommendations, reviews, the audit log | Export and import them (below) |
| Documents uploaded at runtime | Copy `uploads/` by USB or OneDrive (below) |
| Apache vhost, cloudflared config, backup task | Machine-specific; see "Hosting it for real" |

The database is the one that catches people out. Git carries only the
*structure* (`ems_db.sql`) -- never your rows, and never accounts.

**To copy the real data to another machine**

```bash
# on the machine that has the data
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --default-character-set=utf8mb4 ems_db > ems_db-data.sql

# on the other machine, after cloning and creating the database
C:\xampp\mysql\bin\mysql.exe -u root ems_db < ems_db-data.sql
```

Copy `uploads/` across at the same time -- the database rows point at those
files, and without them every View and Download says the file is missing.
The nightly backup in the OneDrive `QA Backups` folder works as the export if
it is recent enough.

**Never commit either of them.** A data dump contains staff email addresses
and password hashes, and `uploads/` holds the departments' real documents.
`.gitignore` already keeps `uploads/` out; keep dumps outside the project
folder or name them so they are ignored.

> The seeded passwords that used to live in `ems_db.sql` (`admin@2026` and
> friends) are still in this repository's **history**, which is public. The
> live passwords have been changed, so those values no longer open anything.
> Removing them from history means rewriting it and force-pushing -- a
> separate decision, coordinated with everyone who has a clone.

---

## Branch workflow

`main` is the working version. Build features on their own branch and merge only
when they are ready:

```bash
git switch main
git pull
git switch -c feature/whatever-you-are-building
# ... work, commit ...
git push -u origin feature/whatever-you-are-building
```

See **[GIT_GUIDE.md](GIT_GUIDE.md)** for branching, switching, pushing, pull
requests, and what to do when git complains.

---

## Hosting it for real: this PC + Cloudflare Tunnel

The live site runs from this PC. Cloudflare terminates HTTPS and forwards
requests down a tunnel to Apache on `127.0.0.1:80`, so there is no port
forwarding and the home IP address is never published.

```
department's browser  ──https──▶  Cloudflare  ──tunnel──▶  cloudflared  ──▶  Apache (127.0.0.1:80)
                                                                                  │
                                                          uploads/ on this PC ◀────┘
                                                                  └── copied to OneDrive (backup)
```

### Apache, not `php -S`

`php -S` handles one request at a time, so two departments uploading at once
block each other. Apache serves the site through two vhosts in
`C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

| Host | Serves |
|---|---|
| `localhost` (listed first, so it is the default) | `C:\xampp\htdocs` — the XAMPP dashboard and other projects |
| `sbc-quality-assurance.com` | this project |

`AllowOverride All` on the project directory is what makes `.htaccess` take
effect. Without it Apache serves `.env`, `ems_db.sql`, `.git/` and every
uploaded document to anyone who asks.

`httpd.conf` binds Apache to **`Listen 127.0.0.1:80`**: the tunnel runs on this
PC, so nothing else needs to reach Apache directly, and the site is not exposed
on whatever Wi-Fi this laptop joins.

Restart Apache from the XAMPP Control Panel after changing any of this. Tick
Apache and MySQL as **services** there so they come back after a reboot.

### The tunnel

See `tools/cloudflared-config.example.yml` for the whole sequence
(`tunnel login` → `create` → `route dns` → `service install`).

In the Cloudflare dashboard set SSL/TLS to **Full** and turn on **Always Use
HTTPS**. A rate-limiting rule on `index.php` and `admin_login.php` is worth
adding — the login pages have no attempt limit of their own.

### Before letting anyone in

```bash
php tools/set_password.php admin      # repeat for finance, cssao, reg1, user1
```

The seeded passwords (`admin` / `admin@2026` among them) are in this repo's
public GitHub history. Change every one of them before the site is reachable.

### Uploads and OneDrive

Uploaded files live in `uploads/` **on this PC** — that is the real store.
`ONEDRIVE_MODE=local` copies each one into the OneDrive folder, where the
OneDrive client syncs it offsite as a backup. Because this PC is the server,
no Microsoft Graph credentials are needed. A file is capped at 40 MB, under
Cloudflare's free-plan limit of 100 MB per request.

### Backups

`tools/backup_db.bat` dumps the database into the OneDrive folder and keeps 7
days. It runs daily at 21:00 via the Task Scheduler entry
**"QA System - Daily DB Backup"**. `uploads/` already reaches OneDrive through
the app; the database only gets there through this job.

Restore one with:

```bash
C:\xampp\mysql\bin\mysql.exe -u root ems_db < "C:\Users\calib\OneDrive - St. Bridget College, Inc\QA Backups\ems_db-YYYY-MM-DD.sql"
```

### What this setup cannot do

- The site is up only while this PC is on, awake and online. A power cut, ISP
  outage or Windows Update reboot takes it down for everyone.
- Everyone's downloads come out of this PC's upload bandwidth.
- Forgot Password still cannot send codes (no SMS gateway) — an admin resets
  passwords with `tools/set_password.php`.
- Uploads are not scanned for malware; the file-type allow-list is the only check.
- The student OneDrive disappears when that account is deactivated. A school
  service account or SharePoint library is the long-term home.
