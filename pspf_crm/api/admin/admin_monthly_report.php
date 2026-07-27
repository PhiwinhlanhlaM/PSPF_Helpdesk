<?php

// 1. Always start the session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Bounce anonymous users to the login page.
if (empty($_SESSION['user'])) {
    header("Location: /pspf_crm/api/signin/index.php");
    exit;
}

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/division_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../includes/metrics_helpers.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

$activeRole = getActiveRole();

$UserId         = (int)$_SESSION['user']['id'];
$UserUsername   = $_SESSION['user']['username'];
$UserEmail      = $_SESSION['user']['email'];
$UserDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);
$userDept       = $_SESSION['user']['division_name'] ?? 'All Departments';

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

// This report is a management view: department-wide (admin) or global (superadmin).
requireAnyRole(['admin', 'superadmin']);

$role = $_SESSION['active_role'] ?? 'user';
$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];
$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// Scope: superadmin sees everything, admin only their own division. Mirrors the
// admin dashboard's scoping so the two always agree on "what counts".
$scopeSql = $isSuperAdmin ? "1=1" : "t.division_id = ?";

// ---------------------------
// SELECTED MONTH
// ---------------------------
$monthParam = trim($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthStart = $monthParam . '-01';
$startTs    = strtotime($monthStart);
if ($startTs === false) {
    $monthParam = date('Y-m');
    $monthStart = $monthParam . '-01';
    $startTs    = strtotime($monthStart);
}
$nextMonthStart = date('Y-m-01', strtotime('+1 month', $startTs));
$prevMonth      = date('Y-m', strtotime('-1 month', $startTs));
$nextMonth      = date('Y-m', strtotime('+1 month', $startTs));
$monthLabel     = date('F Y', $startTs);
$isCurrentMonth = ($monthParam === date('Y-m'));

// ---------------------------
// RESOLVED TICKETS FOR THE MONTH (department / global)
// ---------------------------
// Buckets by RESOLVED_AT (when the ticket reached Resolved/Closed), matching the
// agent report. resolution_minutes measures the AGENT'S real handling time via
// WORK_COMPLETED_AT_SQL (first of Resolved/Closed/Pending Feedback), so tickets
// parked in Pending Feedback -- or manually cleared after hanging -- don't
// inflate the averages. See metrics_helpers.php.
$reportSql = "
    SELECT
        t.id,
        t.title,
        t.priority,
        t.status,
        t.member_type,
        t.created_by,
        t.assigned_to,
        t.description,
        t.query_date,
        " . RESOLVED_AT_SQL . " AS resolved_at,
        TIMESTAMPDIFF(MINUTE, t.query_date, " . WORK_COMPLETED_AT_SQL . ") AS resolution_minutes,
        (SELECT tf.rating
           FROM ticket_feedback tf
          WHERE tf.ticket_id = t.id
          ORDER BY tf.created_at DESC
          LIMIT 1) AS rating
    FROM tickets t
    WHERE $scopeSql
    HAVING resolved_at IS NOT NULL
       AND resolved_at >= ?
       AND resolved_at < ?
    ORDER BY resolved_at ASC
";

$reportStmt = $conn->prepare($reportSql);
if ($isSuperAdmin) {
    $reportStmt->bind_param("ss", $monthStart, $nextMonthStart);
} else {
    $reportStmt->bind_param("iss", $UserDivisionId, $monthStart, $nextMonthStart);
}
$reportStmt->execute();
$reportResult = $reportStmt->get_result();

$rows = [];
while ($r = $reportResult->fetch_assoc()) {
    $rows[] = $r;
}
$reportStmt->close();

// ---------------------------
// EMAIL -> USERNAME LOOKUP (for showing the assigned agent by name)
// ---------------------------
$userMap = [];
$uRes = $conn->query("SELECT email, username FROM users");
if ($uRes) {
    while ($u = $uRes->fetch_assoc()) {
        if (!empty($u['email'])) {
            $userMap[strtolower(trim($u['email']))] = $u['username'];
        }
    }
}

/** Turn a comma-separated assigned_to (emails) into a display list of names. */
function resolveAgentNames($assignedTo, array $userMap) {
    $emails = array_filter(array_map('trim', explode(',', (string)$assignedTo)));
    if (empty($emails)) return ['Unassigned'];
    $names = [];
    foreach ($emails as $em) {
        $names[] = $userMap[strtolower($em)] ?? $em;
    }
    return $names;
}

// ---------------------------
// SUMMARY + PER-AGENT BREAKDOWN (computed in PHP over the fetched rows)
// ---------------------------
$totalResolved = count($rows);
$sumMinutes = 0; $countTimed = 0; $fastest = null; $slowest = null;
$sumRating = 0;  $countRated = 0;
$priorityCounts = ['High' => 0, 'Medium' => 0, 'Low' => 0];

// Per-agent: a ticket with several assignees counts toward each of them, the
// same attribution the dashboard leaderboard uses.
$agentStats = []; // email => [...]

foreach ($rows as $r) {
    $mins = $r['resolution_minutes'];
    $timed = ($mins !== null && is_numeric($mins) && $mins >= 0);
    if ($timed) {
        $sumMinutes += $mins;
        $countTimed++;
        if ($fastest === null || $mins < $fastest) $fastest = (int)$mins;
        if ($slowest === null || $mins > $slowest) $slowest = (int)$mins;
    }
    $rated = ($r['rating'] !== null && is_numeric($r['rating']));
    if ($rated) { $sumRating += (float)$r['rating']; $countRated++; }

    $p = $r['priority'] ?? '';
    if (isset($priorityCounts[$p])) $priorityCounts[$p]++;

    $emails = array_filter(array_map('trim', explode(',', (string)($r['assigned_to'] ?? ''))));
    foreach ($emails as $em) {
        $key = strtolower($em);
        if (!isset($agentStats[$key])) {
            $agentStats[$key] = [
                'name' => $userMap[$key] ?? $em,
                'count' => 0, 'sumMin' => 0, 'cntMin' => 0, 'sumRate' => 0, 'cntRate' => 0,
            ];
        }
        $agentStats[$key]['count']++;
        if ($timed)  { $agentStats[$key]['sumMin'] += $mins; $agentStats[$key]['cntMin']++; }
        if ($rated)  { $agentStats[$key]['sumRate'] += (float)$r['rating']; $agentStats[$key]['cntRate']++; }
    }
}

$avgMinutes = $countTimed > 0 ? ($sumMinutes / $countTimed) : null;
$avgRating  = $countRated > 0 ? ($sumRating / $countRated) : null;
$activeAgents = count($agentStats);

// Sort agents by resolved count (desc), then by name.
uasort($agentStats, function ($a, $b) {
    if ($b['count'] !== $a['count']) return $b['count'] - $a['count'];
    return strcasecmp($a['name'], $b['name']);
});

$scopeLabel = $isSuperAdmin ? 'All Departments' : $userDept;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Report - PSPF Helpdesk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <style>
        .report-toolbar {
            display: flex; flex-wrap: wrap; gap: .75rem;
            align-items: end; justify-content: space-between; margin-bottom: 1rem;
        }
        .month-nav { display: flex; gap: .5rem; align-items: end; }
        .summary-value { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
        .summary-label { color: white; font-size: .8rem; }
        table.report-table th { white-space: nowrap; }
        @media print {
            .no-print, nav.navbar, .settings-actions { display: none !important; }
            .table-container, .stat-card { box-shadow: none !important; border: 1px solid #ddd; }
            a[href]:after { content: ""; }
        }
    </style>
</head>
<body>

<?php include '../agent/topnav_agent.php'; ?>

<div class="container-xl mt-4 mb-4">
    <!-- Header -->
    <div class="settings-header">
        <h1 class="settings-title">
            <i class="bi bi-calendar-check me-2"></i>Monthly Report
        </h1>
        <div class="settings-actions">
            <a href="./admin_dashboard.php" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Report identity -->
    <div class="mb-3">
        <div class="fw-semibold fs-5">
            <i class="bi bi-building me-1"></i><?= htmlspecialchars($scopeLabel) ?>
        </div>
        <div class="text-muted small">
            <i class="bi bi-calendar3 me-1"></i>Resolved tickets for <strong><?= htmlspecialchars($monthLabel) ?></strong>
            &middot; prepared by <?= htmlspecialchars($UserUsername) ?>
        </div>
    </div>

    <!-- Toolbar: month picker + actions -->
    <div class="report-toolbar">
        <form method="GET" class="month-nav no-print">
            <a class="btn btn-outline-secondary" href="?month=<?= urlencode($prevMonth) ?>" title="Previous month">
                <i class="bi bi-chevron-left"></i>
            </a>
            <div>
                <label for="month" class="form-label small mb-1">Month</label>
                <input type="month" id="month" name="month" class="form-control"
                       value="<?= htmlspecialchars($monthParam) ?>" max="<?= date('Y-m') ?>"
                       onchange="this.form.submit()">
            </div>
            <?php if (!$isCurrentMonth): ?>
                <a class="btn btn-outline-secondary" href="?month=<?= urlencode($nextMonth) ?>" title="Next month">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php endif; ?>
        </form>

        <div class="d-flex gap-2 no-print">
            <button type="button" class="btn btn-success" onclick="exportExcel()" <?= $totalResolved === 0 ? 'disabled' : '' ?>>
                <i class="bi bi-file-earmark-excel me-1"></i> Export to Excel
            </button>
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Summary KPIs -->
    <div class="stats-grid mb-4">
        <div class="stat-card">
            <div class="stat-icon success"><i class="bi bi-check2-circle"></i></div>
            <div class="summary-value"><?= $totalResolved ?></div>
            <div class="summary-label">Tickets Resolved</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon info"><i class="bi bi-people-fill"></i></div>
            <div class="summary-value"><?= $activeAgents ?></div>
            <div class="summary-label">Agents Contributing</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon info"><i class="bi bi-clock-history"></i></div>
            <div class="summary-value"><?= formatDuration($avgMinutes) ?></div>
            <div class="summary-label">Avg Resolution Time</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="bi bi-lightning-charge"></i></div>
            <div class="summary-value"><?= $fastest === null ? 'N/A' : formatDuration($fastest) ?></div>
            <div class="summary-label">Fastest Resolution</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning"><i class="bi bi-hourglass-bottom"></i></div>
            <div class="summary-value"><?= $slowest === null ? 'N/A' : formatDuration($slowest) ?></div>
            <div class="summary-label">Slowest Resolution</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning"><i class="bi bi-star-fill"></i></div>
            <div class="summary-value">
                <?= $avgRating === null ? 'N/A' : number_format($avgRating, 1) ?>
                <?php if ($avgRating !== null): ?><small class="text-muted fs-6">/5</small><?php endif; ?>
            </div>
            <div class="summary-label">Avg Rating (<?= $countRated ?>)</div>
        </div>
    </div>

    <!-- Priority breakdown -->
    <div class="mb-4">
        <span class="badge bg-danger fs-6"><?= $priorityCounts['High'] ?> High</span>
        <span class="badge bg-warning text-dark fs-6"><?= $priorityCounts['Medium'] ?> Medium</span>
        <span class="badge bg-success fs-6"><?= $priorityCounts['Low'] ?> Low</span>
        <span class="text-muted small ms-2">priority mix of resolved tickets</span>
    </div>

    <!-- Per-agent breakdown -->
    <div class="table-container mb-4">
        <div class="table-header">
            <h3><i class="bi bi-people-fill me-2"></i>By Agent &mdash; <?= htmlspecialchars($monthLabel) ?></h3>
            <span class="badge bg-secondary"><?= $activeAgents ?> agents</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover report-table mb-0" id="agentTable">
                <thead class="table-dark">
                    <tr>
                        <th>Agent</th>
                        <th>Resolved</th>
                        <th>Avg Resolution Time</th>
                        <th>Avg Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activeAgents > 0): ?>
                        <?php foreach ($agentStats as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['name']) ?></td>
                                <td><?= $a['count'] ?></td>
                                <td><?= $a['cntMin'] > 0 ? formatDuration($a['sumMin'] / $a['cntMin']) : 'N/A' ?></td>
                                <td><?= $a['cntRate'] > 0 ? number_format($a['sumRate'] / $a['cntRate'], 1) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No agent activity for this month.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Resolved tickets list -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="bi bi-list-check me-2"></i>Resolved Tickets &mdash; <?= htmlspecialchars($monthLabel) ?></h3>
            <span class="badge bg-secondary"><?= $totalResolved ?> tickets</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover report-table mb-0" id="reportTable">
                <thead class="table-dark">
                    <tr>
                        <th>Ticket #</th>
                        <th>Title</th>
                        <th>Priority</th>
                        <th>Assigned Agent</th>
                        <th>Requester</th>
                        <th>Created</th>
                        <th>Resolved</th>
                        <th>
                            Resolution Time
                            <i class="bi bi-info-circle text-muted" style="cursor:help;"
                               title="Active agent handling time: from creation until the work was completed (Resolved, Closed, or handed to Pending Feedback). Excludes time spent waiting on the requester's feedback."></i>
                        </th>
                        <th>Rating</th>
                        <!-- Hidden on screen; included in the Excel export / print. -->
                        <th class="d-none">Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($totalResolved > 0): ?>
                        <?php foreach ($rows as $t): ?>
                            <tr>
                                <td><?= 'TCK-' . str_pad($t['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                <td><?= htmlspecialchars($t['title']) ?></td>
                                <td>
                                    <span class="badge <?= $t['priority'] === 'High' ? 'bg-danger' : ($t['priority'] === 'Medium' ? 'bg-warning text-dark' : 'bg-success') ?>">
                                        <?= htmlspecialchars($t['priority']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(implode(', ', resolveAgentNames($t['assigned_to'], $userMap))) ?></td>
                                <td><?= htmlspecialchars($t['created_by']) ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($t['query_date']))) ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($t['resolved_at']))) ?></td>
                                <td><?= formatDuration($t['resolution_minutes']) ?></td>
                                <td>
                                    <?php if ($t['rating'] !== null && is_numeric($t['rating'])): ?>
                                        <span class="text-warning">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="bi <?= $i <= (int)$t['rating'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                            <?php endfor; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                No tickets were resolved in <?= htmlspecialchars($monthLabel) ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Resolution Time</strong> is active agent handling time &mdash; from when the ticket was created
        until the work was completed (Resolved, Closed, or handed to Pending Feedback). It excludes any time the
        ticket spent waiting on the requester's feedback. A ticket assigned to more than one agent counts toward
        each of them in the per-agent breakdown.
    </p>
</div>

<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Convert a rendered table to an array-of-arrays, turning the star column
    // (if present) into a numeric rating.
    function tableToAoa(table, ratingColIndex) {
        const data = [];
        const headers = [];
        table.querySelectorAll('thead th').forEach(th => headers.push(th.textContent.trim().replace(/\s+/g, ' ')));
        data.push(headers);
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = tr.querySelectorAll('td');
            if (cells.length < headers.length) return; // skip placeholder rows
            const row = [];
            cells.forEach((td, idx) => {
                if (idx === ratingColIndex) {
                    const filled = td.querySelectorAll('.bi-star-fill').length;
                    row.push(filled > 0 ? filled : (td.textContent.trim() === '—' ? '' : td.textContent.trim()));
                } else {
                    row.push(td.textContent.trim());
                }
            });
            data.push(row);
        });
        return data;
    }

    function exportExcel() {
        const wb = XLSX.utils.book_new();

        const ticketTable = document.getElementById('reportTable');
        if (ticketTable) {
            const ws1 = XLSX.utils.aoa_to_sheet(tableToAoa(ticketTable, 8)); // rating is last col
            XLSX.utils.book_append_sheet(wb, ws1, 'Resolved Tickets');
        }

        const agentTable = document.getElementById('agentTable');
        if (agentTable) {
            const ws2 = XLSX.utils.aoa_to_sheet(tableToAoa(agentTable, -1)); // no star column
            XLSX.utils.book_append_sheet(wb, ws2, 'By Agent');
        }

        XLSX.writeFile(wb, 'monthly_report_<?= $monthParam ?>.xlsx');
    }
</script>
</body>
</html>
<?php $conn->close(); ?>
