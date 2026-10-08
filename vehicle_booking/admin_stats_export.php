<?php
// admin_stats_export.php?from=YYYY-MM-DD&to=YYYY-MM-DD
//
// Downloads the Administrator dashboard's System Statistics as a CSV file
// (opens in Excel) for pasting into reports: summary figures, each chart's
// numbers, and the full activity log for the period.

session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require_once __DIR__ . '/admin_stats.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    exit('Unauthorized');
}

[$from, $to] = vbStatsPeriod($_GET);
$s = vbCollectStats($conn, $from, $to);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="transport_statistics_' . $from . '_to_' . $to . '.csv"');

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

$row(['PSPF Transport Booking System - Statistics']);
$row(['Reporting period', $from . ' to ' . $to]);
$row(['Generated', date('Y-m-d H:i'), 'by', vbName($_SESSION['name'] ?? '')]);

$section('Summary', ['Measure', 'Value']);
$row(['Requests raised', $s['requests_total']]);
$row(['Requests still awaiting a decision', $s['pending']]);
$row(['Requests actioned (approved / rejected / vehicle assigned)', $s['requests_actioned']]);
$row(['Decisions taken', $s['actions_total']]);
$row(['  of which approvals / assignments', $s['approved_total']]);
$row(['  of which rejections', $s['rejected_total']]);
$row(['Decisions taken by email', $s['email_actions']]);
$row(['  approved by email', $s['email_approved']]);
$row(['  rejected by email', $s['email_rejected']]);
$row(['Share of decisions taken by email', vbPct($s['email_actions'], $s['actions_total'])]);
if ($s['email_tokens'] !== null) {
    $row(['Email approval links sent', $s['email_tokens']['sent']]);
    $row(['Email approval links used', $s['email_tokens']['used']]);
    $row(['Email approval links expired unused', $s['email_tokens']['expired']]);
}
$row(['Avg. hours from submission to full approval', $s['avg_approval_hours'] ?? '']);
$row(['Trips completed (vehicle returned)', $s['trips_completed']]);
$row(['Km driven on trips raised in period', $s['km_total']]);
$row(['Users who raised or actioned a request', $s['users_engaged']]);
if ($s['logins'] !== null) {
    $row(['Sign-ins', $s['logins']['total']]);
    $row(['Different users who signed in', $s['logins']['users']]);
    $row(['Sign-ins via Helpdesk SSO', $s['logins']['sso']]);
}
$row(['New user accounts', $s['users_new']]);
$row(['Active user accounts (all time)', $s['users_active']]);
$row(['Total user accounts (all time)', $s['users_total']]);
$row(['Activity log entries', $s['logs_total']]);

$section('Requests raised per ' . $s['timeline_unit'], ['Period', 'Requests']);
foreach ($s['timeline'] as $p) $row([$p['label'], $p['value']]);

$section('Decisions by stage and channel', ['Stage', 'On the system', 'By email', 'Approved / assigned', 'Rejected', 'Total']);
foreach ($s['channel'] as $stage => $c) {
    $row([VB_STAGE_LABELS[$stage], $c['system'], $c['email'], $c['approved'], $c['rejected'], $c['system'] + $c['email']]);
}

$section('Requests by status', ['Status', 'Requests']);
foreach ($s['by_status'] as $status => $n) $row([vbStatusLabel($status), $n]);

$section('Requests by department', ['Department', 'Requests']);
foreach ($s['by_department'] as $d) $row([$d['label'], $d['value']]);

$section('Vehicle usage', ['Vehicle', 'Trips', 'Km']);
foreach ($s['vehicle_usage'] as $v) $row([trim($v['label']), $v['trips'], $v['km']]);

$section('Active accounts by role', ['Role', 'Users']);
foreach ($s['users_by_role'] as $r) $row([vbRoleLabel($r['label']), $r['value']]);

$section('Most active users', ['User', 'Role', 'Department', 'Requests submitted', 'Actions taken', 'Actions by email']);
foreach ($s['top_users'] as $u) {
    $row([vbName($u['name']), vbRoleLabel($u['role']), $u['department'], $u['submitted'], $u['actioned'], $u['via_email']]);
}

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

fclose($out);
