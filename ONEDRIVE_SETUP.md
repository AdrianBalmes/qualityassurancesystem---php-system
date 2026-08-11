# OneDrive Repository Sync — Setup

Every supporting document a department uploads through **Update Compliance** is
copied automatically into a shared OneDrive for Business account, forming a
central repository of all departments' files.

The code is complete and tested. It stays dormant until `ONEDRIVE_ENABLED=true`
in `.env`, which needs credentials only an Entra ID administrator can create.

---

## Part 1 — Request for IT

> Hand this section to whoever administers the school's Microsoft 365 tenant.

We need an **app registration** so our Quality Assurance web application can
upload department documents to a OneDrive account unattended (no person is
signed in when the upload happens).

**1. Register the application**

Entra admin center → *App registrations* → *New registration*

| Field | Value |
|---|---|
| Name | `SBC QA Document Repository` |
| Supported account types | Accounts in this organizational directory only (single tenant) |
| Redirect URI | *Leave blank* — this app never signs a user in |

**2. Grant one application permission**

*API permissions* → *Add a permission* → *Microsoft Graph* → **Application
permissions** (not Delegated) → select:

| Permission | Why it is needed |
|---|---|
| `Files.ReadWrite.All` | Create folders and upload files into the repository OneDrive |

Then click **Grant admin consent**. Without this final click nothing works.

> `Files.ReadWrite.All` is tenant-wide, which is broader than we need. If your
> policy disallows it, please say so — we can switch the target to a single
> SharePoint document library and use `Sites.Selected`, which grants access to
> exactly one site and nothing else. That is the more restrictive option and we
> are happy to use it. It is a small change on our side.

**3. Create a client secret**

*Certificates & secrets* → *New client secret*. Record the expiry date —
**uploads stop when the secret expires** and it must be replaced before then.
Microsoft caps secrets at 24 months.

**4. Send us these four values**

- Directory (tenant) ID
- Application (client) ID
- Client secret **value** (not the Secret ID)
- The user principal name of the account whose OneDrive holds the repository,
  e.g. `qa-repository@sbc.edu.ph`

**A note on the storage account.** The target is one user's OneDrive for
Business. Microsoft deletes a user's OneDrive when their account is deleted, so
please point this at a dedicated service/shared account rather than a staff
member's personal drive — otherwise the whole repository disappears when that
person leaves. A SharePoint document library avoids this problem entirely and
we can switch to one on request.

---

## Part 2 — Once IT sends the credentials

Fill them into `.env` in the project root:

```ini
ONEDRIVE_ENABLED=true
ONEDRIVE_TENANT_ID=<directory-tenant-id>
ONEDRIVE_CLIENT_ID=<application-client-id>
ONEDRIVE_CLIENT_SECRET=<secret-value>
ONEDRIVE_DRIVE_USER=qa-repository@sbc.edu.ph
ONEDRIVE_ROOT_FOLDER=QA Repository
```

Confirm it reads them:

```bash
php onedrive_worker.php status
```

Copy the documents that already exist into the repository:

```bash
php onedrive_worker.php backfill
php onedrive_worker.php run
```

Add the retry job to cron so a Microsoft outage heals itself:

```cron
*/10 * * * * cd /path/to/project && php onedrive_worker.php run >> /var/log/onedrive_sync.log 2>&1
```

### Trying it without credentials

`ONEDRIVE_DRY_RUN=true` runs the whole pipeline and logs the exact file and
destination path it *would* upload, without contacting Microsoft. Useful for a
demo before IT responds.

---

## How it behaves

Files land in a folder tree keyed by office and audit year:

```
QA Repository/
├── CSSAO/
│   └── 2026/
│       └── evidence_20260811004619_17a33e.pdf
├── Finance/
│   └── 2026/
└── Registrar/
```

**A OneDrive problem never affects a department.** The upload is saved to
`uploads/` and recorded in the database first; only then is OneDrive attempted.
If that fails, the submission still succeeds normally and the file is left
queued in the `onedrive_sync` table for the cron worker to retry. Verified by
running a real submission against deliberately invalid credentials: the
department saw "Compliance update submitted successfully", the file was stored,
and the error was captured in the queue.

Retries stop after `ONEDRIVE_MAX_ATTEMPTS` (default 6) and the row is marked
`failed`. `php onedrive_worker.php retry` resets those and tries again.

Files under 4 MB upload in a single request; larger ones use a chunked upload
session, so big documents are not a problem.

## Commands

| Command | Purpose |
|---|---|
| `php onedrive_worker.php status` | Config, queue counts, recent errors |
| `php onedrive_worker.php run` | Upload everything pending |
| `php onedrive_worker.php retry` | Reset failed rows, then run |
| `php onedrive_worker.php backfill` | Queue documents that predate this feature |

## Files

| File | Role |
|---|---|
| `onedrive_helper.php` | Graph auth + upload (simple and chunked) |
| `onedrive_sync.php` | Queue table, enqueue, process |
| `onedrive_worker.php` | CLI worker for cron |
| `env_helper.php` | Reads `.env` (no Composer needed) |
| `.env.example` | Documented template |

## Operational notes

- **`.env` must never be committed.** It is already in `.gitignore`.
- **Diarise the client secret expiry.** Uploads fail tenant-wide when it lapses,
  and the only symptom is a growing pending queue. `status` will show
  `AADSTS7000222` when this happens.
- **PHP's `upload_max_filesize` is currently 2 MB**, so departments cannot
  attach anything larger regardless of what OneDrive supports. Raise
  `upload_max_filesize` and `post_max_size` in `php.ini` if bigger evidence
  files are expected.
