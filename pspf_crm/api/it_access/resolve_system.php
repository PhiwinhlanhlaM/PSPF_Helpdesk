<?php
/**
 * IT Access - resolve an officer's per-system rejection.
 *
 * When an officer rejects some systems on a request, the request pauses at
 * 'awaiting-requester'. The requester must respond to each rejected system:
 *
 *   action=accept  -> the system is 'dropped' (finally not granted).
 *   action=appeal  -> the system returns to the officer queue ('pending') with
 *                     the requester's added justification; the prior rejection
 *                     reason is preserved as context. appeal_count is bumped.
 *
 * ONE appeal per system: a system that has already been appealed once
 * (appeal_count >= 1) cannot be appealed again - it may only be accepted. This
 * mirrors the whole-request one-appeal rule (007) and prevents endless loops.
 *
 * Once no rejected-and-unresolved system remains, the request leaves
 * 'awaiting-requester': it goes to 'awaiting-director' if every system is now
 * terminal (actioned/dropped), or back to 'claimed'/'new' if an appealed system
 * is again waiting for an officer.
 *
 * POST JSON:
 *   { request_db_id: <id>, system_id: 'banking', action: 'accept'|'appeal',
 *     justification: '...'  (required for appeal, >= 10 chars) }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once __DIR__ . '/mailer.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token mismatch']);
    exit;
}

$userId    = (int)$_SESSION['user']['id'];
$requestId = (int)($body['request_db_id'] ?? 0);
$systemId  = trim((string)($body['system_id'] ?? ''));
$act       = $body['action'] ?? '';
$just      = trim($body['justification'] ?? '');

if (!$requestId || $systemId === '' || !in_array($act, ['accept', 'appeal'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'request_db_id, system_id, and action (accept|appeal) are required']);
    exit;
}
if ($act === 'appeal' && strlen($just) < 10) {
    http_response_code(422);
    echo json_encode(['error' => 'A justification (min 10 characters) is required to appeal']);
    exit;
}

// The request must exist, belong to this requester, and be awaiting a response.
$reqStmt = $conn->prepare(
    "SELECT submitted_by, status, ref_number FROM it_access_requests WHERE id = ?"
);
$reqStmt->bind_param("i", $requestId);
$reqStmt->execute();
$req = $reqStmt->get_result()->fetch_assoc();
$reqStmt->close();

if (!$req) {
    http_response_code(404);
    echo json_encode(['error' => 'Request not found']);
    exit;
}
// Only the requester may resolve their own rejected systems (superadmin may act
// as a documented override for a stuck request).
if ((int)$req['submitted_by'] !== $userId && !hasRole('superadmin')) {
    http_response_code(403);
    echo json_encode(['error' => 'You may only respond to your own request']);
    exit;
}

// The target system must currently be 'rejected' (awaiting the requester).
$sysStmt = $conn->prepare(
    "SELECT id, status, appeal_count FROM it_request_systems
     WHERE request_id = ? AND system_id = ? LIMIT 1"
);
$sysStmt->bind_param("is", $requestId, $systemId);
$sysStmt->execute();
$sys = $sysStmt->get_result()->fetch_assoc();
$sysStmt->close();

if (!$sys) {
    http_response_code(404);
    echo json_encode(['error' => 'System not found on this request']);
    exit;
}
if ($sys['status'] !== 'rejected') {
    http_response_code(409);
    echo json_encode(['error' => "System '{$systemId}' is not awaiting your response (status: {$sys['status']})"]);
    exit;
}

// One-appeal guard: if already appealed once, appeal is no longer allowed.
if ($act === 'appeal' && (int)$sys['appeal_count'] >= 1) {
    http_response_code(409);
    echo json_encode(['error' => 'This system has already been appealed once; you may only accept the decision now.']);
    exit;
}

$conn->begin_transaction();
try {
    if ($act === 'accept') {
        // Finalize as dropped - not granted. Keep the reject reason for the record.
        $u = $conn->prepare(
            "UPDATE it_request_systems SET status = 'dropped'
             WHERE request_id = ? AND system_id = ? AND status = 'rejected'"
        );
        $u->bind_param("is", $requestId, $systemId);
        $u->execute();
        $u->close();
    } else {
        // Appeal: return to the officer queue. Reset claim/action fields so any
        // officer can pick it up fresh, but PRESERVE the officer's reason as
        // context so the re-reviewing officer sees why it was denied. The
        // requester's justification is appended so it travels with the system.
        $rStmt = $conn->prepare(
            "SELECT reject_reason FROM it_request_systems WHERE request_id = ? AND system_id = ? LIMIT 1"
        );
        $rStmt->bind_param("is", $requestId, $systemId);
        $rStmt->execute();
        $prevReason = (string)($rStmt->get_result()->fetch_assoc()['reject_reason'] ?? '');
        $rStmt->close();

        $context = trim("Officer's reason: {$prevReason}\nRequester's appeal: {$just}");

        $u = $conn->prepare(
            "UPDATE it_request_systems
             SET status = 'pending',
                 claimed_by = NULL, claimed_at = NULL,
                 actioned_by = NULL, actioned_at = NULL,
                 reject_reason = ?,
                 appeal_count = appeal_count + 1
             WHERE request_id = ? AND system_id = ? AND status = 'rejected'"
        );
        $u->bind_param("sis", $context, $requestId, $systemId);
        $u->execute();
        $u->close();
    }

    // Recompute the request status now that this system changed.
    //   any 'rejected' left        -> still awaiting-requester
    //   else any pending/claimed   -> back in the officer queue (claimed if some
    //                                 already actioned, else new)
    //   else all terminal          -> awaiting-director
    $st = $conn->query(
        "SELECT
            SUM(status = 'rejected')             AS rejected_open,
            SUM(status IN ('pending','claimed')) AS still_open,
            SUM(status = 'actioned')             AS granted
         FROM it_request_systems WHERE request_id = " . (int)$requestId
    )->fetch_assoc();

    if ((int)$st['rejected_open'] > 0) {
        $newStatus = 'awaiting-requester';
    } elseif ((int)$st['still_open'] > 0) {
        // If any system has already been granted, the request is mid-review
        // ('claimed'); otherwise it is effectively fresh in the officer queue.
        $newStatus = ((int)$st['granted'] > 0) ? 'claimed' : 'new';
    } else {
        $newStatus = 'awaiting-director';
    }

    $up = $conn->prepare("UPDATE it_access_requests SET status = ? WHERE id = ?");
    $up->bind_param("si", $newStatus, $requestId);
    $up->execute();
    $up->close();

    $conn->commit();

    // If the request just re-entered the officer queue because of an appeal,
    // notify the officers so the re-review happens.
    if ($act === 'appeal' && in_array($newStatus, ['new', 'claimed'], true)) {
        [$html, $text] = itAccessEmailBody(
            "IT Access - Appealed System Awaiting Review",
            ["A requester has appealed a previously declined system. It is back in the ICT queue for review, with the requester's added justification and the original decision attached."],
            [
                'Reference' => $req['ref_number'],
                'System'    => $systemId,
            ],
            ['text' => 'Review & claim request', 'url' => itAccessAppUrl()]
        );
        itAccessSendMail(itAccessOfficers($conn), "IT Access - Appealed System Awaiting Review - {$req['ref_number']}", $html, $text);
    }

    // If the request just cleared to the director, notify them.
    if ($newStatus === 'awaiting-director') {
        [$html, $text] = itAccessEmailBody(
            "IT Access Request Awaiting Your Action",
            ["An IT access request has been fully resolved by the ICT team and requester, and is awaiting your final sign-off."],
            ['Reference' => $req['ref_number']],
            ['text' => 'Review & sign off', 'url' => itAccessAppUrl()]
        );
        itAccessSendMail(itAccessDirectors($conn), "IT Access Request Awaiting Your Action - {$req['ref_number']}", $html, $text);
    }

    echo json_encode(['ok' => true, 'system_id' => $systemId, 'action' => $act, 'new_status' => $newStatus]);
} catch (\Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
