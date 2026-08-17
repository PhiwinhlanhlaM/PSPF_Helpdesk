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
$actionedSystems = $body['actioned_systems'] ?? null; // array of system IDs the officer GRANTS
// Per-system rejection: [{ id: 'banking', reason: '...' }, ...]. An officer may
// grant some of their claimed systems and reject others in the same action; the
// request then pauses at 'awaiting-requester' until the requester responds to
// each rejected system. Optional — an empty/absent list means "grant only".
$rejectedSystems = $body['rejected_systems'] ?? null;

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
    'officer-1'  => ['new', 'claimed'],
    'director'   => ['awaiting-director'],
];
if (!isset($validTransitions[$stepRole]) || !in_array($currentStatus, $validTransitions[$stepRole])) {
    http_response_code(409);
    echo json_encode(['error' => "Action '{$stepRole}' is not valid for current status '{$currentStatus}'"]);
    exit;
}

$approverId  = (int)$_SESSION['user']['id'];

// An officer decides ONLY on systems they have claimed. The action carries the
// systems they grant (actioned_systems) and, optionally, ones they reject
// (rejected_systems, each with a reason). Both are intersected with what the
// officer actually owns, so a client can never action or reject someone else's
// claim. $rejectMap ends as [system_id => reason] for the officer's rejections.
$rejectMap = [];
if ($stepRole === 'officer-1' && $action === 'approved') {
    $ownStmt = $conn->prepare(
        "SELECT system_id FROM it_request_systems
         WHERE request_id = ? AND claimed_by = ? AND status = 'claimed'"
    );
    $ownStmt->bind_param("ii", $requestDbId, $approverId);
    $ownStmt->execute();
    $ownRows = $ownStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ownStmt->close();
    $ownedIds = array_map('strval', array_column($ownRows, 'system_id'));

    // Granted: intersect requested grants with owned claims.
    $actionedSystems = is_array($actionedSystems)
        ? array_values(array_intersect(array_map('strval', $actionedSystems), $ownedIds))
        : [];

    // Rejected: keep only owned systems, require a reason (>= 3 chars) each.
    if (is_array($rejectedSystems)) {
        foreach ($rejectedSystems as $rj) {
            $rid = isset($rj['id']) ? (string)$rj['id'] : '';
            $rr  = trim($rj['reason'] ?? '');
            if ($rid === '' || !in_array($rid, $ownedIds, true)) continue;
            if (strlen($rr) < 3) {
                http_response_code(422);
                echo json_encode(['error' => "A reason (min 3 chars) is required to reject system '{$rid}'"]);
                exit;
            }
            $rejectMap[$rid] = $rr;
        }
    }

    // A system can't be both granted and rejected in one action — reject wins is
    // ambiguous, so refuse it rather than guess.
    $overlap = array_intersect($actionedSystems, array_keys($rejectMap));
    if ($overlap) {
        http_response_code(422);
        echo json_encode(['error' => 'A system cannot be both granted and rejected: ' . implode(', ', $overlap)]);
        exit;
    }

    // The officer must decide on at least one of their claimed systems.
    if (count($actionedSystems) === 0 && count($rejectMap) === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'You have no claimed systems to grant or reject on this request']);
        exit;
    }
}

// Compute new status
$newStatus     = 'rejected';
$claimedBy     = null;
$provisionedAt = null;
$allActioned   = false;

if ($action === 'approved') {
    if ($stepRole === 'officer-1') {
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

    // Officer decision: mark granted systems 'actioned' and rejected systems
    // 'rejected' (with reason), then recompute the request status.
    if ($stepRole === 'officer-1' && $action === 'approved') {
        // Grant. Clearing the reject_* fields matters for a system that was
        // previously rejected, then appealed and re-granted — otherwise it would
        // carry a stale "declined" reason onto the authorization PDF.
        if ($actionedSystems) {
            $markStmt = $conn->prepare(
                "UPDATE it_request_systems
                 SET status = 'actioned', actioned_by = ?, actioned_at = UTC_TIMESTAMP(),
                     reject_reason = NULL, rejected_by = NULL, rejected_at = NULL
                 WHERE request_id = ? AND system_id = ? AND claimed_by = ? AND status = 'claimed'"
            );
            foreach ($actionedSystems as $sysId) {
                $markStmt->bind_param("iisi", $approverId, $requestDbId, $sysId, $approverId);
                $markStmt->execute();
            }
            $markStmt->close();
        }

        // Reject (per system, with reason). One-appeal rule: a system that was
        // already appealed once (appeal_count >= 1) and is rejected AGAIN is
        // final — it goes straight to 'dropped' rather than back to the
        // requester. A first-time rejection goes to 'rejected' (awaiting the
        // requester's accept/appeal). The CASE decides per row.
        if ($rejectMap) {
            $rejStmt = $conn->prepare(
                "UPDATE it_request_systems
                 SET status = IF(appeal_count >= 1, 'dropped', 'rejected'),
                     reject_reason = ?, rejected_by = ?, rejected_at = UTC_TIMESTAMP()
                 WHERE request_id = ? AND system_id = ? AND claimed_by = ? AND status = 'claimed'"
            );
            foreach ($rejectMap as $sysId => $rr) {
                $sysIdStr = (string)$sysId;
                $rejStmt->bind_param("siisi", $rr, $approverId, $requestDbId, $sysIdStr, $approverId);
                $rejStmt->execute();
            }
            $rejStmt->close();
        }

        // Recompute the request status from the systems' collective state:
        //   * any system still 'rejected' (awaiting the requester) -> 'awaiting-requester'
        //   * else any system not yet terminal (pending/claimed) -> 'claimed' (more officer work)
        //   * else every system is terminal (actioned or dropped) -> 'awaiting-director'
        $stStmt = $conn->prepare(
            "SELECT
                SUM(status = 'rejected')                          AS rejected_open,
                SUM(status IN ('pending','claimed'))              AS still_open
             FROM it_request_systems WHERE request_id = ?"
        );
        $stStmt->bind_param("i", $requestDbId);
        $stStmt->execute();
        $st = $stStmt->get_result()->fetch_assoc();
        $stStmt->close();

        if ((int)$st['rejected_open'] > 0) {
            $newStatus = 'awaiting-requester';
        } elseif ((int)$st['still_open'] > 0) {
            $newStatus = 'claimed';
        } else {
            $newStatus = 'awaiting-director';
        }
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

    // Some systems were rejected by the officer — the request now waits on the
    // requester to accept or appeal each. Tell them what was denied and why.
    if ($stepRole === 'officer-1' && $action === 'approved' && $rejectMap) {
        $rqInfoStmt = $conn->prepare(
            "SELECT ref_number, employee_name, department, submitted_by
             FROM it_access_requests WHERE id = ?"
        );
        $rqInfoStmt->bind_param("i", $requestDbId);
        $rqInfoStmt->execute();
        $rqInfo = $rqInfoStmt->get_result()->fetch_assoc();
        $rqInfoStmt->close();

        if ($rqInfo) {
            $requestor = itAccessUserById($conn, (int)$rqInfo['submitted_by']);
            if ($requestor) {
                // Build a readable "system: reason" block for the denied systems.
                $deniedLines = [];
                foreach ($rejectMap as $sysId => $rr) {
                    $deniedLines[] = "{$sysId} — {$rr}";
                }
                $deniedList = implode("\n", $deniedLines);

                [$html, $text] = itAccessEmailBody(
                    "IT Access Request — Action Needed",
                    [
                        "Dear {$requestor['name']},",
                        "The ICT team has reviewed your request. Some of the requested access was granted, but the following was declined. Your request will not proceed until you respond to each declined item — you can accept the decision, or appeal it once with further justification.",
                    ],
                    [
                        'Reference'       => $rqInfo['ref_number'],
                        'Employee'        => $rqInfo['employee_name'],
                        'Department'      => $rqInfo['department'],
                        'Declined access' => $deniedList,
                    ],
                    ['text' => 'Respond to the declined items', 'url' => itAccessAppUrl()]
                );
                itAccessSendMail(
                    [$requestor],
                    "IT Access Request — Action Needed - {$rqInfo['ref_number']}",
                    $html, $text
                );
            }
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
