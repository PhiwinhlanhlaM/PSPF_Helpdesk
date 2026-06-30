<?php
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

if (!hasRole('it_officer')) {
    http_response_code(403);
    echo json_encode(['error' => 'it_officer role required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// CSRF validation
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token mismatch']);
    exit;
}

$requestDbId     = (int)($body['request_db_id'] ?? 0);
$actionedSystems = $body['actioned_systems'] ?? null; // array of system IDs the officer is taking on
if (!$requestDbId) {
    http_response_code(422);
    echo json_encode(['error' => 'request_db_id required']);
    exit;
}
if (!is_array($actionedSystems) || count($actionedSystems) === 0) {
    http_response_code(422);
    echo json_encode(['error' => 'At least one system must be selected to claim']);
    exit;
}

$officerId = (int)$_SESSION['user']['id'];

// Fetch current request
$rStmt = $conn->prepare(
    "SELECT ref_number, employee_name, status, claimed_by, submitted_by
     FROM it_access_requests WHERE id = ?"
);
$rStmt->bind_param("i", $requestDbId);
$rStmt->execute();
$req = $rStmt->get_result()->fetch_assoc();
$rStmt->close();

if (!$req) {
    http_response_code(404);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

// Systems can still be claimed while a request is 'new' or partially 'claimed'.
// Once it has advanced to the director (or beyond) nothing more can be claimed.
if (!in_array($req['status'], ['new', 'claimed'], true)) {
    http_response_code(409);
    echo json_encode(['error' => 'Request is no longer available to claim']);
    exit;
}

// Claim only the requested systems that are still 'pending'. This is atomic per
// row, so two officers racing for the same system can never both win it.
$conn->begin_transaction();
try {
    $claimSys = $conn->prepare(
        "UPDATE it_request_systems
         SET status = 'claimed', claimed_by = ?, claimed_at = NOW()
         WHERE request_id = ? AND system_id = ? AND status = 'pending'"
    );

    $claimedIds = [];
    foreach ($actionedSystems as $sysId) {
        $sysId = trim((string)$sysId);
        if ($sysId === '') continue;
        $claimSys->bind_param("iis", $officerId, $requestDbId, $sysId);
        $claimSys->execute();
        if ($claimSys->affected_rows > 0) {
            $claimedIds[] = $sysId;
        }
    }
    $claimSys->close();

    if (count($claimedIds) === 0) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['error' => 'Those systems have already been claimed by another officer']);
        exit;
    }

    // Keep the request in 'claimed' while at least one system is still unactioned.
    // claimed_by tracks the most recent claimer (used for list ordering / back-compat).
    $upStmt = $conn->prepare(
        "UPDATE it_access_requests
         SET status = 'claimed', claimed_by = ?
         WHERE id = ? AND status IN ('new','claimed')"
    );
    $upStmt->bind_param("ii", $officerId, $requestDbId);
    $upStmt->execute();
    $upStmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// No email is sent on claim. Per policy, the requestor is only notified on
// submission and once the request is fully provisioned; officers are notified on
// a new request and on provisioning. Claims (including partial claims) are silent.

echo json_encode(['ok' => true, 'new_status' => 'claimed']);
