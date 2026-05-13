# Changelog

All notable changes to the PSPF Helpdesk CRM are documented here.

---

## [Unreleased] — 2026-05-12

### Added

#### CI/CD — GitHub Actions syntax check + manual deploy script
**Problem:** Deploying to the production server required manually copying individual files over Remote Desktop with no safety net to catch broken PHP before it reached production.

**Solution:** Three-part setup covering code safety and deployment:

- **`deploy.ps1`** — PowerShell script to run on the production server via Remote Desktop. Shows incoming commits, asks for confirmation, runs `git pull`, then `composer install --no-dev`. Aborts cleanly if anything fails. Run it by right-clicking → "Run with PowerShell" or from a terminal with `.\deploy.ps1`.
- **`.github/workflows/ci.yml`** — GitHub Actions workflow that triggers on every push or pull request to `main`. Installs dependencies and runs `php -l` across every project PHP file (excluding `vendor/`). If any file has a syntax error it blocks the push and reports the exact file and line in GitHub.
- **`composer.lock`** — committed to the repo so `composer install` on the server installs the exact same package versions that were tested locally.

**Files added:**
- `deploy.ps1`
- `.github/workflows/ci.yml`

---

### Changed

#### Excel exports — converted from CSV to real XLSX format
**Problem:** All Excel exports were outputting CSV files (`.csv` extension, `text/csv` content type). Although CSV opens in Excel, the organisation standard requires `.xlsx` files for Excel exports.

**Fix:** Installed `phpoffice/phpspreadsheet` via Composer (`composer require phpoffice/phpspreadsheet`). Required enabling `ext-gd` and `ext-zip` in `php.ini` (both were commented out). Rewrote all six CSV export files to use PhpSpreadsheet's `Xlsx` writer — each export now produces a genuine `.xlsx` workbook with a bold blue header row, alternating row shading, and auto-sized columns. Created `export_status_logs_excel.php` as the xlsx replacement for the old `export_status_logs_csv.php` and updated all links that referenced the old file.

**Files changed:**
- `api/admin/export_admin_excel.php` — rewritten from CSV to XLSX
- `api/agent/export_agent_excel.php` — rewritten from CSV to XLSX
- `api/ticket/export_stats_excel.php` — rewritten from CSV to XLSX
- `api/ticket/export_status_excel.php` — rewritten from CSV to XLSX
- `api/ticket/closure_report.php` — Excel export block rewritten from CSV to XLSX; PhpSpreadsheet `use` declarations added at top
- `api/admin/export_status_logs_excel.php` — new file (replaces `export_status_logs_csv.php`)
- `api/admin/ticket_status_logs.php` — Export link updated to point at `export_status_logs_excel.php`
- `api/reports/ticket_status_logs.php` — Export link updated to point at `export_status_logs_excel.php`
- `composer.json` — added `phpoffice/phpspreadsheet ^5.7`

---

### Added

#### Role Switcher — In-session role switching
**Problem:** Users with multiple roles had to log out and log back in to switch roles, which was disruptive to workflow.

**Files added:**
- `api/switch_role.php` — New handler that validates the requested role belongs to the current user, updates the active role in the session, and redirects to the appropriate dashboard. Accepts POST with CSRF token protection.

**Files changed:**
- `api/includes/auth_helpers.php` — Rewrote `renderRoleSwitcher()` to use POST form submissions pointing at `switch_role.php` instead of bare `?switch_role=` GET links. The toggle now hides itself entirely for single-role users and marks the active role with a checkmark.
- `api/agent/topnav.php` — Added role toggle to the desktop navbar (pill-shaped dropdown between the badge strip and avatar) and to the mobile user menu dropdown, above the Logout button.
- `api/order/topnav.php` — Same role toggle additions as `agent/topnav.php` (both navbars are shared across all views).

---

#### Version Control Setup — GitHub repository initialisation
**Problem:** The project had no version control, making collaboration and change tracking difficult.

- Initialised git repository inside `htdocs/pspf_crm/` (moved from the incorrectly placed `htdocs/` root).
- Created `.gitignore` to exclude: `vendor/`, credentials (`api/includes/confi.ini`, `api/db.php`), raw PHPMailer source (`PHPMailer-master/`), Visual Studio metadata (`.vs/`), and loose `.sql` data dumps.
- Removed duplicate `htdocs/vehicle_booking/` folder — confirmed `pspf_crm/vehicle_booking/` is the active copy.
- Added `api/includes/confi.ini.example` as a safe credentials template for collaborators.

---

#### Database Setup Files
**Problem:** No way for a collaborator to build the database on a fresh install — SQL dump files contained live operational data and couldn't be committed.

**Files added:**
- `database/schema.sql` — Clean schema for both `pspf_helpdesk` and `vehicle_requisition` databases. Contains all `CREATE TABLE` statements, indexes, foreign keys, triggers, and seed data for reference tables only (`departments`, `divisions`, `roles`, `outlets`). All transactional data removed.
- `database/seed_admin.sql` — Inserts a default superadmin account (`admin@pspf.co.sz` / `Admin@1234`) so a fresh install has an account to log in with.
- `database/create_user.php` — Interactive CLI script (`php database/create_user.php`) to create users and assign one or more roles without going through the web UI.

---

#### README & CHANGELOG
**Problem:** The GitHub repository had an empty README with no setup instructions.

**Files added:**
- `README.md` — Full project documentation covering features, tech stack, installation steps, user creation, project structure, role switching, auto-escalation cron setup, security notes, and contributing guide.
- `CHANGELOG.md` — This file.

---

### Fixed

#### Remaining exports converted from client-side to server-side
**Problem:** Three export triggers were still client-side and therefore limited to the currently visible page rows:
- `admin_view.php` PDF export used jsPDF + autoTable on the visible HTML table (10 rows per page).
- `admin/ticket_status_logs.php` and `reports/ticket_status_logs.php` Export buttons triggered DataTables' built-in `.buttons-excel` which only exported the currently rendered rows (50 per page).
- `api/order/all_orders.php` Generate PDF button used jsPDF + autoTable on the visible table (25 rows per page).

**Fix:** Created three new server-side export handlers and rewired each button to a plain `<a>` link passing the current active filters:

- **`api/admin/export_admin_pdf.php`** (new) — mirrors `admin_view.php` role-aware query, renders all matching tickets into a landscape mPDF document with alternating row colours and a page-number footer.
- **`api/admin/export_status_logs_csv.php`** (new) — mirrors `ticket_status_logs.php` query (ticket ID, status, date range, changed-by filters), fetches all log entries without `LIMIT`, outputs CSV with 10 columns including previous/new status, reason, and department. Shared by both `admin/` and `reports/` copies of the page.
- **`api/order/export_orders_pdf.php`** (new) — mirrors `all_orders.php` query (date, outlet, type filters), fetches all matching orders, renders a landscape mPDF delivery receipt with totals summary and signature block.

**Files changed:**
- `api/admin/export_admin_pdf.php` — new file
- `api/admin/export_status_logs_csv.php` — new file
- `api/order/export_orders_pdf.php` — new file
- `api/admin/admin_view.php` — PDF export button replaced with server-side link
- `api/admin/ticket_status_logs.php` — Export button replaced with server-side link
- `api/reports/ticket_status_logs.php` — Export button replaced with server-side link
- `api/order/all_orders.php` — Generate PDF button replaced with server-side link

---

#### Excel exports — incomplete columns and client-side pagination on agent export
**Problem:** Three issues across all Excel exports:
1. `agent_view.php` used client-side SheetJS (`XLSX.utils.table_to_book`) to export the visible HTML table — only the current page's 10 rows were exported, and columns like Source, Branch, Member Type, Description, and Assigned To were absent.
2. `export_stats_excel.php` (used by the general query log report) was missing Member Type, Division, Created By, Assigned To, and Description columns.
3. `closure_report.php` Excel export was missing Title, Member Type, Source, Priority, and Closure Reason columns.

**Fix:**
- Created `api/agent/export_agent_excel.php` — server-side handler that mirrors the exact `agent_view.php` query (scoped to the agent's division and email, with search filter support) but without `LIMIT`, producing a full CSV of all assigned tickets with 12 columns.
- Replaced the `exportExcel()` JS button in `agent_view.php` with an `<a>` link to the new handler passing the current search filter.
- Expanded `export_stats_excel.php` SQL to include `member_type`, `division_name` (via JOIN), `created_by`, `assigned_to`, `description`; updated CSV headers to match.
- Expanded `closure_report.php` Excel export SQL to include `title`, `member_type`, `source`, `priority`, `closure_reason`; updated CSV headers to match.

**Files changed:**
- `api/agent/export_agent_excel.php` — new file
- `api/agent/agent_view.php` — export button replaced
- `api/ticket/export_stats_excel.php`
- `api/ticket/closure_report.php`

---

#### Excel exports — file format mismatch warning in MS Excel
**Problem:** All Excel export files used the "HTML table trick" — outputting an HTML `<table>` with `Content-Type: application/vnd.ms-excel` and a `.xls` extension. Excel can open these files but detects that the content is HTML, not a real binary Excel file, and shows a format-mismatch warning every time.

**Fix:** Converted all 5 export files from HTML-table-as-xls to proper CSV output (`Content-Type: text/csv`, `.csv` extension, PHP `fputcsv()`). CSV is a plain-text format that Excel opens natively with no warnings, no library dependencies, and correct column handling.

**Files changed:**
- `api/ticket/export_stats_excel.php`
- `api/ticket/export_status_excel.php`
- `api/ticket/closure_report.php` (Excel export block only)
- `api/admin/export_admin_excel.php`
- `vehicle_booking/report_excel.php`

---

#### Admin ticket view — Excel export only downloaded the current page
**Problem:** The "Export to Excel" button in `admin_view.php` used a client-side JavaScript function (`XLSX.utils.table_to_book`) that converted the visible HTML table to Excel. Because the table is paginated to 10 rows at a time, the export only ever contained 10 rows regardless of how many tickets existed.

**Fix:** Created a new server-side export handler `export_admin_excel.php` that runs the exact same role-aware, filter-aware SQL query as `admin_view.php` but without any `LIMIT`/`OFFSET`, then streams the full result as a downloadable `.xls` file. The Export to Excel button in `admin_view.php` was replaced with an `<a>` link that passes the currently active filters to the new handler.

**Files changed:**
- `api/admin/export_admin_excel.php` — new file
- `api/admin/admin_view.php` — Export to Excel button replaced with server-side link

---

#### Ticket Summary Report — PDF export only contained current page
**Problem:** The PDF export in `report_tickets_full.php` worked by calling `include __FILE__` inside a `ob_start()` buffer, which caused the same PHP script to re-execute — including its paginated `LIMIT ? OFFSET ?` query. The result was a PDF that contained only the 20 rows visible on the current page, not the full dataset.

**Fix:** Added an early-exit PDF export path at the top of the file, before pagination is calculated. When `?export=pdf` is detected, a separate unlimited query (no `LIMIT`/`OFFSET`) fetches all matching rows, renders them into a clean HTML table, and passes that directly to mPDF. The old `include __FILE__` export block at the bottom of the file was removed.

**Files changed:**
- `api/ticket/report_tickets_full.php`

---

#### Ticket form — empty tickets could be submitted
**Problem:** The ticket submission form had `novalidate` on the `<form>` tag, which disabled all browser-level HTML5 validation. Although most fields had `required` attributes, they were completely bypassed — a user could submit an entirely blank ticket. The server-side handler (`submit_query2.php`) also had no validation; it sanitized inputs with `trim()` but went straight to the database `INSERT` regardless of whether fields were empty.

**Fix:**
- Removed `novalidate` from the form tag in `query.php` — restores browser validation for all fields marked `required`.
- Added server-side validation in `submit_query2.php` that checks all required fields (Title, Member Type, Branch, Source, To/Division, Priority, Description, and Phone Number when source is Phone) before touching the database. On failure it redirects back to the form with error messages passed as a URL parameter.
- Added an error banner to `query.php` that displays the returned validation errors as a list below the form header.

**Files changed:**
- `api/ticket/query.php`
- `api/ticket/submit_query2.php`

---

#### Feedback email — "Leave Feedback" link not rendering correctly
**Problem:** The closure email sent to ticket creators contained a styled HTML button (`<a>` tag with heavy inline CSS) for the feedback link. Many email clients, particularly Outlook, strip or ignore inline button styles, causing the link to appear broken or invisible.

**Fix:** Replaced the styled button with a plain `<p>` tag containing a bolded hyperlink — `You can leave your feedback <a href="..."><strong>HERE</strong></a>` — consistent with how the dashboard URL is presented elsewhere in the same email.

**Files changed:**
- `api/ticket/update_ticket_status_ajax.php`
- `api/ticket/update_ticket_status_ajax1.php`

---
