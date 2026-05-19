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

$userId     = (int)$_SESSION['user']['id'];
$activeRole = getActiveRole();
$isOfficer  = isITOfficer();
$isDirector = hasRole('it_director');
$isSuper    = in_array($activeRole, ['admin', 'superadmin']);

if ($isSuper) {
    $whereClause = "1=1";
    $bindTypes   = "";
    $bindArgs    = [];
} elseif ($isDirector) {
    $whereClause = "r.status IN ('awaiting-director','provisioned','rejected') OR r.submitted_by = ?";
    $bindTypes   = "i";
    $bindArgs    = [$userId];
} elseif ($isOfficer) {
    $whereClause = "(r.status NOT IN ('provisioned','rejected')) OR r.claimed_by = ? OR r.submitted_by = ?";
    $bindTypes   = "ii";
    $bindArgs    = [$userId, $userId];
} else {
    $whereClause = "r.submitted_by = ?";
    $bindTypes   = "i";
    $bindArgs    = [$userId];
}

$sql = "
    SELECT
        r.id              AS request_id,
        r.ref_number,
        r.request_type,
        r.employee_name,
        r.employee_id,
        r.department,
        r.division,
        r.job_title,
        r.start_date,
        r.justification,
        r.submitted_by,
        r.submitted_at,
        r.status,
        r.claimed_by,
        r.provisioned_at,
        r.pdf_filename,
        s.id              AS sys_row_id,
        s.system_id,
        s.role            AS sys_role,
        s.sub_values,
        a.id              AS appr_row_id,
        a.step_role,
        a.approver_id,
        au.username       AS approver_username,
        a.action          AS appr_action,
        a.acted_at,
        a.reason,
        a.sig_kind,
        a.sig_data
    FROM it_access_requests r
    LEFT JOIN it_request_systems   s  ON s.request_id = r.id
    LEFT JOIN it_request_approvals a  ON a.request_id = r.id
    LEFT JOIN users                au ON au.id = a.approver_id
    WHERE {$whereClause}
    ORDER BY r.submitted_at DESC, r.id DESC, s.id ASC, a.acted_at ASC
";

$stmt = $conn->prepare($sql);
if ($bindTypes && $bindArgs) {
    $stmt->bind_param($bindTypes, ...$bindArgs);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$requests     = [];
$requestIndex = [];

foreach ($rows as $row) {
    $rid = $row['request_id'];

    if (!isset($requestIndex[$rid])) {
        $requestIndex[$rid] = count($requests);
        $requests[] = [
            'id'            => $row['ref_number'],
            'db_id'         => (int)$rid,
            'requestType'   => $row['request_type'] ?? 'new',
            'employee'      => [
                'name'       => $row['employee_name'],
                'id'         => $row['employee_id'],
                'department' => $row['department'],
                'division'   => $row['division'] ?? '',
                'title'      => $row['job_title'],
            ],
            'systems'       => [],
            'justification' => $row['justification'],
            'startDate'     => $row['start_date'],
            'submittedBy'   => (int)$row['submitted_by'],
            'submittedAt'   => str_replace(' ', 'T', $row['submitted_at']) . 'Z',
            'approvals'     => [],
            'status'        => $row['status'],
            'claimedBy'     => $row['claimed_by'] ? (int)$row['claimed_by'] : null,
            'provisionedAt' => $row['provisioned_at']
                ? str_replace(' ', 'T', $row['provisioned_at']) . 'Z'
                : null,
            'pdfFilename'   => $row['pdf_filename'] ?? null,
            '_sys_ids'      => [],
            '_appr_ids'     => [],
        ];
    }

    $idx = $requestIndex[$rid];

    if ($row['sys_row_id'] && !in_array($row['sys_row_id'], $requests[$idx]['_sys_ids'])) {
        $requests[$idx]['_sys_ids'][] = $row['sys_row_id'];
        $subRaw    = $row['sub_values'];
        $subValues = null;
        if ($subRaw !== null) {
            $decoded   = json_decode($subRaw, true);
            $subValues = ($decoded !== null) ? $decoded : $subRaw;
        }
        $requests[$idx]['systems'][] = [
            'id'        => $row['system_id'],
            'role'      => $row['sys_role'],
            'subValues' => $subValues,
        ];
    }

    if ($row['appr_row_id'] && !in_array($row['appr_row_id'], $requests[$idx]['_appr_ids'])) {
        $requests[$idx]['_appr_ids'][] = $row['appr_row_id'];
        $signature = null;
        if ($row['sig_kind'] === 'drawn' && $row['sig_data']) {
            $signature = ['kind' => 'drawn', 'strokes' => json_decode($row['sig_data'], true)];
        } elseif ($row['sig_kind'] === 'uploaded' && $row['sig_data']) {
            $signature = ['kind' => 'uploaded', 'dataUrl' => $row['sig_data']];
        }
        $appr = [
            'role'         => $row['step_role'],
            'personId'     => (int)$row['approver_id'],
            'approverName' => $row['approver_username'] ?? null,
            'at'           => str_replace(' ', 'T', $row['acted_at']) . 'Z',
            'action'       => $row['appr_action'],
        ];
        if ($signature)    $appr['signature'] = $signature;
        if ($row['reason']) $appr['reason']   = $row['reason'];
        $requests[$idx]['approvals'][] = $appr;
    }
}

foreach ($requests as &$req) {
    unset($req['_sys_ids'], $req['_appr_ids']);
}
unset($req);

echo json_encode(['requests' => array_values($requests)]);
