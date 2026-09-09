<?php
/**
 * Poll the Vehicle.booking Microsoft 365 mailbox via Microsoft Graph and action
 * any approve / reject / assign replies from drivers, supervisors and HRM.
 *
 * Runs on hpkprd on a schedule (Windows Task Scheduler or cron), e.g. every
 * 2 minutes:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\pspf_crm\vehicle_booking\cron_process_email_replies_graph.php
 *
 * Reads mail over HTTPS with an app-only OAuth token - no IMAP, no mailbox
 * password. The token in each email's subject is the security anchor
 * (single-use, per-approver, per-stage, expiring); the sender is checked as a
 * second factor; and an action only applies while the request is still at that
 * stage, so replays or concurrent replies can't double-action. All of that,
 * plus the DB updates and confirmations, is shared with the dashboard via
 * email_action.php.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/notification_engine.php'; // loads email_action.php + sendMailTo()
require __DIR__ . '/graph_client.php';

// --- Preconditions ---------------------------------------------------------
if (!function_exists('curl_init')) {
    fwrite(STDERR, "[vbk] PHP cURL extension is not enabled. Enable extension=curl in php.ini.\n");
    exit(1);
}
$cfg = emailInboxConfig();
if (!$cfg || empty($cfg['tenant_id']) || empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['mailbox'])) {
    fwrite(STDERR, "[vbk] mail_inbox_config.php is missing Graph settings (tenant_id / client_id / client_secret / mailbox). Copy mail_inbox_config.sample.php and fill it in.\n");
    exit(1);
}

$mailbox = $cfg['mailbox'];
$replyTo = emailActionReplyTo() ?: $mailbox;

// --- Get an app-only token -------------------------------------------------
try {
    $token = graphGetToken($cfg);
} catch (\Throwable $e) {
    fwrite(STDERR, "[vbk] token error: " . $e->getMessage() . "\n");
    exit(1);
}

// --- List unread messages in the booking mailbox ---------------------------
$base = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($mailbox);
$list = $base . '/mailFolders/Inbox/messages?'
      . '$filter=' . rawurlencode('isRead eq false')
      . '&$select=' . rawurlencode('id,subject,from,body')
      . '&$top=25';

try {
    [$code, $data] = graphApi($token, 'GET', $list);
} catch (\Throwable $e) {
    fwrite(STDERR, "[vbk] Graph request error: " . $e->getMessage() . "\n");
    exit(1);
}
if ($code !== 200 || !isset($data['value'])) {
    $err = $data['error']['message'] ?? "HTTP $code";
    fwrite(STDERR, "[vbk] Graph list failed: $err\n");
    exit(1);
}

// --- Process each reply ----------------------------------------------------
$processed = 0;
foreach ($data['value'] as $msg) {
    $subject = $msg['subject'] ?? '';
    $sender  = $msg['from']['emailAddress']['address'] ?? '';
    $body    = $msg['body']['content'] ?? '';
    $id      = $msg['id'] ?? '';

    $parsed = parseEmailActionSubject($subject);
    if (!$parsed) {
        // Not one of our approval threads - leave it unread and untouched.
        continue;
    }

    $command = parseEmailActionBody($body);
    if (!$command) {
        vbk_reply($sender,
            "Could not read your vehicle request reply",
            "We received your reply to request #{$parsed['request_id']} but couldn't tell whether you meant to approve or reject.<br><br>" .
            "Please reply again with a single word on the first line: <strong>APPROVE</strong>, or <strong>REJECT</strong> followed by a reason.",
            $replyTo);
        vbk_markRead($token, $base, $id);
        $processed++;
        continue;
    }

    $result = applyEmailAction($conn, $parsed['token'], $sender, $command['action'], $command['reason'], $command['vehicle'] ?? '');

    vbk_reply($sender,
        "Vehicle Request #{$parsed['request_id']}: " . ($result['status'] === 'applied' ? 'Recorded' : 'No change'),
        $result['message'],
        $replyTo);

    vbk_markRead($token, $base, $id);
    $processed++;
    error_log("[vbk] request #{$parsed['request_id']} from {$sender}: {$command['action']} -> {$result['status']}");
}

echo "[vbk] processed {$processed} reply message(s).\n";

// --- Helpers ---------------------------------------------------------------

/** Send a confirmation/notice back to the approver. Best-effort; never fatal. */
function vbk_reply(string $to, string $subject, string $message, ?string $replyTo): void {
    if (!$to) return;
    try {
        sendMailTo($to, $subject, $message, $replyTo);
    } catch (\Throwable $e) {
        error_log("[vbk] confirmation send failed to {$to}: " . $e->getMessage());
    }
}

/** Mark a Graph message read so it is not reprocessed on the next run. */
function vbk_markRead(string $token, string $base, string $id): void {
    if ($id === '') return;
    try {
        graphApi($token, 'PATCH', $base . '/messages/' . rawurlencode($id), ['isRead' => true]);
    } catch (\Throwable $e) {
        error_log("[vbk] mark-read failed: " . $e->getMessage());
    }
}
