<?php
/**
 * Poll the vehicle-booking mailbox and action any approve/reject replies from
 * supervisors and HRM. Runs inside the PSPF network on a schedule, e.g. every
 * two minutes:
 *
 *   * /2 * * * * php /var/www/pspf_crm/vehicle_booking/cron_process_email_replies.php >> /var/log/vbk_email.log 2>&1
 *   (remove the space in "* /2" — cron uses "*\/2")
 *
 * The token embedded in each email's subject is the security anchor: it is
 * single-use, tied to one approver and one stage, and expiring. The sender
 * address is checked as a second factor, and an action only applies while the
 * request is still awaiting that stage — so replays, stale replies, or two
 * supervisors both replying can never double-action a request.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/notification_engine.php'; // also loads email_action.php

// --- Preconditions ---------------------------------------------------------
if (!function_exists('imap_open')) {
    fwrite(STDERR, "[vbk] PHP IMAP extension (php-imap) is not installed/enabled. Aborting.\n");
    exit(1);
}

$cfg = emailInboxConfig();
if (!$cfg || empty($cfg['host']) || empty($cfg['username'])) {
    fwrite(STDERR, "[vbk] mail_inbox_config.php is missing or incomplete. Copy mail_inbox_config.sample.php and fill it in.\n");
    exit(1);
}

$replyTo = emailActionReplyTo(); // booking mailbox, used as From-reply on our confirmations

// --- Connect ---------------------------------------------------------------
$flags = '/imap';
$flags .= !empty($cfg['ssl']) ? '/ssl' : '';
$flags .= !empty($cfg['novalidate_cert']) ? '/novalidate-cert' : '';
$mailboxDsn = '{' . $cfg['host'] . ':' . ($cfg['port'] ?? 143) . $flags . '}' . ($cfg['folder'] ?? 'INBOX');

$inbox = @imap_open($mailboxDsn, $cfg['username'], $cfg['password']);
if (!$inbox) {
    fwrite(STDERR, "[vbk] IMAP connect failed: " . imap_last_error() . "\n");
    exit(1);
}

// Only look at messages we have not read yet. The token's used_at flag is the
// real guard against double-processing; marking messages Seen just avoids
// re-replying on every poll.
$ids = imap_search($inbox, 'UNSEEN');
if ($ids === false) {
    $ids = [];
}

$processed = 0;
foreach ($ids as $num) {
    $header = imap_headerinfo($inbox, $num);
    if (!$header) {
        continue;
    }

    $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
    $fromObj = $header->from[0] ?? null;
    $senderEmail = $fromObj ? ($fromObj->mailbox . '@' . $fromObj->host) : '';

    $parsedSubject = parseEmailActionSubject($subject);
    if (!$parsedSubject) {
        // Not one of our approval threads — leave it untouched for a human.
        continue;
    }

    $body = vbk_get_plain_body($inbox, $num);
    $command = parseEmailActionBody($body);

    if (!$command) {
        vbk_reply($senderEmail,
            "Could not read your vehicle request reply",
            "We received your reply to request #{$parsedSubject['request_id']} but couldn't tell whether you meant to approve or reject.<br><br>" .
            "Please reply again with a single word on the first line: <strong>APPROVE</strong>, or <strong>REJECT</strong> followed by a reason.",
            $replyTo);
        imap_setflag_full($inbox, (string) $num, "\\Seen");
        $processed++;
        continue;
    }

    $result = applyEmailAction($conn, $parsedSubject['token'], $senderEmail, $command['action'], $command['reason']);

    vbk_reply($senderEmail,
        "Vehicle Request #{$parsedSubject['request_id']} — " . ($result['status'] === 'applied' ? 'Recorded' : 'No change'),
        $result['message'],
        $replyTo);

    imap_setflag_full($inbox, (string) $num, "\\Seen");
    $processed++;

    error_log("[vbk] request #{$parsedSubject['request_id']} from {$senderEmail}: {$command['action']} -> {$result['status']}");
}

imap_close($inbox);
echo "[vbk] processed {$processed} reply message(s).\n";

// --- Helpers ---------------------------------------------------------------

/**
 * Send a confirmation/notice back to the approver. Best-effort: never fatal.
 */
function vbk_reply(string $to, string $subject, string $message, ?string $replyTo): void
{
    if (!$to) {
        return;
    }
    try {
        sendMailTo($to, $subject, $message, $replyTo);
    } catch (\Throwable $e) {
        error_log("[vbk] failed to send confirmation to {$to}: " . $e->getMessage());
    }
}

/**
 * Extract a plain-text body from a message, preferring text/plain and falling
 * back to a stripped text/html part.
 */
function vbk_get_plain_body($inbox, int $num): string
{
    $structure = imap_fetchstructure($inbox, $num);

    if (empty($structure->parts)) {
        // Single-part message.
        $body = imap_body($inbox, $num);
        return vbk_decode_part($body, $structure->encoding ?? 0);
    }

    $plain = '';
    $html = '';
    vbk_walk_parts($inbox, $num, $structure->parts, '', $plain, $html);

    if ($plain !== '') {
        return $plain;
    }
    return $html; // parseEmailActionBody() strips tags if needed
}

/**
 * Recurse through MIME parts collecting the first text/plain and text/html bodies.
 */
function vbk_walk_parts($inbox, int $num, array $parts, string $prefix, string &$plain, string &$html): void
{
    foreach ($parts as $i => $part) {
        $section = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
        $subtype = strtoupper($part->subtype ?? '');
        if (($part->type ?? 0) == 0) { // text
            if ($subtype === 'PLAIN' && $plain === '') {
                $plain = vbk_decode_part(imap_fetchbody($inbox, $num, $section), $part->encoding ?? 0);
            } elseif ($subtype === 'HTML' && $html === '') {
                $html = vbk_decode_part(imap_fetchbody($inbox, $num, $section), $part->encoding ?? 0);
            }
        }
        if (!empty($part->parts)) {
            vbk_walk_parts($inbox, $num, $part->parts, $section, $plain, $html);
        }
    }
}

/**
 * Decode a MIME part per its transfer encoding (3 = base64, 4 = quoted-printable).
 */
function vbk_decode_part(string $data, int $encoding): string
{
    switch ($encoding) {
        case 3:  return base64_decode($data);
        case 4:  return quoted_printable_decode($data);
        default: return $data;
    }
}
