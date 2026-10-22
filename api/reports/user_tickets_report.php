<?php
// reports/user_tickets_report.php
// Extract tickets logged by selected users over a chosen timeframe, with
// optional status / priority / query type / division filters, and export the
// result to Excel or CSV.
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/ticket_status_functions.php';
require_once '../db.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

requireAnyRole(['admin', 'superadmin']);

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDept      = $_SESSION['user']['department'] ?? '';
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];

$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------
// Filter inputs
// ---------------------------
$selectedUsers = array_values(array_unique(array_filter(
    array_map('trim', (array)($_GET['users'] ?? [])),
    'strlen'
)));
$dateFrom       = $_GET['date_from']  ?? '';
$dateTo         = $_GET['date_to']    ?? '';
$statusFilter   = $_GET['status']     ?? '';
$priorityFilter = $_GET['priority']   ?? '';
$queryTypeFilter= $_GET['query_type'] ?? '';
$divisionFilter = $isSuperAdmin ? (int)($_GET['division'] ?? 0) : 0;
$export         = $_GET['export']     ?? '';
$hasRun         = isset($_GET['run']) || $export !== '';

// Only accept real Y-m-d dates so a malformed value can't widen the range.
$validDate = function ($d) {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
};
if ($dateFrom !== '' && !$validDate($dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !$validDate($dateTo))   $dateTo   = '';

// ---------------------------
// Lookup lists for the filter form
// ---------------------------
$allUsers = $conn->query("
    SELECT u.username, u.department, d.division_name
    FROM users u
    LEFT JOIN divisions d ON u.division_id = d.id
    ORDER BY u.username
")->fetch_all(MYSQLI_ASSOC);

$allDivisions = $conn->query("SELECT id, division_name FROM divisions ORDER BY division_name")
    ->fetch_all(MYSQLI_ASSOC);

$allStatuses = array_column(
    $conn->query("SELECT DISTINCT status FROM tickets WHERE status IS NOT NULL AND status <> '' ORDER BY status")
        ->fetch_all(MYSQLI_ASSOC),
    'status'
);

$allPriorities = array_column(
    $conn->query("SELECT DISTINCT priority FROM tickets WHERE priority <> '' ORDER BY priority")
        ->fetch_all(MYSQLI_ASSOC),
    'priority'
);

$allQueryTypes = array_column(
    $conn->query("SELECT DISTINCT query_type FROM tickets WHERE query_type <> '' ORDER BY query_type")
        ->fetch_all(MYSQLI_ASSOC),
    'query_type'
);

// ---------------------------
// Build query
// ---------------------------
$whereConditions = [];
$params = [];
$types = '';

if ($selectedUsers) {
    $whereConditions[] = 't.created_by IN (' . implode(',', array_fill(0, count($selectedUsers), '?')) . ')';
    foreach ($selectedUsers as $u) {
        $params[] = $u;
        $types .= 's';
    }
}

if ($dateFrom) {
    $whereConditions[] = 't.query_date >= ?';
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}

if ($dateTo) {
    $whereConditions[] = 't.query_date < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
    $types .= 's';
}

if ($statusFilter !== '') {
    $whereConditions[] = 't.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($priorityFilter !== '') {
    $whereConditions[] = 't.priority = ?';
    $params[] = $priorityFilter;
    $types .= 's';
}

if ($queryTypeFilter !== '') {
    $whereConditions[] = 't.query_type = ?';
    $params[] = $queryTypeFilter;
    $types .= 's';
}

// Admins only see tickets routed to their own division (same scope as admin_view).
if ($isAdmin && $UserDivisionId > 0) {
    $whereConditions[] = 't.division_id = ?';
    $params[] = $UserDivisionId;
    $types .= 'i';
} elseif ($divisionFilter > 0) {
    $whereConditions[] = 't.division_id = ?';
    $params[] = $divisionFilter;
    $types .= 'i';
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$rows = [];
if ($hasRun) {
    $sql = "
        SELECT t.id, t.title, t.query_type, t.status, t.priority, t.source, t.region,
               t.member_type, t.query_date, t.created_by, t.assigned_to, t.updated_at,
               u.department AS creator_department, d.division_name
        FROM tickets t
        LEFT JOIN users u     ON t.created_by  = u.username
        LEFT JOIN divisions d ON t.division_id = d.id
        $whereClause
        ORDER BY t.created_by, t.query_date DESC
    ";
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Per-user summary
$summary = [];
foreach ($rows as $r) {
    $key = $r['created_by'];
    if (!isset($summary[$key])) {
        $summary[$key] = ['user' => $key, 'department' => $r['creator_department'] ?? '', 'total' => 0, 'statuses' => []];
    }
    $summary[$key]['total']++;
    $st = $r['status'] ?: 'Unknown';
    $summary[$key]['statuses'][$st] = ($summary[$key]['statuses'][$st] ?? 0) + 1;
}
$summaryStatuses = [];
foreach ($summary as $s) {
    $summaryStatuses = array_merge($summaryStatuses, array_keys($s['statuses']));
}
$summaryStatuses = array_values(array_unique($summaryStatuses));
sort($summaryStatuses);

$fmtTicket = fn($id) => 'TCK-' . str_pad($id, 6, '0', STR_PAD_LEFT);

// ---------------------------
// Export (before any HTML output)
// ---------------------------
if ($export === 'excel' || $export === 'csv') {
    $columns = ['Ticket ID', 'Title', 'Query Type', 'Status', 'Priority', 'Source', 'Branch',
                'Member Type', 'Logged By', 'Logged By Department', 'Division', 'Assigned To',
                'Date Logged', 'Last Updated'];
    $toRow = fn($r) => [
        $fmtTicket($r['id']), $r['title'], $r['query_type'], $r['status'], $r['priority'],
        $r['source'], $r['region'], $r['member_type'], $r['created_by'],
        $r['creator_department'] ?? '', $r['division_name'] ?? '', $r['assigned_to'] ?? '',
        $r['query_date'], $r['updated_at'],
    ];

    $period = ($dateFrom ?: 'start') . '_to_' . ($dateTo ?: date('Y-m-d'));
    $base   = 'user_tickets_report_' . $period . '_' . date('His');
    $conn->close();

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        header('Cache-Control: max-age=0');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
        fputcsv($out, $columns);
        foreach ($rows as $r) {
            fputcsv($out, $toRow($r));
        }
        fclose($out);
        exit;
    }

    // XLS (HTML table trick - same approach as admin/export_admin_excel.php)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<meta charset="utf-8">';
    echo '<table border="1">';
    echo '<tr><th colspan="' . count($columns) . '">Tickets Logged by Users Report</th></tr>';
    echo '<tr><td colspan="' . count($columns) . '">Users: '
        . htmlspecialchars($selectedUsers ? implode(', ', $selectedUsers) : 'All users')
        . ' | Period: ' . htmlspecialchars(($dateFrom ?: 'Any') . ' to ' . ($dateTo ?: 'Any'))
        . ' | Total tickets: ' . count($rows) . '</td></tr>';
    echo '<tr>';
    foreach ($columns as $c) echo '<th>' . htmlspecialchars($c) . '</th>';
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($toRow($r) as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
        echo '</tr>';
    }

    if ($summary) {
        echo '<tr><td colspan="' . count($columns) . '"></td></tr>';
        echo '<tr><th colspan="' . count($columns) . '">Summary by User</th></tr>';
        echo '<tr><th>User</th><th>Department</th><th>Total</th>';
        foreach ($summaryStatuses as $st) echo '<th>' . htmlspecialchars($st) . '</th>';
        echo '</tr>';
        foreach ($summary as $s) {
            echo '<tr><td>' . htmlspecialchars($s['user']) . '</td><td>' . htmlspecialchars($s['department']) . '</td><td>' . $s['total'] . '</td>';
            foreach ($summaryStatuses as $st) echo '<td>' . ($s['statuses'][$st] ?? 0) . '</td>';
            echo '</tr>';
        }
    }
    echo '</table>';
    exit;
}

$exportQuery = function ($type) {
    $q = $_GET;
    $q['export'] = $type;
    return '?' . http_build_query($q);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Tickets Report - PSPF CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../agent/agent_style.css">

    <style>
        :root {
            --primary-blue: #406997;
            --secondary-blue: #3D5C80;
        }

        .filter-card {
            background: #406997;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .user-picker {
            background: #fff;
            border-radius: 6px;
            padding: 8px;
        }

        .user-picker-list {
            max-height: 220px;
            overflow-y: auto;
            border-top: 1px solid #dee2e6;
            margin-top: 8px;
            padding-top: 6px;
        }

        .user-picker-list .form-check {
            padding: 2px 0 2px 1.75rem;
        }

        .user-picker-list small {
            color: #6c757d;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
        }

        @media print {
            .filter-card, .navbar, .settings-actions, .export-buttons, footer { display: none !important; }
        }
    </style>
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<div class="container mt-4">
    <div class="settings-header">
        <h1 class="settings-title"><i class="bi bi-person-lines-fill me-2"></i>Tickets Logged by Users</h1>
        <div class="settings-actions">
            <button onclick="history.back()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card mt-3">
        <h5 class="text-white"><i class="bi bi-funnel"></i> Report Filters</h5>
        <form method="GET" class="row g-3" id="reportForm">
            <input type="hidden" name="run" value="1">

            <div class="col-lg-5">
                <label class="form-label text-white">
                    Users <small>(<span id="selectedCount"><?= count($selectedUsers) ?></span> selected &mdash; none selected means all users)</small>
                </label>
                <div class="user-picker">
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="userSearch" placeholder="Search users or department...">
                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="selectVisible">Select shown</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearUsers">Clear</button>
                    </div>
                    <div class="user-picker-list" id="userList">
                        <?php foreach ($allUsers as $i => $u): ?>
                        <div class="form-check user-option"
                             data-search="<?= htmlspecialchars(strtolower($u['username'] . ' ' . $u['department'] . ' ' . ($u['division_name'] ?? ''))) ?>">
                            <input class="form-check-input user-checkbox" type="checkbox" name="users[]"
                                   id="user_<?= $i ?>" value="<?= htmlspecialchars($u['username']) ?>"
                                   <?= in_array($u['username'], $selectedUsers, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="user_<?= $i ?>">
                                <?= htmlspecialchars($u['username']) ?>
                                <?php if (!empty($u['department'])): ?>
                                    <small>&middot; <?= htmlspecialchars($u['department']) ?></small>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-white">Quick Range</label>
                        <select class="form-select" id="quickRange">
                            <option value="">Custom</option>
                            <option value="today">Today</option>
                            <option value="7">Last 7 days</option>
                            <option value="30">Last 30 days</option>
                            <option value="this_month">This month</option>
                            <option value="last_month">Last month</option>
                            <option value="this_year">This year</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white">Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white">Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label text-white">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($allStatuses as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-white">Priority</label>
                        <select class="form-select" name="priority">
                            <option value="">All Priorities</option>
                            <?php foreach ($allPriorities as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $priorityFilter === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-white">Query Type</label>
                        <select class="form-select" name="query_type">
                            <option value="">All Types</option>
                            <?php foreach ($allQueryTypes as $q): ?>
                            <option value="<?= htmlspecialchars($q) ?>" <?= $queryTypeFilter === $q ? 'selected' : '' ?>><?= htmlspecialchars($q) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($isSuperAdmin): ?>
                    <div class="col-md-6">
                        <label class="form-label text-white">Division (ticket routed to)</label>
                        <select class="form-select" name="division">
                            <option value="0">All Divisions</option>
                            <?php foreach ($allDivisions as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $divisionFilter === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['division_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="col-md-6 d-flex align-items-end">
                        <small class="text-white-50">Showing tickets routed to your division only.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Apply Filters
                </button>
                <a href="user_tickets_report.php" class="btn btn-info">
                    <i class="bi bi-x-circle"></i> Clear Filters
                </a>
            </div>
        </form>
    </div>

    <?php if ($hasRun): ?>
    <!-- Export -->
    <div class="export-buttons d-flex flex-wrap gap-2 mb-3">
        <a href="<?= htmlspecialchars($exportQuery('excel')) ?>" class="btn btn-success <?= $rows ? '' : 'disabled' ?>">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </a>
        <a href="<?= htmlspecialchars($exportQuery('csv')) ?>" class="btn btn-outline-success <?= $rows ? '' : 'disabled' ?>">
            <i class="bi bi-filetype-csv"></i> Export CSV
        </a>
        <button class="btn btn-secondary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <i class="bi bi-ticket-perforated fs-2"></i>
                <div class="stat-number"><?= count($rows) ?></div>
                <div>Tickets Found</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <i class="bi bi-people fs-2"></i>
                <div class="stat-number"><?= count($summary) ?></div>
                <div>Users with Tickets</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <i class="bi bi-calendar-range fs-2"></i>
                <div class="stat-number" style="font-size:1.1rem; padding:14px 0;">
                    <?= htmlspecialchars(($dateFrom ?: 'Any') . ' → ' . ($dateTo ?: 'Any')) ?>
                </div>
                <div>Period</div>
            </div>
        </div>
    </div>

    <?php if ($summary): ?>
    <!-- Per-user summary -->
    <div class="card mb-4">
        <div class="card-header card-color text-white">
            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Summary by User</h5>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Department</th>
                        <th>Total</th>
                        <?php foreach ($summaryStatuses as $st): ?>
                        <th><?= htmlspecialchars($st) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['user']) ?></td>
                        <td><?= htmlspecialchars($s['department']) ?></td>
                        <td><strong><?= $s['total'] ?></strong></td>
                        <?php foreach ($summaryStatuses as $st): ?>
                        <td><?= $s['statuses'][$st] ?? 0 ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ticket list -->
    <div class="card mb-4">
        <div class="card-header card-color text-white">
            <h5 class="mb-0">
                <i class="bi bi-table"></i> Tickets
                <span class="badge bg-secondary ms-2"><?= count($rows) ?> records</span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!$rows): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted"></i>
                <h5 class="mt-3">No tickets match these filters</h5>
                <p class="text-muted">Try widening the date range or selecting more users.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="ticketsTable">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Title</th>
                            <th>Query Type</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Logged By</th>
                            <th>Division</th>
                            <th>Assigned To</th>
                            <th>Date Logged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-nowrap"><strong><?= $fmtTicket($r['id']) ?></strong></td>
                            <td><?= htmlspecialchars($r['title']) ?></td>
                            <td><?= htmlspecialchars($r['query_type']) ?></td>
                            <td>
                                <span class="badge bg-<?= getStatusColor($r['status']) ?>">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($r['priority']) ?></td>
                            <td>
                                <?= htmlspecialchars($r['created_by']) ?>
                                <?php if (!empty($r['creator_department'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($r['creator_department']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['division_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['assigned_to'] ?? '') ?></td>
                            <td class="text-nowrap" data-order="<?= htmlspecialchars($r['query_date']) ?>">
                                <?= date('M d, Y H:i', strtotime($r['query_date'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Select one or more users, choose a timeframe and any other filters, then click <strong>Apply Filters</strong> to generate the report.
    </div>
    <?php endif; ?>
</div>

<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<script>
$(function () {
    if ($('#ticketsTable').length) {
        $('#ticketsTable').DataTable({ pageLength: 25, order: [[8, 'desc']] });
    }

    function updateCount() {
        $('#selectedCount').text($('.user-checkbox:checked').length);
    }

    // Filter the user list as you type
    $('#userSearch').on('input', function () {
        var term = this.value.toLowerCase().trim();
        $('.user-option').each(function () {
            $(this).toggle(!term || $(this).data('search').indexOf(term) !== -1);
        });
    });

    $('#selectVisible').on('click', function () {
        $('.user-option:visible .user-checkbox').prop('checked', true);
        updateCount();
    });

    $('#clearUsers').on('click', function () {
        $('.user-checkbox').prop('checked', false);
        updateCount();
    });

    $(document).on('change', '.user-checkbox', updateCount);

    // Quick date ranges
    function fmt(d) {
        var m = ('0' + (d.getMonth() + 1)).slice(-2), day = ('0' + d.getDate()).slice(-2);
        return d.getFullYear() + '-' + m + '-' + day;
    }
    $('#quickRange').on('change', function () {
        var now = new Date(), from = null, to = new Date(now);
        switch (this.value) {
            case 'today':      from = new Date(now); break;
            case '7':          from = new Date(now); from.setDate(now.getDate() - 6); break;
            case '30':         from = new Date(now); from.setDate(now.getDate() - 29); break;
            case 'this_month': from = new Date(now.getFullYear(), now.getMonth(), 1); break;
            case 'last_month':
                from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                to   = new Date(now.getFullYear(), now.getMonth(), 0);
                break;
            case 'this_year':  from = new Date(now.getFullYear(), 0, 1); break;
            default: return;
        }
        $('input[name="date_from"]').val(fmt(from));
        $('input[name="date_to"]').val(fmt(to));
    });

    $('input[type="date"]').on('change', function () { $('#quickRange').val(''); });

    $('#reportForm').on('submit', function (e) {
        var from = $('input[name="date_from"]').val(), to = $('input[name="date_to"]').val();
        if (from && to && from > to) {
            e.preventDefault();
            alert('"Date From" must be on or before "Date To".');
        }
    });
});
</script>
</body>
</html>
