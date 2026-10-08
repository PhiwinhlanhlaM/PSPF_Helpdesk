<?php
require_once __DIR__ . '/trip_helpers.php';
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require_once __DIR__ . '/admin_stats.php';
require_once __DIR__ . '/partials/stats_charts.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

[$from, $to] = vbStatsPeriod($_GET);
$stats = vbCollectStats($conn, $from, $to);

// Quick period buttons: label => [from, to]
$today = new DateTimeImmutable('today');
$presets = [
    'This month'   => [$today->format('Y-m-01'), $today->format('Y-m-d')],
    'Last month'   => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
    'Last 90 days' => [$today->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d')],
    'This year'    => [$today->format('Y-01-01'), $today->format('Y-m-d')],
];
$periodLabel = date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
$exportUrl   = 'admin_stats_export.php?' . http_build_query(['from' => $from, 'to' => $to]);

$statusRows = [];
foreach ($stats['by_status'] as $status => $n) {
    $statusRows[] = ['label' => vbStatusLabel($status), 'value' => $n];
}
$roleRows = array_map(static fn($r) => ['label' => vbRoleLabel($r['label']), 'value' => $r['value']], $stats['users_by_role']);
$deptRows = array_slice($stats['by_department'], 0, 8);
if (count($stats['by_department']) > 8) {
    $deptRows[] = ['label' => 'Other', 'value' => array_sum(array_column(array_slice($stats['by_department'], 8), 'value'))];
}
$vehicleTrips = array_map(static fn($v) => ['label' => $v['label'], 'value' => $v['trips']], $stats['vehicle_usage']);
$unitWord = ['day' => 'day', 'week' => 'week', 'month' => 'month'][$stats['timeline_unit']];
$tokens = $stats['email_tokens'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Admin Dashboard'; require __DIR__ . '/partials/head.php'; ?>
    <style>
        .stats-root {
            --series-1: #2a78d6;   /* On the system / single-series bars */
            --series-2: #eb6834;   /* By email */
            --grid: var(--ink-200);
        }
        .stats-head { display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 1rem; }
        .stats-filter { display: flex; flex-wrap: wrap; align-items: end; gap: .5rem; }
        .stats-filter .form-control { width: auto; }
        .stats-presets { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .6rem; }

        .stat-tile { background: var(--vb-surface); border: 1px solid var(--vb-border); border-radius: var(--vb-radius);
                     box-shadow: var(--vb-shadow); padding: 1rem 1.1rem; height: 100%; }
        .stat-tile .stat-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-500); font-weight: 600; }
        .stat-tile .stat-value { font-size: 2rem; font-weight: 700; line-height: 1.15; color: var(--ink-900); }
        .stat-tile .stat-sub { font-size: .85rem; color: var(--ink-600); }
        .stat-tile .stat-icon { float: right; color: var(--pspf-600); opacity: .7; font-size: 1.2rem; }

        .chart-card { background: var(--vb-surface); border: 1px solid var(--vb-border); border-radius: var(--vb-radius);
                      box-shadow: var(--vb-shadow); padding: 1.1rem 1.2rem; height: 100%; }
        .chart-card h5 { font-size: 1rem; margin-bottom: .15rem; }
        .chart-card .chart-sub { font-size: .85rem; color: var(--ink-500); margin-bottom: .9rem; }
        .vb-chart-empty { color: var(--ink-500); font-style: italic; margin: 1.5rem 0; text-align: center; }

        /* Horizontal bars */
        .vb-hbars { display: flex; flex-direction: column; gap: .45rem; }
        .vb-hbar-row { display: grid; grid-template-columns: minmax(7rem, 38%) 1fr; align-items: center; gap: .75rem; font-size: .88rem; }
        .vb-hbar-label { color: var(--ink-700); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .vb-hbar-track { display: flex; align-items: center; gap: .45rem; padding-right: 3.5rem; min-height: 1.4rem; }
        .vb-hbar { flex: none; height: 14px; background: var(--series-1); border-radius: 0 4px 4px 0; }
        .vb-hbar-value { flex: none; color: var(--ink-700); font-variant-numeric: tabular-nums; font-weight: 600; }

        /* Stacked channel bars */
        .vb-stack { flex: none; display: flex; gap: 2px; height: 20px; }
        .vb-seg { min-width: 1.4rem; display: flex; align-items: center; justify-content: center; color: #fff;
                  font-size: .75rem; font-weight: 700; }
        .vb-seg:last-child { border-radius: 0 4px 4px 0; }
        .vb-s1 { background: var(--series-1); }
        .vb-s2 { background: var(--series-2); }
        .vb-legend { display: flex; gap: 1.1rem; font-size: .85rem; color: var(--ink-700); margin-bottom: .7rem; }
        .vb-swatch { display: inline-block; width: .8rem; height: .8rem; border-radius: 2px; margin-right: .35rem; vertical-align: -1px; }

        /* Columns over time */
        .vb-cols-wrap { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .5rem; }
        .vb-cols-axis { display: flex; flex-direction: column; justify-content: space-between; height: 180px;
                        font-size: .75rem; color: var(--ink-500); text-align: right; transform: translateY(-.45em); }
        .vb-cols { display: flex; align-items: flex-end; gap: 2px; height: 180px; border-bottom: 1px solid var(--ink-300);
                   background: linear-gradient(to bottom, var(--grid) 1px, transparent 1px) 0 0 / 100% 50%; }
        .vb-col-slot { flex: 1 1 0; min-width: 0; height: 100%; display: flex; align-items: flex-end; cursor: default; }
        .vb-col { width: 100%; max-width: 36px; margin: 0 auto; background: var(--series-1); border-radius: 4px 4px 0 0; }
        .vb-col-slot:hover .vb-col, .vb-hbar:hover, .vb-seg:hover { filter: brightness(1.12); }
        .vb-cols-x { display: flex; gap: 2px; margin-top: .3rem; }
        .vb-cols-x span { flex: 1 1 0; min-width: 0; font-size: .72rem; color: var(--ink-500); white-space: nowrap; overflow: visible; }

        #vb-tip { position: fixed; z-index: 2000; pointer-events: none; background: var(--ink-900); color: #fff;
                  font-size: .8rem; padding: .3rem .55rem; border-radius: 6px; box-shadow: var(--vb-shadow-lg); display: none; }

        .stats-table { font-size: .88rem; }
        .stats-table th { color: var(--ink-600); font-weight: 600; white-space: nowrap; }
        .stats-table td.num, .stats-table th.num { text-align: right; font-variant-numeric: tabular-nums; }

        .print-only { display: none; }
        @media print {
            .main-navbar, .vb-footer, .no-print { display: none !important; }
            .print-only { display: block; }
            body { background: #fff; }
            .container { max-width: none; }
            .chart-card, .stat-tile { box-shadow: none; break-inside: avoid; }
            .vb-hbar, .vb-col, .vb-seg, .vb-swatch { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-light">
    <?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5 mb-5 stats-root">
    <div class="no-print">
        <h3 class="text-primary">Admin Dashboard</h3>
        <p>Welcome, <?= htmlspecialchars(vbName($_SESSION['name'])) ?></p>

        <div class="row mt-4 g-3">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <h4>Manage Users</h4>
                        <p>Add, update, or remove system users and assign roles.</p>
                        <a href="manage_users.php" class="btn btn-primary">Go to Users</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <h4>Manage Vehicles</h4>
                        <p>Add new vehicles, update info, and manage availability.</p>
                        <a href="manage_vehicles.php" class="btn btn-success">Go to Vehicles</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== System statistics ===================== -->
    <div class="print-only mb-3">
        <img src="PSPFlogo.png" alt="PSPF" style="height:48px">
        <h3 class="mt-2 mb-0">Transport Booking System – Statistics</h3>
    </div>

    <div class="stats-head mt-5 mb-3">
        <div>
            <h4 class="mb-0">System Statistics</h4>
            <div class="text-muted">Reporting period: <strong><?= htmlspecialchars($periodLabel) ?></strong></div>
        </div>
        <div class="no-print">
            <form class="stats-filter" method="get">
                <div>
                    <label class="form-label small mb-1" for="from">From</label>
                    <input type="date" class="form-control form-control-sm" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
                </div>
                <div>
                    <label class="form-label small mb-1" for="to">To</label>
                    <input type="date" class="form-control form-control-sm" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
                </div>
                <button class="btn btn-primary btn-sm">Apply</button>
                <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars($exportUrl) ?>"><i class="fa fa-file-csv me-1"></i>Export CSV</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fa fa-print me-1"></i>Print / PDF</button>
            </form>
            <div class="stats-presets">
                <?php foreach ($presets as $label => [$pf, $pt]): ?>
                    <a class="btn btn-sm <?= ($pf === $from && $pt === $to) ? 'btn-secondary' : 'btn-outline-secondary' ?>"
                       href="?<?= htmlspecialchars(http_build_query(['from' => $pf, 'to' => $pt])) ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Headline numbers -->
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-file-lines stat-icon"></i>
                <div class="stat-label">Requests raised</div>
                <div class="stat-value"><?= number_format($stats['requests_total']) ?></div>
                <div class="stat-sub"><?= number_format($stats['pending']) ?> still awaiting a decision</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-list-check stat-icon"></i>
                <div class="stat-label">Requests actioned</div>
                <div class="stat-value"><?= number_format($stats['requests_actioned']) ?></div>
                <div class="stat-sub"><?= number_format($stats['approved_total']) ?> approvals · <?= number_format($stats['rejected_total']) ?> rejections</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-envelope-circle-check stat-icon"></i>
                <div class="stat-label">Actioned by email</div>
                <div class="stat-value"><?= number_format($stats['email_actions']) ?></div>
                <div class="stat-sub"><?= vbPct($stats['email_actions'], $stats['actions_total']) ?> of all decisions ·
                    <?= number_format($stats['email_approved']) ?> approved, <?= number_format($stats['email_rejected']) ?> rejected</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-users stat-icon"></i>
                <div class="stat-label">Users using the system</div>
                <div class="stat-value"><?= number_format($stats['users_engaged']) ?></div>
                <div class="stat-sub">raised or actioned a request · <?= number_format($stats['users_active']) ?> active accounts</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-right-to-bracket stat-icon"></i>
                <div class="stat-label">Sign-ins</div>
                <?php if ($stats['logins'] !== null): ?>
                    <div class="stat-value"><?= number_format($stats['logins']['total']) ?></div>
                    <div class="stat-sub">by <?= number_format($stats['logins']['users']) ?> different users · <?= number_format($stats['logins']['sso']) ?> via Helpdesk SSO</div>
                <?php else: ?>
                    <div class="stat-value">–</div>
                    <div class="stat-sub">Not tracked yet: run <code>sql/login_logs.sql</code></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-flag-checkered stat-icon"></i>
                <div class="stat-label">Trips completed</div>
                <div class="stat-value"><?= number_format($stats['trips_completed']) ?></div>
                <div class="stat-sub"><?= number_format($stats['km_total']) ?> km driven on trips raised in period</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-stopwatch stat-icon"></i>
                <div class="stat-label">Avg. time to full approval</div>
                <div class="stat-value"><?= $stats['avg_approval_hours'] === null ? '–' : htmlspecialchars((string)$stats['avg_approval_hours']) . '<small class="fs-6 fw-normal"> hrs</small>' ?></div>
                <div class="stat-sub">from submission to HRM approval</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-tile">
                <i class="fa fa-scroll stat-icon"></i>
                <div class="stat-label">Log entries</div>
                <div class="stat-value"><?= number_format($stats['logs_total']) ?></div>
                <div class="stat-sub"><?= number_format($stats['users_new']) ?> new user accounts in period</div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="chart-card">
                <h5>Requests raised per <?= $unitWord ?></h5>
                <div class="chart-sub">New vehicle requests submitted in the period</div>
                <?= vbChartColumns($stats['timeline']) ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="chart-card">
                <h5>How decisions were made</h5>
                <div class="chart-sub">Approvals, rejections and vehicle assignments, by stage and channel</div>
                <?= vbChartChannel($stats['channel'], VB_STAGE_LABELS) ?>
                <?php if ($tokens !== null && $tokens['sent'] > 0): ?>
                    <p class="small text-muted mt-3 mb-0">
                        <i class="fa fa-envelope me-1"></i><?= number_format($tokens['sent']) ?> email approval links sent ·
                        <?= number_format($tokens['used']) ?> used (<?= vbPct($tokens['used'], $tokens['sent']) ?>) ·
                        <?= number_format($tokens['expired']) ?> expired unused
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="chart-card">
                <h5>Requests by status</h5>
                <div class="chart-sub">Current status of requests raised in the period</div>
                <?= vbChartBars($statusRows) ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <h5>Requests by department</h5>
                <div class="chart-sub">Who is using transport the most</div>
                <?= vbChartBars($deptRows) ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="chart-card">
                <h5>Trips per vehicle</h5>
                <div class="chart-sub">Approved and completed trips raised in the period</div>
                <?= vbChartBars($vehicleTrips, '', 'No vehicles registered.') ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <h5>Active accounts by role</h5>
                <div class="chart-sub">All active user accounts (not limited to the period)</div>
                <?= vbChartBars($roleRows) ?>
            </div>
        </div>
    </div>

    <!-- Tables -->
    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="chart-card">
                <h5>Most active users</h5>
                <div class="chart-sub">Requests submitted and actions taken in the period</div>
                <?php if (!$stats['top_users']): ?>
                    <p class="vb-chart-empty">No activity in this period.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm stats-table mb-0">
                        <thead><tr><th>User</th><th>Role</th><th class="num">Submitted</th><th class="num">Actioned</th><th class="num">By email</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['top_users'] as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars(vbName($u['name'])) ?><div class="small text-muted"><?= htmlspecialchars($u['department'] ?? '') ?></div></td>
                                <td><?= htmlspecialchars(vbRoleLabel($u['role'])) ?></td>
                                <td class="num"><?= (int)$u['submitted'] ?></td>
                                <td class="num"><?= (int)$u['actioned'] ?></td>
                                <td class="num"><?= (int)$u['via_email'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <h5>Vehicle usage</h5>
                <div class="chart-sub">Trips and distance (mileage in − mileage out)</div>
                <div class="table-responsive">
                    <table class="table table-sm stats-table mb-0">
                        <thead><tr><th>Vehicle</th><th class="num">Trips</th><th class="num">Km</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['vehicle_usage'] as $v): ?>
                            <tr>
                                <td><?= htmlspecialchars(trim($v['label'])) ?></td>
                                <td class="num"><?= number_format((int)$v['trips']) ?></td>
                                <td class="num"><?= number_format((int)$v['km']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="chart-card">
                <h5>Activity log</h5>
                <div class="chart-sub">
                    Latest <?= min(25, $stats['logs_total']) ?> of <?= number_format($stats['logs_total']) ?> entries in the period ·
                    the CSV export contains the full log. Click a row for the request details.
                </div>
                <?php if (!$stats['recent_logs']): ?>
                    <p class="vb-chart-empty">No activity in this period.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover stats-table mb-0">
                        <thead><tr><th>When</th><th>Request</th><th>Action</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['recent_logs'] as $l): ?>
                            <tr<?= $l['request_id'] ? ' data-request-id="' . (int)$l['request_id'] . '" style="cursor:pointer"' : '' ?>>
                                <td class="text-nowrap"><?= htmlspecialchars(date('j M Y H:i', strtotime($l['created_at']))) ?></td>
                                <td><?= $l['request_id'] ? '#' . (int)$l['request_id'] : '–' ?></td>
                                <td>
                                    <?= htmlspecialchars($l['action']) ?>
                                    <?php if (stripos($l['action'], '(via email)') !== false): ?>
                                        <span class="badge bg-light text-dark border"><i class="fa fa-envelope me-1"></i>Email</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(vbName($l['name'] ?? '')) ?: '<span class="text-muted">System</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <p class="print-only small text-muted mt-3">Generated <?= date('j M Y H:i') ?> by <?= htmlspecialchars(vbName($_SESSION['name'])) ?></p>
</div>

<div id="vb-tip" role="tooltip"></div>
<script>
(function () {
    var tip = document.getElementById('vb-tip');
    document.addEventListener('mousemove', function (e) {
        var el = e.target.closest ? e.target.closest('[data-tip]') : null;
        if (!el) { tip.style.display = 'none'; return; }
        tip.textContent = el.getAttribute('data-tip');
        tip.style.display = 'block';
        var x = e.clientX + 12, y = e.clientY - tip.offsetHeight - 10;
        if (x + tip.offsetWidth > window.innerWidth - 8) x = e.clientX - tip.offsetWidth - 12;
        if (y < 8) y = e.clientY + 16;
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
    });
})();
</script>
<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
