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

$requestDbId     = (int)($body['request_db_id'] ?? 0);
$action          = $body['action'] ?? '';
$stepRole        = $body['step_role'] ?? '';
$signature       = $body['signature'] ?? null;
$reason          = trim($body['reason'] ?? '');
$actionedSystems = $body['actioned_systems'] ?? null; // array of system IDs actioned by officer

// Validate inputs
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
if (in_array($stepRole, ['officer-1', 'officer-2']) && !hasRole('it_officer')) {
    http_response_code(403);
    echo json_encode(['error' => 'it_officer role required']);
    exit;
}
if ($stepRole === 'director' && !hasRole('it_director')) {
    http_response_code(403);
    echo json_encode(['error' => 'it_director role required']);
    exit;
}
// The supervisor step is restricted to the person this request was actually
// routed to — holding the role is not enough, or any supervisor could approve
// anyone's request. The division delegate (absence cover) and superadmins
// (documented override for a stuck request) may also act.
if ($stepRole === 'supervisor') {
    if (!hasRole('supervisor') && !hasRole('superadmin')) {
        http_response_code(403);
        echo json_encode(['error' => 'supervisor role required']);
        exit;
    }
    if (!itaCanActionSupervisorStep($conn, (int)$_SESSION['user']['id'], $requestDbId)) {
        http_response_code(403);
        echo json_encode(['error' => 'This request is not awaiting your approval']);
        exit;
    }
}

// Fetch current request status
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

// Validate that the action is legal for the current status.
// Officers act on a request that is new/claimed; the director acts once it is
// fully actioned (awaiting-director).
$validTransitions = [
    'supervisor' => ['awaiting-supervisor'],
    'officer-1'  => ['new', 'claimed'],
    'director'   => ['awaiting-director'],
];
if (!isset($validTransitions[$stepRole]) || !in_array($currentStatus, $validTransitions[$stepRole])) {
    http_response_code(409);
    echo json_encode(['error' => "Action '{$stepRole}' is not valid for current status '{$currentStatus}'"]);
    exit;
}

$approverId  = (int)$_SESSION['user']['id'];

// An officer may only action systems they have claimed; reject ones they don't own.
if ($stepRole === 'officer-1' && $action === 'approved') {
    if (!is_array($actionedSystems) || count($actionedSystems) === 0) {
        http_response_code(422);
        echo json_encode(['error' => 'No systems specified to action']);
        exit;
    }
    $ownStmt = $conn->prepare(
        "SELECT system_id FROM it_request_systems
         WHERE request_id = ? AND claimed_by = ? AND status = 'claimed'"
    );
    $ownStmt->bind_param("ii", $requestDbId, $approverId);
    $ownStmt->execute();
    $ownRows = $ownStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ownStmt->close();
    $ownedIds = array_column($ownRows, 'system_id');

    $actionedSystems = array_values(array_intersect(
        array_map('strval', $actionedSystems),
        array_map('strval', $ownedIds)
    ));
    if (count($actionedSystems) === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'You have no claimed systems left to action on this request']);
        exit;
    }
}

// Compute new status
$newStatus     = 'rejected';
$claimedBy     = null;
$provisionedAt = null;
$allActioned   = false;

if ($action === 'approved') {
    if ($stepRole === 'supervisor') {
        // Supervisor approval releases the request into the ICT queue, which is
        // exactly what 'new' has always meant to the officer dashboard.
        $newStatus = 'new';
    } elseif ($stepRole === 'officer-1') {
        $claimedBy = $approverId;
        // Status is resolved after we mark systems actioned, inside the transaction.
        $newStatus = $currentStatus; // tentative; may become 'awaiting-director'
    } elseif ($stepRole === 'director') {
        $newStatus = 'provisioned';
        $provisionedAt = gmdate('Y-m-d H:i:s'); // UTC, consistent with acted_at
    }
}

// Prepare signature data
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
    // Insert approval record. acted_at is set to UTC explicitly (rather than the
    // column's local-time default) so all approval steps share one timezone and
    // the 'Z' suffix added at serialization time is truthful.
    $apStmt = $conn->prepare(
        "INSERT INTO it_request_approvals
         (request_id, step_role, approver_id, action, reason, sig_kind, sig_data, actioned_systems, acted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $reasonVal        = $reason ?: null;
    $actionedSysJson  = ($actionedSystems && is_array($actionedSystems)) ? json_encode($actionedSystems) : null;
    $apStmt->bind_param("isssssss", $requestDbId, $stepRole, $approverId, $action, $reasonVal, $sigKind, $sigData, $actionedSysJson);
    $apStmt->execute();
    $apStmt->close();

    // Officer actioning: mark this officer's claimed systems as actioned, then
    // advance to the director only once EVERY system on the request is actioned.
    if ($stepRole === 'officer-1' && $action === 'approved') {
        $markStmt = $conn->prepare(
            "UPDATE it_request_systems
             SET status = 'actioned', actioned_by = ?, actioned_at = UTC_TIMESTAMP()
             WHERE request_id = ? AND system_id = ? AND claimed_by = ? AND status = 'claimed'"
        );
        foreach ($actionedSystems as $sysId) {
            $markStmt->bind_param("iisi", $approverId, $requestDbId, $sysId, $approverId);
            $markStmt->execute();
        }
        $markStmt->close();

        // Are any systems still outstanding (not yet actioned)?
        $pendStmt = $conn->prepare(
            "SELECT COUNT(*) AS remaining FROM it_request_systems
             WHERE request_id = ? AND status <> 'actioned'"
        );
        $pendStmt->bind_param("i", $requestDbId);
        $pendStmt->execute();
        $remaining = (int)$pendStmt->get_result()->fetch_assoc()['remaining'];
        $pendStmt->close();

        $allActioned = ($remaining === 0);
        $newStatus   = $allActioned ? 'awaiting-director' : 'claimed';
    }

    // Update request status
    if ($stepRole === 'officer-1' && $action === 'approved') {
        $upStmt = $conn->prepare(
            "UPDATE it_access_requests SET status = ?, claimed_by = ? WHERE id = ?"
        );
        $upStmt->bind_param("sii", $newStatus, $claimedBy, $requestDbId);
        $upStmt->execute();
        $upStmt->close();
    } elseif ($provisionedAt !== null) {
        $upStmt = $conn->prepare(
            "UPDATE it_access_requests SET status = ?, provisioned_at = ? WHERE id = ?"
        );
        $upStmt->bind_param("ssi", $newStatus, $provisionedAt, $requestDbId);
        $upStmt->execute();
        $upStmt->close();
    } else {
        $upStmt = $conn->prepare(
            "UPDATE it_access_requests SET status = ? WHERE id = ?"
        );
        $upStmt->bind_param("si", $newStatus, $requestDbId);
        $upStmt->execute();
        $upStmt->close();
    }

    $conn->commit();

    // A request was rejected — tell the requester, with the reason and a link
    // back. This is the notification that was previously missing entirely: a
    // rejected requester had no way to learn of it except by logging in.
    if ($action === 'rejected') {
        $rInfoStmt = $conn->prepare(
            "SELECT ref_number, employee_name, department, submitted_by, appeal_of
             FROM it_access_requests WHERE id = ?"
        );
        $rInfoStmt->bind_param("i", $requestDbId);
        $rInfoStmt->execute();
        $rInfo = $rInfoStmt->get_result()->fetch_assoc();
        $rInfoStmt->close();

        if ($rInfo) {
            $requestor = itAccessUserById($conn, (int)$rInfo['submitted_by']);
            if ($requestor) {
                // A request that is itself an appeal cannot be appealed again —
                // so the message tells the requester whether this is the end of
                // the road or whether they may revise and appeal.
                $isAppeal = $rInfo['appeal_of'] !== null;
                $intro = [
                    "Dear {$requestor['name']},",
                    "Your IT access request has been rejected. The reason is below.",
                ];
                $intro[] = $isAppeal
                    ? "This was an appeal, so the decision is final and no further appeal is possible. If you still need this access, please raise it with the ICT team directly."
                    : "If you believe this was in error or can address the reason, you may revise and appeal the request once from your request history.";

                [$html, $text] = itAccessEmailBody(
                    "IT Access Request Rejected",
                    $intro,
                    [
                        'Reference'  => $rInfo['ref_number'],
                        'Employee'   => $rInfo['employee_name'],
                        'Department' => $rInfo['department'],
                        'Reason'     => $reason,
                    ],
                    ['text' => 'View request', 'url' => itAccessAppUrl()]
                );
                itAccessSendMail(
                    [$requestor],
                    "IT Access Request Rejected - {$rInfo['ref_number']}",
                    $html, $text
                );
            }
        }
    }

    // A supervisor has approved: the request has just entered the ICT queue, so
    // notify the officers now (submit.php deliberately held this back while the
    // request was still sitting with the supervisor).
    if ($stepRole === 'supervisor' && $action === 'approved') {
        $sInfoStmt = $conn->prepare(
            "SELECT ref_number, employee_name, department FROM it_access_requests WHERE id = ?"
        );
        $sInfoStmt->bind_param("i", $requestDbId);
        $sInfoStmt->execute();
        $sInfo = $sInfoStmt->get_result()->fetch_assoc();
        $sInfoStmt->close();

        if ($sInfo) {
            [$html, $text] = itAccessEmailBody(
                "New IT Access Request",
                ["A request has been approved by the requester's supervisor and is now awaiting ICT action."],
                [
                    'Reference'  => $sInfo['ref_number'],
                    'Employee'   => $sInfo['employee_name'],
                    'Department' => $sInfo['department'],
                ],
                ['text' => 'Review & claim request', 'url' => itAccessAppUrl()]
            );
            itAccessSendMail(
                itAccessOfficers($conn),
                "New IT Access Request - {$sInfo['ref_number']}",
                $html, $text
            );
        }
    }

    // Notify the IT Director when a request advances to their queue (non-blocking)
    if ($newStatus === 'awaiting-director') {
        $infoStmt = $conn->prepare(
            "SELECT ref_number, employee_name, department FROM it_access_requests WHERE id = ?"
        );
        $infoStmt->bind_param("i", $requestDbId);
        $infoStmt->execute();
        $info = $infoStmt->get_result()->fetch_assoc();
        $infoStmt->close();

        if ($info) {
            $appUrl = itAccessAppUrl();
            [$html, $text] = itAccessEmailBody(
                "IT Access Request Awaiting Your Action",
                ["An IT access request has been actioned by the ICT team and is awaiting your final sign-off."],
                [
                    'Reference'  => $info['ref_number'],
                    'Employee'   => $info['employee_name'],
                    'Department' => $info['department'],
                ],
                ['text' => 'Review & sign off', 'url' => $appUrl]
            );
            itAccessSendMail(
                itAccessDirectors($conn),
                "IT Access Request Awaiting Your Action - {$info['ref_number']}",
                $html, $text
            );
        }
    }

    // After director provisioning: generate the PDF, then notify the requestor
    // (access granted) and the IT officers (request provisioned).
    if ($newStatus === 'provisioned') {
        try {
            require_once __DIR__ . '/generate_pdf.php';
            generateAndUploadPdf($conn, $requestDbId);
        } catch (\Throwable $pdfEx) {
            error_log('PDF/SharePoint error: ' . $pdfEx->getMessage());
            // Non-fatal — provisioning already committed
        }

        // Fetch request details for the notifications (non-blocking)
        $pInfoStmt = $conn->prepare(
            "SELECT ref_number, employee_name, department, submitted_by FROM it_access_requests WHERE id = ?"
        );
        $pInfoStmt->bind_param("i", $requestDbId);
        $pInfoStmt->execute();
        $pInfo = $pInfoStmt->get_result()->fetch_assoc();
        $pInfoStmt->close();

        if ($pInfo) {
            $refNum   = $pInfo['ref_number'];
            $empName  = $pInfo['employee_name'];
            $empDept  = $pInfo['department'];

            // Requestor: all systems claimed and actioned — access granted.
            $requestor = itAccessUserById($conn, (int)$pInfo['submitted_by']);
            if ($requestor) {
                [$html, $text] = itAccessEmailBody(
                    "IT Access Request Provisioned",
                    [
                        "Dear {$requestor['name']},",
                        "All systems have been claimed and actioned. {$empName} now has access.",
                    ],
                    [
                        'Reference'  => $refNum,
                        'Employee'   => $empName,
                        'Department' => $empDept,
                    ]
                );
                itAccessSendMail([$requestor], "IT Access Request Provisioned - $refNum", $html, $text);
            }

            // IT officers: the director has reviewed and provisioned the request.
            [$html, $text] = itAccessEmailBody(
                "IT Access Request Provisioned",
                ["The ICT Director has reviewed and provisioned the following IT access request. All systems have been actioned."],
                [
                    'Reference'  => $refNum,
                    'Employee'   => $empName,
                    'Department' => $empDept,
                ]
            );
            itAccessSendMail(itAccessOfficers($conn), "IT Access Request Provisioned - $refNum", $html, $text);
        }
    }

    echo json_encode(['ok' => true, 'new_status' => $newStatus]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
