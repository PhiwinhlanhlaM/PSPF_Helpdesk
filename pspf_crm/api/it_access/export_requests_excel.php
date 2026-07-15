<?php
/**
 * IT Access — request export (Excel-readable).
 *
 * Exports the IT access requests for a reporting period as a spreadsheet, using
 * the same period definitions as the director dashboard stats so the two can
 * never disagree. One row per request, with the requested systems flattened
 * into a single cell.
 *
 * Access mirrors list.php: superadmin/admin, IT officers and the IT director
 * may export across the organisation; anyone else gets only their own requests.
 * The period is resolved server-side from a preset id (or an explicit from/to
 * for the custom range) rather than trusting client-supplied SQL fragments.
 *
 *   GET export_requests_excel.php?range=this-month
 *   GET export_requests_excel.php?range=custom&from=2026-03-01&to=2026-03-31
 */

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

if (!isLoggedIn()) { http_response_code(401); echo 'Not authenticated'; exit; }
enforceActiveUser($conn);

$userId     = (int)$_SESSION['user']['id'];
$activeRole = getActiveRole();
$isSuper    = in_array($activeRole, ['admin', 'superadmin']);
$isOfficer  = hasRole('it_officer');
$isDirector = hasRole('it_director');
$seesAll    = $isSuper || $isOfficer || $isDirector;

// ---------------------------------------------------------------------
// Resolve the reporting period.
//
// Mirrors dirRangeBounds() in Director.jsx. Timestamps are stored in UTC but
// the period is expressed in server-local terms — the same convention the
// dashboard uses, so "this month" means the month on the viewer's calendar.
// Returns [fromSql, toSql] as 'Y-m-d H:i:s', or null for all time.
// ---------------------------------------------------------------------
function ita_range_bounds(string $preset, string $from = '', string $to = ''): ?array {
    $startOfDay = static fn(string $d): string => $d . ' 00:00:00';
    $endOfDay   = static fn(string $d): string => $d . ' 23:59:59';

    switch ($preset) {
        case 'this-month':
            return [$startOfDay(date('Y-m-01')), $endOfDay(date('Y-m-t'))];
        case 'last-month':
            return [
                $startOfDay(date('Y-m-01', strtotime('first day of last month'))),
                $endOfDay(date('Y-m-t', strtotime('last day of last month'))),
            ];
        case 'this-quarter': {
            $qStartMonth = (intdiv((int)date('n') - 1, 3) * 3) + 1;
            $y = (int)date('Y');
            $start = sprintf('%04d-%02d-01', $y, $qStartMonth);
            $end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $qStartMonth + 2)));
            return [$startOfDay($start), $endOfDay($end)];
        }
        case 'this-year':
            return [$startOfDay(date('Y-01-01')), $endOfDay(date('Y-12-31'))];
        case 'custom': {
            // Only accept strict YYYY-MM-DD, and require both ends.
            $ok = static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
                && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
            if (!$ok($from) || !$ok($to)) return null;
            if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }
            return [$startOfDay($from), $endOfDay($to)];
        }
        case 'all':
        default:
            return null;
    }
}

$presets = ['this-month', 'last-month', 'this-quarter', 'this-year', 'all', 'custom'];
$range   = $_GET['range'] ?? 'this-month';
if (!in_array($range, $presets, true)) $range = 'this-month';

$bounds = ita_range_bounds($range, trim($_GET['from'] ?? ''), trim($_GET['to'] ?? ''));

// A custom range with missing/invalid dates is a client error, not silently "all time".
if ($range === 'custom' && $bounds === null) {
    http_response_code(400);
    echo 'A custom range needs a valid from and to date (YYYY-MM-DD).';
    exit;
}

$rangeLabels = [
    'this-month'   => 'This month',
    'last-month'   => 'Last month',
    'this-quarter' => 'This quarter',
    'this-year'    => 'This year',
    'all'          => 'All time',
    'custom'       => 'Custom range',
];

// ---------------------------------------------------------------------
// Build the query. Period filters on submitted_at, matching the dashboard's
// "Submitted · in period" stat.
// ---------------------------------------------------------------------
$where  = [];
$params = [];
$types  = '';

if (!$seesAll) {
    $where[]  = 'r.submitted_by = ?';
    $params[] = $userId;
    $types   .= 'i';
}
if ($bounds !== null) {
    $where[]  = 'r.submitted_at BETWEEN ? AND ?';
    $params[] = $bounds[0];
    $params[] = $bounds[1];
    $types   .= 'ss';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        r.ref_number,
        r.request_type,
        r.employee_name,
        r.employee_id,
        r.department,
        r.division,
        r.job_title,
        r.start_date,
        r.justification,
        r.status,
        r.submitted_at,
        r.provisioned_at,
        sb.full_name AS submitter_full_name,
        sb.username  AS submitter_username,
        sb.email     AS submitter_email,
        GROUP_CONCAT(
            CONCAT(s.system_id, IF(s.role IS NULL OR s.role = '', '', CONCAT(' (', s.role, ')')))
            ORDER BY s.id SEPARATOR ', '
        ) AS systems_list
    FROM it_access_requests r
    LEFT JOIN users              sb ON sb.id = r.submitted_by
    LEFT JOIN it_request_systems s  ON s.request_id = r.id
    {$whereSql}
    GROUP BY r.id
    ORDER BY r.submitted_at DESC, r.id DESC
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Display name: saved full name, else email local-part, else username.
// Same precedence as list.php so exported names match the on-screen ones.
function ita_display_name(?string $full, ?string $email, ?string $uname): string {
    $full = trim((string)$full);
    if ($full !== '') return $full;
    $email = trim((string)$email);
    if ($email !== '' && strpos($email, '@') !== false) {
        return substr($email, 0, strpos($email, '@'));
    }
    return (string)($uname ?? '');
}

$statusLabels = [
    'new'               => 'New',
    'claimed'           => 'Under review',
    'awaiting-director' => 'Awaiting director review',
    'provisioned'       => 'Provisioned',
    'rejected'          => 'Rejected',
];

$periodText = $bounds === null
    ? 'All time'
    : date('d/m/Y', strtotime($bounds[0])) . ' to ' . date('d/m/Y', strtotime($bounds[1]));

$filename = 'IT_Access_Requests_' . $range . '_' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM so Excel reads accented characters correctly.
echo "\xEF\xBB\xBF";
?>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<table border="0" cellspacing="0" cellpadding="4">
  <tr><td colspan="12" style="font-size:15pt;font-weight:bold;">PSPF — IT Access Requests</td></tr>
  <tr>
    <td colspan="12" style="color:#555;">Period: <?= htmlspecialchars($rangeLabels[$range]) ?> (<?= htmlspecialchars($periodText) ?>)</td>
  </tr>
  <tr>
    <td colspan="12" style="color:#555;">Generated: <?= htmlspecialchars(date('d/m/Y H:i')) ?> by <?= htmlspecialchars(ita_display_name(
          $_SESSION['user']['full_name'] ?? '',
          $_SESSION['user']['email'] ?? '',
          $_SESSION['user']['username'] ?? ''
    )) ?><?= $seesAll ? '' : ' — scope: your own requests only' ?></td>
  </tr>
  <tr><td colspan="12"></td></tr>
  <tr style="background-color:#3D5A7E;color:#ffffff;font-weight:bold;">
    <th>Reference</th>
    <th>Type</th>
    <th>Employee</th>
    <th>Employee ID</th>
    <th>Department</th>
    <th>Division</th>
    <th>Job title</th>
    <th>Systems requested</th>
    <th>Status</th>
    <th>Submitted by</th>
    <th>Submitted</th>
    <th>Provisioned</th>
  </tr>
<?php
$rowCount = 0;
while ($row = $result->fetch_assoc()):
    $rowCount++;
    $zebra = $rowCount % 2 === 0 ? ' style="background-color:#F4F6F8;"' : '';
?>
  <tr<?= $zebra ?>>
    <td style="mso-number-format:'\@';"><?= htmlspecialchars($row['ref_number']) ?></td>
    <td><?= $row['request_type'] === 'change' ? 'Change of access' : 'New access' ?></td>
    <td><?= htmlspecialchars($row['employee_name']) ?></td>
    <td style="mso-number-format:'\@';"><?= htmlspecialchars($row['employee_id'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['department']) ?></td>
    <td><?= htmlspecialchars($row['division'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['job_title']) ?></td>
    <td><?= htmlspecialchars($row['systems_list'] ?? '') ?></td>
    <td><?= htmlspecialchars($statusLabels[$row['status']] ?? $row['status']) ?></td>
    <td><?= htmlspecialchars(ita_display_name(
          $row['submitter_full_name'], $row['submitter_email'], $row['submitter_username']
    )) ?></td>
    <td><?= $row['submitted_at']   ? htmlspecialchars(date('d/m/Y H:i', strtotime($row['submitted_at']))) : '' ?></td>
    <td><?= $row['provisioned_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($row['provisioned_at']))) : '' ?></td>
  </tr>
<?php endwhile; ?>
<?php if ($rowCount === 0): ?>
  <tr><td colspan="12" style="color:#777;">No requests were submitted in this period.</td></tr>
<?php endif; ?>
  <tr><td colspan="12"></td></tr>
  <tr><td colspan="12" style="font-weight:bold;">Total requests: <?= $rowCount ?></td></tr>
</table>
<?php
$stmt->close();
$conn->close();
