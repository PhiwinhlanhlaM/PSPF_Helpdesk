<?php
// dashboard/ticket_progress.php
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/ticket_status_functions.php';
require_once '../db.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// ---------------------------
// Role Switching
// ---------------------------

// allow any system role here
requireAnyRole(['user','agent','admin','superadmin']);

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

// Get date range filters
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // First day of current month
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$departmentFilter = isset($_GET['department']) ? $_GET['department'] : '';
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : '';
$agentFilter = isset($_GET['agent']) ? $_GET['agent'] : '';

// Get overall statistics
$statsSql = "
    SELECT 
        COUNT(*) as total_tickets,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tickets,
        SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) as resolved_tickets,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_tickets,
        SUM(CASE WHEN t.status = 'Escalated' THEN 1 ELSE 0 END) as escalated_tickets,
        AVG(TIMESTAMPDIFF(HOUR, t.query_date, 
            CASE WHEN t.status = 'Closed' 
            THEN (SELECT MAX(change_date) FROM ticket_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
            ELSE NOW() END)) as avg_resolution_time
    FROM tickets t
    WHERE DATE(t.query_date) BETWEEN ? AND ?
";

$params = [$dateFrom, $dateTo];
$types = "ss";

if ($departmentFilter) {
    $statsSql .= " AND t.department = ?";
    $params[] = $departmentFilter;
    $types .= "s";
}

if ($priorityFilter) {
    $statsSql .= " AND t.priority = ?";
    $params[] = $priorityFilter;
    $types .= "s";
}

if ($agentFilter) {
    $statsSql .= " AND t.assigned_to LIKE ?";
    $params[] = "%$agentFilter%";
    $types .= "s";
}

$stmt = $conn->prepare($statsSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get ticket timeline data for charts
$timelineSql = "
    SELECT 
        t.id,
        CONCAT('TCK-', LPAD(t.id, 6, '0')) as ticket_number,
        t.title,
        t.priority,
        t.status as current_status,
        t.query_date as created_date,
        (SELECT MAX(change_date) FROM ticket_status_logs WHERE ticket_id = t.id AND new_status = 'Closed') as closed_date,
        (SELECT COUNT(*) FROM ticket_status_logs WHERE ticket_id = t.id) as status_changes,
        TIMESTAMPDIFF(HOUR, t.query_date, 
            CASE WHEN t.status = 'Closed' 
            THEN (SELECT MAX(change_date) FROM ticket_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
            ELSE NOW() END) as hours_open,
        u.department,
        t.assigned_to
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.username
    WHERE DATE(t.query_date) BETWEEN ? AND ?
    ORDER BY t.query_date DESC
    LIMIT 100
";

$stmt = $conn->prepare($timelineSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get status flow data for Sankey diagram
$flowSql = "
    SELECT 
        COALESCE(old_status, 'Created') as from_status,
        new_status as to_status,
        COUNT(*) as count
    FROM ticket_status_logs tsl
    JOIN tickets t ON tsl.ticket_id = t.id
    WHERE DATE(t.query_date) BETWEEN ? AND ?
    GROUP BY old_status, new_status
    ORDER BY count DESC
";

$stmt = $conn->prepare($flowSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$statusFlow = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get resolution time distribution
$resolutionSql = "
    SELECT 
        CASE 
            WHEN resolution_hours <= 1 THEN '0-1 hour'
            WHEN resolution_hours <= 4 THEN '1-4 hours'
            WHEN resolution_hours <= 24 THEN '4-24 hours'
            WHEN resolution_hours <= 72 THEN '1-3 days'
            WHEN resolution_hours <= 168 THEN '3-7 days'
            ELSE 'Over 7 days'
        END as time_range,
        COUNT(*) as ticket_count
    FROM (
        SELECT 
            t.id,
            TIMESTAMPDIFF(HOUR, t.query_date, 
                (SELECT MAX(change_date) FROM ticket_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
            ) as resolution_hours
        FROM tickets t
        WHERE t.status = 'Closed'
        AND DATE(t.query_date) BETWEEN ? AND ?
    ) as resolved_tickets
    GROUP BY time_range
    ORDER BY 
        CASE time_range
            WHEN '0-1 hour' THEN 1
            WHEN '1-4 hours' THEN 2
            WHEN '4-24 hours' THEN 3
            WHEN '1-3 days' THEN 4
            WHEN '3-7 days' THEN 5
            ELSE 6
        END
";

$stmt = $conn->prepare($resolutionSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$resolutionData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get departments for filter
$deptStmt = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $deptStmt->fetch_all(MYSQLI_ASSOC);
$deptStmt->close();

// Get agents for filter
$agentStmt = $conn->query("
    SELECT DISTINCT u.username, u.email
    FROM users u
    INNER JOIN user_roles ur ON u.id = ur.user_id
    INNER JOIN roles r ON ur.role_id = r.id
    WHERE r.name IN ('agent', 'admin', 'superadmin')
    ORDER BY u.username
");
$agents = $agentStmt->fetch_all(MYSQLI_ASSOC);
$agentStmt->close();

// Batch-load ticket status history (PERFORMANCE FIX 🚀)
$statusHistories = [];

if (!empty($tickets)) {
    $ticketIds = array_column($tickets, 'id');
    $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
    $types = str_repeat('i', count($ticketIds));

    $sql = "
        SELECT ticket_id, old_status, new_status, change_date
        FROM ticket_status_logs
        WHERE ticket_id IN ($placeholders)
        ORDER BY ticket_id, change_date
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ticketIds);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $statusHistories[$row['ticket_id']][] = $row;
    }

    $stmt->close();
}

$trendSql = "
    SELECT 
        DATE(t.query_date) as day,
        COUNT(*) as created_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_count
    FROM tickets t
    WHERE DATE(t.query_date) BETWEEN ? AND ?
    GROUP BY DATE(t.query_date)
    ORDER BY day
";

$stmt = $conn->prepare($trendSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$trendData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$agentPerfSql = "
    SELECT 
        t.assigned_to AS agent,
        COUNT(CASE WHEN t.status = 'Closed' THEN 1 END) AS resolved,
        AVG(TIMESTAMPDIFF(HOUR, t.query_date, tsl.change_date)) AS avg_hours
    FROM tickets t
    LEFT JOIN ticket_status_logs tsl 
        ON t.id = tsl.ticket_id AND tsl.new_status = 'Closed'
    WHERE t.assigned_to IS NOT NULL
    AND DATE(t.query_date) BETWEEN ? AND ?
    GROUP BY t.assigned_to
    ORDER BY resolved DESC
";

$stmt = $conn->prepare($agentPerfSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$agentPerf = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$deptSql = "
    SELECT 
        u.department,
        COUNT(*) AS total
    FROM tickets t
    JOIN users u ON t.created_by = u.username
    WHERE u.department IS NOT NULL
    AND DATE(t.query_date) BETWEEN ? AND ?
    GROUP BY u.department
    ORDER BY total DESC
";

$stmt = $conn->prepare($deptSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$deptData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$perfSql = "
    SELECT 
        DATE(t.query_date) as week,
        AVG(TIMESTAMPDIFF(HOUR, t.query_date, 
            IFNULL(tsl.change_date, NOW())
        )) as avg_hours
    FROM tickets t
    LEFT JOIN ticket_status_logs tsl 
        ON t.id = tsl.ticket_id AND tsl.new_status = 'Closed'
    WHERE DATE(t.query_date) BETWEEN ? AND ?
    GROUP BY DATE(t.query_date)
";

$stmt = $conn->prepare($perfSql);
$stmt->bind_param("ss", $dateFrom, $dateTo);
$stmt->execute();
$perfData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Progress Dashboard - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style6.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../agent/agent_style.css">

</head>
<body>


<?php include '../agent/topnav.php'; ?>

<div id="loading" class="loading-indicator" style="display: none;">
  <div class="spinner-border text-primary" role="status">
    <span class="visually-hidden">Loading...</span>
  </div>
</div>

<main id="main-content">
    <div class="container mt-5">
        <div class="settings-header">   
            <h1 class="settings-title"><i class="bi bi-speedometer2 me-2"></i>Agents Assignment Flow</h1>
                <div class="settings-actions">
                    <!-- Back Button -->
                    <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                </div>
            </div>
        
        <div class="container-fluid mt-4">
            <!-- Filters -->
            <div class="filter-card">
                <h5 class="mb-3"><i class="bi bi-funnel"></i> Filter Dashboard</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" 
                            value="<?= htmlspecialchars($dateFrom) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" 
                            value="<?= htmlspecialchars($dateTo) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['department']) ?>"
                                <?= ($departmentFilter == $dept['department']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['department']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Priority</label>
                        <select class="form-select" name="priority">
                            <option value="">All Priorities</option>
                            <option value="Low" <?= ($priorityFilter == 'Low') ? 'selected' : '' ?>>Low</option>
                            <option value="Medium" <?= ($priorityFilter == 'Medium') ? 'selected' : '' ?>>Medium</option>
                            <option value="High" <?= ($priorityFilter == 'High') ? 'selected' : '' ?>>High</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Assigned Agent</label>
                        <select class="form-select" name="agent">
                            <option value="">All Agents</option>
                            <?php foreach ($agents as $agent): ?>
                            <option value="<?= htmlspecialchars($agent['username']) ?>"
                                <?= ($agentFilter == $agent['username']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($agent['email'] ?: $agent['username']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                        <a href="ticket_progress.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="stat-card card-animate" style="border-top-color: var(--info-cyan);">
                        <div class="stat-icon" style="background-color: rgba(23, 162, 184, 0.1); color: var(--info-cyan);">
                            <i class="bi bi-folder-plus"></i>
                        </div>
                        <div class="stat-number"><?= $stats['total_tickets'] ?? 0 ?></div>
                        <div class="stat-label">Total Tickets</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card card-animate" style="border-top-color: #e49600;">
                        <div class="stat-icon" style="background-color: rgba(23, 162, 184, 0.1); color: #e49600;">
                            <i class="bi bi-door-open"></i>
                        </div>
                        <div class="stat-number"><?= $stats['open_tickets'] ?? 0 ?></div>
                        <div class="stat-label">Open</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card card-animate" style="border-top-color: var(--primary-blue);">
                        <div class="stat-icon" style="background-color: rgba(26, 107, 188, 0.1); color: var(--primary-blue);">
                            <i class="bi bi-gear"></i>
                        </div>
                        <div class="stat-number"><?= $stats['in_progress_tickets'] ?? 0 ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card card-animate" style="border-top-color: var(--success-green);">
                        <div class="stat-icon" style="background-color: rgba(40, 167, 69, 0.1); color: var(--success-green);">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-number"><?= $stats['resolved_tickets'] ?? 0 ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card card-animate" style="border-top-color: #6c757d;">
                        <div class="stat-icon" style="background-color: rgba(108, 117, 125, 0.1); color: #6c757d;">
                            <i class="bi bi-archive"></i>
                        </div>
                        <div class="stat-number"><?= $stats['closed_tickets'] ?? 0 ?></div>
                        <div class="stat-label">Closed</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card card-animate" style="border-top-color: var(--danger-red);">
                        <div class="stat-icon" style="background-color: rgba(220, 53, 69, 0.1); color: var(--danger-red);">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                        <div class="stat-number"><?= $stats['escalated_tickets'] ?? 0 ?></div>
                        <div class="stat-label">Escalated</div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row 1 -->
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="chart-title card-color text-white">
                            <i class="bi bi-people me-2"></i>
                            Agent Performance
                        </h5>
                        <canvas id="agentPerformanceChart" height="250"></canvas>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="chart-title card-color text-white">
                            <i class="bi bi-building me-2"></i>
                            Department Performance
                        </h5>
                        <canvas id="departmentChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            <!-- Charts Row 2 -->
            <div class="row ">
                <div class="col-lg-6">
                    <div class="chart-container">
                        <h5 class="chart-title">
                            <i class="bi bi-calendar-week me-2"></i>
                            Ticket Creation Trend
                        </h5>
                        <canvas id="creationTrendChart" height="300"></canvas>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="chart-container">
                        <h5 class="chart-title">
                            <i class="bi bi-speedometer me-2"></i>
                            Performance Metrics
                        </h5>
                        <div id="performanceChart" style="height: 300px;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Ticket Timelines -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="chart-container">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="chart-title mb-0">
                                <i class="bi bi-diagram-3 me-2"></i>
                                Recent Ticket Timelines
                            </h5>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary active" data-view="timeline">
                                    <i class="bi bi-list-ul"></i> Timeline
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" data-view="gantt">
                                    <i class="bi bi-bar-chart"></i> Gantt
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" data-view="table">
                                    <i class="bi bi-table"></i> Table
                                </button>
                            </div>
                        </div>
                        
                        <!-- Timeline View -->
                        <div id="timelineView" class="view-section">
                            <div class="timeline-container">
                                <?php foreach ($tickets as $ticket): 
                                    $statusHistory = $statusHistories[$ticket['id']] ?? [];

                                ?>
                                <div class="timeline-item timeline-<?= strtolower(str_replace(' ', '-', $ticket['current_status'])) ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1">
                                                <a href="../ticket/view_ticket.php?id=<?= $ticket['id'] ?>" 
                                                class="text-decoration-none">
                                                    <?= $ticket['ticket_number'] ?>: <?= htmlspecialchars(substr($ticket['title'], 0, 50)) ?>
                                                </a>
                                            </h6>
                                            <div class="mb-2">
                                                <span class="badge bg-<?= $ticket['priority'] == 'High' ? 'danger' : 'warning' ?> me-2">
                                                    <?= $ticket['priority'] ?>
                                                </span>
                                                <span class="status-badge bg-<?= getStatusColor($ticket['current_status']) ?>">
                                                    <i class="bi <?= getStatusIcons($ticket['current_status']) ?>"></i>
                                                    <?= $ticket['current_status'] ?>
                                                </span>
                                                <span class="duration-badge ms-2">
                                                    <i class="bi bi-clock"></i>
                                                    <?= floor($ticket['hours_open'] / 24) ?>d <?= $ticket['hours_open'] % 24 ?>h
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted d-block">
                                                Created: <?= date('M d, Y', strtotime($ticket['created_date'])) ?>
                                            </small>
                                            <?php if ($ticket['closed_date']): ?>
                                            <small class="text-muted">
                                                Closed: <?= date('M d, Y', strtotime($ticket['closed_date'])) ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Status Progress Bar -->
                                    <div class="ticket-progress-bar">
                                        <?php 
                                        $colors = ['Open' => '#17a2b8', 'In Progress' => '#1a6bbc', 'Resolved' => '#28a745', 'Closed' => '#6c757d'];
                                        $totalChanges = count($statusHistory);
                                        $segmentWidth = $totalChanges > 0 ? (100 / $totalChanges) : 0;
                                        
                                        foreach ($statusHistory as $change): 
                                        ?>
                                        <div class="progress-segment" 
                                            style="width: <?= $segmentWidth ?>%; background-color: <?= $colors[$change['new_status']] ?? '#6c757d' ?>;"
                                            title="<?= $change['new_status'] ?> on <?= date('M d, H:i', strtotime($change['change_date'])) ?>">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- Status History -->
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <?php foreach ($statusHistory as $change): ?>
                                        <small class="text-muted">
                                            <?= $change['old_status'] ? $change['old_status'] . ' → ' : '' ?>
                                            <strong><?= $change['new_status'] ?></strong>
                                            <span class="ms-1">
                                                (<?= date('M d', strtotime($change['change_date'])) ?>)
                                            </span>
                                        </small>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Gantt View -->
                        <div id="ganttView" class="view-section" style="display: none;">
                            <div style="height: 500px; overflow-x: auto; overflow-y: auto;">
                                <div id="ganttContainer"></div>
                            </div>
                        </div>
                        
                        <!-- Table View -->
                        <div id="tableView" class="view-section" style="display: none;">
                            <div class="table-responsive">
                                <table class="table table-hover" id="ticketsTable">
                                    <thead>
                                        <tr>
                                            <th>Ticket #</th>
                                            <th>Title</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Duration</th>
                                            <th>Changes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tickets as $ticket): ?>
                                        <tr>
                                            <td>
                                                <strong><?= $ticket['ticket_number'] ?></strong>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(substr($ticket['title'], 0, 40)) ?>...
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $ticket['priority'] == 'High' ? 'danger' : 'warning' ?>">
                                                    <?= $ticket['priority'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= getStatusColor($ticket['current_status']) ?>">
                                                    <?= $ticket['current_status'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= date('M d, Y', strtotime($ticket['created_date'])) ?>
                                            </td>
                                            <td>
                                                <span class="duration-badge">
                                                    <?= floor($ticket['hours_open'] / 24) ?>d <?= $ticket['hours_open'] % 24 ?>h
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $ticket['status_changes'] ?> changes</span>
                                            </td>
                                            <td>
                                                <button type="button" 
                                                            class="btn btn-sm btn-info view-ticket-btn" 
                                                            data-ticket-id="<?= $ticket['id'] ?>"
                                                            title="View Ticket">
                                                        <i class="bi bi-eye"></i>
                                                    </button>

                                                
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Performance Metrics -->
            <div class="row">
             <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="chart-container">
                        <h5 class="chart-title">
                            <i class="bi bi-timeline me-2"></i>
                            Ticket Status Flow
                        </h5>
                        <div id="statusFlowChart" style="height: 400px;"></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="chart-container">
                        <h5 class="chart-title">
                            <i class="bi bi-clock-history me-2"></i>
                            Resolution Time Distribution
                        </h5>
                        <div id="resolutionChart" style="height: 400px;"></div>
                    </div>
                </div>
            </div>
            <div class="row mb-4">
                
            </div>
        </div>
        </div>

        <!-- Ticket View Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-labelledby="ticketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header card-color text-white rounded-top">
                <h5 class="modal-title d-flex align-items-center" id="ticketModalLabel">
                    <i class="bi bi-ticket-perforated me-2"></i> Ticket Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" id="modalBodyContent">
                Loading...
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>



    </main>
        <!-- Footer -->
       <?php include '../footer.php';?>
    
    <!-- Scripts --><!-- Core libs (ORDER MATTERS) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#ticketsTable').DataTable({
            pageLength: 10,
            order: [[4, 'desc']]
        });
        
        // View switcher
        $('[data-view]').click(function() {
            const view = $(this).data('view');
            
            // Update button states
            $('[data-view]').removeClass('active').addClass('btn-outline-secondary');
            $(this).removeClass('btn-outline-secondary').addClass('active');
            
            // Show selected view
            $('.view-section').hide();
            $('#' + view + 'View').show();
        });
        
        // Initialize charts
        initializeCharts();
        
        // Auto-refresh every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);
    });
    
    function initializeCharts() {
        // Status Flow Chart (Sankey-like)
        const flowOptions = {
            series: [
                <?php
                $grouped = [];
                foreach ($statusFlow as $row) {
                    $grouped[$row['from_status']][] = [
                        'x' => $row['to_status'],
                        'y' => (int)$row['count']
                    ];
                }

                foreach ($grouped as $from => $rows) {
                    echo json_encode([
                        'name' => $from,
                        'data' => $rows
                    ]) . ",";
                }
                ?>
                ],

            chart: {
                type: 'heatmap',
                height: 400,
                toolbar: {
                    show: true
                }
            },
            dataLabels: {
                enabled: true,
                style: {
                    fontSize: '12px',
                    colors: ['#fff']
                }
            },
            colors: ['#17a2b8', '#1a6bbc', '#28a745', '#6c757d', '#fd7e14', '#dc3545'],
            xaxis: {
                type: 'category',
                categories: ['Created', 'Open', 'In Progress', 'Resolved', 'Closed', 'Escalated']
            },
            yaxis: {
                type: 'category',
                categories: ['Open', 'In Progress', 'Resolved', 'Closed', 'Escalated']
            },
            title: {
                text: 'Status Transition Patterns',
                align: 'left',
                style: {
                    fontSize: '14px'
                }
            },
            tooltip: {
                y: {
                    formatter: function(val, opts) {
                        return val + ': ' + opts.series[opts.seriesIndex][opts.dataPointIndex] + ' tickets';
                    }
                }
            }
        };
        
        const flowChart = new ApexCharts(document.querySelector("#statusFlowChart"), flowOptions);
        flowChart.render();
        
        // Resolution Time Chart
        const resolutionOptions = {
            series: [
                <?php 
                $values = [];
                $labels = [];
                foreach ($resolutionData as $data) {
                    $values[] = $data['ticket_count'];
                    $labels[] = $data['time_range'];
                }
                echo json_encode($values);
                ?>
            ],
            chart: {
                type: 'donut',
                height: 400
            },
            labels: <?= json_encode($labels) ?>,
            colors: ['#17a2b8', '#1a6bbc', '#28a745', '#fd7e14', '#dc3545', '#6c757d'],
            legend: {
                position: 'bottom'
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Total Tickets',
                                color: '#666',
                                fontSize: '14px'
                            }
                        }
                    }
                }
            },
            responsive: [{
                breakpoint: 480,
                options: {
                    chart: {
                        width: 200
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }]
        };
        
        const resolutionChart = new ApexCharts(document.querySelector("#resolutionChart"), resolutionOptions);
        resolutionChart.render();
        
        // Creation Trend Chart
        const trendLabels = <?= json_encode(array_column($trendData, 'day')) ?>;
        const createdData = <?= json_encode(array_column($trendData, 'created_count')) ?>;
        const closedData = <?= json_encode(array_column($trendData, 'closed_count')) ?>;

        new Chart(document.getElementById('creationTrendChart'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Tickets Created',
                        data: createdData,
                        borderColor: '#1a6bbc',
                        backgroundColor: 'rgba(26,107,188,0.1)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Tickets Closed',
                        data: closedData,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40,167,69,0.1)',
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        
        // Performance Metrics Chart
        const performanceOptions = {
            series: [{
                name: 'Avg Resolution Time',
                data: [4.2, 3.8, 5.1, 4.5, 3.9, 4.8, 5.2]
            }],
            chart: {
                height: 300,
                type: 'area',
                toolbar: {
                    show: false
                }
            },
            colors: ['#1a6bbc'],
            dataLabels: {
                enabled: false
            },
            stroke: {
                curve: 'smooth',
                width: 2
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.7,
                    opacityTo: 0.3,
                    stops: [0, 90, 100]
                }
            },
            xaxis: {
                categories: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6', 'Week 7']
            },
            yaxis: {
                title: {
                    text: 'Hours'
                }
            },
            tooltip: {
                x: {
                    format: 'dd/MM/yy HH:mm'
                },
            },
        };
        
        new ApexCharts(document.querySelector("#performanceChart"), {
            chart: { type: 'area', height: 300 },
            series: [{
                name: 'Avg Resolution Time (hrs)',
                data: <?= json_encode(array_map(fn($r)=>round($r['avg_hours'],2), $perfData)) ?>
            }],
            xaxis: {
                categories: <?= json_encode(array_column($perfData, 'week')) ?>
            }
        }).render();

        
        // Agent Performance Chart
        const agentLabels = <?= json_encode(array_column($agentPerf, 'agent')) ?>;
const agentResolved = <?= json_encode(array_column($agentPerf, 'resolved')) ?>;
const agentAvg = <?= json_encode(array_map(fn($r) => round($r['avg_hours'], 2), $agentPerf)) ?>;

new Chart(document.getElementById('agentPerformanceChart'), {
    type: 'bar',
    data: {
        labels: agentLabels,
        datasets: [
            {
                label: 'Tickets Resolved',
                data: agentResolved,
                backgroundColor: '#1a6bbc'
            },
            {
                label: 'Avg Resolution Time (hrs)',
                data: agentAvg,
                type: 'line',
                borderColor: '#28a745',
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true },
            y1: {
                position: 'right',
                beginAtZero: true,
                grid: { drawOnChartArea: false }
            }
        }
    }
});

        
        // Department Chart
        new Chart(document.getElementById('departmentChart'), {
    type: 'polarArea',
    data: {
        labels: <?= json_encode(array_column($deptData, 'department')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($deptData, 'total')) ?>,
            backgroundColor: [
                '#1a6bbc','#28a745','#fd7e14',
                '#6c757d','#dc3545','#6b9ac4','#a4167e'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' }
        }
    }
});

        
        // Generate Gantt chart
        generateGanttChart();
    }
    
    function generateGanttChart() {
        const ganttContainer = document.getElementById('ganttContainer');
        const tickets = <?= json_encode(array_slice($tickets, 0, 10)) ?>;
        
        let html = '<div style="min-width: 800px;">';
        const today = new Date();
        const startDate = new Date(today);
        startDate.setDate(today.getDate() - 14);
        
        tickets.forEach((ticket, index) => {
            const createdDate = new Date(ticket.created_date);
            const closedDate = ticket.closed_date ? new Date(ticket.closed_date) : today;
            
            // Calculate position and width
            const totalDays = 14;
            const startOffset = Math.max(0, (createdDate - startDate) / (1000 * 60 * 60 * 24));
            const duration = (closedDate - createdDate) / (1000 * 60 * 60 * 24);
            const width = Math.max(1, (duration / totalDays) * 100);
            
            // Status-based color
            const statusColors = {
                'Open': '#17a2b8',
                'In Progress': '#1a6bbc',
                'Resolved': '#28a745',
                'Closed': '#6c757d',
                'Escalated': '#dc3545'
            };
            
            html += `
                <div class="d-flex align-items-center mb-2">
                    <div style="width: 150px; font-size: 0.8rem;">
                        ${ticket.ticket_number}
                    </div>
                    <div style="flex: 1; position: relative; height: 25px; background: #f8f9fa; border-radius: 4px;">
                        <div class="gantt-bar" 
                             style="position: absolute; left: ${startOffset}%; width: ${width}%; background-color: ${statusColors[ticket.current_status] || '#6c757d'};">
                            <span class="gantt-label">${ticket.current_status}</span>
                        </div>
                    </div>
                    <div style="width: 100px; text-align: right; font-size: 0.8rem;">
                        ${Math.round(duration)}d
                    </div>
                </div>
            `;
        });
        
        // Timeline header
        html += `
            <div class="d-flex mt-3" style="margin-left: 150px;">
                ${Array.from({length: 15}, (_, i) => {
                    const date = new Date(startDate);
                    date.setDate(startDate.getDate() + i);
                    return `<div style="flex: 1; text-align: center; font-size: 0.7rem; color: #6c757d;">
                        ${date.getDate()}/${date.getMonth() + 1}
                    </div>`;
                }).join('')}
            </div>
        `;
        
        html += '</div>';
        ganttContainer.innerHTML = html;
    }
    
    // Export dashboard data
    function exportDashboardData() {
        const data = {
            filters: {
                dateFrom: '<?= $dateFrom ?>',
                dateTo: '<?= $dateTo ?>',
                department: '<?= $departmentFilter ?>',
                priority: '<?= $priorityFilter ?>'
            },
            stats: <?= json_encode($stats) ?>,
            tickets: <?= json_encode($tickets) ?>,
            exportDate: new Date().toISOString()
        };
        
        const dataStr = JSON.stringify(data, null, 2);
        const dataUri = 'data:application/json;charset=utf-8,'+ encodeURIComponent(dataStr);
        
        const exportFileDefaultName = `ticket_progress_dashboard_${new Date().toISOString().split('T')[0]}.json`;
        
        const linkElement = document.createElement('a');
        linkElement.setAttribute('href', dataUri);
        linkElement.setAttribute('download', exportFileDefaultName);
        linkElement.click();
    }
    
   $(document).on('click', '.view-ticket-btn', async function () {
    const ticketId = $(this).data('ticket-id');

    const modalEl = document.getElementById('ticketModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    const modalBody = document.getElementById('modalBodyContent');

    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2 text-muted">Loading ticket details...</p>
        </div>
    `;

    try {
        const response = await fetch(`../ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (!data.success) {
            modalBody.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            return;
        }

        renderTicketModal(data.ticket);

    } catch (err) {
        console.error(err);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                Failed to load ticket details.
            </div>
        `;
    }
});


    function getBadgeClass(status) {
        if (!status) return 'bg-secondary';
        
        status = status.toLowerCase();
        switch(status) {
            case 'open': return 'bg-warning text-dark';
            case 'in progress':
            case 'in_progress':
            case 'in-progress': return 'bg-info text-dark';
            case 'closed': return 'bg-success';
            case 'escalated':
            case 'escalate': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }


    // Print dashboard
    function printDashboard() {
        window.print();
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    </script>


    
    <!-- Print Styles -->
    <style media="print">
        .dashboard-header, .filter-card, .btn, .chart-title .bi, [data-view] {
            display: none !important;
        }
        
        .chart-container, .stat-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        
        body {
            font-size: 12px;
        }
        
        .stat-number {
            font-size: 1.5rem;
        }
    </style>
</body>
</html>