<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
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

// Determine which requests this user can see
$isOfficer    = hasRole('it_officer');
$isDirector   = hasRole('it_director');
$isSupervisor = hasRole('supervisor');
$isSuper      = in_array($activeRole, ['admin', 'superadmin']);

// Build WHERE clause based on role
if ($isSuper) {
    $whereClause = "1=1";
    $bindTypes   = "";
    $bindArgs    = [];
} elseif ($isSupervisor && !$isOfficer && !$isDirector) {
    // A supervisor sees requests routed to them (including ones they have
    // already actioned, so their history is visible), anything where they are
    // their division's delegate standing in, and their own requests.
    $whereClause = "r.supervisor_id = ?
                    OR r.submitted_by = ?
                    OR EXISTS (
                         SELECT 1 FROM users ru
                         JOIN divisions rd ON rd.id = ru.division_id
                         WHERE ru.id = r.submitted_by AND rd.delegate_id = ?
                       )";
    $bindTypes   = "iii";
    $bindArgs    = [$userId, $userId, $userId];
} elseif ($isDirector) {
    // Director sees director-queue + terminal + own submitted requests
    $whereClause = "r.status IN ('awaiting-director','provisioned','rejected') OR r.submitted_by = ?";
    $bindTypes   = "i";
    $bindArgs    = [$userId];
} elseif ($isOfficer) {
    // Officer sees all live requests + anything they claimed + their own.
    // 'awaiting-supervisor' is excluded: those have not yet been approved by
    // the requester's supervisor, so they are not in the ICT queue and showing
    // them would invite officers to action work that may still be rejected.
    $whereClause = "(r.status NOT IN ('awaiting-supervisor','provisioned','rejected'))
                    OR r.claimed_by = ? OR r.submitted_by = ?";
    $bindTypes   = "ii";
    $bindArgs    = [$userId, $userId];
} else {
    // regular user / agent — own requests only
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
        r.appeal_of,
        ao.ref_number AS appeal_of_ref,
        (SELECT COUNT(*) FROM it_access_requests ap WHERE ap.appeal_of = r.id) AS appeal_count,
        r.supervisor_id,
        sv.full_name  AS supervisor_full_name,
        sv.username   AS supervisor_username,
        sb.email      AS submitter_email,
        sb.username   AS submitter_username,
        sb.full_name  AS submitter_full_name,
        r.submitted_at,
        r.status,
        r.claimed_by,
        r.provisioned_at,
        r.pdf_filename,
        s.id              AS sys_row_id,
        s.system_id,
        s.role            AS sys_role,
        s.sub_values,
        s.status          AS sys_status,
        s.claimed_by      AS sys_claimed_by,
        s.actioned_by     AS sys_actioned_by,
        a.id              AS appr_row_id,
        a.step_role,
        a.approver_id,
        a.action          AS appr_action,
        a.acted_at,
        a.reason,
        a.sig_kind,
        a.sig_data,
        ap.full_name      AS approver_full_name,
        ap.username       AS approver_username,
        ap.email          AS approver_email
    FROM it_access_requests r
    LEFT JOIN users                sb ON sb.id = r.submitted_by
    LEFT JOIN users                sv ON sv.id = r.supervisor_id
    LEFT JOIN it_access_requests   ao ON ao.id = r.appeal_of
    LEFT JOIN it_request_systems   s ON s.request_id = r.id
    LEFT JOIN it_request_approvals a ON a.request_id = r.id
    LEFT JOIN users                ap ON ap.id = a.approver_id
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

/**
 * Serialize a stored IT Access datetime as an ISO-8601 UTC string (trailing 'Z').
 *
 * As of the timezone fix, all IT Access timestamps are WRITTEN in UTC
 * (UTC_TIMESTAMP()/gmdate), so here we only need to format them and label them
 * 'Z' — no offset shift. This keeps the time shown right after signing (the
 * browser's new Date().toISOString()) in agreement with the reloaded timeline.
 *
 * Note: rows written before this fix were stored in server-local time and will
 * read ~offset hours off until they are re-actioned; new activity is correct.
 */
function itaToUtcIso(?string $utcDateTime): ?string {
    if (!$utcDateTime || $utcDateTime === '0000-00-00 00:00:00') return null;
    return str_replace(' ', 'T', $utcDateTime) . 'Z';
}

// Aggregate flat rows into nested request objects
$requests = [];
$requestIndex = []; // ref_number => array index in $requests

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
            // Display name: saved full name if set, else email local-part, else username.
            'submittedByName' => (function ($full, $email, $uname) {
                $full = trim((string)$full);
                if ($full !== '') return $full;
                $email = trim((string)$email);
                if ($email !== '' && strpos($email, '@') !== false) {
                    return substr($email, 0, strpos($email, '@'));
                }
                return $uname ?: '';
            })($row['submitter_full_name'] ?? '', $row['submitter_email'] ?? '', $row['submitter_username'] ?? ''),
            'submittedAt'   => itaToUtcIso($row['submitted_at']),
            // Who the request was routed to for supervisor approval, so the UI
            // can show the requester where it is sitting. Null when there was no
            // supervisor on file and it went straight to ICT.
            // Appeal linkage. `appealOf` names the rejected request this one
            // appeals; `canAppeal` tells the UI whether the "revise & appeal"
            // action should be offered — true only for a rejected ORIGINAL that
            // has not already been appealed (one appeal only).
            'appealOf'       => $row['appeal_of'] ? (int)$row['appeal_of'] : null,
            'appealOfRef'    => $row['appeal_of_ref'] ?: null,
            'canAppeal'      => ($row['status'] === 'rejected'
                                 && $row['appeal_of'] === null
                                 && (int)$row['appeal_count'] === 0),
            'supervisorId'   => $row['supervisor_id'] ? (int)$row['supervisor_id'] : null,
            'supervisorName' => (function ($full, $uname) {
                $full = trim((string)$full);
                if ($full !== '') return $full;
                return $uname ?: null;
            })($row['supervisor_full_name'] ?? '', $row['supervisor_username'] ?? ''),
            'approvals'     => [],
            'status'        => $row['status'],
            'claimedBy'     => $row['claimed_by'] ? (int)$row['claimed_by'] : null,
            'provisionedAt' => itaToUtcIso($row['provisioned_at']),
            'pdfFilename'   => $row['pdf_filename'] ?? null,
            '_sys_ids'      => [],   // dedup helper
            '_appr_ids'     => [],   // dedup helper
        ];
    }

    $idx = $requestIndex[$rid];

    // Add system row (deduplicate)
    if ($row['sys_row_id'] && !in_array($row['sys_row_id'], $requests[$idx]['_sys_ids'])) {
        $requests[$idx]['_sys_ids'][] = $row['sys_row_id'];
        $subRaw = $row['sub_values'];
        $subValues = null;
        if ($subRaw !== null) {
            $decoded = json_decode($subRaw, true);
            $subValues = ($decoded !== null) ? $decoded : $subRaw;
        }
        $requests[$idx]['systems'][] = [
            'id'         => $row['system_id'],
            'role'       => $row['sys_role'],
            'subValues'  => $subValues,
            'status'     => $row['sys_status'] ?? 'pending',
            'claimedBy'  => $row['sys_claimed_by']  ? (int)$row['sys_claimed_by']  : null,
            'actionedBy' => $row['sys_actioned_by'] ? (int)$row['sys_actioned_by'] : null,
        ];
    }

    // Add approval row (deduplicate)
    if ($row['appr_row_id'] && !in_array($row['appr_row_id'], $requests[$idx]['_appr_ids'])) {
        $requests[$idx]['_appr_ids'][] = $row['appr_row_id'];
        $signature = null;
        if ($row['sig_kind'] === 'drawn' && $row['sig_data']) {
            $signature = ['kind' => 'drawn', 'strokes' => json_decode($row['sig_data'], true)];
        } elseif ($row['sig_kind'] === 'uploaded' && $row['sig_data']) {
            $signature = ['kind' => 'uploaded', 'dataUrl' => $row['sig_data']];
        }
        // Display name of whoever took this step: saved full name, else email
        // local-part, else username. Lets the UI show the real actor instead of
        // any demo seed person.
        $apprName = (function ($full, $email, $uname) {
            $full = trim((string)$full);
            if ($full !== '') return $full;
            $email = trim((string)$email);
            if ($email !== '' && strpos($email, '@') !== false) {
                return substr($email, 0, strpos($email, '@'));
            }
            return $uname ?: '';
        })($row['approver_full_name'] ?? '', $row['approver_email'] ?? '', $row['approver_username'] ?? '');

        $appr = [
            'role'       => $row['step_role'],
            'personId'   => (int)$row['approver_id'],
            'personName' => $apprName,
            'at'         => itaToUtcIso($row['acted_at']),
            'action'     => $row['appr_action'],
        ];
        if ($signature) $appr['signature'] = $signature;
        if ($row['reason']) $appr['reason'] = $row['reason'];
        $requests[$idx]['approvals'][] = $appr;
    }
}

// Strip internal dedup helpers
foreach ($requests as &$req) {
    unset($req['_sys_ids'], $req['_appr_ids']);
}
unset($req);

echo json_encode(['requests' => array_values($requests)]);
