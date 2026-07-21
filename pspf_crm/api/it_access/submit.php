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
require_once __DIR__ . '/supervisor_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);
// Any authenticated, active user may submit an IT access request. Requests are
// gated by the approval chain (supervisor -> ICT -> director), not by who is
// allowed to ask — so no role check here beyond being signed in.

// Read JSON body
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

// Validate required fields
$emp         = $body['employee'] ?? [];
$systems     = $body['systems'] ?? [];
$justification = trim($body['justification'] ?? '');
$startDate   = trim($body['startDate'] ?? '');
$approvals   = $body['approvals'] ?? [];
$requestType = in_array($body['requestType'] ?? '', ['new','change']) ? $body['requestType'] : 'new';

$errors = [];
if (empty($emp['name']))       $errors[] = 'employee.name required';
if (empty($emp['department'])) $errors[] = 'employee.department required';
if (empty($emp['title']))      $errors[] = 'employee.title required';
if (empty($startDate))         $errors[] = 'startDate required';
if (empty($systems))           $errors[] = 'At least one system required';
if (strlen($justification) < 10) $errors[] = 'justification must be at least 10 characters';

$managerApproval = null;
foreach ($approvals as $a) {
    if (($a['role'] ?? '') === 'manager') { $managerApproval = $a; break; }
}
if (!$managerApproval || empty($managerApproval['signature'])) {
    $errors[] = 'Manager signature required';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['error' => implode('; ', $errors)]);
    exit;
}

// Validate start date format YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    http_response_code(422);
    echo json_encode(['error' => 'startDate must be YYYY-MM-DD']);
    exit;
}

$submittedBy = (int)$_SESSION['user']['id'];

// Generate ref number: REQ-YYYY-NNNN
$year = (int)date('Y');
$seqStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM it_access_requests WHERE YEAR(submitted_at) = ?"
);
$seqStmt->bind_param("i", $year);
$seqStmt->execute();
$seqRow = $seqStmt->get_result()->fetch_assoc();
$seqStmt->close();
$seq = (int)$seqRow['cnt'] + 1;
$refNumber = 'REQ-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

// Sanitize employee fields
$empName   = htmlspecialchars(trim($emp['name']), ENT_QUOTES, 'UTF-8');
$empId     = htmlspecialchars(trim($emp['id'] ?? ''), ENT_QUOTES, 'UTF-8');
$empDept   = htmlspecialchars(trim($emp['department']), ENT_QUOTES, 'UTF-8');
$empDiv    = htmlspecialchars(trim($emp['division'] ?? ''), ENT_QUOTES, 'UTF-8');
$empTitle  = htmlspecialchars(trim($emp['title']), ENT_QUOTES, 'UTF-8');
$justClean = htmlspecialchars($justification, ENT_QUOTES, 'UTF-8');

// ---------------------------------------------------------------------
// Route the request. A requester may nominate their supervisor on the form;
// we accept that choice only if the person genuinely holds the supervisor role
// (the dropdown is not the access control). Otherwise we resolve it from their
// own override, then their division's supervisor, then the division delegate.
//
// If nothing resolves, the request skips the supervisor step and enters the
// ICT queue as 'new' — a request must never stall because nobody was assigned.
// ---------------------------------------------------------------------
$chosenSupervisor = isset($body['supervisorId']) ? (int)$body['supervisorId'] : 0;
$supervisorId = null;
if ($chosenSupervisor > 0
    && $chosenSupervisor !== $submittedBy
    && itaIsUsableSupervisor($conn, $chosenSupervisor)) {
    $supervisorId = $chosenSupervisor;
} else {
    $supervisorId = itaResolveSupervisor($conn, $submittedBy);
}
$initialStatus = $supervisorId !== null ? 'awaiting-supervisor' : 'new';

$conn->begin_transaction();
try {
    // Insert main request
    $stmt = $conn->prepare(
        "INSERT INTO it_access_requests
         (ref_number, request_type, employee_name, employee_id, department, division, job_title, start_date, justification, submitted_by, supervisor_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "sssssssssiis",
        $refNumber, $requestType, $empName, $empId, $empDept, $empDiv, $empTitle,
        $startDate, $justClean, $submittedBy, $supervisorId, $initialStatus
    );
    $stmt->execute();
    $requestId = $conn->insert_id;
    $stmt->close();

    // Insert systems
    $syStmt = $conn->prepare(
        "INSERT INTO it_request_systems (request_id, system_id, role, sub_values) VALUES (?, ?, ?, ?)"
    );
    foreach ($systems as $sys) {
        $sysId    = htmlspecialchars(trim($sys['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawRole  = $sys['role'] ?? '';
        $sysRole  = htmlspecialchars(is_array($rawRole) ? implode(', ', $rawRole) : trim($rawRole), ENT_QUOTES, 'UTF-8');
        $subVals  = isset($sys['subValues']) ? json_encode($sys['subValues']) : null;
        $syStmt->bind_param("isss", $requestId, $sysId, $sysRole, $subVals);
        $syStmt->execute();
    }
    $syStmt->close();

    // Insert manager approval + signature
    $sig = $managerApproval['signature'];
    $sigKind = $sig['kind'] ?? null;
    $sigData = null;
    if ($sigKind === 'drawn' && isset($sig['strokes'])) {
        $sigData = json_encode($sig['strokes']);
    } elseif ($sigKind === 'uploaded' && isset($sig['dataUrl'])) {
        $sigData = $sig['dataUrl'];
    }
    // Store the action time in UTC so it is consistent with the officer/director
    // steps and with the 'Z' suffix added when the list is serialized. Using the
    // server clock (UTC) avoids trusting a possibly-skewed browser clock.
    $apStmt = $conn->prepare(
        "INSERT INTO it_request_approvals
         (request_id, step_role, approver_id, action, acted_at, sig_kind, sig_data)
         VALUES (?, 'manager', ?, 'approved', UTC_TIMESTAMP(), ?, ?)"
    );
    $apStmt->bind_param("iiss", $requestId, $submittedBy, $sigKind, $sigData);
    $apStmt->execute();
    $apStmt->close();

    $conn->commit();

    // ---- Send notification emails (non-blocking, via shared CRM mail worker) ----
    $submitterEmail = $_SESSION['user']['email'] ?? '';
    // Prefer the saved full name, then session full name, then username.
    $submitterName  = $_SESSION['user']['full_name'] ?? '';
    if (trim($submitterName) === '') {
        $nStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        if ($nStmt) {
            $nStmt->bind_param("i", $submittedBy);
            $nStmt->execute();
            $nRow = $nStmt->get_result()->fetch_assoc();
            $nStmt->close();
            $submitterName = trim((string)($nRow['full_name'] ?? ''));
        }
    }
    if ($submitterName === '') $submitterName = $_SESSION['user']['username'] ?? 'User';

    // Build a readable system list for the email detail row
    $systemParts = [];
    foreach ($systems as $sys) {
        $sysId      = trim($sys['id'] ?? '');
        $rawSysRole = $sys['role'] ?? '';
        $sysRole    = is_array($rawSysRole) ? implode(', ', $rawSysRole) : trim($rawSysRole);
        $systemParts[] = $sysId . ($sysRole ? " ($sysRole)" : '');
    }
    $systemList = implode("\n", $systemParts);
    $claimUrl   = itAccessAppUrl();

    // 1. Confirmation to the requestor
    if ($submitterEmail) {
        [$html, $text] = itAccessEmailBody(
            "IT Access Request Submitted",
            [
                "Dear $submitterName,",
                "Your IT access request has been successfully submitted. It has been routed to the ICT team for review, followed by the Director for final sign-off. You will receive further updates as the request progresses.",
            ],
            [
                'Reference'  => $refNumber,
                'Employee'   => $empName,
                'Department' => $empDept,
                'Start date' => $startDate,
                'Systems'    => $systemList,
            ]
        );
        itAccessSendMail(
            [['email' => $submitterEmail, 'name' => $submitterName]],
            "IT Access Request Submitted - $refNumber",
            $html, $text
        );
    }

    // 2. Notify whoever the request is actually waiting on. When a supervisor
    //    was resolved it sits with them first, so telling ICT now would be
    //    premature — they are notified once the supervisor approves.
    $detailRows = [
        'Reference'    => $refNumber,
        'Employee'     => $empName,
        'Department'   => $empDept,
        'Start date'   => $startDate,
        'Submitted by' => $submitterName,
        'Systems'      => $systemList,
    ];

    if ($supervisorId !== null) {
        $supervisor = itAccessUserById($conn, $supervisorId);
        if ($supervisor) {
            [$html, $text] = itAccessEmailBody(
                "IT Access Request Awaiting Your Approval",
                [
                    "Dear {$supervisor['name']},",
                    "A member of your team has requested IT access. It needs your approval before the ICT team can action it.",
                ],
                $detailRows,
                ['text' => 'Review request', 'url' => $claimUrl]
            );
            itAccessSendMail(
                [$supervisor],
                "IT Access Request Awaiting Your Approval - $refNumber",
                $html, $text
            );
        }
    } else {
        // No supervisor on file — the request went straight to the ICT queue.
        [$html, $text] = itAccessEmailBody(
            "New IT Access Request",
            ["A new IT access request has been submitted and is awaiting action."],
            $detailRows,
            ['text' => 'Review & claim request', 'url' => $claimUrl]
        );
        itAccessSendMail(
            itAccessOfficers($conn),
            "New IT Access Request - $refNumber",
            $html, $text
        );
    }

    echo json_encode(['ok' => true, 'id' => $requestId, 'ref' => $refNumber]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
