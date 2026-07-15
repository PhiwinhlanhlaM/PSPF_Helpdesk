# PSPF Helpdesk — Django port

A Python/Django full-stack port of the legacy PHP helpdesk (`pspf_crm/api`).
This is **phase 1**: the core helpdesk — authentication, roles, tickets, the IT
Access request workflow, departments/divisions and a knowledge base. The
vehicle-booking module and the standalone *IT Access Form* React app are **not**
included in this phase (see [Scope](#scope) below).

## Quick start

```bash
cd django_app
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt          # Django (+ mysqlclient for MySQL)

python manage.py migrate                 # SQLite by default
python manage.py seed_demo               # demo roles + users (password: password123)
python manage.py runserver
```

Open http://127.0.0.1:8000/ and sign in with one of the seeded accounts:

| Username | Role(s)              | What they can do                              |
|----------|----------------------|-----------------------------------------------|
| `alice`  | user                 | Log & track tickets                           |
| `bob`    | agent                | Work tickets routed to their division         |
| `carol`  | admin, user          | Admin dashboard; submit IT Access requests    |
| `dave`   | it_officer           | Claim & action IT Access requests             |
| `erin`   | it_director          | Sign off / provision IT Access requests       |

Create a Django admin superuser with `python manage.py createsuperuser` to reach
`/admin/`.

## Configuration

Everything is environment-driven (see `config/settings.py`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `DB_ENGINE` | `sqlite` | Set to `mysql` to use the legacy `pspf_helpdesk` DB |
| `DB_NAME` / `DB_USER` / `DB_PASSWORD` / `DB_HOST` / `DB_PORT` | `pspf_helpdesk` / `root` / `` / `127.0.0.1` / `3306` | MySQL connection |
| `DJANGO_SECRET_KEY` | dev key | **Set in production** |
| `DJANGO_DEBUG` | `True` | Set `False` in production |
| `DJANGO_ALLOWED_HOSTS` | `*` | Comma-separated hosts |
| `PASSWORD_EXPIRY_DAYS` | `90` | Password expiry policy |
| `EMAIL_BACKEND` | console | Set to SMTP backend + `EMAIL_*` vars for real mail |

### Pointing at the existing MySQL database

The models map onto the legacy table names (`users`, `roles`, `user_roles`,
`departments`, `divisions`, `tickets`, `ticket_status_logs`,
`it_access_requests`, `it_request_systems`, `it_request_approvals`). To run
against a copy of the live DB:

```bash
export DB_ENGINE=mysql DB_NAME=pspf_helpdesk DB_USER=root DB_PASSWORD=secret
python manage.py migrate --fake-initial   # tables already exist
```

> Note: legacy passwords are PHP `password_hash()` bcrypt hashes. Django's
> default hasher understands bcrypt, so add `django.contrib.auth.hashers.
> BCryptSHA256PasswordHasher` / `BCryptPasswordHasher` to `PASSWORD_HASHERS`
> and install `bcrypt` if you migrate real user rows.

## How the PHP maps to Django

| Legacy PHP | Django equivalent |
|------------|-------------------|
| `db.php` (`mysqli`) | `DATABASES` + the ORM |
| `session_config.php`, `$_SESSION` | Django sessions |
| `includes/auth_functions.php` (`authenticateUser`) | `accounts/backends.py` (username-or-email backend) |
| `includes/auth_helpers.php` (roles, `hasRole`, `requireAnyRole`) | `accounts/roles.py` (`get_active_role`, `require_roles`, `require_held_role`) |
| `switch_role.php` | `accounts.views.switch_role_view` |
| `enforceActiveUser` / `enforcePasswordPolicy` | `accounts/middleware.py` |
| CSRF token handling | Django's built-in CSRF middleware |
| `ticket/submit_query2.php` | `tickets.views.query_view` + `tickets/services.py` |
| `ticket/ticket_success2.php` + `notified_at` guard | `tickets.services.notify_ticket_once` |
| `ticket/change_status.php` + `ticket_status_logs` | `tickets.services.change_status` |
| `departments/list.php`, `employees/lookup.php` | `orgunits/views.py` (JSON) |
| `it_access/*.php` | `it_access/` app |
| PHPMailer + `mail_config.php` | Django's email framework (`django.core.mail`) |

## Project layout

```
django_app/
├── config/            # project settings, urls, wsgi/asgi
├── accounts/          # custom User, Role, UserRole; auth, roles, middleware
├── orgunits/          # Department, Division + JSON endpoints
├── tickets/           # Ticket, TicketStatusLog, feedback; dashboards & flows
├── it_access/         # IT Access request workflow (submit/claim/approve)
├── knowledge_base/    # KB articles
├── templates/         # base.html (Bootstrap 5)
└── manage.py
```

## Scope

**Included (phase 1):** auth + multi-role + role switching, password policy,
ticket submit/track/detail/dashboards (user/agent/admin), status changes with
audit log, one-time assignment email, IT Access request workflow, departments/
divisions, employee lookup, knowledge base, Django admin.

**Deferred:** vehicle-booking module (`pspf_crm/vehicle_booking`), the React
*IT Access Form* SPA, PDF/Excel exports, SharePoint integration, the PowerShell
deploy pipeline (`deploy/`), and various one-off report/AJAX endpoints. These
can be ported in later phases following the same patterns.

The legacy PHP code is left untouched in `pspf_crm/` for reference during the
migration.
