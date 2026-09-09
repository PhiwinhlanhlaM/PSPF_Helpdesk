# Reply-by-email approvals (Driver, Supervisor & HRM)

Lets drivers, supervisors and HRM action vehicle requests by **replying to the
notification email**, so they can act while off the PSPF network. The website
stays internal-only; only email crosses the network boundary. A cron job running
**inside** the network reads the booking mailbox and applies the decision.

## How it works

Every notification email's subject carries a hidden, single-use token:
`... [VBK-<request_id>-<32 hex>]`. The recipient replies with a one-word command
on the first line, and `cron_process_email_replies.php` (inside the network)
polls `Vehicle.booking@pspf.co.sz` over IMAP, matches the token, verifies the
sender, and runs the **same** DB update + `request_logs` insert + next-stage
email that the dashboard buttons run.

| Stage (request status) | Recipient | Reply with |
|------------------------|-----------|------------|
| `pending_driver` (new request) | Drivers | `ASSIGN <registration>` or `REJECT <reason>` |
| `pending_supervisor` | Dept supervisors | `APPROVE` or `REJECT <reason>` |
| `pending_hrm` | HRM | `APPROVE` or `REJECT <reason>` |

The driver's email lists the currently **available** vehicles so they know which
registrations are valid. `ASSIGN` also confirms the vehicle is free, marks it
`allocated`, records the driver, and moves the request to supervisor approval -
exactly as the dashboard does. A mistyped or unavailable registration gets a
reply with the valid list and the token stays usable, so the driver can just
reply again.

### Security
- The **token** is the anchor: single-use, tied to one approver + one stage,
  expires after `EMAIL_TOKEN_TTL_DAYS` (default 7).
- The **sender address** is checked as a second factor.
- An action only applies while the request is still awaiting that stage, so
  replays, stale replies, or two supervisors both replying can't double-action.
- The real password lives only in `mail_inbox_config.php`, which is git-ignored.

## Reading the mailbox: two methods

The mailbox lives in Microsoft 365, which blocks password-based IMAP, so the
default method is **Graph** (`method => 'graph'`), read over HTTPS with an
app-only OAuth token. A password-IMAP poller is kept for the case of a local /
in-house mailbox (`method => 'imap'`).

| | Graph (365) | IMAP (local mailbox) |
|---|---|---|
| Poller | `cron_process_email_replies_graph.php` | `cron_process_email_replies.php` |
| Auth | app-only OAuth token | mailbox password |
| Needs | Entra app registration | IMAP enabled + reachable |
| PHP ext | cURL | IMAP |

## One-time setup (Graph / 365)

1. **Database**, create the token table:
   ```sh
   mysql -u root vehicle_requisition < sql/email_action_tokens.sql
   ```
2. **Entra app registration** (365 admin) - see the next section. It yields a
   **tenant ID, client ID, and client secret**.
3. **PHP cURL extension** enabled (XAMPP: ensure `extension=curl` is on in
   php.ini, and `curl.cainfo` points at a `cacert.pem` so TLS verification works).
4. **Config**, copy the sample and fill in the Graph values:
   ```sh
   copy mail_inbox_config.sample.php mail_inbox_config.php
   # set method=graph, tenant_id, client_id, client_secret, mailbox, reply_to
   ```
5. **Schedule** the poller every couple of minutes. On Windows (Task Scheduler),
   program `C:\xampp\php\php.exe`, arguments the full path to
   `cron_process_email_replies_graph.php`. On Linux cron:
   ```cron
   */2 * * * * php /path/to/vehicle_booking/cron_process_email_replies_graph.php >> /var/log/vbk_email.log 2>&1
   ```

## Microsoft 365 app registration (hand to your 365 / Entra admin)

Standard "let a service read one shared mailbox" pattern:

1. **Entra admin center -> App registrations -> New registration.** Name e.g.
   `PSPF Vehicle Booking Mail Reader`, single tenant, no redirect URI. Copy the
   **Application (client) ID** and **Directory (tenant) ID**.
2. **Certificates & secrets -> New client secret.** Copy the secret **value**
   (shown once).
3. **API permissions -> Add -> Microsoft Graph -> Application permissions ->
   `Mail.ReadWrite`**, then **Grant admin consent**. (ReadWrite so the poller can
   mark replies read; Mail.Read alone can read but not mark.)
4. **Restrict the app to only this mailbox** (Exchange Online PowerShell), so it
   cannot read any other mailbox in the tenant:
   ```powershell
   New-DistributionGroup -Name "VBK-MailReader-Scope" -Type Security `
     -Members Vehicle.booking@pspf.co.sz `
     -PrimarySmtpAddress vbk-mailreader-scope@pspf.co.sz

   New-ApplicationAccessPolicy -AppId <CLIENT_ID> `
     -PolicyScopeGroupId vbk-mailreader-scope@pspf.co.sz `
     -AccessRight RestrictAccess `
     -Description "Restrict Vehicle Booking app to the booking mailbox only"

   Test-ApplicationAccessPolicy -Identity Vehicle.booking@pspf.co.sz -AppId <CLIENT_ID>  # Granted
   Test-ApplicationAccessPolicy -Identity anyone.else@pspf.co.sz    -AppId <CLIENT_ID>   # Denied
   ```
5. Return **tenant ID, client ID, client secret**, and confirm the mailbox
   address. No mailbox password is needed.

## Testing checklist

- [ ] `php -m` shows `curl` (Graph) or `imap` (IMAP method).
- [ ] Run the poller by hand: `php cron_process_email_replies_graph.php` (Graph)
      or `php cron_process_email_replies.php` (IMAP). It should connect and print
      `processed 0 reply message(s).` A token/permission error here means the app
      registration or config needs a fix.
- [ ] Submit a test request -> driver gets an email listing available vehicles.
      Reply `ASSIGN <registration>` -> vehicle is marked allocated, request moves
      to `pending_supervisor`, confirmation comes back.
- [ ] Reply `ASSIGN <bad reg>` -> get the available-vehicle list back and the
      token still works (reply again with a valid one).
- [ ] Reply `APPROVE` from the supervisor's address -> request moves to
      `pending_hrm`.
- [ ] Reply `REJECT not needed` as HRM -> request becomes `rejected` with the
      reason recorded and logged in `request_logs`.
- [ ] Reply from a different address -> no action, "did not come from..." notice.
- [ ] Reply twice -> second reply gets "already been actioned".

## Files

| File | Purpose |
|------|---------|
| `sql/email_action_tokens.sql` | Token table |
| `email_action.php` | Token issuing, reply parsing, `applyEmailAction()` |
| `notification_engine.php` | Emits tokens + `Reply-To` on approval emails |
| `graph_client.php` | Microsoft Graph token + HTTPS helper (app-only) |
| `cron_process_email_replies_graph.php` | Graph poller (Microsoft 365) |
| `cron_process_email_replies.php` | IMAP poller (local mailbox alternative) |
| `mail_inbox_config.sample.php` | Config template (copy to `mail_inbox_config.php`) |
