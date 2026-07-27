<?php

// 1. Always start the session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Check if the "user" key is empty or completely missing
if (empty($_SESSION['user'])) {
    // 3. Redirect to your login page
    header("Location: /pspf_crm/api/signin/index.php");
    exit; // 4. Stop executing the rest of the script
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
$UserDept       = $_SESSION['user']['division_name'] ?? '';
$UserDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

requireAnyRole(['agent', 'admin', 'superadmin']);

$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];
$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------
// SELECTED MONTH
// ---------------------------
// Accepts ?month=YYYY-MM ; defaults to the current month. Falls back to the
// current month if the value is malformed so the report never breaks.
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

// First day of the following month (exclusive upper bound).
$nextMonthStart = date('Y-m-01', strtotime('+1 month', $startTs));
$prevMonth      = date('Y-m', strtotime('-1 month', $startTs));
$nextMonth      = date('Y-m', strtotime('+1 month', $startTs));
$monthLabel     = date('F Y', $startTs);
$isCurrentMonth = ($monthParam === date('Y-m'));

// ---------------------------
// RESOLVED TICKETS FOR THE MONTH
// ---------------------------
// A ticket counts for the month if it FIRST reached a completed state
// (Resolved / Closed) during that month, taken from the status-change log --
// the same definition the dashboard KPIs use (see metrics_helpers.php). This is
// throughput: what the agent actually finished that month.
$reportSql = "
    SELECT
        t.id,
        t.title,
        t.priority,
        t.status,
        t.member_type,
        t.created_by,
        t.query_date,
        " . RESOLVED_AT_SQL . " AS resolved_at,
        TIMESTAMPDIFF(MINUTE, t.query_date, " . RESOLVED_AT_SQL . ") AS resolution_minutes,
        (SELECT tf.rating
           FROM ticket_feedback tf
          WHERE tf.ticket_id = t.id
          ORDER BY tf.created_at DESC
          LIMIT 1) AS rating
    FROM tickets t
    WHERE FIND_IN_SET(?, t.assigned_to)
    HAVING resolved_at IS NOT NULL
       AND resolved_at >= ?
       AND resolved_at < ?
    ORDER BY resolved_at ASC
";

$reportStmt = $conn->prepare($reportSql);
$reportStmt->bind_param("sss", $UserEmail, $monthStart, $nextMonthStart);
$reportStmt->execute();
$reportResult = $reportStmt->get_result();

$rows = [];
while ($r = $reportResult->fetch_assoc()) {
    $rows[] = $r;
}
$reportStmt->close();

// ---------------------------
// SUMMARY (computed in PHP over the fetched rows)
// ---------------------------
$totalResolved = count($rows);
$sumMinutes    = 0;
$countTimed    = 0;
$fastest       = null;
$slowest       = null;
$sumRating     = 0;
$countRated    = 0;
$priorityCounts = ['High' => 0, 'Medium' => 0, 'Low' => 0];

foreach ($rows as $r) {
    $mins = $r['resolution_minutes'];
    if ($mins !== null && is_numeric($mins) && $mins >= 0) {
        $sumMinutes += $mins;
        $countTimed++;
        if ($fastest === null || $mins < $fastest) $fastest = (int)$mins;
        if ($slowest === null || $mins > $slowest) $slowest = (int)$mins;
    }
    if ($r['rating'] !== null && is_numeric($r['rating'])) {
        $sumRating += (float)$r['rating'];
        $countRated++;
    }
    $p = $r['priority'] ?? '';
    if (isset($priorityCounts[$p])) {
        $priorityCounts[$p]++;
    }
}

$avgMinutes = $countTimed > 0 ? ($sumMinutes / $countTimed) : null;
$avgRating  = $countRated > 0 ? ($sumRating / $countRated) : null;

function badgeClassForStatus($status) {
    return match (strtolower(trim((string)$status))) {
        'open'            => 'bg-warning text-dark',
        'in progress'     => 'bg-info text-dark',
        'resolved',
        'closed'          => 'bg-success',
        'pending feedback'=> 'bg-primary',
        'escalate',
        'escalated'       => 'bg-danger',
        default           => 'bg-secondary',
    };
}
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
    <link rel="stylesheet" href="./agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- SheetJS (XLSX) for client-side Excel export -->
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <style>
        .report-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: end;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .month-nav {
            display: flex;
            gap: .5rem;
            align-items: end;
        }
        .summary-value { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
        .summary-label { color: var(--text-muted, #64748b); font-size: .8rem; }
        table.report-table th { white-space: nowrap; }
        @media print {
            .no-print, nav.navbar, .settings-actions, .report-toolbar .no-print { display: none !important; }
            .table-container, .stat-card { box-shadow: none !important; border: 1px solid #ddd; }
            a[href]:after { content: ""; }
        }
    </style>
</head>
<body>

<?php include './topnav_agent.php'; ?>

<div class="container-xl mt-4 mb-4">
    <!-- Header -->
    <div class="settings-header">
        <h1 class="settings-title">
            <i class="bi bi-calendar-check me-2"></i>Monthly Report
        </h1>
        <div class="settings-actions">
            <a href="./agent_dashboard.php" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Report identity (also used on the printed page) -->
    <div class="mb-3">
        <div class="fw-semibold fs-5"><?= htmlspecialchars($UserUsername) ?></div>
        <div class="text-muted small">
            <?php if (!empty($UserDept)): ?>
                <i class="bi bi-building me-1"></i><?= htmlspecialchars($UserDept) ?> &middot;
            <?php endif; ?>
            <i class="bi bi-calendar3 me-1"></i>Resolved tickets for <strong><?= htmlspecialchars($monthLabel) ?></strong>
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
        <div class="stat-card">
            <div class="stat-icon danger"><i class="bi bi-flag-fill"></i></div>
            <div class="summary-value" style="font-size:1.1rem;">
                <span class="badge bg-danger"><?= $priorityCounts['High'] ?> High</span>
                <span class="badge bg-warning text-dark"><?= $priorityCounts['Medium'] ?> Med</span>
                <span class="badge bg-success"><?= $priorityCounts['Low'] ?> Low</span>
            </div>
            <div class="summary-label">By Priority</div>
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
                        <th>Requester</th>
                        <th>Created</th>
                        <th>Resolved</th>
                        <th>Resolution Time</th>
                        <th>Rating</th>
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
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                No tickets were resolved in <?= htmlspecialchars($monthLabel) ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Build a clean sheet from the rendered table (star icons -> numeric rating).
    function exportExcel() {
        const table = document.getElementById('reportTable');
        if (!table) return;

        const data = [];
        // Header row
        const headers = [];
        table.querySelectorAll('thead th').forEach(th => headers.push(th.textContent.trim()));
        data.push(headers);

        // Body rows
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = tr.querySelectorAll('td');
            if (cells.length < 8) return; // skip the "no tickets" placeholder row
            const row = [];
            cells.forEach((td, idx) => {
                if (idx === 7) {
                    // Rating column: count filled stars
                    const filled = td.querySelectorAll('.bi-star-fill').length;
                    row.push(filled > 0 ? filled : '');
                } else {
                    row.push(td.textContent.trim());
                }
            });
            data.push(row);
        });

        const ws = XLSX.utils.aoa_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Resolved');
        XLSX.writeFile(wb, 'monthly_report_<?= $monthParam ?>.xlsx');
    }
</script>
</body>
</html>
<?php $conn->close(); ?>
