# XAMPP Project Directory Map

> **Interactive navigation guide** for the PSPF development environment.
> Click any file link to open it. Use the Table of Contents to jump between sections.

---

## Table of Contents

- [Overview](#overview)
- [XAMPP Infrastructure](#xampp-infrastructure)
  - [Apache](#apache)
  - [PHP](#php)
  - [MySQL](#mysql)
  - [Mail](#mail)
- [Web Projects (htdocs)](#web-projects-htdocs)
  - [PSPF CRM — Main App](#pspf-crm--main-app)
    - [Entry Points](#entry-points)
    - [API — Authentication](#api--authentication)
    - [API — Admin Portal](#api--admin-portal)
    - [API — Agent Portal](#api--agent-portal)
    - [API — Ticket Management](#api--ticket-management)
    - [API — Order System](#api--order-system)
    - [API — Reports](#api--reports)
    - [API — Settings](#api--settings)
    - [Core Includes](#core-includes)
    - [Composer Dependencies](#composer-dependencies)
    - [Databases](#databases)
  - [Vehicle Booking System](#vehicle-booking-system)
    - [Entry & Auth](#entry--auth)
    - [Role Dashboards](#role-dashboards)
    - [Workflow Actions](#workflow-actions)
    - [Reporting](#reporting)
    - [Config & Services](#config--services)
  - [Default XAMPP Pages](#default-xampp-pages)
- [Session & Temp Storage](#session--temp-storage)
- [Technology Stack](#technology-stack)
- [Quick Reference — Connection Details](#quick-reference--connection-details)

---

## Overview

This is a XAMPP 8.2.12 development environment (Windows 11) hosting **two interconnected PHP web applications** for the PSPF organisation:

| Application | Path | Database |
|---|---|---|
| PSPF Helpdesk CRM | [htdocs/pspf_crm/](pspf_crm/) | `pspf_helpdesk` |
| Vehicle Requisition | [htdocs/vehicle_booking/](vehicle_booking/) | `vehicle_requisition` |

Both apps share the same SMTP server (`192.168.1.15:25`) and run on PHP 8.2 + MariaDB 10.4.

---

## XAMPP Infrastructure

### Apache

| File / Folder | Purpose |
|---|---|
| [../apache/conf/httpd.conf](../apache/conf/httpd.conf) | Main Apache config — listens on port 80, document root |
| [../apache/conf/extra/httpd-xampp.conf](../apache/conf/extra/httpd-xampp.conf) | XAMPP-specific settings, loads `php8apache2_4.dll` |
| [../apache/conf/extra/httpd-vhosts.conf](../apache/conf/extra/httpd-vhosts.conf) | Virtual hosts (all commented out — add new vhosts here) |
| [../apache/conf/extra/httpd-ssl.conf](../apache/conf/extra/httpd-ssl.conf) | SSL/TLS settings for HTTPS |
| [../apache/logs/](../apache/logs/) | Access and error logs |

### PHP

| File / Folder | Purpose |
|---|---|
| [../php/php.ini](../php/php.ini) | Main PHP config (last modified 2026-03-12) |
| [../php/ext/](../php/ext/) | PHP extensions (mysqli, mbstring, curl, gd, etc.) |
| [../php/pear/](../php/pear/) | PEAR package manager |

### MySQL

| File / Folder | Purpose |
|---|---|
| [../mysql/my.ini](../mysql/my.ini) | MariaDB 10.4 configuration |
| [../mysql/data/pspf_helpdesk/](../mysql/data/pspf_helpdesk/) | CRM database files |
| [../mysql/data/vehicle_requisition/](../mysql/data/vehicle_requisition/) | Vehicle booking database files |
| [../mysql/data/mysql_error.log](../mysql/data/mysql_error.log) | DB error log |

> **Credentials:** host `localhost`, user `root`, password *(empty)*, port `3306`

### Mail

| File / Folder | Purpose |
|---|---|
| [../sendmail/sendmail.ini](../sendmail/sendmail.ini) | Sendmail config |
| [../php/php.ini](../php/php.ini) | `mailToDisk` enabled — test emails written to `c:\xampp\mailoutput\` |

---

## Web Projects (htdocs)

### PSPF CRM — Main App

Root: [pspf_crm/](pspf_crm/)

The primary helpdesk / ticketing system with role-based access for users, agents, and admins.

#### Entry Points

| File | Purpose |
|---|---|
| [pspf_crm/dashboard.php](pspf_crm/dashboard.php) | Main dashboard UI shell (HTML/CSS layout) |
| [pspf_crm/api/db.php](pspf_crm/api/db.php) | Global `mysqli` database connection — required by almost every API file |
| [pspf_crm/api/session_config.php](pspf_crm/api/session_config.php) | Session settings: 24 hr lifetime, httponly cookies, strict mode |
| [pspf_crm/api/mail_config.php](pspf_crm/api/mail_config.php) | PHPMailer bootstrap — SMTP `192.168.1.15:25`, from `administrator@pspf.co.sz` |

#### API — Authentication

Path: [pspf_crm/api/signin/](pspf_crm/api/signin/)

| File | Purpose |
|---|---|
| `login.php` | Validates credentials, sets session, redirects by role |
| `register_user.php` | New user registration |
| `forgot_password.php` | Password reset request flow |
| `role_selection.php` | Lets multi-role users choose active role on login |

#### API — Admin Portal

Path: [pspf_crm/api/admin/](pspf_crm/api/admin/)

| File | Purpose |
|---|---|
| `admin_dashboard.php` *(48 KB)* | Admin home — stats, KPIs, overview panels |
| `admin_view.php` *(95 KB)* | Detailed admin data viewer — tickets, users, logs |

#### API — Agent Portal

Path: [pspf_crm/api/agent/](pspf_crm/api/agent/)

| File | Purpose |
|---|---|
| `agent_dashboard.php` *(37 KB)* | Agent home — assigned tickets, queue, quick actions |

#### API — Ticket Management

Path: [pspf_crm/api/ticket/](pspf_crm/api/ticket/) — 37 files covering the full ticket lifecycle.

| File | Purpose |
|---|---|
| `create_ticket.php` | Submit a new support ticket |
| `ticket_progress.php` *(30 KB)* | Live ticket progress view with timeline |
| `ticket_status_logs.php` *(30 KB)* | Full audit trail of every status change |
| `user_dashboard.php` *(37 KB)* | End-user ticket list and status dashboard |
| `bulk_update.php` | Batch status/assignment updates |
| `export_*.php` | Export ticket data (CSV / Excel / PDF) |
| `report_*.php` | Generated ticket reports by date / category / agent |

#### API — Order System

Path: [pspf_crm/api/order/](pspf_crm/api/order/)

| File | Purpose |
|---|---|
| `food_order.php` *(23 KB)* | Staff food ordering — menu browsing, cart, submission |
| *(+ 5 supporting files)* | Order status, history, admin management |

#### API — Reports

Path: [pspf_crm/api/reports/](pspf_crm/api/reports/)

| File | Purpose |
|---|---|
| Various `*_report.php` | Generated reports — can output PDF (via mPDF/DOMPDF) or Excel |

#### API — Settings

Path: [pspf_crm/api/settings/](pspf_crm/api/settings/)

| File | Purpose |
|---|---|
| `user_management.php` | Create / edit / deactivate user accounts |
| `profile_settings.php` | Edit own profile, change password |

#### Core Includes

Path: [pspf_crm/api/includes/](pspf_crm/api/includes/)

| File | Purpose |
|---|---|
| [pspf_crm/api/includes/auth_functions.php](pspf_crm/api/includes/auth_functions.php) | Core login/logout/session validation logic |
| [pspf_crm/api/includes/auth_helpers.php](pspf_crm/api/includes/auth_helpers.php) | Utility wrappers around auth_functions |
| [pspf_crm/api/includes/ticket_status_functions.php](pspf_crm/api/includes/ticket_status_functions.php) | Shared status transition rules |
| [pspf_crm/api/includes/auto_escalation_cron.php](pspf_crm/api/includes/auto_escalation_cron.php) | Scheduled escalation script — run via CRON/Task Scheduler |
| [pspf_crm/api/includes/role_switcher.php](pspf_crm/api/includes/role_switcher.php) | Runtime role switching for multi-role accounts |
| [pspf_crm/api/includes/confi.ini](pspf_crm/api/includes/confi.ini) | DB config: host, dbname `pspf_helpdesk`, credentials |

#### Composer Dependencies

| Package | Purpose |
|---|---|
| `mpdf/mpdf` v8.2 | Primary PDF generation library |
| `dompdf/dompdf` v3.1 | HTML → PDF alternative renderer |
| `tecnickcom/tcpdf` v6.10 | Low-level PDF toolkit (used for complex layouts) |
| `phpmailer/phpmailer` v6.10 | Email sending via SMTP |

Config: [pspf_crm/composer.json](pspf_crm/composer.json) | Installed: [pspf_crm/vendor/](pspf_crm/vendor/)

#### Databases

| File | Purpose |
|---|---|
| [pspf_helpdesk(3).sql](pspf_helpdesk\(3\).sql) | Full schema + data dump for `pspf_helpdesk` (import via phpMyAdmin) |

---

### Vehicle Booking System

Root: [vehicle_booking/](vehicle_booking/)

A multi-role vehicle requisition workflow: users request → supervisor approves → HR approves → driver assigned → vehicle returned.

#### Entry & Auth

| File | Purpose |
|---|---|
| [vehicle_booking/login.php](vehicle_booking/login.php) | PDO-based login with `password_verify()`, role-based redirect |
| [vehicle_booking/db.php](vehicle_booking/db.php) | PDO connection to `vehicle_requisition` database |
| [vehicle_booking/mail_config.php](vehicle_booking/mail_config.php) | PHPMailer SMTP config (same server as CRM) |

#### Role Dashboards

| File | Role | Purpose |
|---|---|---|
| [vehicle_booking/user_dashboard.php](vehicle_booking/user_dashboard.php) | User | View own requests, submit new ones |
| [vehicle_booking/driver_dashboard.php](vehicle_booking/driver_dashboard.php) | Driver | View assigned trips, manage vehicle status |
| [vehicle_booking/supervisor_dashboard.php](vehicle_booking/supervisor_dashboard.php) | Supervisor | Review and approve/reject requests |
| [vehicle_booking/hrm_dashboard.php](vehicle_booking/hrm_dashboard.php) | HR Manager | Final approval layer, fleet oversight |
| [vehicle_booking/admin_dashboard.php](vehicle_booking/admin_dashboard.php) | Admin | Full system control — users, vehicles, all requests |

#### Workflow Actions

| File | Purpose |
|---|---|
| [vehicle_booking/request_vehicle.php](vehicle_booking/request_vehicle.php) | User submits a new vehicle request |
| [vehicle_booking/request_form.php](vehicle_booking/request_form.php) | Request form UI |
| [vehicle_booking/return_form.php](vehicle_booking/return_form.php) | Driver records vehicle return |
| [vehicle_booking/driver_approve_request.php](vehicle_booking/driver_approve_request.php) | Driver accepts/declines an assigned trip |
| [vehicle_booking/supervisor_approve_request.php](vehicle_booking/supervisor_approve_request.php) | Supervisor approval step |
| [vehicle_booking/hrm_approve_request.php](vehicle_booking/hrm_approve_request.php) | HR final approval step |
| [vehicle_booking/approve_ticket.php](vehicle_booking/approve_ticket.php) | Generic approve action handler |
| [vehicle_booking/reject_ticket.php](vehicle_booking/reject_ticket.php) | Generic reject action handler |
| [vehicle_booking/notification_engine.php](vehicle_booking/notification_engine.php) | Sends push/in-app notifications on status changes |
| [vehicle_booking/email_engine.php](vehicle_booking/email_engine.php) | Triggers email notifications via PHPMailer |
| `cron_return_escalations.rb` | Ruby script — auto-escalates overdue return requests |

#### Reporting

| File | Purpose |
|---|---|
| [vehicle_booking/report_page.php](vehicle_booking/report_page.php) | Report UI — filter by date, driver, status |
| [vehicle_booking/report_excel.php](vehicle_booking/report_excel.php) | Export report as Excel file |
| [vehicle_booking/report_pdf.php](vehicle_booking/report_pdf.php) | Export report as PDF |

#### Config & Services

| File | Purpose |
|---|---|
| [vehicle_booking/db.php](vehicle_booking/db.php) | PDO DB connection (host `localhost`, db `vehicle_requisition`, user `root`) |
| [vehicle_booking/style.css](vehicle_booking/style.css) | Base stylesheet |
| `style1.css`, `style5.css` | Role / page-specific style overrides |

Database schema: [vehicle_requisition.sql](vehicle_requisition.sql)

---

### Default XAMPP Pages

| Path | Purpose |
|---|---|
| [index.php](index.php) | Root redirect → `/dashboard/` |
| [dashboard/index.html](dashboard/index.html) | XAMPP welcome page with links to phpMyAdmin, phpinfo, etc. |
| [dashboard/faq.html](dashboard/faq.html) | XAMPP FAQ |
| [xampp/](xampp/) | phpinfo, status, and XAMPP utilities |
| [webalizer/](webalizer/) | Web traffic log analyser |

**phpMyAdmin:** `http://localhost/phpmyadmin/` (user: `root`, no password)

---

## Session & Temp Storage

| Path | Purpose |
|---|---|
| [../tmp/](../tmp/) | PHP session files (`sess_*`) + MySQL InnoDB temp files |

Sessions expire after **24 hours** (configured in `session_config.php`).

---

## Technology Stack

| Layer | Technology | Version |
|---|---|---|
| Web Server | Apache HTTP Server | 2.4.58 |
| Language | PHP | 8.2.12 |
| Database | MariaDB | 10.4.32 |
| PDF (primary) | mPDF | 8.2 |
| PDF (alt) | DOMPDF | 3.1 |
| PDF (low-level) | TCPDF | 6.10 |
| Email | PHPMailer | 6.10 |
| DB Driver (CRM) | `mysqli` | — |
| DB Driver (Vehicle) | `PDO` | — |
| Package Manager | Composer | — |
| Control Panel | XAMPP Control | 3.3.0 |

---

## Quick Reference — Connection Details

```
# Database
Host:     localhost
Port:     3306
User:     root
Password: (none)

# CRM Database
Name:     pspf_helpdesk
Config:   pspf_crm/api/includes/confi.ini
          pspf_crm/api/db.php

# Vehicle Database
Name:     vehicle_requisition
Config:   vehicle_booking/db.php

# SMTP Mail Server
Host:     192.168.1.15
Port:     25
Auth:     disabled (internal network)
From:     administrator@pspf.co.sz

# Local URLs
Main app:    http://localhost/pspf_crm/dashboard.php
Vehicles:    http://localhost/vehicle_booking/login.php
phpMyAdmin:  http://localhost/phpmyadmin/
```

---

*Generated 2026-05-06 — update this file as new modules are added.*
