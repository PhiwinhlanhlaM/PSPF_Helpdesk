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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

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

$requestDbId     = (int)($body['request_db_id']    ?? 0);
$action          = $body['action']                 ?? '';
$stepRole        = $body['step_role']              ?? '';
$signature       = $body['signature']              ?? null;
$reason          = trim($body['reason']            ?? '');
$actionedSystems = $body['actioned_systems']       ?? null;

if (!$requestDbId || !in_array($action, ['approved', 'rejected']) || !$stepRole) {
    http_response_code(422);
    echo json_encode(['error' => 'request_db_id, action, and step_role are required']);
    exit;
}
if ($action === 'approved' && !$signature) {
    http_response_code(422);
    echo json_encode(['error' => 'signature required for approval']);
    exit;
}
if ($action === 'rejected' && strlen($reason) < 3) {
    http_response_code(422);
    echo json_encode(['error' => 'reason required for rejection (min 3 chars)']);
    exit;
}

// Role guard
if (in_array($stepRole, ['officer-1', 'officer-2']) && !isITOfficer()) {
    http_response_code(403);
    echo json_encode(['error' => 'ICT officer access required']);
    exit;
}
if ($stepRole === 'director' && !hasRole('it_director')) {
    http_response_code(403);
    echo json_encode(['error' => 'it_director role required']);
    exit;
}

$rStmt = $conn->prepare("SELECT status, claimed_by FROM it_access_requests WHERE id = ?");
$rStmt->bind_param("i", $requestDbId);
$rStmt->execute();
$reqRow = $rStmt->get_result()->fetch_assoc();
$rStmt->close();

if (!$reqRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

$currentStatus = $reqRow['status'];
$validTransitions = [
    'officer-1' => ['new', 'claimed'],
    'director'  => ['awaiting-director'],
];
if (!isset($validTransitions[$stepRole]) || !in_array($currentStatus, $validTransitions[$stepRole])) {
    http_response_code(409);
    echo json_encode(['error' => "Action '{$stepRole}' is not valid for status '{$currentStatus}'"]);
    exit;
}

$approverId    = (int)$_SESSION['user']['id'];
$newStatus     = 'rejected';
$claimedBy     = null;
$provisionedAt = null;

if ($action === 'approved') {
    if ($stepRole === 'officer-1') {
        $newStatus = 'awaiting-director';
        $claimedBy = $approverId;
    } elseif ($stepRole === 'director') {
        $newStatus     = 'provisioned';
        $provisionedAt = date('Y-m-d H:i:s');
    }
}

$sigKind = null;
$sigData = null;
if ($action === 'approved' && $signature) {
    $sigKind = $signature['kind'] ?? null;
    if ($sigKind === 'drawn' && isset($signature['strokes'])) {
        $sigData = json_encode($signature['strokes']);
    } elseif ($sigKind === 'uploaded' && isset($signature['dataUrl'])) {
        $sigData = $signature['dataUrl'];
    }
}

$conn->begin_transaction();
try {
    $reasonVal       = $reason ?: null;
    $actionedSysJson = ($actionedSystems && is_array($actionedSystems)) ? json_encode($actionedSystems) : null;

    $apStmt = $conn->prepare(
        "INSERT INTO it_request_approvals
         (request_id, step_role, approver_id, action, reason, sig_kind, sig_data, actioned_systems)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $apStmt->bind_param("isssssss",
        $requestDbId, $stepRole, $approverId, $action,
        $reasonVal, $sigKind, $sigData, $actionedSysJson
    );
    $apStmt->execute();
    $apStmt->close();

    if ($claimedBy !== null && $provisionedAt === null) {
        $upStmt = $conn->prepare("UPDATE it_access_requests SET status = ?, claimed_by = ? WHERE id = ?");
        $upStmt->bind_param("sii", $newStatus, $claimedBy, $requestDbId);
    } elseif ($provisionedAt !== null) {
        $upStmt = $conn->prepare("UPDATE it_access_requests SET status = ?, provisioned_at = ? WHERE id = ?");
        $upStmt->bind_param("ssi", $newStatus, $provisionedAt, $requestDbId);
    } else {
        $upStmt = $conn->prepare("UPDATE it_access_requests SET status = ? WHERE id = ?");
        $upStmt->bind_param("si", $newStatus, $requestDbId);
    }
    $upStmt->execute();
    $upStmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

if ($newStatus === 'provisioned') {
    try {
        require_once __DIR__ . '/generate_pdf.php';
        generateAndUploadPdf($conn, $requestDbId);
    } catch (\Throwable $pdfEx) {
        error_log('PDF/SharePoint error: ' . $pdfEx->getMessage());
    }
}

// ── Email notifications (non-fatal) ─────────────────────────────────────────
try {
    require_once __DIR__ . '/../mail_config.php';

    // Fetch request details + submitter email
    $notifStmt = $conn->prepare("
        SELECT r.ref_number, r.employee_name, r.department, r.start_date,
               u.Email AS submitter_email, u.Username AS submitter_name
        FROM it_access_requests r
        JOIN users u ON u.id = r.submitted_by
        WHERE r.id = ?
    ");
    $notifStmt->bind_param("i", $requestDbId);
    $notifStmt->execute();
    $notifRow = $notifStmt->get_result()->fetch_assoc();
    $notifStmt->close();

    if ($notifRow) {
        $ref           = $notifRow['ref_number'];
        $empName       = $notifRow['employee_name'];
        $empDept       = $notifRow['department'];
        $startDate     = $notifRow['start_date'];
        $submEmail     = $notifRow['submitter_email'];
        $submName      = $notifRow['submitter_name'];
        $actionUrl     = getBaseUrl() . '/api/it_access/index.php';
        $approverName  = $_SESSION['user']['username'] ?? 'IT Staff';

        $headerHtml = fn(string $title) => "
<div style='background:linear-gradient(135deg,#1f3450,#3d5a7e);padding:20px 24px;border-radius:6px 6px 0 0;'>
  <span style='color:#fff;font-size:18px;font-weight:700;'>$title</span>
</div>";
        $metaHtml = "
<table style='border-collapse:collapse;width:100%;margin:16px 0;'>
  <tr><td style='padding:4px 8px;font-weight:600;width:40%;'>Reference</td><td style='padding:4px 8px;font-family:monospace;'>$ref</td></tr>
  <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Employee</td><td style='padding:4px 8px;'>$empName</td></tr>
  <tr><td style='padding:4px 8px;font-weight:600;'>Department</td><td style='padding:4px 8px;'>$empDept</td></tr>
  <tr style='background:#eef2f7;'><td style='padding:4px 8px;font-weight:600;'>Access Start Date</td><td style='padding:4px 8px;'>$startDate</td></tr>
</table>";

        $wrap = fn(string $hdr, string $body) =>
            "<div style='font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2839;max-width:600px;'>$hdr"
          . "<div style='background:#f8f9fb;padding:20px 24px;border:1px solid #dfe3eb;border-top:none;border-radius:0 0 6px 6px;'>$body"
          . "<p style='margin-top:20px;color:#888;font-size:12px;'>Regards,<br><strong>PSPF CRM System</strong></p></div></div>";

        if ($action === 'approved' && $newStatus === 'awaiting-director' && $submEmail) {
            // Officer signed — tell admin it's now with the director
            $mail = getMailer();
            $mail->addAddress($submEmail, $submName);
            $mail->isHTML(true);
            $mail->Subject = "IT Access Provisioned — Awaiting Director Sign-Off · $ref";
            $body = "<p>Dear <strong>$submName</strong>,</p>"
                  . "<p>The IT team has provisioned the access and signed off on the request. It is now awaiting the Director's authorisation signature.</p>"
                  . $metaHtml
                  . "<p style='color:#555;font-size:13px;'>Actioned by: <strong>$approverName</strong>. You will receive a final confirmation once the Director has signed.</p>";
            $mail->Body    = $wrap(($headerHtml)("Access Provisioned · Awaiting Director"), $body);
            $mail->AltBody = "Dear $submName,\n\nThe IT team ($approverName) has provisioned the access for $ref ($empName). It is now awaiting the Director's sign-off.\n\nRegards,\nPSPF CRM System";
            $mail->send();

            // Also notify directors who have it_director role
            $dirStmt = $conn->prepare("
                SELECT u.Email AS email, u.Username AS username
                FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE r.name = 'it_director' AND u.is_active = 1 AND u.Email != ''
            ");
            $dirStmt->execute();
            $directors = $dirStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $dirStmt->close();

            if (!empty($directors)) {
                $mail3 = getMailer();
                foreach ($directors as $dir) {
                    $mail3->addAddress($dir['email'], $dir['username']);
                }
                $mail3->isHTML(true);
                $mail3->Subject = "IT Access Request Awaiting Your Sign-Off · $ref";
                $reviewUrl = getBaseUrl() . '/api/it_access/index.php?request=' . urlencode($ref);
                $body3 = "<p>An IT access request has been provisioned and is ready for your sign-off.</p>"
                       . $metaHtml
                       . "<p style='color:#555;font-size:13px;'>The IT Officer (<strong>$approverName</strong>) has confirmed that access has been granted. Your signature records the authorisation.</p>"
                       . "<p style='text-align:center;margin-top:20px;'>"
                       . "<a href='$reviewUrl' style='display:inline-block;background:#1f3450;color:#fff;font-weight:700;font-size:15px;padding:12px 28px;border-radius:5px;text-decoration:none;'>REVIEW &amp; SIGN</a>"
                       . "</p>";
                $mail3->Body    = $wrap(($headerHtml)("Awaiting Your Sign-Off"), $body3);
                $mail3->AltBody = "IT Access Request $ref ($empName) has been provisioned by $approverName and is awaiting your sign-off.\n\nREVIEW: $reviewUrl\n\nRegards,\nPSPF CRM System";
                $mail3->send();
            }

        } elseif ($action === 'approved' && $newStatus === 'provisioned' && $submEmail) {
            // Director signed — tell admin access is fully authorised
            $mail = getMailer();
            $mail->addAddress($submEmail, $submName);
            $mail->isHTML(true);
            $mail->Subject = "IT Access Fully Authorised · $ref";
            $body = "<p>Dear <strong>$submName</strong>,</p>"
                  . "<p>The Director has signed off on the IT access request. Access is now fully authorised and the PDF record has been generated.</p>"
                  . $metaHtml
                  . "<p style='text-align:center;margin-top:20px;'>"
                  . "<a href='$actionUrl' style='display:inline-block;background:#0f7a4a;color:#fff;font-weight:700;font-size:15px;padding:12px 28px;border-radius:5px;text-decoration:none;'>VIEW REQUEST</a>"
                  . "</p>";
            $mail->Body    = $wrap(($headerHtml)("Access Fully Authorised ✓"), $body);
            $mail->AltBody = "Dear $submName,\n\nThe Director has authorised the IT access request $ref for $empName. Access is active from $startDate.\n\nRegards,\nPSPF CRM System";
            $mail->send();

        } elseif ($action === 'rejected' && $submEmail) {
            // Any rejection — notify admin with reason
            $mail = getMailer();
            $mail->addAddress($submEmail, $submName);
            $mail->isHTML(true);
            $rejectorRole = ($stepRole === 'director') ? 'the Director' : 'the IT Officer';
            $mail->Subject = "IT Access Request Flagged · $ref";
            $reasonHtml = $reason
                ? "<div style='background:#fde8e8;border-left:3px solid #e53e3e;padding:10px 14px;border-radius:0 4px 4px 0;margin:12px 0;font-size:13px;color:#742a2a;'>" . htmlspecialchars($reason) . "</div>"
                : '';
            $body = "<p>Dear <strong>$submName</strong>,</p>"
                  . "<p>Your IT access request has been flagged by $rejectorRole. Please review the reason below and resubmit if appropriate.</p>"
                  . $metaHtml . $reasonHtml
                  . "<p style='text-align:center;margin-top:20px;'>"
                  . "<a href='$actionUrl' style='display:inline-block;background:#1f3450;color:#fff;font-weight:700;font-size:15px;padding:12px 28px;border-radius:5px;text-decoration:none;'>VIEW REQUEST</a>"
                  . "</p>";
            $mail->Body    = $wrap(($headerHtml)("IT Access Request Flagged"), $body);
            $mail->AltBody = "Dear $submName,\n\nYour IT access request $ref has been flagged by $rejectorRole.\n\nReason: $reason\n\nRegards,\nPSPF CRM System";
            $mail->send();
        }
    }
} catch (\Throwable $mailEx) {
    error_log('IT access approval email error: ' . $mailEx->getMessage());
}

echo json_encode(['ok' => true, 'new_status' => $newStatus]);
