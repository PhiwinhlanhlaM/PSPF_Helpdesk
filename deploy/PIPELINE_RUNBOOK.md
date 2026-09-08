# PSPF Helpdesk — Deploy Pipeline Runbook

Operator + auditor guide for the reviewed, one-click delivery pipeline described
in [`PIPELINE_DESIGN.md`](PIPELINE_DESIGN.md). This document covers the built
system: how it is installed, how a deploy flows, and how to recover.

---

## 1. Components (what actually runs)

| Component | Where | Role |
|---|---|---|
| **Deploy dashboard** | CRM: `pspf_crm/api/deploy/index.php` (superadmin-only) | Web UI. Queues intent, reviews diff+drift, approves/declines. **Never executes anything.** |
| **`deploy_requests` / `deploy_state`** | `pspf_helpdesk` DB | The queue + audit trail + last-deployed marker. |
| **Runner** | `deploy/runner.ps1` (scheduled task, service account) | The **only** actor that runs git and touches live files. Polls the queue. |
| **Deploy engine** | `deploy/deploy.ps1` | Fetch → diff → drift-check → lint → backup → apply → verify → auto-rollback. |
| **Rollback** | `deploy/rollback.ps1` | Restores any prior deploy from its backup. |

**Security property:** the web tier only reads/writes two DB tables. Even if the
CRM were compromised, an attacker could at most queue a deploy of an already-
committed commit from your own repo, which still passes lint + backup + drift-
check + a recorded human approval. There is no path from the web page to code
execution. See PIPELINE_DESIGN.md §7.

---

## 2. One-time install

### 2.1 Database
Apply the migration to the **live** `pspf_helpdesk` DB (manual, by policy):
```
mysql -u root -p pspf_helpdesk < pspf_crm/api/deploy/migrations/001_deploy_pipeline.sql
```
Idempotent — safe to re-run. Creates `deploy_requests` + `deploy_state` (seeded).

### 2.2 Service account (the runner's identity)
Create a **dedicated** Windows account (not a human login), e.g. `svc_deploy`,
with least privilege:
- **Write** access to `\\192.168.1.16\xampp\htdocs`.
- **Read** access to the GitHub repo — configure git credentials for that
  account (a fine-grained read-only PAT, or Git Credential Manager under its
  profile).
- **No DB admin.** It only needs read/write on `deploy_requests` + `deploy_state`
  (it uses the same MySQL login the CRM uses today; scope it down if desired).

> The live MySQL only accepts `127.0.0.1`, so the runner must run **on the live
> box** (or a host with a DB tunnel). Point it at the DB with the `PSPF_DB_*`
> env vars if the defaults (root / no password / pspf_helpdesk) don't apply.

### 2.3 Install the scheduled task
On the runner host, as an admin:
```powershell
cd <path>\deploy
.\install-runner-task.ps1 -User "PSPF\svc_deploy" -IntervalMinutes 1
```
This registers **“PSPF Deploy Runner”** to run `runner.ps1 -Once` at startup and
every minute. Verify:
```powershell
Get-ScheduledTask -TaskName "PSPF Deploy Runner"
```
Remove with `.\install-runner-task.ps1 -Remove`.

---

## 3. Normal deploy flow (the happy path)

1. **Developer** pushes to `main` on GitHub. *Nothing happens on the server.*
2. **Superadmin** opens **Settings → Deployments** in the CRM and clicks
   **Check for updates**. This inserts a `check` request.
3. Within ~1 min the **runner** fetches `main`, computes the diff vs the last-
   deployed commit and a drift report, and sets the request to `ready`
   (or `no_change`).
4. The superadmin refreshes the dashboard and reviews: commit message, author,
   the exact file list (NEW/CHANGED), and any drift.
5. **Approve & deploy** (or **Decline** with a reason).
6. The runner runs `deploy.ps1`: lint → backup → drift-guard → apply → post-
   deploy verify (auto-rollback on any failure). On success it advances
   `deploy_state.last_deployed_sha` and marks the request `deployed`.
7. The dashboard shows the outcome; every step is a row in `deploy_requests`.

**Runner health:** the dashboard shows an online/offline dot from
`deploy_state.runner_heartbeat` (healthy = beat within 5 min). Offline → requests
queue but nothing deploys (safe default).

---

## 4. Drift — what it means and how to clear it

**Drift** = an in-scope live file was edited directly on the server (outside the
pipeline) since the last deploy. Deploying would silently overwrite that work, so
the pipeline **refuses** (dashboard blocks Approve; the runner aborts a deploy
and marks it `failed`). Protected config (`db.php`, `mail_config.php`,
`sharepoint_config.php`) is exempt — it is expected to differ per environment.

To clear drift:
1. Inspect the listed files on live.
2. **Reconcile into the repo:** copy the intended live changes back into the
   GitHub repo and commit them (re-mirror), so the repo reflects reality.
3. Deploy the reconciled repo through the pipeline as normal.

> Escape hatch (rare): `deploy.ps1 -AllowDrift` overrides the guard. Only use it
> when the live edits are known-disposable, and never wire it into the runner.

---

## 5. Failure & recovery

| Situation | What happens | Operator action |
|---|---|---|
| Deploy fails mid-apply | `deploy.ps1` **auto-rolls-back**; live restored to pre-deploy state; request `failed`, reason in `log_excerpt` | Read the reason; fix; re-check/approve |
| Post-deploy PHP broken on live | Verify step fails → **auto-rollback** | Same |
| Drift detected | Deploy refused, request `failed` | Reconcile (§4) |
| Runner down | Nothing deploys; dashboard shows “offline” | Restart the scheduled task / host |
| Need to undo a *successful* deploy | Each deploy has a timestamped backup + `manifest.json` under `htdocs\_deploy_backups\` | `.\rollback.ps1 -BackupDir "...\backup_YYYYMMDD_HHMMSS"` |

After a manual rollback, also correct `deploy_state.last_deployed_sha` if you
rolled back to an earlier commit, so drift is measured from the right baseline.

---

## 6. Database migrations (still manual, by policy)

The pipeline **never** runs SQL. When a deploy includes new `*.sql` files (e.g.
under `.../migrations/`), `deploy.ps1` lists them. To apply, on the live box:
1. Back up the live DB (phpMyAdmin export or `mysqldump`).
2. Review the SQL.
3. Run it against the live DB (MySQL only accepts `127.0.0.1`).
4. Record date / file / operator for the audit trail.

---

## 7. Testing against staging (before trusting production)

`deploy.ps1` and `runner.ps1` accept overrides so you can exercise the whole
flow without touching production:

```powershell
# Point the deploy engine at a throwaway copy of htdocs.
$env:PSPF_LIVE_ROOT = "C:\staging\htdocs"

# (Optional) point the runner at a staging DB.
$env:PSPF_DB_HOST = "127.0.0.1"; $env:PSPF_DB_NAME = "pspf_helpdesk_staging"

# Run one runner cycle by hand.
.\runner.ps1 -Once
```
Seed `deploy_state.last_deployed_sha` to an older commit, insert a `check` row,
and watch it flow to `ready`; then flip it to an approved `deploy` and confirm it
applies + advances the SHA. (This is exactly how the pipeline was validated:
check→ready, drift→refuse, clean→deployed.)

---

## 8. Audit trail (what an auditor can rely on)

- **Every** action is an immutable row in `deploy_requests`: who requested, who
  decided, when, which commit, the reviewed diff, the drift report, the outcome,
  and a log excerpt.
- Each applied deploy also writes `manifest.json` (commit, branch, file list) and
  `deploy.log` into its backup folder under `htdocs\_deploy_backups\`.
- `deploy_state` records the currently-live commit and the last approver.
- The runner writes a daily log to `%TEMP%\pspf_deploy\runner_logs\`.

---

## 9. Open items carried from the design (team decisions)

These were flagged in PIPELINE_DESIGN.md §10 and are wired as follows:

1. **Runner cadence** — installed at 1 min; change with `-IntervalMinutes`.
2. **Separation of duties** — dashboard supports it via
   `$ENFORCE_SEPARATION_OF_DUTIES` (default **off**; turn on once ≥2 superadmins).
3. **Service account** — must be chosen at install (§2.2); do not use a human login.
4. **Notifications** — not built yet (email-on-pending / email-on-deploy). Can
   reuse the CRM mailer; add when desired.
5. **Local vs server** — dashboard is intended **server-only**; the local dev box
   edits itself directly and needs no approval gate.
