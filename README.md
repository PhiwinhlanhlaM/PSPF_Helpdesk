# PSPF Helpdesk CRM

A role-based internal helpdesk and ticketing system built for PSPF, with an integrated vehicle requisition module.

---

## Features

- **Ticket management** — create, assign, track, escalate, and close support tickets
- **Role-based access** — User, Agent, Admin, Super Admin with in-session role switching
- **Vehicle requisition** — multi-step approval workflow (User → Driver → Supervisor → HRM)
- **PDF exports** — ticket reports and summaries via mPDF / DOMPDF
- **Email notifications** — PHPMailer via internal SMTP
- **Knowledge base** — self-service portal for common queries
- **Food ordering** — staff meal ordering system
- **Auto-escalation** — scheduled cron job for overdue tickets

---

## Tech Stack

| Layer | Technology |
|---|---|
| Server | Apache 2.4 (XAMPP) |
| Language | PHP 8.2 |
| Database | MariaDB 10.4 |
| PDF | mPDF 8.2, DOMPDF 3.1, TCPDF 6.10 |
| Email | PHPMailer 6.10 |
| Frontend | HTML / CSS / Bootstrap 5 / Bootstrap Icons |
| Package manager | Composer |

---

## Requirements

- [XAMPP](https://www.apachefriends.org/) 8.2+ (Apache + MySQL + PHP)
- PHP 8.2 CLI (for the user creation script)
- [Composer](https://getcomposer.org/)
- Access to an internal SMTP server (or configure a public one)

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/PhiwinhlanhlaM/PSPF_Helpdesk.git
```

Place the cloned folder inside your XAMPP web root:

```
C:\xampp\htdocs\pspf_crm\
```

### 2. Install PHP dependencies

```bash
cd pspf_crm
composer install
```

### 3. Set up the databases

Import the schema (creates both `pspf_helpdesk` and `vehicle_requisition` databases):

```bash
mysql -u root < database/schema.sql
```

Seed the default admin account:

```bash
mysql -u root pspf_helpdesk < database/seed_admin.sql
```

> Default credentials — **Email:** `admin@pspf.co.sz` **Password:** `Admin@1234`
> Change the password immediately after first login.

### 4. Configure the database connection

Copy the example config and fill in your values:

```bash
cp api/includes/confi.ini.example api/includes/confi.ini
```

Edit `api/includes/confi.ini`:

```ini
[database]
host     = localhost
database = pspf_helpdesk
username = root
password =

[application]
base_url = http://localhost/pspf_crm/
debug    = false
timezone = UTC
```

Also update `api/db.php` with the same credentials.

### 5. Configure email

Edit `api/mail_config.php` and set your SMTP server details:

```php
$mail->Host   = '192.168.1.15';   // your mail server
$mail->Port   = 25;
$mail->setFrom('administrator@yourdomain.com', 'PSPF CRM');
```

### 6. Start XAMPP and open the app

Start Apache and MySQL from the XAMPP Control Panel, then visit:

```
http://localhost/pspf_crm/dashboard.php
```

---

## Creating Users

Use the interactive CLI script to add users and assign roles:

```bash
php database/create_user.php
```

The script will prompt for username, email, password, division, and role(s). Available roles:

| Role | Access |
|---|---|
| `user` | Submit and track own tickets |
| `agent` | Handle assigned tickets |
| `admin` | Department-level ticket management |
| `superadmin` | Full system access, reports, user management |

---

## Project Structure

```
pspf_crm/
├── api/
│   ├── admin/          # Admin dashboard and views
│   ├── agent/          # Agent dashboard, topnav
│   ├── includes/       # Auth, helpers, role switcher, cron
│   ├── order/          # Food ordering module
│   ├── reports/        # Report generation
│   ├── settings/       # Profile and user management
│   ├── signin/         # Login, registration, role selection
│   ├── ticket/         # Full ticket lifecycle
│   ├── db.php          # Database connection
│   ├── mail_config.php # PHPMailer setup
│   └── session_config.php
├── database/
│   ├── schema.sql      # Full schema for both databases
│   ├── seed_admin.sql  # Default superadmin account
│   └── create_user.php # CLI user creation script
├── vendor/             # Composer dependencies (not committed)
├── vehicle_booking/    # Vehicle requisition module
├── composer.json
└── dashboard.php       # App entry point
```

---

## Role Switching

Users with multiple roles can switch between them directly from the navbar without logging out. The toggle is visible only when a user has more than one role assigned.

---

## Auto-Escalation (Cron)

To enable automatic ticket escalation, schedule `api/includes/auto_escalation_cron.php` to run periodically.

**Windows Task Scheduler:**
```
Program : C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\pspf_crm\api\includes\auto_escalation_cron.php
Trigger  : Daily / every hour
```

**Linux cron:**
```bash
0 * * * * php /var/www/html/pspf_crm/api/includes/auto_escalation_cron.php
```

---

## Security Notes

- `api/includes/confi.ini` and `api/db.php` are excluded from version control — never commit credentials
- Sessions use `httponly` cookies and strict mode with a 24-hour lifetime
- Passwords are hashed with bcrypt (`password_hash`)
- All DB queries use prepared statements (PDO in vehicle module, mysqli in CRM)
- CSRF tokens protect role-switch and form submissions

---

## Contributing

1. Create a branch from `main`
2. Make your changes
3. Open a pull request with a clear description of what changed and why

---

## License

Internal use only — PSPF ICT Department. &copy; 2026 PSPF.
