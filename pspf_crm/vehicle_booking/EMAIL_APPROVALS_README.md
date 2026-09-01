# Reply-by-email approvals (Supervisor & HRM)

Lets supervisors and HRM approve or reject vehicle requests by **replying to the
notification email** — so they can act while off the PSPF network. The website
stays internal-only; only email crosses the network boundary. A cron job running
**inside** the network reads the booking mailbox and applies the decision.

Driver "assign a car" by email is **not** included yet (it needs a vehicle
choice) — drivers continue to use the dashboard.

## How it works

1. When a request reaches `pending_supervisor` (driver has assigned a vehicle),
   each department supervisor is emailed. The subject carries a hidden,
   single-use token: `... [VBK-<request_id>-<32 hex>]`.
2. The supervisor replies **`APPROVE`** or **`REJECT <reason>`** from anywhere.
3. `cron_process_email_replies.php` (inside the network) polls
   `Vehicle.booking@pspf.co.sz` over IMAP, matches the token, verifies the
   sender, and runs the **same** DB update + `request_logs` insert + next-stage
   email that the dashboard buttons run.
4. The same flow repeats for HRM at `pending_hrm`.

### Security
- The **token** is the anchor: single-use, tied to one approver + one stage,
  expires after `EMAIL_TOKEN_TTL_DAYS` (default 7).
- The **sender address** is checked as a second factor.
- An action only applies while the request is still awaiting that stage, so
  replays, stale replies, or two supervisors both replying can't double-action.
- The real password lives only in `mail_inbox_config.php`, which is git-ignored.

## One-time setup

1. **Database** — create the token table:
   ```sh
   mysql -u root vehicle_requisition < sql/email_action_tokens.sql
   ```
2. **PHP IMAP extension** — required by the poller:
   ```sh
   # Debian/Ubuntu
   sudo apt-get install php-imap && sudo phpenmod imap && sudo systemctl restart apache2
   ```
   (On XAMPP/Windows, uncomment `extension=imap` in php.ini and restart Apache.)
3. **Inbox config** — copy the sample and fill it in:
   ```sh
   cp mail_inbox_config.sample.php mail_inbox_config.php
   # then edit host/port/ssl/username/password for Vehicle.booking@pspf.co.sz
   ```
4. **Cron** — run the poller every couple of minutes:
   ```cron
   */2 * * * * php /path/to/pspf_crm/vehicle_booking/cron_process_email_replies.php >> /var/log/vbk_email.log 2>&1
   ```

## Testing checklist

- [ ] `php -m | grep imap` shows the extension.
- [ ] Run the poller by hand: `php cron_process_email_replies.php` — it should
      connect and print `processed 0 reply message(s).`
- [ ] Submit a test request, let the driver assign a vehicle, then reply
      `APPROVE` from the supervisor's address → request moves to `pending_hrm`
      and a confirmation email comes back.
- [ ] Reply `REJECT not needed` as HRM → request becomes `rejected` with the
      reason recorded and logged in `request_logs`.
- [ ] Reply from a different address → no action, "did not come from…" notice.
- [ ] Reply twice → second reply gets "already been actioned".

## Files

| File | Purpose |
|------|---------|
| `sql/email_action_tokens.sql` | Token table |
| `email_action.php` | Token issuing, reply parsing, `applyEmailAction()` |
| `notification_engine.php` | Emits tokens + `Reply-To` on approval emails |
| `cron_process_email_replies.php` | IMAP poller |
| `mail_inbox_config.sample.php` | Config template (copy to `mail_inbox_config.php`) |
