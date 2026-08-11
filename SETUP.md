# Running this project on another device

## First time on a new machine

```bash
git clone https://github.com/AdrianBalmes/qualityassurancesystem---php-system.git
cd qualityassurancesystem---php-system
git checkout feature/onedrive-repository-sync
```

**1. Create the database**

```bash
mysql -u root -e "CREATE DATABASE ems_db;"
mysql -u root ems_db < ems_db.sql
```

**2. Run setup**

```bash
php setup.php
```

This adds anything `ems_db.sql` is missing and creates the upload directories.
Safe to run as many times as you like — it checks before changing anything.

> Needed because `ems_db.sql` predates a few columns the code expects
> (`feedback.attachment_name` and `attachment_original_name`). Without this
> step, Create Feedback fails with `Unknown column 'attachment_name'`.

**3. Create `.env`** (only if you are working on OneDrive sync)

```bash
cp .env.example .env
```

`.env` is gitignored, so it never travels with the repo — real credentials must
never be committed. See [ONEDRIVE_SETUP.md](ONEDRIVE_SETUP.md).

**4. Start it**

```bash
php -S localhost:8080
```

Open <http://localhost:8080>.

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
| `.env` | `cp .env.example .env`, then fill in |
| The database itself | Import `ems_db.sql`, then `php setup.php` |
| Files uploaded at runtime | Only files committed to `uploads/` come across |

The database is the one that catches people out. Git carries the *schema*
(`ems_db.sql`), not your rows — recommendations and documents you create on one
device will not appear on the other. To move real data:

```bash
# on the source device
mysqldump -u root ems_db > ems_db.sql

# on the other device, after pulling
mysql -u root ems_db < ems_db.sql
```

Think before committing a refreshed dump: it will contain whatever test data
and user accounts existed at that moment.

---

## Branch workflow

`main` is the working version. Build features on their own branch and merge
only when they are ready.

```bash
git checkout main
git pull
git checkout -b feature/whatever-you-are-building
# ... work, commit ...
git push -u origin feature/whatever-you-are-building
```

`-u` is only needed the first time you push a new branch; `git push` alone
works afterwards.

**Merging into main** is best done as a Pull Request on GitHub — it gives you a
diff to review and a record of what landed. From the repo page, GitHub offers a
"Compare & pull request" button after you push a branch.

Or locally:

```bash
git checkout main
git pull
git merge feature/whatever-you-are-building
git push
```

**Don't let a branch sit for weeks.** `feature/audit-rec-file-upload` fell three
weeks behind and now conflicts with `main` in five files, including one that
`main` deleted. Merging `main` into your branch regularly keeps that from
happening:

```bash
git checkout feature/whatever-you-are-building
git merge main
```
