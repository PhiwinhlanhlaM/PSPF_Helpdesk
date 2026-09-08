# PSPF Helpdesk — Continuous Delivery Pipeline (Design & Runbook)

**Status:** BUILT — implemented per §11. This doc is the design of record; the
operator/auditor guide is [`PIPELINE_RUNBOOK.md`](PIPELINE_RUNBOOK.md).
**Audience:** CRM/IT team + auditors.
**Scope:** Controlled, reviewable deployment of the GitHub repo to the live CRM
server (`\\192.168.1.16\xampp\htdocs`), driven from a CRM approval dashboard.

---

## 1. Goal

Let the team ship repo changes to production through a **reviewed, one-click,
audited** flow surfaced inside the CRM, without exposing production to remote code
execution and without silently overwriting work done directly on live.

This is **continuous delivery with a human gate** (one approval step from repo to
live), *not* fully automatic continuous deployment. That distinction is deliberate
for a production, audited system.

---

## 2. Constraints that shape the design (facts, not preferences)

1. **The live server is LAN-only.** `192.168.1.16` has a private IP; GitHub cannot
   reach it. Therefore **GitHub webhooks are impossible** — detection must be
   initiated from inside the network (pull, not push).
2. **A web page that runs git/shell = RCE over HTTP.** Anything the Apache/PHP
   process can execute, an attacker who reaches that page can execute. So the web
   tier must never invoke git or the deploy script directly.
3. **People sometimes edit live directly.** (Proven: `vehicle_booking` drifted.)
   Any repo→live process must detect drift and refuse to clobber it.
4. **The DB is never touched by deploys.** Schema/data migrations remain manual.
5. **`vehicle_booking` is out of scope** and excluded from all deploys.

---

## 3. Design decisions (agreed)

| Decision | Choice | Why |
|---|---|---|
| CRM UI | **Approval dashboard + read-only diff viewer** | No terminal, no exec surface |
| Trigger | **Manual "Check for updates" button** | No unattended automation; zero auto-deploy risk |
| Drift | **Block deploy + alert** | Never overwrite direct-on-live edits |
| Execution | **Decoupled runner** (PHP queues intent; PowerShell acts) | Removes RCE from the web tier |
| State store | **`deploy_requests` table** in `pspf_helpdesk` | Integrates with CRM auth + audit/history |

---

## 4. Architecture

```
Developer → push to GitHub main
                                   (nothing auto-happens on the server)

┌─────────────────────────── CRM (Apache/PHP) ───────────────────────────┐
│  Deploy dashboard  (superadmin only)                                    │
│   • "Check for updates"  → INSERT deploy_requests(type=check)           │
│   • shows pending deployment: commit msg + author + REAL diff + drift   │
│   • Approve / Decline(reason) → UPDATE status                           │
│                                                                         │
│   The web tier ONLY reads/writes the deploy_requests table.            │
│   It NEVER runs git, PowerShell, or shell commands.                     │
└─────────────────────────────────────────────────────────────────────────┘
                                   │  (table = the queue)
                                   ▼
┌──────────────── Runner (PowerShell, scheduled task, runs as a          ┐
│                  privileged service account on the server) ~every 60s)  │
│   Reads deploy_requests:                                                 │
│   • type=check   → git fetch; compute diff vs live + drift; status=ready │
│   • status=approved → run deploy.ps1 (lint, backup, drift-check, apply,  │
│                        auto-rollback); write outcome; status=deployed/   │
│                        failed; record deployed SHA                       │
│   The runner is the ONLY component that touches git and live files.     │
└─────────────────────────────────────────────────────────────────────────┘
```

**Why decoupling matters (the core security property):** the web page expresses
*intent only*. Even if the CRM were compromised, the attacker can at most queue a
deploy of an **already-committed commit from your own GitHub repo**, which still
passes through lint + backup + drift-check + human approval. They cannot run
arbitrary code, because the web tier has no execution path.

---

## 5. Data model — `deploy_requests` (in `pspf_helpdesk`)

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK AI | |
| `type` | ENUM('check','deploy') | check = look for updates; deploy = apply |
| `status` | ENUM('pending','checking','ready','approved','declined','deploying','deployed','failed','no_change') | lifecycle |
| `commit_sha` | VARCHAR(40) | target commit |
| `commit_msg` | TEXT | the change-request description |
| `commit_author` | VARCHAR(150) | from git |
| `diff_summary` | MEDIUMTEXT (JSON) | files: NEW/CHANGED + counts (what the approver sees) |
| `drift_report` | MEDIUMTEXT (JSON) | files on live that differ from last-deployed SHA |
| `requested_by` | INT FK users | who clicked Check |
| `decided_by` | INT FK users | who approved/declined |
| `decided_at` | DATETIME | |
| `decision_reason` | TEXT | required on decline |
| `deployed_sha` | VARCHAR(40) | recorded after success |
| `log_excerpt` | MEDIUMTEXT | tail of the deploy log |
| `created_at` / `updated_at` | DATETIME | |

A tiny companion marker (`deploy_state`: `last_deployed_sha`, `updated_at`) records
the last commit successfully deployed, so drift can be measured against it.

---

## 6. Runner responsibilities (the privileged actor)

Runs as a **dedicated service account** (NOT the web server user) via Task
Scheduler. One instance at a time (lock file). For each cycle:

1. **check** requests → `git fetch origin main`; if `main` is ahead of
   `last_deployed_sha`, compute the diff (reusing `deploy.ps1 -DryRun` logic) and a
   **drift report** (live vs `last_deployed_sha` for in-scope files). Set `ready`
   (or `no_change`). Store commit msg/author/diff/drift for the UI.
2. **approved** requests → run `deploy.ps1` (existing engine: PHP-lint gate,
   timestamped backup, transactional apply, **auto-rollback on failure**,
   post-deploy verification, config-preserving, `vehicle_booking` excluded, DB
   untouched). On drift detected → **abort, status=failed**, attach drift report.
   On success → update `last_deployed_sha`, status=`deployed`.
3. Write `log_excerpt` + outcome back to the row for the dashboard/audit.

---

## 7. Security model (audit-facing)

- **No RCE surface:** web tier cannot execute anything; only the runner can, and it
  only runs the fixed, reviewed `deploy.ps1` against your own repo.
- **AuthN/AuthZ:** dashboard is **superadmin-only**, enforced by held-role check
  (`hasRole('superadmin')`), CSRF-protected, same session model as the CRM.
- **Separation of duties (optional, recommended):** the person who clicks *Check*
  should not be the same as who *Approves* (enforceable via a config flag).
- **Least privilege:** runner service account has write access only to `htdocs` and
  read access to the repo; it does NOT need DB admin (only read/write to
  `deploy_requests`).
- **Immutable audit trail:** every check/approve/decline/deploy is a row with who,
  when, commit, diff, and outcome. Backups + manifests per deploy already exist.
- **No secrets in the flow:** config files remain protected/never overwritten;
  repo has placeholders only.
- **Drift guard:** production edits made outside the pipeline block a deploy rather
  than being silently lost.

---

## 8. Failure & recovery

- **Deploy fails mid-apply:** `deploy.ps1` auto-rolls-back to pre-deploy state
  (already built + tested). Row marked `failed` with log.
- **Drift detected:** deploy refused; team reconciles (re-mirror live → repo) then
  retries. (A future `sync-live-to-repo` helper can make this one step.)
- **Runner down:** nothing deploys (safe default). Dashboard shows requests stuck
  in `pending`/`approved`; a health indicator flags a stale runner.
- **Rollback:** `rollback.ps1` restores any prior deploy from its backup.

---

## 9. What we deliberately do NOT build

- ❌ Browser terminal / any shell-in-a-page.
- ❌ PHP invoking git or PowerShell.
- ❌ Automatic deploy without human approval.
- ❌ Any deploy path that can touch `vehicle_booking` or the database.

---

## 10. Open questions for the team

1. **Runner cadence:** every 60s? 5 min? (Latency between approve and live.)
2. **Separation of duties:** enforce different people for Check vs Approve?
3. **Service account:** which Windows account runs the runner? (Needs htdocs write
   + repo read; ideally not a human's login.)
4. **Notifications:** email the team when a deployment is pending approval / after a
   deploy? (Reuses the CRM mailer.)
5. **Local dashboard:** you mentioned "locally and on the server." Recommendation:
   build it **server-only** (local is your dev box; it changes itself directly and
   needs no approval gate). Confirm.

---

## 11. Build order — DONE

1. ✅ `deploy_requests` + `deploy_state` tables — `pspf_crm/api/deploy/migrations/001_deploy_pipeline.sql`.
2. ✅ `last_deployed_sha` tracking + drift-check in `deploy.ps1` (adds
   `-LastDeployedSha`, `-NonInteractive`, `-AutoApprove`, `-AllowDrift`,
   `-JsonOut`, `-LiveRoot`).
3. ✅ `runner.ps1` + `install-runner-task.ps1` (scheduled task, lock, heartbeat).
4. ✅ CRM dashboard `pspf_crm/api/deploy/index.php` — superadmin-only,
   check/approve/decline, diff + drift viewer, history, runner-health dot.
   Nav link added under Settings (superadmin).
5. ✅ Validated end-to-end on a staging path (`-LiveRoot` / `PSPF_LIVE_ROOT`):
   check→ready, drift→refuse, clean→deployed, SHA advanced.
6. ✅ Operator runbook + audit notes — `PIPELINE_RUNBOOK.md`.

Remaining team decisions (not code) are tracked in the runbook §9: enable
separation-of-duties once ≥2 superadmins, choose the runner service account at
install time, and optionally add email notifications.
