# PSPF Helpdesk — Deployment

Safe, diff-based deployment of the CRM + IT Access integration from the GitHub
repo to the **live** server (`\\192.168.1.16\xampp\htdocs`).

## Why it works this way

The live server is a **LAN-only Windows XAMPP box** with a private IP
(`192.168.1.16`). GitHub's cloud **cannot reach it**, so a push-based pipeline
(GitHub → server) is impossible. Instead, deployment is **pull-based and operator
-driven**: an operator runs `deploy.ps1` from a workstation that

1. has **git** installed, and
2. can **write to the live `htdocs` share**.

Nothing is installed on production. The live box is never modified except by the
file copies this script performs.

## What it does (every run)

1. **Fetch** the chosen branch (`main` by default) from GitHub into a temp clone.
2. **Diff** the clone against live — determine exactly which files are NEW or CHANGED
   (line-ending differences are ignored).
3. **Lint** every changed/added `.php` file with PHP *before* touching live; abort on any syntax error.
4. **Preview + approve** — print the change set; require the operator to type `DEPLOY`.
5. **Back up** the affected live files (timestamped) under `htdocs\_deploy_backups\`,
   and write the `manifest.json` *before* applying anything.
6. **Apply transactionally** — copy files in, tracking each success. **If any file
   fails to apply, the deploy automatically rolls back** every change so far so
   live returns to its exact pre-deploy state (no half-deployed CRM).
7. **Verify on live** — re-lint the deployed PHP as it now sits on the server
   (catches corruption-in-transit). If verification fails, the deploy
   **auto-rolls-back** too.
8. **Report** any DB migration files that changed (run manually) and the manual
   rollback command for that deploy.

## Scope — IT Access integration only (allowlist)

**The Git repo is the source of truth.** The repo has been mirrored from
production, so it reflects the full live codebase; live is expected to be at or
behind the repo. The deploy detects every difference between the repo and live
(within the managed folders) and pushes it.

Managed folders (`$ManagedFolders` in `deploy.ps1`):

- `pspf_crm/` — the CRM (API, includes, modules, IT Access, nested vehicle_booking)
- `IT Access Form/` — the React app
- `vehicle_booking/` — the standalone vehicle booking app

Within those, **everything is deployable except**:

- **Protected config** (`$ProtectedRelPaths`) — `db.php`, `mail_config.php`,
  `sharepoint_config.php` for the CRM and both vehicle_booking copies. Live keeps
  its own (they hold per-environment secrets).
- **Excluded paths** (`$ExcludeDirRegex` / `$ExcludeFileRegex`) — `vendor/`,
  `uploads/`, `.vs/`, `.git/`, `node_modules/`, `tmp/`, `*.sql`, `*.log`, and
  test-only files (`test_*.php`, e.g. the `test_login_helper.php` session bypass).

> Keeping the repo as the source of truth depends on changes flowing
> repo → live. If someone edits live directly, re-mirror live into the repo before
> the next deploy so the repo does not push a stale copy over their change.

## Safety guarantees

- **Full mirror, minus protected/excluded paths** — see above.
- **Database is never touched.** Schema/data migrations are **manual**, on purpose
  (see below).
- **No deletions** — files on live but absent from the repo are *reported*, never
  deleted (so an out-of-band live file is never silently removed). Retire a file
  manually if intended.
- **Line-ending-insensitive diff** — files differing only by CRLF/LF are treated
  as unchanged, so deploys show real changes only.
- **Live config is never overwritten** — `pspf_crm/api/db.php`,
  `mail_config.php`, and `sharepoint_config.php` keep the live values.
- **Auto-rollback on failure** — if applying files fails partway, or post-deploy
  verification finds a broken file on live, the deploy automatically restores the
  pre-deploy state (CHANGED files reverted from backup; NEW files removed). Live is
  never left half-deployed.
- **No deletions** — files present on live but absent from the repo are *reported*,
  never deleted. (Remove manually if a file is intentionally retired.)
- **Excluded from deploy:** `vendor/`, `uploads/`, `.vs/`, `.git/`, `node_modules/`,
  `tmp/`, and any `*.sql` / `*.log`.
- **Always backs up first;** rollback is one command.

## Usage

From the workstation (PowerShell), in this `deploy/` folder:

```powershell
# Preview only — fetch, diff, lint. No backup, no writes.
.\deploy.ps1 -DryRun

# Real deploy (will prompt for DEPLOY confirmation).
.\deploy.ps1

# Deploy a specific branch.
.\deploy.ps1 -Branch hotfix/login
```

### Rolling back

Each deploy prints its backup path and the exact rollback command:

```powershell
# Roll back the most recent deploy (asks which, then ROLLBACK to confirm).
.\rollback.ps1

# Roll back a specific deploy.
.\rollback.ps1 -BackupDir "\\192.168.1.16\xampp\htdocs\_deploy_backups\backup_YYYYMMDD_HHMMSS"

# Preview a rollback.
.\rollback.ps1 -DryRun
```

Rollback restores the previous version of **changed** files. Files the deploy
**added** are listed but not deleted (remove manually if needed).

## Database migrations (manual, by policy)

A running production CRM should not have schema changes fired automatically on
every code deploy. When a deploy includes new `*.sql` migration files (e.g. under
`pspf_crm/api/it_access/migrations/`), the script lists them. To apply:

1. **Back up the live database first** (phpMyAdmin → Export, or `mysqldump` on the box).
2. Review the migration SQL.
3. Run it on the live DB **on the server** (live MySQL only accepts `127.0.0.1`),
   e.g. via the box's phpMyAdmin or `mysql` shell.
4. Record what was applied (date, file, operator) for the audit trail.

## Audit trail

Every deploy writes, into its backup folder:

- `manifest.json` — commit SHA, branch, file list with NEW/CHANGED status, timestamp.
- `deploy.log` — full run log.

These provide a per-deploy record of *what changed, from which commit, when*.

## Pre-requisites on the operator workstation

- Git installed and authenticated to GitHub (Git Credential Manager is fine).
- Network access to `\\192.168.1.16\xampp\htdocs` with write permission.
- XAMPP PHP at `C:\xampp\php\php.exe` (used only for linting; deploy still runs
  without it, just without the lint gate).
