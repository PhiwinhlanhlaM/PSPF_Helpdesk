# Ticket classification & daily department digests

Automatically classifies every ticket by subject matter and emails each
department's admin(s) a daily summary of the tickets their department received.

## What it does

1. **Classifies tickets.** Every new ticket is tagged with a category
   (Access & Accounts, Email & Communication, Network & Connectivity,
   Hardware & Devices, Software & Applications, Finance & Payroll, HR & Personnel,
   Facilities & Vehicles, Data & Reporting, or General) from its title and
   description. The category is stored on `tickets.category`.
2. **Emails a daily digest.** Once a day, each department (division) that has at
   least one active admin gets one email to its admin(s) summarising the previous
   day's tickets — counts by category, priority and status, the total open
   backlog, and a "needs attention" list of High/Urgent/Escalated tickets.

Classification is rule-based (keyword matching in
`api/includes/ticket_classifier.php`) — it runs entirely in PHP with no external
service, so it works on the internal SMTP-only network with no API keys or cost.

## Files

| File | Purpose |
| --- | --- |
| `api/includes/ticket_classifier.php` | `classifyTicket()` + category rules |
| `api/ticket/submit_query2.php` | classifies each ticket at submission |
| `api/ticket/backfill_ticket_categories.php` | classifies existing tickets |
| `api/ticket/send_department_digests.php` | builds & sends the daily digest |
| `api/ticket/migrations/003_add_ticket_category.sql` | adds `tickets.category` |
| `api/ticket/migrations/004_add_department_digest_log.sql` | send-once guard table |

## One-time setup

1. **Apply the migrations** (order matters):

   ```bash
   mysql -u root -p pspf_helpdesk < api/ticket/migrations/003_add_ticket_category.sql
   mysql -u root -p pspf_helpdesk < api/ticket/migrations/004_add_department_digest_log.sql
   ```

2. **Backfill categories** for tickets created before this feature:

   ```bash
   php api/ticket/backfill_ticket_categories.php          # classify untagged tickets
   php api/ticket/backfill_ticket_categories.php --dry     # preview only
   php api/ticket/backfill_ticket_categories.php --all      # re-classify everything
   ```

3. **Confirm the mailer.** The digest uses the shared `getMailer()` from
   `api/mail_config.php` (SMTP relay). Make sure that host/port is reachable from
   wherever the scheduled task runs.

## Schedule the daily digest

Run `send_department_digests.php` once a day (e.g. 07:00). It reports on
**yesterday** by default.

**Linux cron**

```cron
0 7 * * * php /var/www/pspf_crm/api/ticket/send_department_digests.php >> /var/log/pspf_digest.log 2>&1
```

**Windows Task Scheduler** — daily trigger, action:

```
Program:   C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\pspf_crm\api\ticket\send_department_digests.php
```

### Options

| Option | Effect |
| --- | --- |
| `--date=YYYY-MM-DD` | Report on a specific date instead of yesterday. |
| `--window-days=N` | Include the N days ending on `--date` (default 1). |
| `--include-empty` | Also email departments with no new tickets and no backlog. |
| `--force` | Ignore the per-day send log (allow a re-send). |
| `--dry` | Print the digests to the console; don't email or log. |

Preview without sending:

```bash
php api/ticket/send_department_digests.php --dry
php api/ticket/send_department_digests.php --date=2026-07-19 --dry
```

## Notes

- **Who gets the email:** users with the `admin` role whose `division_id` matches
  the ticket's `division_id`. This mirrors how the admin dashboards already scope
  a department. Give a department an admin (role + `division_id`) and they're
  automatically included; no admin means no digest for that department.
- **Sent once per day:** `department_digest_log` records each (division, date)
  send, so an accidental second run the same day is a no-op (override with
  `--force`).
- **Tuning categories:** edit the keyword lists in
  `api/includes/ticket_classifier.php`, then re-run the backfill with `--all` to
  re-tag existing tickets.
