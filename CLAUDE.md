# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Migration in progress:** A full stack rewrite has been planned. See [`ARCHITECTURE_MIGRATION.md`](ARCHITECTURE_MIGRATION.md) for the target stack (Django + DRF + PostgreSQL + Redis + React/Vite + Docker). The PHP codebase documented below remains live until cutover. Do not start new feature modules in PHP — new modules belong in the new stack.

---

## Project Overview

PSPF Helpdesk CRM — an internal role-based helpdesk system for PSPF. Four roles: **user, agent, admin, superadmin**. Core modules: ticket management, food ordering, vehicle booking (separate sub-app at `vehicle_booking/`), reporting, and user management.

---

## Environment

- **Runtime**: PHP 8.2, Apache 2.4, MariaDB 10.4 via XAMPP on Windows
- **PHP binary**: `C:\xampp\php\php.exe`
- **Composer**: `C:\xampp\php\composer.phar` (run as `& "C:\xampp\php\php.exe" "C:\xampp\php\composer.phar" ...`)
- **Web root**: `C:\xampp\htdocs\pspf_crm\` — the `api/` folder is the application root served by Apache
- **Two databases**: `pspf_helpdesk` (main CRM) and `vehicle_requisition` (vehicle booking sub-app)

---

## Common Commands

**Install dependencies (after pulling or adding a package):**
```powershell
& "C:\xampp\php\php.exe" "C:\xampp\php\composer.phar" install --no-dev
```

**Add a Composer package:**
```powershell
Set-Location "C:\xampp\htdocs\pspf_crm"
& "C:\xampp\php\php.exe" "C:\xampp\php\composer.phar" require vendor/package
```

**Syntax check all PHP files (mirrors CI):**
```powershell
Get-ChildItem -Path "C:\xampp\htdocs\pspf_crm" -Filter "*.php" -Recurse |
  Where-Object { $_.FullName -notmatch "\\vendor\\" -and $_.FullName -notmatch "\\PHPMailer-master\\" } |
  ForEach-Object { & "C:\xampp\php\php.exe" -l $_.FullName }
```

**Syntax check a single file:**
```powershell
& "C:\xampp\php\php.exe" -l "C:\xampp\htdocs\pspf_crm\api\ticket\some_file.php"
```

**Create a new user (CLI):**
```powershell
& "C:\xampp\php\php.exe" "C:\xampp\htdocs\pspf_crm\database\create_user.php"
```

**Deploy to production (run on production server via Remote Desktop):**
```powershell
.\deploy.ps1
```

---

## Credentials & Config

- **Database credentials**: `api/includes/confi.ini` — gitignored. Copy from `api/includes/confi.ini.example` on fresh installs.
- **Database connection**: `api/db.php` — gitignored. Uses mysqli, connects to `pspf_helpdesk` on `127.0.0.1` as root.
- **Mail**: Internal SMTP at `192.168.1.15:25`, no auth. Configured in `api/mail_config.php` via `getMailer()`.
- **PHP extensions required**: `ext-gd`, `ext-zip`, `ext-mbstring` — must be enabled in `php.ini` (uncomment the lines). Required by PhpSpreadsheet.

---

## Architecture

### Request flow
There is no front controller or router. Each PHP file is a direct URL endpoint. A request to `/api/ticket/query.php` runs that file directly. Authentication is enforced at the top of every file by calling helpers from `api/includes/auth_helpers.php`.

### Authentication & authorisation
Every protected file starts with this pattern:
```php
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

enforceActiveUser($conn);          // redirects if user is disabled
enforcePasswordPolicy($conn);      // redirects if password expired (90-day policy)
requireAnyRole(['admin', 'superadmin']);  // redirects to signin if role not matched
```

Key functions in `auth_helpers.php`:
- `getActiveRole()` / `setActiveRole()` — session-stored active role (users can hold multiple roles and switch in-session via `api/switch_role.php`)
- `requireAnyRole(array)` — access guard; redirects to `api/signin/` on failure
- `enforceActiveUser($conn)` — checks `is_active` flag in DB, destroys session if false
- `renderRoleSwitcher()` — returns HTML dropdown for the nav bar; hidden for single-role users
- `getRoleHomePage($role)` — canonical redirect target per role

### Session
Configured in `api/session_config.php` (24h lifetime, httponly, strict mode). Always call `session_start()` before any output. `$_SESSION['user']` holds: `id`, `username`, `email`, `department`, `role`, `roles[]`, `active_role`.

### Database
All queries use **mysqli prepared statements** with `bind_param()`. The connection is `$conn` (global, from `api/db.php`). Dynamic WHERE clauses are built by appending to `$whereClauses[]` and `$params[]` arrays with a `$types` string, then spread into `bind_param($types, ...$params)`.

The vehicle booking sub-app (`vehicle_booking/`) uses **PDO** instead of mysqli and has its own `vendor/` directory.

### Exports (Excel / PDF)
- **Excel**: All exports use PhpSpreadsheet (`vendor/phpoffice/phpspreadsheet`). Every export file calls `ob_start()` at the top and `ob_end_clean()` immediately before sending headers — this is intentional and required because `api/db.php` has a closing `?>` tag that emits a newline which corrupts binary output.
- **Shared styling**: `api/includes/xlsx_styles.php` — call `applyXlsxStyles($sheet, $headers, $rowCount, $title)` after writing all data. It inserts a title row, applies the colour scheme, sets column widths (max 40 chars, wraps beyond that), and freezes panes.
- **PDF**: mPDF for ticket/order reports (`vendor/mpdf/mpdf`), DOMPDF for some legacy views.

### CSRF
Tokens are generated in `auth_helpers.php` and stored in `$_SESSION['csrf_token']`. POST forms that mutate state should include a hidden `csrf_token` field and verify it server-side.

### Role switcher
Multi-role users see a dropdown in the nav bar. Switching posts to `api/switch_role.php`, which calls `setActiveRole()` and redirects to the new role's dashboard. The active role (not the user's base role) controls which access guards pass.

---

## Module Layout

| Folder | Purpose |
|---|---|
| `api/admin/` | Admin and superadmin views — dashboard, ticket management, reports, user management |
| `api/agent/` | Agent portal — dashboard and assigned ticket list |
| `api/ticket/` | Core ticket logic — creation, status changes, escalation, exports, AJAX endpoints |
| `api/order/` | Food ordering system |
| `api/reports/` | Reporting views (currently shares `ticket_status_logs.php` with `api/admin/`) |
| `api/settings/` | User profile and user management |
| `api/signin/` | Login, registration, password reset |
| `api/includes/` | Shared helpers — auth, session, mail, Excel styling, ticket status functions |
| `api/assets/` | Static frontend assets (Bootstrap Icons, fonts) |
| `database/` | `schema.sql` (clean schema), `seed_admin.sql` (default admin), `create_user.php` (CLI) |
| `vehicle_booking/` | Separate vehicle requisition sub-app with its own vendor/ and database |

Root `api/` files: `db.php`, `session_config.php`, `mail_config.php`, `footer.php`, `user_dashboard.php`, `dashboard.php` (role-based redirect), `switch_role.php`, `unauthorized.php`.

---

## Known Technical Debt

- **Duplicate files**: `api/includes/ticket_status_functions_.php` is a near-duplicate of `ticket_status_functions.php` — use `ticket_status_functions.php` only.
- **Duplicate views**: `api/admin/ticket_status_logs.php` and `api/reports/ticket_status_logs.php` are identical — `api/admin/` is canonical.
- **Near-duplicate AJAX handlers**: `api/ticket/update_ticket_status_ajax.php` and `update_ticket_status_ajax1.php` differ only by a hardcoded IP in the feedback email link.
- **God files**: `api/admin/admin_view.php` (~1800 lines), `api/admin/ticket_progress.php` (~1150 lines), `api/admin/admin_dashboard.php` (~970 lines) mix SQL, business logic, and HTML.
- **Debug files in production path**: `api/debug_session.php`, `api/debug_roles.php` — do not expose these.
- **Dead export file**: `api/admin/export_status_logs_csv.php` is no longer linked anywhere; superseded by `export_status_logs_excel.php`.

---

## CI / Deployment

- **CI**: GitHub Actions (`.github/workflows/ci.yml`) — runs PHP 8.2 syntax check on every push/PR to `main`. Must pass before deploying.
- **Deploy**: Production is on-premise Windows, company network only. Run `deploy.ps1` on the production server via Remote Desktop. It previews incoming commits, confirms, runs `git pull`, then `composer install --no-dev`.
- **First-time production setup**: Enable `ext-gd` and `ext-zip` in the production `php.ini`; copy `confi.ini` and `db.php` manually (gitignored).
- **`vendor/` is gitignored** — `composer install` must be run on the production server; `deploy.ps1` handles this automatically.
