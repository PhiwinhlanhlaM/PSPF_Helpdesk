<?php
// it_access/stats.php
// Read-only analytics for the IT Access module. Returns one JSON payload with
// request-POV and system-POV metrics, computed org-wide. Visible to the IT
// oversight/ops chain only: it_officer, it_director, superadmin.
//
// All numbers are org-wide regardless of which of the three roles is asking -
// this is a shared oversight page. Cycle-time percentiles are computed in PHP
// because MariaDB 10.4 has no PERCENTILE_CONT.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once __DIR__ . '/catalog_shared.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

$activeRole = getActiveRole();
$canView = hasRole('it_officer') || hasRole('it_director')
        || in_array($activeRole, ['admin', 'superadmin'], true);
if (!$canView) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

// --- Helpers ---------------------------------------------------------------

/** Median of a numeric array (returns null for empty). */
function statMedian(array $xs): ?float {
    if (!$xs) return null;
    sort($xs);
    $n = count($xs);
    $mid = intdiv($n, 2);
    return ($n % 2) ? (float)$xs[$mid] : ($xs[$mid - 1] + $xs[$mid]) / 2.0;
}

/** Nearest-rank percentile (0-100) of a numeric array (null for empty). */
function statPercentile(array $xs, float $p): ?float {
    if (!$xs) return null;
    sort($xs);
    $n = count($xs);
    $rank = (int)ceil(($p / 100) * $n);
    $rank = max(1, min($n, $rank));
    return (float)$xs[$rank - 1];
}

$catalog = itaBuildCatalog($conn, true);

// --- 1. Volume by ISO week (last 12 weeks) --------------------------------
$volumeByWeek = [];
$res = $conn->query(
    "SELECT DATE_FORMAT(submitted_at, '%x-W%v') AS wk, COUNT(*) AS c
     FROM it_access_requests
     WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
     GROUP BY wk ORDER BY wk ASC"
);
while ($row = $res->fetch_assoc()) {
    $volumeByWeek[] = ['week' => $row['wk'], 'count' => (int)$row['c']];
}

// --- 2. Status funnel ------------------------------------------------------
$statusFunnel = [];
$res = $conn->query(
    "SELECT status, COUNT(*) AS c FROM it_access_requests GROUP BY status"
);
while ($row = $res->fetch_assoc()) {
    $statusFunnel[$row['status']] = (int)$row['c'];
}

// --- 3. Cycle time (submitted -> provisioned), in hours -------------------
$cycleHours = [];
$res = $conn->query(
    "SELECT TIMESTAMPDIFF(HOUR, submitted_at, provisioned_at) AS h
     FROM it_access_requests
     WHERE status = 'provisioned' AND provisioned_at IS NOT NULL"
);
while ($row = $res->fetch_assoc()) {
    if ($row['h'] !== null && $row['h'] >= 0) $cycleHours[] = (float)$row['h'];
}
$cycleTime = [
    'count'      => count($cycleHours),
    'medianHrs'  => statMedian($cycleHours),
    'p90Hrs'     => statPercentile($cycleHours, 90),
];

// --- 4. Approval vs rejection vs in-flight --------------------------------
$provisioned = $statusFunnel['provisioned'] ?? 0;
$rejected    = $statusFunnel['rejected'] ?? 0;
$totalReq    = array_sum($statusFunnel);
$inFlight    = $totalReq - $provisioned - $rejected;
$decided     = $provisioned + $rejected;
$approvalRate = [
    'provisioned' => $provisioned,
    'rejected'    => $rejected,
    'inFlight'    => $inFlight,
    'total'       => $totalReq,
    'approvalPct' => $decided ? round($provisioned / $decided * 100, 1) : null,
];

// --- 5. By department ------------------------------------------------------
$byDepartment = [];
$res = $conn->query(
    "SELECT department, COUNT(*) AS c FROM it_access_requests
     GROUP BY department ORDER BY c DESC"
);
while ($row = $res->fetch_assoc()) {
    $byDepartment[] = ['department' => $row['department'] ?: '(none)', 'count' => (int)$row['c']];
}

// --- 6. Officer workload + throughput -------------------------------------
// Count of systems actioned per officer, and avg hours from claim -> action.
$officerStats = [];
$res = $conn->query(
    // avg_action_hrs averages only non-negative durations (NULLIF+CASE): manual
    // test data can have actioned_at before claimed_at, which would skew the mean.
    "SELECT s.actioned_by AS uid, u.username, u.full_name,
            COUNT(*) AS actioned,
            AVG(CASE WHEN TIMESTAMPDIFF(HOUR, s.claimed_at, s.actioned_at) >= 0
                     THEN TIMESTAMPDIFF(HOUR, s.claimed_at, s.actioned_at) END) AS avg_action_hrs
     FROM it_request_systems s
     JOIN users u ON u.id = s.actioned_by
     WHERE s.actioned_by IS NOT NULL AND s.actioned_at IS NOT NULL
     GROUP BY s.actioned_by
     ORDER BY actioned DESC"
);
while ($row = $res->fetch_assoc()) {
    $officerStats[] = [
        'userId'       => (int)$row['uid'],
        'name'         => $row['full_name'] ?: $row['username'],
        'actioned'     => (int)$row['actioned'],
        'avgActionHrs' => $row['avg_action_hrs'] !== null ? round((float)$row['avg_action_hrs'], 1) : null,
    ];
}

// --- 7. Claimed but not actioned (stuck) ----------------------------------
// Systems still 'claimed' with how long they've sat, oldest first.
$stuckClaimed = [];
$res = $conn->query(
    "SELECT r.ref_number, s.system_id, s.sub_values, s.role,
            u.username, u.full_name,
            TIMESTAMPDIFF(HOUR, s.claimed_at, NOW()) AS waiting_hrs
     FROM it_request_systems s
     JOIN it_access_requests r ON r.id = s.request_id
     LEFT JOIN users u ON u.id = s.claimed_by
     WHERE s.status = 'claimed' AND s.claimed_at IS NOT NULL
     ORDER BY waiting_hrs DESC
     LIMIT 20"
);
while ($row = $res->fetch_assoc()) {
    $sub = null;
    if (!empty($row['sub_values'])) {
        $sub = json_decode($row['sub_values'], true) ?? $row['sub_values'];
    }
    $disp = itaSystemDisplay($row['system_id'], $row['role'] ?? '', $sub, $catalog);
    $stuckClaimed[] = [
        'ref'        => $row['ref_number'],
        'system'     => $disp['name'],
        'officer'    => $row['full_name'] ?: $row['username'] ?: '(unknown)',
        'waitingHrs' => (int)$row['waiting_hrs'],
    ];
}

// --- 8. Most-requested systems + 9. per-system grant/deny -----------------
// One pass over all requested-system rows; resolve display names (so "Other"
// typed names group under their real name).
$sysAgg = []; // name => ['total'=>, 'granted'=>, 'denied'=>]
$res = $conn->query(
    "SELECT system_id, role, sub_values, status FROM it_request_systems"
);
while ($row = $res->fetch_assoc()) {
    $sub = null;
    if (!empty($row['sub_values'])) {
        $sub = json_decode($row['sub_values'], true) ?? $row['sub_values'];
    }
    $disp = itaSystemDisplay($row['system_id'], $row['role'] ?? '', $sub, $catalog);
    $name = $disp['name'] !== '' ? $disp['name'] : $row['system_id'];
    if (!isset($sysAgg[$name])) $sysAgg[$name] = ['total' => 0, 'granted' => 0, 'denied' => 0];
    $sysAgg[$name]['total']++;
    if ($row['status'] === 'actioned')                       $sysAgg[$name]['granted']++;
    elseif (in_array($row['status'], ['dropped', 'rejected'], true)) $sysAgg[$name]['denied']++;
}
$topSystems = [];
foreach ($sysAgg as $name => $a) {
    $decidedSys = $a['granted'] + $a['denied'];
    $topSystems[] = [
        'system'  => $name,
        'total'   => $a['total'],
        'granted' => $a['granted'],
        'denied'  => $a['denied'],
        'grantPct'=> $decidedSys ? round($a['granted'] / $decidedSys * 100, 1) : null,
    ];
}
// Sort by total requested, descending.
usort($topSystems, fn($x, $y) => $y['total'] <=> $x['total']);

echo json_encode([
    'generatedAt'  => date('c'),
    'volumeByWeek' => $volumeByWeek,
    'statusFunnel' => $statusFunnel,
    'cycleTime'    => $cycleTime,
    'approvalRate' => $approvalRate,
    'byDepartment' => $byDepartment,
    'officerStats' => $officerStats,
    'stuckClaimed' => $stuckClaimed,
    'topSystems'   => $topSystems,
], JSON_UNESCAPED_UNICODE);
