<?php
/**
 * Shared logic for approving/rejecting vehicle requests by email.
 *
 * The web dashboards remain the primary interface; this adds an out-of-office
 * path for supervisors and HRM, who often need to act while off the PSPF
 * network. Each approval email carries a single-use token in its subject line
 * (e.g. "[VBK-123-<32 hex>]"). The approver replies APPROVE or REJECT <reason>;
 * cron_process_email_replies.php polls the booking mailbox and calls
 * applyEmailAction() below, which runs the exact same DB updates + request_logs
 * inserts + notifications that supervisor_approve_request.php / hrm_approve_request.php
 * run today.
 *
 * NOTE: this file defines functions only and must not require notification_engine.php
 * (which requires this file). sendRequestEmail()/sendMailTo() are resolved at call
 * time, by which point notification_engine.php has loaded.
 */

// How long an emailed approval token stays valid.
if (!defined('EMAIL_TOKEN_TTL_DAYS')) {
    define('EMAIL_TOKEN_TTL_DAYS', 7);
}

/**
 * Per-stage configuration: what status the request must be in, which column
 * records the actor, and what to do on approve/reject. Mirrors the web handlers.
 */
function emailActionStageConfig(): array
{
    return [
        'supervisor' => [
            'expected_status' => 'pending_supervisor',
            'id_column'       => 'supervisor_id',
            'approve_status'  => 'pending_hrm',
            'approve_log'     => 'Supervisor approved request (via email)',
            'reject_log'      => 'Supervisor rejected request (via email)',
            'approve_stage'   => 'supervisor_approved',
            'reject_stage'    => 'supervisor_rejected',
            'label'           => 'Supervisor',
        ],
        'hrm' => [
            'expected_status' => 'pending_hrm',
            'id_column'       => 'hrm_id',
            'approve_status'  => 'approved',
            'approve_log'     => 'HRM approved request (via email)',
            'reject_log'      => 'HRM rejected request (via email)',
            'approve_stage'   => 'hrm_approved',
            'reject_stage'    => 'hrm_rejected',
            'label'           => 'HRM',
        ],
    ];
}

/**
 * Load the inbox config (host/credentials + reply_to) if present.
 * Returns null when the file has not been created yet, so web pages that merely
 * include notification_engine.php never break for want of it.
 */
function emailInboxConfig(): ?array
{
    static $cfg = false; // false = not yet attempted, null = absent
    if ($cfg === false) {
        $path = __DIR__ . '/mail_inbox_config.php';
        $cfg = is_file($path) ? require $path : null;
    }
    return $cfg;
}

/** The address replies should be directed to (the booking mailbox), or null. */
function emailActionReplyTo(): ?string
{
    $cfg = emailInboxConfig();
    return $cfg['reply_to'] ?? null;
}

/**
 * Create and store a single-use token for one approver at one stage.
 */
function issueEmailActionToken(PDO $conn, int $request_id, string $stage, int $approver_user_id): string
{
    $token = bin2hex(random_bytes(16)); // 32 hex chars
    $conn->prepare("
        INSERT INTO email_action_tokens
            (token, request_id, stage, approver_user_id, expires_at, created_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), NOW())
    ")->execute([$token, $request_id, $stage, $approver_user_id, EMAIL_TOKEN_TTL_DAYS]);
    return $token;
}

/** The marker embedded in an approval email's subject line. */
function emailActionSubjectMarker(int $request_id, string $token): string
{
    return "[VBK-{$request_id}-{$token}]";
}

/**
 * The instruction block appended to an approval email body so the approver
 * knows exactly how to reply.
 */
function emailActionInstructions(): string
{
    return
        "<br><hr>" .
        "<strong>To action this request by email</strong> (works off the PSPF network):<br>" .
        "Simply <strong>reply to this email</strong> with one of:<br>" .
        "&nbsp;&nbsp;&bull; <strong>APPROVE</strong><br>" .
        "&nbsp;&nbsp;&bull; <strong>REJECT</strong> followed by the reason, e.g. <em>REJECT vehicle needed elsewhere</em><br>" .
        "<small>Keep the subject line unchanged so we can match your reply. " .
        "This request can also be actioned on the dashboard when on the network.</small>";
}

/**
 * Pull the request id + token out of a (possibly "Re: ...") subject line.
 * Returns ['request_id'=>int,'token'=>string] or null.
 */
function parseEmailActionSubject(string $subject): ?array
{
    if (preg_match('/\[VBK-(\d+)-([0-9a-fA-F]{32})\]/', $subject, $m)) {
        return ['request_id' => (int) $m[1], 'token' => strtolower($m[2])];
    }
    return null;
}

/**
 * Read the approver's command from the reply body.
 * Uses the first line that starts with a keyword, skipping blank and quoted
 * (">") lines so signatures and quoted history don't interfere.
 * Returns ['action'=>'approve'|'reject','reason'=>string] or null.
 */
function parseEmailActionBody(string $body): ?array
{
    // Strip HTML if the reply came as HTML.
    if (stripos($body, '<') !== false && stripos($body, '>') !== false) {
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $lines = preg_split('/\r\n|\r|\n/', $body);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '>') {
            continue; // blank or quoted history
        }
        if (preg_match('/^(approve|approved|yes|ok|okay)\b/i', $line)) {
            return ['action' => 'approve', 'reason' => ''];
        }
        if (preg_match('/^(reject|rejected|decline|declined|no)\b[:\s-]*(.*)$/i', $line, $m)) {
            return ['action' => 'reject', 'reason' => trim($m[2])];
        }
        // First meaningful line was not a recognised command — stop looking so we
        // don't accidentally match a keyword buried in prose further down.
        break;
    }
    return null;
}

/**
 * Apply an emailed approve/reject decision.
 *
 * Returns ['status'=>..., 'message'=>...] where status is one of:
 *   applied | already_actioned | invalid_token | expired | used |
 *   wrong_sender | wrong_stage . 'message' is a human-readable line suitable
 *   for the confirmation reply sent back to the approver.
 */
function applyEmailAction(PDO $conn, string $token, string $senderEmail, string $action, string $reason = ''): array
{
    $token = strtolower(trim($token));
    $senderEmail = strtolower(trim($senderEmail));

    // 1. Look up the token.
    $stmt = $conn->prepare("SELECT * FROM email_action_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['status' => 'invalid_token', 'message' => 'We could not recognise this approval link. It may be from an old email.'];
    }
    if ($row['used_at'] !== null) {
        return ['status' => 'used', 'message' => "This request (#{$row['request_id']}) has already been actioned. No change was made."];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['status' => 'expired', 'message' => "This approval link for request #{$row['request_id']} has expired. Please action it on the dashboard."];
    }

    $stageKey = $row['stage'];
    $stages = emailActionStageConfig();
    if (!isset($stages[$stageKey])) {
        return ['status' => 'invalid_token', 'message' => 'Unknown approval stage.'];
    }
    $cfg = $stages[$stageKey];

    // 2. Verify the sender is the approver this token was issued to.
    $approver = $conn->prepare("SELECT user_id, email, name FROM users WHERE user_id = ?");
    $approver->execute([$row['approver_user_id']]);
    $approver = $approver->fetch(PDO::FETCH_ASSOC);
    if (!$approver || strtolower(trim($approver['email'])) !== $senderEmail) {
        return ['status' => 'wrong_sender', 'message' => 'This reply did not come from the address the request was sent to, so no action was taken.'];
    }

    // 3. Confirm the request is still awaiting this stage.
    $req = $conn->prepare("SELECT request_id, status FROM vehicle_requests WHERE request_id = ?");
    $req->execute([$row['request_id']]);
    $req = $req->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return ['status' => 'invalid_token', 'message' => "Request #{$row['request_id']} could not be found."];
    }
    if ($req['status'] !== $cfg['expected_status']) {
        // Someone else already moved it on (another supervisor, or the dashboard).
        markTokenUsed($conn, $token, null);
        return ['status' => 'already_actioned', 'message' => "Request #{$row['request_id']} has already progressed and no longer needs your action."];
    }

    // 4. Claim the token atomically so two concurrent replies can't both act.
    $decision = ($action === 'approve') ? 'approved' : 'rejected';
    if (!markTokenUsed($conn, $token, $decision)) {
        return ['status' => 'already_actioned', 'message' => "Request #{$row['request_id']} has already been actioned. No change was made."];
    }

    $request_id = (int) $row['request_id'];
    $approver_id = (int) $approver['user_id'];

    // 5. Apply the same changes the web handlers make.
    if ($action === 'approve') {
        $conn->prepare("
            UPDATE vehicle_requests
            SET {$cfg['id_column']} = ?, status = ?, updated_at = NOW()
            WHERE request_id = ?
        ")->execute([$approver_id, $cfg['approve_status'], $request_id]);

        $conn->prepare("
            INSERT INTO request_logs (request_id, action_by, action, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([$request_id, $approver_id, $cfg['approve_log']]);

        sendRequestEmail($conn, $request_id, $cfg['approve_stage']);

        return ['status' => 'applied', 'message' => "Thank you. You have APPROVED request #{$request_id}."];
    }

    // Reject
    $conn->prepare("
        UPDATE vehicle_requests
        SET status = 'rejected', rejection_reason = ?, updated_at = NOW()
        WHERE request_id = ?
    ")->execute([$reason, $request_id]);

    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, ?, NOW())
    ")->execute([$request_id, $approver_id, $cfg['reject_log']]);

    sendRequestEmail($conn, $request_id, $cfg['reject_stage']);

    return ['status' => 'applied', 'message' => "Thank you. You have REJECTED request #{$request_id}." . ($reason !== '' ? " Reason recorded: {$reason}" : '')];
}

/**
 * Mark a token consumed, but only if it is still unused. Returns true when this
 * call is the one that claimed it (affected a row), false if already used.
 */
function markTokenUsed(PDO $conn, string $token, ?string $result): bool
{
    $stmt = $conn->prepare("
        UPDATE email_action_tokens
        SET used_at = NOW(), result = ?
        WHERE token = ? AND used_at IS NULL
    ");
    $stmt->execute([$result, $token]);
    return $stmt->rowCount() > 0;
}
