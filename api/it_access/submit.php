<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

// Only admins and superadmins may submit IT access requests
if (!in_array(getActiveRole(), ['admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Only admins may submit IT access requests']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// CSRF
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token mismatch']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$emp           = $body['employee']     ?? [];
$systems       = $body['systems']      ?? [];
$justification = trim($body['justification'] ?? '');
$startDate     = trim($body['startDate']     ?? '');
$approvals     = $body['approvals']    ?? [];
$requestType   = in_array($body['requestType'] ?? '', ['new', 'change']) ? $body['requestType'] : 'new';

$errors = [];
if (empty(trim($emp['name'] ?? '')))       $errors[] = 'employee.name required';
if (empty(trim($emp['department'] ?? ''))) $errors[] = 'employee.department required';
if (empty(trim($emp['title'] ?? '')))      $errors[] = 'employee.title required';
if (empty($startDate))                     $errors[] = 'startDate required';
if (empty($systems))                       $errors[] = 'At least one system required';
if (strlen($justification) < 10)           $errors[] = 'justification must be at least 10 characters';

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

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    http_response_code(422);
    echo json_encode(['error' => 'startDate must be YYYY-MM-DD']);
    exit;
}

$submittedBy = (int)$_SESSION['user']['id'];

// Generate ref number REQ-YYYY-NNNN (sequential within year)
$year    = (int)date('Y');
$seqStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM it_access_requests WHERE YEAR(submitted_at) = ?");
$seqStmt->bind_param("i", $year);
$seqStmt->execute();
$seq       = (int)$seqStmt->get_result()->fetch_assoc()['cnt'] + 1;
$seqStmt->close();
$refNumber = 'REQ-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

$empName   = htmlspecialchars(trim($emp['name']),           ENT_QUOTES, 'UTF-8');
$empId     = htmlspecialchars(trim($emp['id'] ?? ''),       ENT_QUOTES, 'UTF-8');
$empDept   = htmlspecialchars(trim($emp['department']),     ENT_QUOTES, 'UTF-8');
$empDiv    = htmlspecialchars(trim($emp['division'] ?? ''), ENT_QUOTES, 'UTF-8');
$empTitle  = htmlspecialchars(trim($emp['title']),          ENT_QUOTES, 'UTF-8');
$justClean = htmlspecialchars($justification,               ENT_QUOTES, 'UTF-8');

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO it_access_requests
         (ref_number, request_type, employee_name, employee_id, department, division, job_title, start_date, justification, submitted_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')"
    );
    $stmt->bind_param("sssssssssi",
        $refNumber, $requestType, $empName, $empId, $empDept, $empDiv,
        $empTitle, $startDate, $justClean, $submittedBy
    );
    $stmt->execute();
    $requestId = $conn->insert_id;
    $stmt->close();

    $syStmt = $conn->prepare(
        "INSERT INTO it_request_systems (request_id, system_id, role, sub_values) VALUES (?, ?, ?, ?)"
    );
    foreach ($systems as $sys) {
        $sysId   = htmlspecialchars(trim($sys['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawRole = $sys['role'] ?? '';
        $sysRole = htmlspecialchars(
            is_array($rawRole) ? implode(', ', $rawRole) : trim($rawRole),
            ENT_QUOTES, 'UTF-8'
        );
        $subVals = isset($sys['subValues']) ? json_encode($sys['subValues']) : null;
        $syStmt->bind_param("isss", $requestId, $sysId, $sysRole, $subVals);
        $syStmt->execute();
    }
    $syStmt->close();

    $sig     = $managerApproval['signature'];
    $sigKind = $sig['kind'] ?? null;
    $sigData = null;
    if ($sigKind === 'drawn' && isset($sig['strokes'])) {
        $sigData = json_encode($sig['strokes']);
    } elseif ($sigKind === 'uploaded' && isset($sig['dataUrl'])) {
        $sigData = $sig['dataUrl'];
    }
    $approvedAt = preg_replace('/\.\d+Z?$/', '', preg_replace('/T/', ' ', $managerApproval['at'] ?? date('Y-m-d H:i:s')));

    $apStmt = $conn->prepare(
        "INSERT INTO it_request_approvals
         (request_id, step_role, approver_id, action, acted_at, sig_kind, sig_data)
         VALUES (?, 'manager', ?, 'approved', ?, ?, ?)"
    );
    $apStmt->bind_param("iisss", $requestId, $submittedBy, $approvedAt, $sigKind, $sigData);
    $apStmt->execute();
    $apStmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ── Send notification emails (non-fatal) ─────────────────────
try {
    require_once '../mail_config.php';

    $actionUrl = getBaseUrl() . '/api/it_access/index.php';

    $submitterEmail = $_SESSION['user']['email']    ?? '';
    $submitterName  = $_SESSION['user']['username'] ?? 'User';

    // Build systems list rows for HTML email
    $sysRowsHtml = '';
    $sysRowsText = '';
    foreach ($systems as $sys) {
        $rawR    = $sys['role'] ?? '';
        $roleStr = is_array($rawR) ? implode(', ', $rawR) : trim($rawR);
        $sysName = strtoupper(trim($sys['id'] ?? ''));
        $sysRowsHtml .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($sysName) . '</td>'
                      . '<td style="padding:4px 8px;border-bottom:1px solid #eee;color:#3d5a7e;">' . htmlspecialchars($roleStr) . '</td></tr>';
        $sysRowsText .= "  \u{2022} $sysName" . ($roleStr ? " ($roleStr)" : '') . "\n";
    }

    // ── 1. Confirmation to the submitting admin ───────────────
    if ($submitterEmail) {
        $mail = getMailer();
        $mail->addAddress($submitterEmail, $submitterName);
        $mail->isHTML(true);
        $mail->Subject = "IT Access Request Logged \u{2013} $refNumber";
        $mail->Body = "
<div style='font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2839;max-width:600px;'>
  <div style='background:linear-gradient(135deg,#1f3450,#3d5a7e);padding:20px 24px;border-radius:6px 6px 0 0;'>
    <span style='color:#fff;font-size:18px;font-weight:700;'>IT Access Request Logged</span>
  </div>
  <div style='background:#f8f9fb;padding:20px 24px;border:1px solid #dfe3eb;border-top:none;border-radius:0 0 6px 6px;'>
    <p>Dear <strong>$submitterName</strong>,</p>
    <p>Your IT access request has been successfully logged and routed to the ICT team for review.</p>
    <table style='border-collapse:collapse;width:100%;margin:16px 0;'>
      <tr><td style='padding:4px 8px;font-weight:600;width:40%;'>Reference</td><td style='padding:4px 8px;font-family:monospace;'>$refNumber</td></tr>
      <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Employee</td><td style='padding:4px 8px;'>$empName</td></tr>
      <tr><td style='padding:4px 8px;font-weight:600;'>Department</td><td style='padding:4px 8px;'>$empDept</td></tr>
      <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Job Title</td><td style='padding:4px 8px;'>$empTitle</td></tr>
      <tr><td style='padding:4px 8px;font-weight:600;'>Access Start Date</td><td style='padding:4px 8px;'>$startDate</td></tr>
    </table>
    <p style='font-weight:600;margin-bottom:6px;'>Systems Requested:</p>
    <table style='border-collapse:collapse;width:100%;margin-bottom:16px;'>
      <thead><tr style='background:#eef2f7;'><th style='padding:6px 8px;text-align:left;font-size:12px;'>System</th><th style='padding:6px 8px;text-align:left;font-size:12px;'>Role / Level</th></tr></thead>
      <tbody>$sysRowsHtml</tbody>
    </table>
    <p style='color:#555;font-size:13px;'>Your request will be reviewed by the ICT team, then forwarded to the Director for final sign-off. You will be notified when it is provisioned.</p>
    <p style='margin-top:20px;color:#888;font-size:12px;'>Regards,<br><strong>PSPF CRM System</strong></p>
  </div>
</div>";
        $mail->AltBody =
            "Dear $submitterName,\n\n" .
            "Your IT access request ($refNumber) has been logged.\n\n" .
            "Employee: $empName | Department: $empDept | Start: $startDate\n\n" .
            "Systems requested:\n$sysRowsText\n" .
            "Regards,\nPSPF CRM System";
        $mail->send();
    }

    // ── 2. Notification to all active ICT-dept agents (isITOfficer equivalent) ─
    $officerStmt = $conn->prepare(
        "SELECT u.Email AS email, u.Username AS username
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         WHERE r.name = 'agent' AND u.department = 'ICT' AND u.is_active = 1 AND u.Email != ''"
    );
    $officerStmt->execute();
    $officers = $officerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $officerStmt->close();

    if (!empty($officers)) {
        $mail2 = getMailer();
        foreach ($officers as $off) {
            $mail2->addAddress($off['email'], $off['username']);
        }
        $mail2->isHTML(true);
        $mail2->Subject = "New IT Access Request \u{2013} $refNumber";
        $mail2->Body = "
<div style='font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2839;max-width:600px;'>
  <div style='background:linear-gradient(135deg,#1f3450,#3d5a7e);padding:20px 24px;border-radius:6px 6px 0 0;'>
    <span style='color:#fff;font-size:18px;font-weight:700;'>New IT Access Request</span>
  </div>
  <div style='background:#f8f9fb;padding:20px 24px;border:1px solid #dfe3eb;border-top:none;border-radius:0 0 6px 6px;'>
    <p>A new IT access request has been submitted and is awaiting your action.</p>
    <table style='border-collapse:collapse;width:100%;margin:16px 0;'>
      <tr><td style='padding:4px 8px;font-weight:600;width:40%;'>Reference</td><td style='padding:4px 8px;font-family:monospace;'>$refNumber</td></tr>
      <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Employee</td><td style='padding:4px 8px;'>$empName</td></tr>
      <tr><td style='padding:4px 8px;font-weight:600;'>Department</td><td style='padding:4px 8px;'>$empDept</td></tr>
      <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Job Title</td><td style='padding:4px 8px;'>$empTitle</td></tr>
      <tr><td style='padding:4px 8px;font-weight:600;'>Start Date</td><td style='padding:4px 8px;'>$startDate</td></tr>
      <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Submitted By</td><td style='padding:4px 8px;'>$submitterName</td></tr>
    </table>
    <p style='font-weight:600;margin-bottom:6px;'>Systems to Action:</p>
    <table style='border-collapse:collapse;width:100%;margin-bottom:20px;'>
      <thead><tr style='background:#eef2f7;'><th style='padding:6px 8px;text-align:left;font-size:12px;'>System</th><th style='padding:6px 8px;text-align:left;font-size:12px;'>Role / Level</th></tr></thead>
      <tbody>$sysRowsHtml</tbody>
    </table>
    <p style='margin-bottom:16px;'>Please log in and grant the requested access on each platform, then mark the request as actioned.</p>
    <p style='text-align:center;'>
      <a href='$actionUrl' style='display:inline-block;background:#1f3450;color:#fff;font-weight:700;font-size:15px;padding:12px 28px;border-radius:5px;text-decoration:none;letter-spacing:0.03em;'>ACTION</a>
    </p>
    <p style='margin-top:20px;color:#888;font-size:12px;'>Regards,<br><strong>PSPF CRM System</strong></p>
  </div>
</div>";
        $mail2->AltBody =
            "New IT Access Request: $refNumber\n\n" .
            "Employee: $empName | Department: $empDept | Start: $startDate\n" .
            "Submitted by: $submitterName\n\n" .
            "Systems to action:\n$sysRowsText\n" .
            "ACTION: $actionUrl\n\n" .
            "Regards,\nPSPF CRM System";
        $mail2->send();
    }
} catch (Exception $mailEx) {
    error_log('IT access email error: ' . $mailEx->getMessage());
}

echo json_encode(['ok' => true, 'id' => $requestId, 'ref' => $refNumber]);
