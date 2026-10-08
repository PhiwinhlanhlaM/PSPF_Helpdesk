<?php
/**
 * The "Statistics" section shared by the Administrator dashboard, the
 * driver's Transport Report and the HRM Report.
 *
 * Usage (inside the page's main .container):
 *     $statsView  = 'driver';                 // key of VB_STATS_VIEWS
 *     $statsTitle = 'Fleet Statistics';       // optional heading
 *     require __DIR__ . '/partials/stats_section.php';
 *
 * Needs $conn (db.php). The reporting period comes from ?from=&to= in the
 * URL (default: this month). Any other query-string values are kept when
 * the period changes.
 */
require_once dirname(__DIR__) . '/admin_stats.php';
require_once __DIR__ . '/stats_charts.php';

$statsTitle = $statsTitle ?? 'System Statistics';
$show = VB_STATS_VIEWS[$statsView];
$has  = static fn(string $group, string $key): bool => in_array($key, $show[$group], true);

[$from, $to] = vbStatsPeriod($_GET);
$stats = vbCollectStats($conn, $from, $to);

// Links keep any other query-string values the page uses.
$keepQuery = array_diff_key($_GET, ['from' => 1, 'to' => 1]);
$periodHref = static fn(string $f, string $t): string => '?' . http_build_query($keepQuery + ['from' => $f, 'to' => $t]);

$today = new DateTimeImmutable('today');
$presets = [
    'This month'   => [$today->format('Y-m-01'), $today->format('Y-m-d')],
    'Last month'   => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
    'Last 90 days' => [$today->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d')],
    'This year'    => [$today->format('Y-01-01'), $today->format('Y-m-d')],
];
$periodLabel = date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
$exportUrl   = 'admin_stats_export.php?' . http_build_query(['from' => $from, 'to' => $to, 'view' => $statsView]);

$statusRows = [];
foreach ($stats['by_status'] as $status => $n) {
    $statusRows[] = ['label' => vbStatusLabel($status), 'value' => $n];
}
$roleRows = array_map(static fn($r) => ['label' => vbRoleLabel($r['label']), 'value' => $r['value']], $stats['users_by_role']);
$deptRows = array_slice($stats['by_department'], 0, 8);
if (count($stats['by_department']) > 8) {
    $deptRows[] = ['label' => 'Other', 'value' => array_sum(array_column(array_slice($stats['by_department'], 8), 'value'))];
}
$vehicleTrips = array_map(static fn($v) => ['label' => trim($v['label']), 'value' => $v['trips']], $stats['vehicle_usage']);
$vehicleKm    = array_map(static fn($v) => ['label' => trim($v['label']), 'value' => $v['km']], $stats['vehicle_usage']);
usort($vehicleKm, static fn($a, $b) => $b['value'] <=> $a['value']);
$tokens = $stats['email_tokens'];

// Headline tiles: key => [icon, label, value HTML, sub-line HTML]
$e = static fn($v) => htmlspecialchars((string)$v);
$tiles = [
    'requests' => ['fa-file-lines', 'Requests raised', number_format($stats['requests_total']),
        number_format($stats['pending']) . ' still awaiting a decision'],
    'actioned' => ['fa-list-check', 'Requests actioned', number_format($stats['requests_actioned']),
        number_format($stats['approved_total']) . ' approvals · ' . number_format($stats['rejected_total']) . ' rejections'],
    'email' => ['fa-envelope-circle-check', 'Actioned by email', number_format($stats['email_actions']),
        vbPct($stats['email_actions'], $stats['actions_total']) . ' of all decisions · '
        . number_format($stats['email_approved']) . ' approved, ' . number_format($stats['email_rejected']) . ' rejected'],
    'users' => ['fa-users', 'Users using the system', number_format($stats['users_engaged']),
        'raised or actioned a request · ' . number_format($stats['users_active']) . ' active accounts'],
    'logins' => $stats['logins'] !== null
        ? ['fa-right-to-bracket', 'Sign-ins', number_format($stats['logins']['total']),
           'by ' . number_format($stats['logins']['users']) . ' different users · ' . number_format($stats['logins']['sso']) . ' via Helpdesk SSO']
        : ['fa-right-to-bracket', 'Sign-ins', '–', 'Not tracked yet: run <code>sql/login_logs.sql</code>'],
    'trips' => ['fa-flag-checkered', 'Trips completed', number_format($stats['trips_completed']),
        'vehicles returned in the period'],
    'km' => ['fa-road', 'Km driven', number_format($stats['km_total']),
        'on approved and completed trips raised in the period'],
    'on_road' => ['fa-car-side', 'On the road now', number_format($stats['on_road_now']),
        'approved trips that have left and are not yet returned'],
    'pending' => ['fa-hourglass-half', 'Awaiting HRM', number_format($stats['by_status']['pending_hrm']),
        number_format($stats['pending']) . ' awaiting a decision at any stage'],
    'approval_rate' => ['fa-circle-check', 'Approval rate', $stats['approval_rate'],
        number_format($stats['approved_requests']) . ' approved · ' . number_format($stats['by_status']['rejected']) . ' rejected'],
    'avg_approval' => ['fa-stopwatch', 'Avg. time to full approval',
        $stats['avg_approval_hours'] === null ? '–' : $e($stats['avg_approval_hours']) . '<small class="fs-6 fw-normal"> hrs</small>',
        'from submission to HRM approval'],
    'logs' => ['fa-scroll', 'Log entries', number_format($stats['logs_total']),
        number_format($stats['users_new']) . ' new user accounts in period'],
];

$unitWord = $stats['timeline_unit'];
$charts = [
    'timeline'      => ['col-lg-7', 'Requests raised per ' . $unitWord, 'New vehicle requests submitted in the period',
                        static fn() => vbChartColumns($stats['timeline'])],
    'channel'       => ['col-lg-5', 'How decisions were made', 'Approvals, rejections and vehicle assignments, by stage and channel',
                        static function () use ($stats, $tokens) {
                            $html = vbChartChannel($stats['channel'], VB_STAGE_LABELS);
                            if ($tokens !== null && $tokens['sent'] > 0) {
                                $html .= '<p class="small text-muted mt-3 mb-0"><i class="fa fa-envelope me-1"></i>'
                                       . number_format($tokens['sent']) . ' email approval links sent · '
                                       . number_format($tokens['used']) . ' used (' . vbPct($tokens['used'], $tokens['sent']) . ') · '
                                       . number_format($tokens['expired']) . ' expired unused</p>';
                            }
                            return $html;
                        }],
    'status'        => ['col-lg-6', 'Requests by status', 'Current status of requests raised in the period',
                        static fn() => vbChartBars($statusRows)],
    'department'    => ['col-lg-6', 'Requests by department', 'Who is using transport the most',
                        static fn() => vbChartBars($deptRows)],
    'vehicle_trips' => ['col-lg-6', 'Trips per vehicle', 'Approved and completed trips raised in the period',
                        static fn() => vbChartBars($vehicleTrips, '', 'No vehicles registered.')],
    'vehicle_km'    => ['col-lg-6', 'Distance per vehicle', 'Km driven (mileage in − mileage out)',
                        static fn() => vbChartBars($vehicleKm, 'km', 'No vehicles registered.')],
    'roles'         => ['col-lg-6', 'Active accounts by role', 'All active user accounts (not limited to the period)',
                        static fn() => vbChartBars($roleRows)],
];
// The timeline and channel charts share a row 7/5; alone, the timeline is half width like the rest.
if (!$has('charts', 'channel')) $charts['timeline'][0] = 'col-lg-6';
if (!$has('charts', 'timeline')) $charts['channel'][0] = 'col-lg-6';
?>
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
    .vb-hbar-track { display: flex; align-items: center; gap: .45rem; padding-right: 4.5rem; min-height: 1.4rem; }
    .vb-hbar { flex: none; height: 14px; background: var(--series-1); border-radius: 0 4px 4px 0; }
    .vb-hbar-value { flex: none; color: var(--ink-700); font-variant-numeric: tabular-nums; font-weight: 600; white-space: nowrap; }

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
    .stats-table tfoot td { font-weight: 700; border-top: 2px solid var(--ink-300); }

    .print-only { display: none; }
    @media print {
        .main-navbar, .vb-footer, .no-print { display: none !important; }
        /* Print / PDF prints only the statistics, not the rest of the page. */
        body.vb-printing-stats .container > :not(.stats-root):not(:has(.stats-root)) { display: none !important; }
        .print-only { display: block; }
        body { background: #fff; }
        .container { max-width: none; }
        .chart-card, .stat-tile { box-shadow: none; break-inside: avoid; height: auto; }
        .stats-head h4 { display: none; }   /* the print heading above already names the report */
        .vb-hbar, .vb-col, .vb-seg, .vb-swatch { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<section class="stats-root" id="statistics">
    <div class="print-only mb-3">
        <img src="PSPFlogo.png" alt="PSPF" style="height:48px">
        <h3 class="mt-2 mb-0">Transport Booking System – <?= $e($statsTitle) ?></h3>
    </div>

    <div class="stats-head mb-3">
        <div>
            <h4 class="mb-0"><?= $e($statsTitle) ?></h4>
            <div class="text-muted">Reporting period: <strong><?= $e($periodLabel) ?></strong></div>
        </div>
        <div class="no-print">
            <form class="stats-filter" method="get" action="#statistics">
                <?php foreach ($keepQuery as $k => $v): if (is_scalar($v)): ?>
                    <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
                <?php endif; endforeach; ?>
                <div>
                    <label class="form-label small mb-1" for="stats-from">From</label>
                    <input type="date" class="form-control form-control-sm" id="stats-from" name="from" value="<?= $e($from) ?>">
                </div>
                <div>
                    <label class="form-label small mb-1" for="stats-to">To</label>
                    <input type="date" class="form-control form-control-sm" id="stats-to" name="to" value="<?= $e($to) ?>">
                </div>
                <button class="btn btn-primary btn-sm">Apply</button>
                <a class="btn btn-outline-success btn-sm" href="<?= $e($exportUrl) ?>"><i class="fa fa-file-csv me-1"></i>Export CSV</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="vbPrintStats()"><i class="fa fa-print me-1"></i>Print / PDF</button>
            </form>
            <div class="stats-presets">
                <?php foreach ($presets as $label => [$pf, $pt]): ?>
                    <a class="btn btn-sm <?= ($pf === $from && $pt === $to) ? 'btn-secondary' : 'btn-outline-secondary' ?>"
                       href="<?= $e($periodHref($pf, $pt)) ?>#statistics"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Headline numbers -->
    <div class="row g-3">
        <?php foreach ($show['tiles'] as $key): [$icon, $label, $value, $sub] = $tiles[$key]; ?>
            <div class="col-6 col-lg-3">
                <div class="stat-tile">
                    <i class="fa <?= $icon ?> stat-icon"></i>
                    <div class="stat-label"><?= $e($label) ?></div>
                    <div class="stat-value"><?= $value ?></div>
                    <div class="stat-sub"><?= $sub ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="row g-3 mt-1">
        <?php foreach ($show['charts'] as $key): [$col, $title, $sub, $render] = $charts[$key]; ?>
            <div class="<?= $col ?>">
                <div class="chart-card">
                    <h5><?= $e($title) ?></h5>
                    <div class="chart-sub"><?= $e($sub) ?></div>
                    <?= $render() ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Tables -->
    <div class="row g-3 mt-1">
        <?php if ($has('tables', 'department_summary')): ?>
        <div class="col-12">
            <div class="chart-card">
                <h5>Department summary</h5>
                <div class="chart-sub">Requests raised in the period by department, their outcome and distance driven</div>
                <?php if (!$stats['department_summary']): ?>
                    <p class="vb-chart-empty">No requests were raised in this period.</p>
                <?php else: $sum = ['requests' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0, 'completed' => 0, 'km' => 0]; ?>
                <div class="table-responsive">
                    <table class="table table-sm stats-table mb-0">
                        <thead><tr><th>Department</th><th class="num">Requests</th><th class="num">Approved</th><th class="num">Rejected</th>
                            <th class="num">Pending</th><th class="num">Trips completed</th><th class="num">Km</th><th class="num">Share of requests</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['department_summary'] as $d): foreach ($sum as $k => $_) $sum[$k] += (int)$d[$k]; ?>
                            <tr>
                                <td><?= $e($d['department']) ?></td>
                                <td class="num"><?= number_format((int)$d['requests']) ?></td>
                                <td class="num"><?= number_format((int)$d['approved']) ?></td>
                                <td class="num"><?= number_format((int)$d['rejected']) ?></td>
                                <td class="num"><?= number_format((int)$d['pending']) ?></td>
                                <td class="num"><?= number_format((int)$d['completed']) ?></td>
                                <td class="num"><?= number_format((int)$d['km']) ?></td>
                                <td class="num"><?= vbPct((int)$d['requests'], $stats['requests_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td>Total</td>
                            <?php foreach ($sum as $v): ?><td class="num"><?= number_format($v) ?></td><?php endforeach; ?>
                            <td class="num">100%</td></tr></tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($has('tables', 'top_users')): ?>
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
                                <td><?= $e(vbName($u['name'])) ?><div class="small text-muted"><?= $e($u['department'] ?? '') ?></div></td>
                                <td><?= $e(vbRoleLabel($u['role'])) ?></td>
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
        <?php endif; ?>

        <?php if ($has('tables', 'vehicle_usage')): ?>
        <div class="<?= $has('tables', 'top_users') ? 'col-lg-6' : 'col-12' ?>">
            <div class="chart-card">
                <h5>Vehicle usage</h5>
                <div class="chart-sub">Approved and completed trips raised in the period, and distance (mileage in − mileage out)</div>
                <div class="table-responsive">
                    <table class="table table-sm stats-table mb-0">
                        <thead><tr><th>Vehicle</th><th class="num">Trips</th><th class="num">Km</th><th class="num">Avg. km per trip</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['vehicle_usage'] as $v): ?>
                            <tr>
                                <td><?= $e(trim($v['label'])) ?></td>
                                <td class="num"><?= number_format((int)$v['trips']) ?></td>
                                <td class="num"><?= number_format((int)$v['km']) ?></td>
                                <td class="num"><?= (int)$v['trips'] > 0 ? number_format((int)$v['km'] / (int)$v['trips']) : '–' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($has('tables', 'activity')): ?>
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
                                <td class="text-nowrap"><?= $e(date('j M Y H:i', strtotime($l['created_at']))) ?></td>
                                <td><?= $l['request_id'] ? '#' . (int)$l['request_id'] : '–' ?></td>
                                <td>
                                    <?= $e($l['action']) ?>
                                    <?php if (stripos($l['action'], '(via email)') !== false): ?>
                                        <span class="badge bg-light text-dark border"><i class="fa fa-envelope me-1"></i>Email</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $e(vbName($l['name'] ?? '')) ?: '<span class="text-muted">System</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <p class="print-only small text-muted mt-3">Generated <?= date('j M Y H:i') ?> by <?= $e(vbName($_SESSION['name'] ?? '')) ?></p>
</section>

<div id="vb-tip" role="tooltip"></div>
<script>
function vbPrintStats() {
    document.body.classList.add('vb-printing-stats');
    window.print();
}
window.addEventListener('afterprint', function () { document.body.classList.remove('vb-printing-stats'); });
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
