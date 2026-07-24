<?php
/**
 * IT Access — appeal a rejected request (feedback item 3).
 *
 * Creates a NEW request linked to a rejected one via appeal_of, carrying the
 * requester's revisions. The original is never modified — it stays as the
 * signed record of the rejection. The appeal re-enters the chain from the top
 * (supervisor -> ICT -> director), exactly like a fresh request.
 *
 * Rules enforced here:
 *   - only the person who submitted the original may appeal it
 *   - the original must actually be 'rejected'
 *   - ONE appeal only: a request that is itself an appeal, or one already
 *     appealed, cannot be appealed again
 *
 * POST JSON mirrors submit.php's shape, plus:
 *   { appealOf: <original request db id>, ...employee/systems/justification/etc }
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
require_once __DIR__ . '/supervisor_helpers.php';

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

$submittedBy = (int)$_SESSION['user']['id'];
$appealOf    = (int)($body['appealOf'] ?? 0);
if ($appealOf <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'appealOf (the rejected request) is required']);
    exit;
}

// ---------------------------------------------------------------------
// Validate the original: exists, belongs to this user, is rejected, and has
// not itself been appealed or is an appeal. All enforced server-side — the UI
// only hides the button, it does not gate the action.
// ---------------------------------------------------------------------
$origStmt = $conn->prepare(
    "SELECT id, submitted_by, status, appeal_of, ref_number FROM it_access_requests WHERE id = ?"
);
$origStmt->bind_param("i", $appealOf);
$origStmt->execute();
$orig = $origStmt->get_result()->fetch_assoc();
$origStmt->close();

if (!$orig) {
    http_response_code(404);
    echo json_encode(['error' => 'Original request not found']);
    exit;
}
if ((int)$orig['submitted_by'] !== $submittedBy) {
    http_response_code(403);
    echo json_encode(['error' => 'You may only appeal your own request']);
    exit;
}
if ($orig['status'] !== 'rejected') {
    http_response_code(409);
    echo json_encode(['error' => 'Only a rejected request can be appealed']);
    exit;
}
if ($orig['appeal_of'] !== null) {
    // The original is itself an appeal — a rejected appeal is final.
    http_response_code(409);
    echo json_encode(['error' => 'This was already an appeal, so it cannot be appealed again.']);
    exit;
}
// Has this request already been appealed once?
$dupStmt = $conn->prepare("SELECT id FROM it_access_requests WHERE appeal_of = ? LIMIT 1");
$dupStmt->bind_param("i", $appealOf);
$dupStmt->execute();
$already = $dupStmt->get_result()->fetch_assoc();
$dupStmt->close();
if ($already) {
    http_response_code(409);
    echo json_encode(['error' => 'This request has already been appealed once.']);
    exit;
}

// ---------------------------------------------------------------------
// Validate the revised payload (same rules as submit.php).
// ---------------------------------------------------------------------
$emp           = $body['employee'] ?? [];
$systems       = $body['systems'] ?? [];
$justification = trim($body['justification'] ?? '');
$startDate     = trim($body['startDate'] ?? '');
$approvals     = $body['approvals'] ?? [];
$requestType   = in_array($body['requestType'] ?? '', ['new','change']) ? $body['requestType'] : 'new';

$errors = [];
if (empty($emp['name']))          $errors[] = 'employee.name required';
if (empty($emp['department']))    $errors[] = 'employee.department required';
if (empty($emp['title']))         $errors[] = 'employee.title required';
if (empty($startDate))            $errors[] = 'startDate required';
if (empty($systems))              $errors[] = 'At least one system required';
if (strlen($justification) < 10)  $errors[] = 'justification must be at least 10 characters';

$managerApproval = null;
foreach ($approvals as $a) {
    if (($a['role'] ?? '') === 'manager') { $managerApproval = $a; break; }
}
if (!$managerApproval || empty($managerApproval['signature'])) {
    $errors[] = 'Signature required';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $errors[] = 'startDate must be YYYY-MM-DD';
}
if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode('; ', $errors)]);
    exit;
}

// Ref number: REQ-YYYY-NNNN (same scheme as submit.php)
$year = (int)date('Y');
$seqStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM it_access_requests WHERE YEAR(submitted_at) = ?");
$seqStmt->bind_param("i", $year);
$seqStmt->execute();
$seq = (int)$seqStmt->get_result()->fetch_assoc()['cnt'] + 1;
$seqStmt->close();
$refNumber = 'REQ-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

$empName   = htmlspecialchars(trim($emp['name']), ENT_QUOTES, 'UTF-8');
$empId     = htmlspecialchars(trim($emp['id'] ?? ''), ENT_QUOTES, 'UTF-8');
$empDept   = htmlspecialchars(trim($emp['department']), ENT_QUOTES, 'UTF-8');
$empDiv    = htmlspecialchars(trim($emp['division'] ?? ''), ENT_QUOTES, 'UTF-8');
$empTitle  = htmlspecialchars(trim($emp['title']), ENT_QUOTES, 'UTF-8');
$justClean = htmlspecialchars($justification, ENT_QUOTES, 'UTF-8');

// Route the appeal exactly like a new request: nominated supervisor if valid,
// else resolve; else straight to ICT.
$chosenSupervisor = isset($body['supervisorId']) ? (int)$body['supervisorId'] : 0;
$supervisorId = null;
if ($chosenSupervisor > 0 && $chosenSupervisor !== $submittedBy
    && itaIsUsableSupervisor($conn, $chosenSupervisor)) {
    $supervisorId = $chosenSupervisor;
} else {
    $supervisorId = itaResolveSupervisor($conn, $submittedBy);
}
$initialStatus = $supervisorId !== null ? 'awaiting-supervisor' : 'new';

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO it_access_requests
         (ref_number, request_type, employee_name, employee_id, department, division, job_title,
          start_date, justification, submitted_by, appeal_of, supervisor_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "sssssssssiiis",
        $refNumber, $requestType, $empName, $empId, $empDept, $empDiv, $empTitle,
        $startDate, $justClean, $submittedBy, $appealOf, $supervisorId, $initialStatus
    );
    $stmt->execute();
    $requestId = $conn->insert_id;
    $stmt->close();

    // Systems
    $syStmt = $conn->prepare(
        "INSERT INTO it_request_systems (request_id, system_id, role, sub_values) VALUES (?, ?, ?, ?)"
    );
    foreach ($systems as $sys) {
        $sysId   = htmlspecialchars(trim($sys['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawRole = $sys['role'] ?? '';
        $sysRole = htmlspecialchars(is_array($rawRole) ? implode(', ', $rawRole) : trim($rawRole), ENT_QUOTES, 'UTF-8');
        $subVals = isset($sys['subValues']) ? json_encode($sys['subValues']) : null;
        $syStmt->bind_param("isss", $requestId, $sysId, $sysRole, $subVals);
        $syStmt->execute();
    }
    $syStmt->close();

    // Manager (requester) approval + signature
    $sig = $managerApproval['signature'];
    $sigKind = $sig['kind'] ?? null;
    $sigData = null;
    if ($sigKind === 'drawn' && isset($sig['strokes'])) {
        $sigData = json_encode($sig['strokes']);
    } elseif ($sigKind === 'uploaded' && isset($sig['dataUrl'])) {
        $sigData = $sig['dataUrl'];
    }
    $apStmt = $conn->prepare(
        "INSERT INTO it_request_approvals
         (request_id, step_role, approver_id, action, acted_at, sig_kind, sig_data)
         VALUES (?, 'manager', ?, 'approved', UTC_TIMESTAMP(), ?, ?)"
    );
    $apStmt->bind_param("iiss", $requestId, $submittedBy, $sigKind, $sigData);
    $apStmt->execute();
    $apStmt->close();

    $conn->commit();

    // Notify whoever the appeal now waits on — supervisor if routed, else ICT.
    $submitterName = $_SESSION['user']['full_name'] ?? ($_SESSION['user']['username'] ?? 'User');
    $detail = [
        'Reference'   => $refNumber,
        'Appeal of'   => $orig['ref_number'],
        'Employee'    => $empName,
        'Department'  => $empDept,
        'Start date'  => $startDate,
    ];
    if ($supervisorId !== null) {
        $supervisor = itAccessUserById($conn, $supervisorId);
        if ($supervisor) {
            [$html, $text] = itAccessEmailBody(
                "IT Access Appeal Awaiting Your Approval",
                ["Dear {$supervisor['name']},",
                 "A previously rejected IT access request has been revised and resubmitted as an appeal. It needs your approval."],
                $detail,
                ['text' => 'Review appeal', 'url' => itAccessAppUrl()]
            );
            itAccessSendMail([$supervisor], "IT Access Appeal Awaiting Your Approval - $refNumber", $html, $text);
        }
    } else {
        [$html, $text] = itAccessEmailBody(
            "IT Access Appeal Submitted",
            ["A previously rejected request has been revised and resubmitted as an appeal, and is awaiting ICT action."],
            $detail,
            ['text' => 'Review & claim request', 'url' => itAccessAppUrl()]
        );
        itAccessSendMail(itAccessOfficers($conn), "IT Access Appeal Submitted - $refNumber", $html, $text);
    }

    echo json_encode(['ok' => true, 'id' => $requestId, 'ref' => $refNumber, 'appealOf' => $appealOf]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
