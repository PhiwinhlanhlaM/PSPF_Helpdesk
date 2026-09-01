<?php
/**
 * Sample inbox config for reply-by-email approvals.
 *
 * Copy this file to  mail_inbox_config.php  (which is git-ignored so the real
 * password is never committed) and fill in the values for the dedicated
 * booking mailbox — Vehicle.booking@pspf.co.sz.
 *
 * The cron poller (cron_process_email_replies.php) reads this mailbox over IMAP;
 * 'reply_to' is stamped on outgoing approval emails so approvers' replies land
 * back here.
 */

return [
    // IMAP server for the booking mailbox.
    'host'            => 'mail.pspf.co.sz',   // your mail server host or IP
    'port'            => 143,                 // 143 (plain/STARTTLS) or 993 (IMAPS)
    'ssl'            => false,                // true if using IMAPS on 993
    'novalidate_cert' => false,               // true only for a self-signed cert on an internal server
    'folder'          => 'INBOX',

    // Credentials for Vehicle.booking@pspf.co.sz
    'username'        => 'Vehicle.booking@pspf.co.sz',
    'password'        => 'CHANGE_ME',

    // Address replies should be directed to (normally the booking mailbox itself).
    'reply_to'        => 'Vehicle.booking@pspf.co.sz',
];
