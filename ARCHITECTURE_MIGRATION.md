# PSPF CRM — Full Stack Modernisation Plan

## Context

The current CRM is a 106-file procedural PHP monolith with no routing framework, mixed DB libraries (mysqli + PDO), no caching, and no containerisation. The dev team plans to add multiple new modules and needs an architecture that supports parallel development, CI/CD, and scalability. This is a **big-bang rewrite** into a modern, containerised Python-based stack.

**Current state:**
- PHP 8.2 / Apache / MariaDB on XAMPP
- 27 DB tables across 2 databases, 8 functional modules, 20 JSON API endpoints
- One React mini-app (IT Access) via Babel standalone; rest is server-rendered HTML
- No Docker, no tests, no framework, no Redis, partial GitHub Actions

---

## Target Technology Stack

| Layer | Technology | Rationale |
|---|---|---|
| **Backend API** | **Django 5 + Django REST Framework** | Built-in ORM, admin panel covers superadmin UI, migrations, auth. DRF gives consistent REST patterns. Closest to PHP conventions — lowest ramp-up for a mixed team. |
| **Async tasks** | **Celery + Redis** | Email, PDF generation, auto-escalation cron — all currently blocking in PHP; move to async workers. |
| **Session & caching** | **Redis** | Replace PHP file-based sessions. Cache expensive dashboard aggregation queries. |
| **Database** | **PostgreSQL 16** | Better JSON support, window functions, proper `ARRAY` type (replaces `FIND_IN_SET` CSV hack). Per-module schemas within one instance. |
| **Frontend** | **React 18 + Vite + TypeScript** | Upgrade existing React code with a proper build pipeline. Eliminates Babel-standalone and `?v=14` cache-busting. |
| **Auth** | **Django auth + SimpleJWT** | Session auth for browser; JWT for React SPA and future mobile/third-party integrations. |
| **Email** | **Django email backend** | Replaces PHPMailer. Same SMTP server (`192.168.1.15:25`), configured via env var. |
| **PDF generation** | **WeasyPrint** | Python equivalent of mPDF; CSS-based layout. |
| **Containerisation** | **Docker + Docker Compose** | One `docker-compose.yml` spins up: `web`, `worker`, `redis`, `db`, `nginx`. |
| **CI/CD** | **GitHub Actions** | Lint (ruff), tests (pytest), frontend build, Docker image build, deploy via self-hosted runner on LAN server. |
| **Reverse proxy** | **Nginx** | Replaces Apache. Serves React static build, proxies `/api/` to Django (Gunicorn). |

---

## Architecture Overview

```
Browser
  │
  ├─ GET /        → Nginx → React SPA (static /dist)
  └─ POST /api/*  → Nginx → Django (Gunicorn) → PostgreSQL
                                      │
                                      └─ Celery (Redis) → Email, PDF, Escalations
```

**Django app structure:**
```
backend/
  pspf_crm/         ← Django project (settings, urls, wsgi, celery)
  apps/
    core/           ← users, roles, RBAC, activity log
    tickets/        ← ticket lifecycle, assignments, escalations
    it_access/      ← IT access request workflow
    vehicle/        ← vehicle booking (replaces vehicle_booking/ PHP)
    orders/         ← food ordering
    reports/        ← PDF + Excel exports
    notifications/  ← email + in-app notifications
```

Each `app/` maps to a named PostgreSQL schema (`helpdesk`, `it_access`, `vehicle`, etc.) — isolated but joinable via cross-schema queries.

---

## Target Directory Layout

```
pspf_crm/                        ← git root
  backend/                       ← Django project
    manage.py
    pspf_crm/
      settings/
        base.py
        dev.py
        prod.py
      urls.py
      celery.py
    apps/
      core/ tickets/ it_access/ vehicle/ orders/ reports/ notifications/
    requirements/
      base.txt  dev.txt  prod.txt
  frontend/                      ← React + Vite + TypeScript
    src/
      modules/
        tickets/ it_access/ vehicle/ dashboard/
      shared/
        components/ hooks/
        tokens.css               ← migrated from it_access_form/styles/tokens.css
    vite.config.ts
    package.json
    tsconfig.json
  docker/
    nginx/nginx.conf
    postgres/init.sql            ← creates per-module schemas
  docker-compose.yml
  docker-compose.prod.yml
  .github/
    workflows/
      ci.yml
      deploy.yml
  .env.example                   ← committed (no secrets)
  .env                           ← gitignored
```

---

## Phase 1 — Infrastructure & Auth (Week 1–2)

**Goal:** Docker Compose stack running; Django scaffolded; auth working end-to-end.

### `docker-compose.yml`
```yaml
services:
  db:
    image: postgres:16-alpine
    environment: { POSTGRES_DB: pspf_crm, POSTGRES_USER: "${DB_USER}", POSTGRES_PASSWORD: "${DB_PASS}" }
    volumes: [postgres_data:/var/lib/postgresql/data]
  redis:
    image: redis:7-alpine
  web:
    build: ./backend
    command: gunicorn pspf_crm.wsgi:application --bind 0.0.0.0:8000
    env_file: .env
    depends_on: [db, redis]
  worker:
    build: ./backend
    command: celery -A pspf_crm worker -l info
    env_file: .env
    depends_on: [redis, db]
  frontend:
    build: ./frontend
    volumes: [./frontend:/app, /app/node_modules]
    ports: ["5173:5173"]
  nginx:
    image: nginx:alpine
    ports: ["80:80"]
    volumes: [./docker/nginx/nginx.conf:/etc/nginx/conf.d/default.conf]
    depends_on: [web, frontend]
```

### `backend/pspf_crm/settings/base.py` key settings
- `DATABASES`: PostgreSQL with `OPTIONS: {'options': '-c search_path=helpdesk'}`
- `CACHES`: `django-redis` backend
- `SESSION_ENGINE`: `django.contrib.sessions.backends.cache`
- `REST_FRAMEWORK`: default auth `JWTAuthentication`, default permission `IsAuthenticated`
- `CELERY_BROKER_URL`: `redis://redis:6379/0`

### `backend/apps/core/`
- `models.py`: `User` (extends `AbstractUser`, adds `department`, `division`), `Role`, `UserRole`, `ActivityLog`
- `permissions.py`: `IsActiveUser`, `HasRole`, `IsITOfficer` DRF permission classes
- `management/commands/migrate_from_php.py`: reads legacy MariaDB via `DATABASES['legacy']`, maps to Django models. bcrypt hashes migrate directly.

**PHP stays live — nothing is touched during Phase 1.**

---

## Phase 2 — Core Ticket Module (Week 3–5)

**Goal:** Ticket CRUD, assignment, escalation, status history in Django.

### `backend/apps/tickets/`
- `models.py`: `Ticket` (status as `TextChoices`), `TicketAssignment`, `TicketHistory`, `TicketEscalation`, `TicketFeedback`
  - `assigned_to`: `ManyToManyField(User)` — replaces `FIND_IN_SET` CSV hack
- `serializers.py`: DRF serializers for all endpoints
- `views.py`: `TicketViewSet` with role-scoped queryset (superadmin → all; agent → assigned; user → own)
- `tasks.py`: `send_ticket_assignment_email`, `check_and_escalate_overdue_tickets`, `send_feedback_survey` (Celery)

### `frontend/src/modules/tickets/`
React components replacing PHP server-rendered ticket pages.

---

## Phase 3 — IT Access, Vehicle, Orders (Week 6–8)

### IT Access (`backend/apps/it_access/`)
- Models: `AccessRequest`, `AccessApproval` (`sig_data` as `JSONField`), `RequestedSystem`
- `tasks.py`: `generate_pdf` using WeasyPrint (replaces mPDF)
- Frontend: migrate `it_access_form/app/*.jsx` → `frontend/src/modules/it_access/*.tsx`

### Vehicle (`backend/apps/vehicle/`)
- Models: `Vehicle`, `VehicleRequest`, `RequestLog`
- Approval chain as `TextChoices` status: `pending_driver → pending_supervisor → pending_hrm → approved`
- Separate `vehicle` schema in Postgres (same instance as main DB)

### Orders (`backend/apps/orders/`)
- Models: `Outlet`, `Order` (`order_items` as `JSONField`)

---

## Phase 4 — Reports, Admin, Notifications (Week 9–10)

- **Reports** (`backend/apps/reports/`): DRF endpoints returning Excel (openpyxl) or PDF (WeasyPrint). Replaces 6 PHP export files.
- **Django Admin**: Register all models — replaces `api/admin/` PHP pages and `api/settings/user_management.php`. Biggest free win.
- **Notifications** (`backend/apps/notifications/`): `Notification` model + Celery email tasks. Redis pub/sub for real-time updates (replaces the escalation polling loop).

---

## Phase 5 — CI/CD (Week 11)

### `.github/workflows/ci.yml`
```yaml
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres: { image: postgres:16 }
      redis:    { image: redis:7 }
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with: { python-version: '3.12' }
      - run: pip install -r backend/requirements/dev.txt
      - run: ruff check backend/
      - run: pytest backend/ --cov
      - run: cd frontend && npm ci && npm run build
      - run: docker build ./backend -t pspf-crm:${{ github.sha }}
```

### `.github/workflows/deploy.yml`
```yaml
on:
  workflow_dispatch:
    inputs:
      confirm: { description: 'Type "deploy" to confirm', required: true }
jobs:
  deploy:
    runs-on: self-hosted        # runner registered on production LAN server
    if: github.event.inputs.confirm == 'deploy'
    steps:
      - run: git pull origin main
      - run: docker compose -f docker-compose.prod.yml up -d --build
      - run: docker compose exec web python manage.py migrate --no-input
      - run: docker compose exec web python manage.py collectstatic --no-input
```

**Self-hosted runner setup:** Install GitHub Actions runner on production LAN server. Add `PROD_DB_PASS`, `PROD_SECRET_KEY` etc. as GitHub repository secrets.

---

## `.env.example`
```env
DJANGO_SECRET_KEY=change-me
DJANGO_ENV=development
DB_HOST=db
DB_PORT=5432
DB_NAME=pspf_crm
DB_USER=pspf
DB_PASS=change-me
REDIS_URL=redis://redis:6379/0
EMAIL_HOST=192.168.1.15
EMAIL_PORT=25
JWT_ACCESS_TOKEN_LIFETIME_MINUTES=60
JWT_REFRESH_TOKEN_LIFETIME_DAYS=7
```

---

## PHP → Django Reference Map

| PHP file | New location |
|---|---|
| `api/includes/auth_helpers.php` | `backend/apps/core/permissions.py` |
| `api/includes/log_activity.php` | `backend/apps/core/models.py` `ActivityLog` + Django signals |
| `api/admin/admin_dashboard.php` | Django admin + `backend/apps/tickets/views.py` |
| `database/schema.sql` | Django migrations in each `apps/*/migrations/` |
| `it_access_form/styles/tokens.css` | `frontend/src/shared/tokens.css` |
| `it_access_form/app/*.jsx` | `frontend/src/modules/it_access/*.tsx` |
| `api/includes/auto_escalation_cron.php` | `backend/apps/tickets/tasks.py` Celery beat |

---

## Cutover Safety Plan

1. Data migration script reads live MariaDB read-only — non-destructive
2. **Parallel run** (2–3 days): Django on port 8080, PHP on port 80
3. **Cutover**: Nginx routes port 80 → Django. PHP inaccessible, MariaDB intact
4. **Rollback window** (30 days): Re-point Nginx to Apache, MariaDB still available

---

## Verification Checklist

- [ ] `docker compose up` → all 5 services start, Django admin at `/admin/`
- [ ] `POST /api/auth/token/` with legacy credentials → JWT returned
- [ ] `GET /api/tickets/` as agent → returns only assigned tickets
- [ ] `python manage.py migrate_from_php --dry-run` → row counts without writing
- [ ] Submit IT access request → PDF generated via Celery, email sent
- [ ] Push branch with ruff error → GitHub Actions CI blocks merge
- [ ] Manual deploy dispatch → self-hosted runner pulls, migrates, restarts containers
