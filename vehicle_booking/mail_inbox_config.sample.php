<?php
/**
 * Sample inbox config for reply-by-email approvals.
 *
 * Copy this file to  mail_inbox_config.php  (git-ignored, so real secrets are
 * never committed) and fill in the values.
 *
 * Two ways to read the booking mailbox:
 *   'graph' - the Microsoft 365 mailbox over Microsoft Graph (app-only OAuth).
 *             Recommended when mail is on 365. Poller:
 *             cron_process_email_replies_graph.php
 *   'imap'  - a mailbox reachable over IMAP with a password (e.g. a local /
 *             in-house mailbox). Poller: cron_process_email_replies.php
 *
 * 'reply_to' is stamped on outgoing approval emails so replies land in the
 * booking mailbox. For Graph that is simply the 365 mailbox itself.
 *
 * See EMAIL_APPROVALS_README.md for the Entra app registration steps.
 */

return [
    'method'   => 'graph',

    'reply_to' => 'Vehicle.booking@pspf.co.sz',

    // ----- Microsoft 365 / Graph  (method 'graph') -----
    // From the Entra app registration your 365 admin creates.
    'tenant_id'     => 'CHANGE_ME',   // Directory (tenant) ID
    'client_id'     => 'CHANGE_ME',   // Application (client) ID
    'client_secret' => 'CHANGE_ME',   // client secret VALUE (not the secret ID)
    'mailbox'       => 'Vehicle.booking@pspf.co.sz',

    // ----- IMAP  (method 'imap', alternative for a local mailbox) -----
    'host'            => 'mail.pspf.co.sz',
    'port'            => 993,          // 993 (IMAPS) or 143 (plain/STARTTLS)
    'ssl'             => true,
    'novalidate_cert' => false,        // true only for an internal self-signed cert
    'folder'          => 'INBOX',
    'username'        => 'Vehicle.booking@pspf.co.sz',
    'password'        => 'CHANGE_ME',
];
