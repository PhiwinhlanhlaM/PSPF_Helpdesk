<?php
// admin_stats_export.php?from=YYYY-MM-DD&to=YYYY-MM-DD&view=admin|driver|hrm
//
// Downloads the statistics shown on the Administrator dashboard, the
// driver's Transport Report or the HRM Report as a CSV file (opens in
// Excel) for pasting into reports: summary figures, each chart's numbers
// and, for administrators, the full activity log for the period.

session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require_once __DIR__ . '/admin_stats.php';

// Admins get any view; drivers and HRM get their own report's figures.
$view = isset($_SESSION['user_id']) ? vbStatsViewFor($_SESSION['role'] ?? '', $_GET['view'] ?? null) : null;
if ($view === null) {
    http_response_code(403);
    exit('Unauthorized');
}
$show = VB_STATS_VIEWS[$view];
$has  = static fn(string $group, string $key): bool => in_array($key, $show[$group], true);

[$from, $to] = vbStatsPeriod($_GET);
$s = vbCollectStats($conn, $from, $to);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="transport_statistics_' . $view . '_' . $from . '_to_' . $to . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly

$row = static function (array $cells) use ($out) {
    // Neutralise spreadsheet formulas in text taken from the database.
    $cells = array_map(static function ($c) {
        $c = (string)$c;
        return preg_match('/^[=+\-@\t\r]/', $c) ? "'" . $c : $c;
    }, $cells);
    fputcsv($out, $cells);
};
$section = static function (string $title, array $header) use ($row) {
    $row([]);
    $row([$title]);
    $row($header);
};

$row(['PSPF Transport Booking System - Statistics (' . ['admin' => 'Administrator', 'driver' => 'Fleet', 'hrm' => 'HRM'][$view] . ')']);
$row(['Reporting period', $from . ' to ' . $to]);
$row(['Generated', date('Y-m-d H:i'), 'by', vbName($_SESSION['name'] ?? '')]);

$section('Summary', ['Measure', 'Value']);
$row(['Requests raised', $s['requests_total']]);
$row(['Requests still awaiting a decision', $s['pending']]);
if ($has('tiles', 'pending')) {
    $row(['  of which awaiting HRM', $s['by_status']['pending_hrm']]);
}
if ($has('tiles', 'approval_rate')) {
    $row(['Requests approved', $s['approved_requests']]);
    $row(['Requests rejected', $s['by_status']['rejected']]);
    $row(['Approval rate', $s['approval_rate']]);
}
if ($has('tiles', 'actioned')) {
    $row(['Requests actioned (approved / rejected / vehicle assigned)', $s['requests_actioned']]);
    $row(['Decisions taken', $s['actions_total']]);
    $row(['  of which approvals / assignments', $s['approved_total']]);
    $row(['  of which rejections', $s['rejected_total']]);
}
if ($has('tiles', 'email')) {
    $row(['Decisions taken by email', $s['email_actions']]);
    $row(['  approved by email', $s['email_approved']]);
    $row(['  rejected by email', $s['email_rejected']]);
    $row(['Share of decisions taken by email', vbPct($s['email_actions'], $s['actions_total'])]);
    if ($s['email_tokens'] !== null) {
        $row(['Email approval links sent', $s['email_tokens']['sent']]);
        $row(['Email approval links used', $s['email_tokens']['used']]);
        $row(['Email approval links expired unused', $s['email_tokens']['expired']]);
    }
}
if ($has('tiles', 'avg_approval')) {
    $row(['Avg. hours from submission to full approval', $s['avg_approval_hours'] ?? '']);
}
if ($has('tiles', 'on_road')) {
    $row(['Trips on the road now', $s['on_road_now']]);
}
$row(['Trips completed (vehicle returned)', $s['trips_completed']]);
$row(['Km driven on trips raised in period', $s['km_total']]);
if ($has('tiles', 'users')) {
    $row(['Users who raised or actioned a request', $s['users_engaged']]);
}
if ($has('tiles', 'logins') && $s['logins'] !== null) {
    $row(['Sign-ins', $s['logins']['total']]);
    $row(['Different users who signed in', $s['logins']['users']]);
    $row(['Sign-ins via Helpdesk SSO', $s['logins']['sso']]);
}
if ($has('tiles', 'logs')) {
    $row(['New user accounts', $s['users_new']]);
    $row(['Active user accounts (all time)', $s['users_active']]);
    $row(['Total user accounts (all time)', $s['users_total']]);
    $row(['Activity log entries', $s['logs_total']]);
}

if ($has('charts', 'timeline')) {
    $section('Requests raised per ' . $s['timeline_unit'], ['Period', 'Requests']);
    foreach ($s['timeline'] as $p) $row([$p['label'], $p['value']]);
}

if ($has('charts', 'channel')) {
    $section('Decisions by stage and channel', ['Stage', 'On the system', 'By email', 'Approved / assigned', 'Rejected', 'Total']);
    foreach ($s['channel'] as $stage => $c) {
        $row([VB_STAGE_LABELS[$stage], $c['system'], $c['email'], $c['approved'], $c['rejected'], $c['system'] + $c['email']]);
    }
}

if ($has('charts', 'status')) {
    $section('Requests by status', ['Status', 'Requests']);
    foreach ($s['by_status'] as $status => $n) $row([vbStatusLabel($status), $n]);
}

if ($has('tables', 'department_summary')) {
    $section('Department summary', ['Department', 'Requests', 'Approved', 'Rejected', 'Pending', 'Trips completed', 'Km', 'Share of requests']);
    foreach ($s['department_summary'] as $d) {
        $row([$d['department'], $d['requests'], $d['approved'], $d['rejected'], $d['pending'], $d['completed'], $d['km'],
              vbPct((int)$d['requests'], $s['requests_total'])]);
    }
} elseif ($has('charts', 'department')) {
    $section('Requests by department', ['Department', 'Requests']);
    foreach ($s['by_department'] as $d) $row([$d['label'], $d['value']]);
}

if ($has('tables', 'vehicle_usage') || $has('charts', 'vehicle_trips')) {
    $section('Vehicle usage', ['Vehicle', 'Trips', 'Km', 'Avg. km per trip']);
    foreach ($s['vehicle_usage'] as $v) {
        $row([trim($v['label']), $v['trips'], $v['km'], (int)$v['trips'] > 0 ? round((int)$v['km'] / (int)$v['trips']) : '']);
    }
}

if ($has('charts', 'roles')) {
    $section('Active accounts by role', ['Role', 'Users']);
    foreach ($s['users_by_role'] as $r) $row([vbRoleLabel($r['label']), $r['value']]);
}

if ($has('tables', 'top_users')) {
    $section('Most active users', ['User', 'Role', 'Department', 'Requests submitted', 'Actions taken', 'Actions by email']);
    foreach ($s['top_users'] as $u) {
        $row([vbName($u['name']), vbRoleLabel($u['role']), $u['department'], $u['submitted'], $u['actioned'], $u['via_email']]);
    }
}

if ($has('tables', 'activity')) {
    $section('Activity log', ['Date / time', 'Request #', 'Action', 'By', 'Channel']);
    $log = $conn->prepare("
        SELECT rl.created_at, rl.request_id, rl.action, u.name
        FROM request_logs rl
        LEFT JOIN users u ON u.user_id = rl.action_by
        WHERE rl.created_at BETWEEN ? AND ?
        ORDER BY rl.created_at, rl.log_id
    ");
    $log->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    while ($l = $log->fetch(PDO::FETCH_ASSOC)) {
        $row([
            $l['created_at'],
            $l['request_id'] ?? '',
            $l['action'],
            vbName($l['name'] ?? '') ?: 'System',
            stripos($l['action'], '(via email)') !== false ? 'Email' : 'System',
        ]);
    }
}

fclose($out);
