# PSPF Helpdesk — UI Smoke Tests

Automated [Playwright](https://playwright.dev) tests that check the app is healthy
every day: the sign-in page loads, bad logins are rejected, and (optionally) a real
account can log in and reach the dashboard.

These run **locally**, against the app on the internal network
(default `http://hpkprd/pspf_crm/api/signin/index.php`). They do not need internet
access to the app — only to a machine that can reach `hpkprd`.

## What it checks

| Test | Needs a test account? | What it proves |
|------|:---:|----------------|
| Sign-in page loads and renders the form | No | App is up; PHP isn't fatal-erroring |
| Invalid login shows an error | No | Auth path is alive and rejects bad creds |
| Valid account reaches the dashboard | Yes | End-to-end login actually works |

The first two run with no configuration. The third runs only when you provide a
test account (see below); otherwise it is skipped.

## One-time setup

Run these from the `ui-tests` folder on a machine that can reach the app.

1. Install [Node.js](https://nodejs.org) (18 or newer).
2. Install dependencies and the browser:
   ```
   npm install
   npm run install:browser
   ```
3. Create your local config:
   ```
   copy .env.example .env      # Windows
   # cp .env.example .env      # macOS/Linux
   ```
   Edit `.env`:
   - `BASE_URL` — the app's base URL (default `http://hpkprd`).
   - `TEST_EMAIL` / `TEST_PASSWORD` — optional; a **throwaway** test account to
     exercise the real login. Leave blank to skip that test.

   `.env` is gitignored — never commit real credentials.

## Running

```
npm test            # run all tests headless
npm run test:headed # watch it drive a real browser
npm run report      # open the HTML report from the last run
```

A failing run prints which check failed and, thanks to the config, keeps a
screenshot / video / trace under `test-results/` for that failure.

## Running it every day (Windows Task Scheduler)

`run-daily.ps1` runs the suite once and archives each day's HTML report under
`history\<date>\`. It exits non-zero on failure so Task Scheduler flags the run.

Register a daily 7:00 AM task (run once, in an elevated PowerShell):

```powershell
$action  = New-ScheduledTaskAction -Execute 'powershell.exe' `
  -Argument '-ExecutionPolicy Bypass -File "C:\xampp\htdocs\ui-tests\run-daily.ps1"'
$trigger = New-ScheduledTaskTrigger -Daily -At 7:00AM
Register-ScheduledTask -TaskName 'PSPF Helpdesk UI Smoke' `
  -Action $action -Trigger $trigger -RunLevel Highest -Description 'Daily UI smoke tests'
```

Adjust the path to wherever this repo lives on the server. To be notified of
failures, point the task's "on failure" at an email/notification step, or check
`history\` each morning.

### macOS / Linux (cron alternative)

```
# every day at 07:00 — run in the ui-tests folder
0 7 * * * cd /path/to/PSPF_Helpdesk/ui-tests && npm test >> history/cron.log 2>&1
```

## Extending

Start here, then grow coverage as needed — e.g. creating a ticket, agent/admin
views, the vehicle-booking flow. Add new `*.spec.ts` files under `tests/`.
Keep flows that change data behind a dedicated test account so daily runs stay safe.
