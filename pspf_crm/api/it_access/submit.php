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
require_once __DIR__ . '/form_fields_shared.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

// Only holders of the 'supervisor' role may submit an IT access request. The
// role is the gate for who is allowed to ask on behalf of their team; the
// request is then reviewed by ICT and signed off by the Director. This is a
// deliberate, role-based restriction - an admin is not implicitly a supervisor.
if (!hasRole('supervisor')) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a supervisor may submit an IT access request']);
    exit;
}

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

// Validate the superadmin-defined custom fields. Unknown keys are dropped;
// required fields must be answered; choices are checked against their options.
$customSubmitted = is_array($body['customValues'] ?? null) ? $body['customValues'] : [];
$activeFields    = itaFormFields($conn, false);
$customValues    = itaValidateCustomValues($customSubmitted, $activeFields, $errors);

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

// The request enters the ICT queue directly as 'new'. There is no supervisor
// approval step - the submitter already holds the supervisor role, which is the
// authority to raise the request; ICT then reviews and the Director signs off.
$conn->begin_transaction();
try {
    // Custom-field answers stored as a JSON map (NULL when none answered).
    $customJson = $customValues ? json_encode($customValues, JSON_UNESCAPED_UNICODE) : null;

    // Insert main request
    $stmt = $conn->prepare(
        "INSERT INTO it_access_requests
         (ref_number, request_type, employee_name, employee_id, department, division, job_title, start_date, justification, submitted_by, custom_values, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')"
    );
    $stmt->bind_param(
        "sssssssssis",
        $refNumber, $requestType, $empName, $empId, $empDept, $empDiv, $empTitle,
        $startDate, $justClean, $submittedBy, $customJson
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
        // Merge free-text sub-answers (subTexts, e.g. the "Other" system's name
        // and role) into subValues so they persist and render downstream. The
        // form keeps them in a separate object; here they become one JSON blob.
        $merged = [];
        if (isset($sys['subValues']) && is_array($sys['subValues'])) {
            $merged = $sys['subValues'];
        }
        if (isset($sys['subTexts']) && is_array($sys['subTexts'])) {
            foreach ($sys['subTexts'] as $k => $v) {
                $v = is_string($v) ? trim($v) : $v;
                if ($v !== '' && $v !== null) $merged[$k] = $v;
            }
        }
        $subVals  = $merged ? json_encode($merged) : null;
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

    // Build a readable system list for the email detail row. Resolve display
    // names via the shared helper so the "Other" system shows the typed name.
    require_once __DIR__ . '/catalog_shared.php';
    $emailCatalog = itaBuildCatalog($conn, true);
    $systemParts = [];
    foreach ($systems as $sys) {
        $rawSysRole = $sys['role'] ?? '';
        $sysRole    = is_array($rawSysRole) ? implode(', ', $rawSysRole) : trim($rawSysRole);
        // Merge subValues + subTexts (same as the insert) so free-text answers
        // are available for display.
        $merged = (isset($sys['subValues']) && is_array($sys['subValues'])) ? $sys['subValues'] : [];
        if (isset($sys['subTexts']) && is_array($sys['subTexts'])) {
            foreach ($sys['subTexts'] as $k => $v) {
                $v = is_string($v) ? trim($v) : $v;
                if ($v !== '' && $v !== null) $merged[$k] = $v;
            }
        }
        $disp = itaSystemDisplay(trim($sys['id'] ?? ''), $sysRole, $merged, $emailCatalog);
        $line = $disp['name'] . ($disp['role'] ? " ({$disp['role']})" : '');
        if ($disp['detail']) $line .= " - {$disp['detail']}";
        $systemParts[] = $line;
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

    // 2. Notify the ICT officers - the request enters their queue immediately.
    $detailRows = [
        'Reference'    => $refNumber,
        'Employee'     => $empName,
        'Department'   => $empDept,
        'Start date'   => $startDate,
        'Submitted by' => $submitterName,
        'Systems'      => $systemList,
    ];
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

    echo json_encode(['ok' => true, 'id' => $requestId, 'ref' => $refNumber]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
