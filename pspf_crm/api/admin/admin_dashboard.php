<?php
session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/division_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../includes/log_activity.php';
require_once '../includes/metrics_helpers.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

$divisionId   = $_SESSION['user']['division_id'];
$userDept = $_SESSION['user']['division_name'] ?? 'All Departments';

$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];

$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// Set scope based on role
$scopeCondition = $isSuperAdmin ? "1=1" : "t.division_id = ?";
$scopeParams = $isSuperAdmin ? [] : [$UserDivisionId];
$scopeTypes = $isSuperAdmin ? "" : "i";


require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/division_helpers.php';
require_once '../includes/role_switcher.php';

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

// ---------------------------
// AGENT PERFORMANCE SUMMARY - FIXED with proper roles structure
// ---------------------------
$agentPerformance = null;

try {
    if ($isSuperAdmin) {
        // Superadmin sees all agents and admins across all departments
        $agentSql = "
            SELECT 
                u.id,
                u.username,
                u.email,
                GROUP_CONCAT(DISTINCT r.name) as roles,
                u.division_id,
                d.division_name as department,
                COUNT(DISTINCT t.id) as total_assigned,
                SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
                ROUND(AVG(CASE
                    WHEN t.status IN ('Resolved', 'Closed')
                    THEN TIMESTAMPDIFF(MINUTE, t.query_date, " . RESOLVED_AT_SQL . ")
                    ELSE NULL
                END), 0) as avg_time,
                ROUND(AVG(f.rating), 1) as avg_rating,
                SUM(CASE WHEN t.status = 'Escalated' THEN 1 ELSE 0 END) as escalated,
                SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress
            FROM users u
            LEFT JOIN divisions d ON u.division_id = d.id
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            LEFT JOIN tickets t ON FIND_IN_SET(u.email, t.assigned_to)
            LEFT JOIN ticket_feedback f ON t.id = f.ticket_id
            WHERE r.name IN ('agent', 'admin') 
                AND (u.is_active = 1 OR u.is_active IS NULL)
            GROUP BY u.id, u.username, u.email, u.division_id, d.division_name
            HAVING total_assigned > 0 OR resolved > 0
            ORDER BY resolved DESC, avg_rating DESC
            LIMIT 15
        ";
        
        $agentResult = $conn->query($agentSql);
        $agentPerformance = $agentResult;
        
    } else {
        // Admin sees only agents and admins in their department
        $agentSql = "
            SELECT 
                u.id,
                u.username,
                u.email,
                GROUP_CONCAT(DISTINCT r.name) as roles,
                COUNT(DISTINCT t.id) as total_assigned,
                SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
                ROUND(AVG(CASE
                    WHEN t.status IN ('Resolved', 'Closed')
                    THEN TIMESTAMPDIFF(MINUTE, t.query_date, " . RESOLVED_AT_SQL . ")
                    ELSE NULL
                END), 0) as avg_time,
                ROUND(AVG(f.rating), 1) as avg_rating,
                SUM(CASE WHEN t.status = 'Escalated' THEN 1 ELSE 0 END) as escalated,
                SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            LEFT JOIN tickets t ON FIND_IN_SET(u.email, t.assigned_to) AND t.division_id = ?
            LEFT JOIN ticket_feedback f ON t.id = f.ticket_id
            WHERE r.name IN ('agent', 'admin') 
                AND u.division_id = ? 
                AND (u.is_active = 1 OR u.is_active IS NULL)
            GROUP BY u.id, u.username, u.email
            HAVING total_assigned > 0 OR resolved > 0
            ORDER BY resolved DESC, avg_rating DESC
            LIMIT 15
        ";
        
        $agentStmt = $conn->prepare($agentSql);
        if ($agentStmt) {
            $agentStmt->bind_param("ii", $UserDivisionId, $UserDivisionId);
            $agentStmt->execute();
            $agentPerformance = $agentStmt->get_result();
            $agentStmt->close();
        }
    }
    
} catch (Exception $e) {
    error_log("Agent performance query failed: " . $e->getMessage());
    $agentPerformance = null;
}

// Rest of your code continues...

// ---------------------------
// TODAY'S ACTIVITY (Department/Global)
// ---------------------------
$today = date('Y-m-d');
$todayActivitySql = "
    SELECT
        SUM(" . COMPLETED_TODAY_SQL . ") as resolved_today,
        SUM(DATE(t.query_date) = ?) as actions_today,
        SUM(t.status = 'Escalated' AND DATE(t.updated_at) = ?) as escalated_today,
        SUM(t.status = 'Open' AND DATE(t.query_date) = ?) as new_today
    FROM tickets t
    WHERE $scopeCondition
";

$todayStmt = $conn->prepare($todayActivitySql);
if ($isSuperAdmin) {
    $todayStmt->bind_param("sss", $today, $today, $today);
} else {
    $todayStmt->bind_param("sssi", $today, $today, $today, $UserDivisionId);
}
$todayStmt->execute();
$todayResult = $todayStmt->get_result();
$todayActivity = $todayResult->fetch_assoc();
$todayStmt->close();

// ---------------------------
// DEPARTMENT OVERALL RATINGS & FEEDBACK
// ---------------------------
$ratingsSql = "
    SELECT 
        COUNT(*) as total_rated,
        AVG(f.rating) as avg_rating,
        SUM(f.rating = 5) as five_star,
        SUM(f.rating = 4) as four_star,
        SUM(f.rating = 3) as three_star,
        SUM(f.rating = 2) as two_star,
        SUM(f.rating = 1) as one_star,
        COUNT(CASE WHEN f.comment IS NOT NULL AND f.comment != '' THEN 1 END) as feedback_count
    FROM ticket_feedback f
    JOIN tickets t ON f.ticket_id = t.id
    WHERE $scopeCondition
";

$ratingsStmt = $conn->prepare($ratingsSql);
if ($isSuperAdmin) {
    $ratingsStmt->execute();
} else {
    $ratingsStmt->bind_param("i", $UserDivisionId);
    $ratingsStmt->execute();
}
$ratingsResult = $ratingsStmt->get_result();
$ratings = $ratingsResult->fetch_assoc();
$ratingsStmt->close();

// ---------------------------
// RECENT FEEDBACK (Department/Global)
// ---------------------------
$feedbackSql = "
    SELECT 
        f.*,
        t.title as ticket_title,
        t.id as ticket_id,
        u.username as user_name,
        DATE_FORMAT(f.created_at, '%M %d, %Y') as formatted_date
    FROM ticket_feedback f
    JOIN tickets t ON f.ticket_id = t.id
    LEFT JOIN users u ON f.user_id = u.id
    WHERE $scopeCondition
      AND f.comment IS NOT NULL 
      AND f.comment != ''
    ORDER BY f.created_at DESC
    LIMIT 10
";

$feedbackStmt = $conn->prepare($feedbackSql);
if ($isSuperAdmin) {
    $feedbackStmt->execute();
} else {
    $feedbackStmt->bind_param("i", $UserDivisionId);
    $feedbackStmt->execute();
}
$feedbackResult = $feedbackStmt->get_result();
$recentFeedback = [];
while ($row = $feedbackResult->fetch_assoc()) {
    $recentFeedback[] = $row;
}
$feedbackStmt->close();

// ---------------------------
// DEPARTMENT PERFORMANCE METRICS
// ---------------------------
$perfSql = "
    SELECT
        COUNT(*) AS resolved_count,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.query_date, " . RESOLVED_AT_SQL . ")), 2) AS avg_resolution_time,
        ROUND(AVG(f.rating), 2) AS avg_rating
    FROM tickets t
    LEFT JOIN ticket_feedback f ON t.id = f.ticket_id
    WHERE $scopeCondition
      AND t.status IN ('Resolved', 'Closed')
      AND " . RESOLVED_AT_SQL . " >= DATE_SUB(NOW(), INTERVAL " . RESOLUTION_WINDOW_DAYS . " DAY)
";

$perfStmt = $conn->prepare($perfSql);
if ($isSuperAdmin) {
    $perfStmt->execute();
} else {
    $perfStmt->bind_param("i", $UserDivisionId);
    $perfStmt->execute();
}
$perfResult = $perfStmt->get_result();
$performance = $perfResult->fetch_assoc();
$perfStmt->close();

// ---------------------------
// DEPARTMENT TICKET STATS
// ---------------------------
$statsSql = "
    SELECT
        COUNT(*) AS total,
        SUM(t.status = 'Open') AS open_count,
        SUM(t.status = 'In Progress') AS in_progress,
        SUM(t.status = 'Pending Feedback') AS pending_feedback,
        SUM(t.status = 'Resolved') AS resolved,
        SUM(t.status = 'Escalated') AS escalated,
        SUM(t.status NOT IN (" . TERMINAL_TICKET_STATUSES . ")
            AND t.query_date < DATE_SUB(NOW(), INTERVAL 3 DAY)
            ) AS overdue,
        SUM(t.status = 'Open' AND DATE(t.query_date) = CURDATE()) AS new_today
    FROM tickets t
    WHERE $scopeCondition
";

$statsStmt = $conn->prepare($statsSql);
if ($isSuperAdmin) {
    $statsStmt->execute();
} else {
    $statsStmt->bind_param("i", $UserDivisionId);
    $statsStmt->execute();
}
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$deptTickets    = (int)($stats['total'] ?? 0);
$deptOpen       = (int)($stats['open_count'] ?? 0);
$deptInProgress = (int)($stats['in_progress'] ?? 0);
$deptPending    = (int)($stats['pending_feedback'] ?? 0);
$deptResolved   = (int)($stats['resolved'] ?? 0);
$deptEscalated  = (int)($stats['escalated'] ?? 0);
$deptOverdue    = (int)($stats['overdue'] ?? 0);
$deptNewToday   = (int)($stats['new_today'] ?? 0);
$deptResolvedToday = (int)($todayActivity['resolved_today'] ?? 0);

// ---------------------------
// ACTIVITY LOGS
// ---------------------------
$logsSql = "
    SELECT 
        l.*,
        t.title as ticket_title,
        u.username as action_by
    FROM ticket_status_logs l
    JOIN tickets t ON l.ticket_id = t.id
    LEFT JOIN users u ON l.changed_by = u.id
    WHERE $scopeCondition
    ORDER BY l.change_date DESC
    LIMIT 20
";

$logsStmt = $conn->prepare($logsSql);
if ($isSuperAdmin) {
    $logsStmt->execute();
} else {
    $logsStmt->bind_param("i", $UserDivisionId);
    $logsStmt->execute();
}
$activityLogs = $logsStmt->get_result();
$logsStmt->close();

// ---------------------------
// ESCALATED TICKETS (Department/Global)
// ---------------------------
$escalatedSql = "
    SELECT 
        t.*,
        u.username as created_by_name,
        a.username as assigned_agent,
        TIMESTAMPDIFF(HOUR, t.query_date, NOW()) as hours_old,
        (SELECT COUNT(*) FROM ticket_status_logs WHERE ticket_id = t.id) as activity_count
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.username
    LEFT JOIN users a ON FIND_IN_SET(a.email, t.assigned_to)
    WHERE t.status = 'Escalated'
      AND $scopeCondition
    ORDER BY t.updated_at DESC
    LIMIT 10
";

$escStmt = $conn->prepare($escalatedSql);
if ($isSuperAdmin) {
    $escStmt->execute();
} else {
    $escStmt->bind_param("i", $UserDivisionId);
    $escStmt->execute();
}
$escalatedTickets = $escStmt->get_result();
$escStmt->close();

// ---------------------------
// NEW TICKETS (Department/Global)
// ---------------------------
$newTicketsSql = "
    SELECT 
        t.*,
        u.username as created_by_name,
        TIMESTAMPDIFF(MINUTE, t.query_date, NOW()) as minutes_old
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.username
    WHERE t.status = 'Open'
      AND $scopeCondition
    ORDER BY t.query_date DESC
    LIMIT 10
";

$newStmt = $conn->prepare($newTicketsSql);
if ($isSuperAdmin) {
    $newStmt->execute();
} else {
    $newStmt->bind_param("i", $UserDivisionId);
    $newStmt->execute();
}
$newTickets = $newStmt->get_result();
$newStmt->close();

// ---------------------------
// RECENT TICKETS
// ---------------------------
$recentSql = "
    SELECT 
        t.*,
        u.username as created_by_name,
        a.username as assigned_agent,
        DATEDIFF(NOW(), t.query_date) as days_old
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.username
    LEFT JOIN users a ON FIND_IN_SET(a.email, t.assigned_to)
    WHERE $scopeCondition
    ORDER BY t.updated_at DESC
    LIMIT 15
";

$recentStmt = $conn->prepare($recentSql);
if ($isSuperAdmin) {
    $recentStmt->execute();
} else {
    $recentStmt->bind_param("i", $UserDivisionId);
    $recentStmt->execute();
}
$recentTickets = $recentStmt->get_result();
$recentStmt->close();

// Calculate satisfaction score
$satisfactionScore = 0;
if (($ratings['total_rated'] ?? 0) > 0) {
    $satisfactionScore = round(($ratings['avg_rating'] ?? 0) * 20);
}

$pageTitle = $isSuperAdmin ? 'Super Admin Dashboard' : 'Admin Dashboard';
$scopeLabel = $isSuperAdmin ? 'All Departments' : htmlspecialchars($userDept);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?> - PSPF Helpdesk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
</head>

<body>
    <?php include '../agent/topnav_agent.php'; ?>

    <div class="container-xl mt-5 mb-4">
        <!-- Header -->
        <div class="settings-header mb-4">
            <h1 class="settings-title">
                <i class="bi bi-<?= $isSuperAdmin ? 'person-gear' : 'shield-fill-check' ?> me-2"></i>
                <?= $pageTitle ?> - 
                <span class="scope-badge"><?= $scopeLabel ?></span>
            </h1>
            <div class="settings-actions">
                <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="admin_view.php?status=open" class="action-btn">
                <i class="bi bi-clock"></i> Open (<?= $deptOpen ?>)
            </a>
            <a href="admin_view.php?department=&status=In+Progress&priority=&filter=" class="action-btn">
                <i class="bi bi-arrow-repeat"></i> In Progress (<?= $deptInProgress ?>)
            </a>
            <a href="admin_view.php?status=escalated" class="action-btn danger">
                <i class="bi bi-exclamation-triangle"></i> Escalated (<?= $deptEscalated ?>)
            </a>
            <a href="admin_view.php?status=pending feedback" class="action-btn warning">
                <i class="bi bi-chat"></i> Pending Feedback (<?= $deptPending ?>)
            </a>
            <a href="admin_view.php?status=resolved" class="action-btn success">
                <i class="bi bi-check-circle"></i> Resolved (<?= $deptResolved ?>)
            </a>
            <a href="reports.php" class="action-btn primary">
                <i class="bi bi-bar-chart"></i> Reports
            </a>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card <?= $deptNewToday > 0 ? 'urgent' : '' ?>">
                <div class="stat-icon"><i class="bi bi-ticket-perforated"></i></div>
                <div class="stat-value"><?= $deptNewToday ?></div>
                <div class="stat-label">New Today</div>
                <?php if ($deptNewToday > 0): ?>
                    <div class="stat-badge">🔥 New</div>
                <?php endif; ?>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
                <div class="stat-value"><?= $deptResolvedToday ?></div>
                <div class="stat-label">Resolved Today</div>
            </div>

            <div class="stat-card <?= $deptOverdue > 0 ? 'urgent' : '' ?>">
                <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
                <div class="stat-value"><?= $deptOverdue ?></div>
                <div class="stat-label">Overdue</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-star-fill"></i></div>
                <div class="stat-value"><?= $satisfactionScore ?>%</div>
                <div class="stat-label">Satisfaction</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                <div class="stat-value"><?= formatDuration($performance['avg_resolution_time'] ?? null) ?></div>
                <div class="stat-label">Avg Resolution (30d)</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-people"></i></div>
                <div class="stat-value"><?= $deptTickets ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
        </div>

        <!-- Alert Cards - New & Escalated -->
        <div class="alert-cards">
            <!-- New Tickets -->
            <div class="alert-card <?= $deptNewToday > 0 ? 'urgent' : '' ?>">
                <div class="alert-header <?= $deptNewToday > 0 ? 'urgent' : '' ?>">
                    <i class="bi bi-bell-fill"></i>
                    <span>New Tickets (<?= $deptNewToday ?>)</span>
                </div>
                <div class="alert-body">
                    <?php if ($newTickets && $newTickets->num_rows > 0): ?>
                        <?php while ($ticket = $newTickets->fetch_assoc()): ?>
                            <div class="ticket-item <?= $ticket['priority'] == 'High' ? 'urgent' : '' ?>" 
                                 onclick="viewTicket(<?= $ticket['id'] ?>)">
                                <div class="ticket-meta">
                                    <span class="ticket-id">#<?= $ticket['id'] ?></span>
                                    <span class="ticket-time">
                                        <i class="bi bi-clock"></i> <?= $ticket['minutes_old'] ?>m
                                    </span>
                                </div>
                                <div class="ticket-title"><?= htmlspecialchars(substr($ticket['title'], 0, 40)) ?>...</div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="ticket-priority priority-<?= strtolower($ticket['priority']) ?>">
                                        <?= $ticket['priority'] ?>
                                    </span>
                                    <small><?= htmlspecialchars($ticket['created_by_name'] ?? $ticket['created_by'] ?? 'Unknown') ?></small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-1"></i>
                            <p class="mt-2">No new tickets</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Escalated Tickets -->
            <div class="alert-card <?= $deptEscalated > 0 ? 'warning' : '' ?>">
                <div class="alert-header <?= $deptEscalated > 0 ? 'warning' : '' ?>">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Escalated Tickets (<?= $deptEscalated ?>)</span>
                </div>
                <div class="alert-body">
                    <?php if ($escalatedTickets && $escalatedTickets->num_rows > 0): ?>
                        <?php while ($ticket = $escalatedTickets->fetch_assoc()): ?>
                            <div class="ticket-item urgent" onclick="viewTicket(<?= $ticket['id'] ?>)">
                                <div class="ticket-meta">
                                    <span class="ticket-id">#<?= $ticket['id'] ?></span>
                                    <span class="ticket-time">
                                        <i class="bi bi-hourglass-split"></i> <?= $ticket['hours_old'] ?>h
                                    </span>
                                </div>
                                <div class="ticket-title"><?= htmlspecialchars(substr($ticket['title'], 0, 40)) ?>...</div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="ticket-priority priority-high"><?= $ticket['priority'] ?></span>
                                    <small>
                                        <i class="bi bi-person"></i> <?= htmlspecialchars($ticket['assigned_agent'] ?? $ticket['assigned_to'] ?? 'Unassigned') ?>
                                    </small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle fs-1 text-success"></i>
                            <p class="mt-2">No escalated tickets</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Two-Column Layout -->
        <div class="two-column">
            <!-- Left Column - Recent Tickets & Agent Performance -->
            <div>
                <!-- Recent Tickets -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="bi bi-clock-history"></i> Recent Tickets</h3>
                        <a href="admin_view.php" class="text-white text-decoration-none small">View All →</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Agent</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentTickets && $recentTickets->num_rows > 0): ?>
                                    <?php while ($ticket = $recentTickets->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?= $ticket['id'] ?></td>
                                            <td><?= htmlspecialchars(substr($ticket['title'], 0, 30)) ?>...</td>
                                            <td>
                                                <span class="ticket-priority priority-<?= strtolower($ticket['priority']) ?>">
                                                    <?= $ticket['priority'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $ticket['status'])) ?>">
                                                    <?= $ticket['status'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($ticket['assigned_agent']): ?>
                                                    <span class="badge bg-info"><?= htmlspecialchars($ticket['assigned_agent']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewTicket(<?= $ticket['id'] ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">No tickets found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Agent Performance -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="bi bi-people-fill"></i> Agent Performance</h3>
        <span class="text-white small">Top 15 by resolution</span>
    </div>
    <div class="p-3" style="max-height: 500px; overflow-y: auto;">
        <?php if ($agentPerformance && $agentPerformance->num_rows > 0): ?>
            <?php while ($agent = $agentPerformance->fetch_assoc()): 
                // Parse roles from comma-separated list
                $agentRoles = explode(',', $agent['roles'] ?? '');
                $isAdmin = in_array('admin', $agentRoles);
                $isAgent = in_array('agent', $agentRoles);
            ?>
                <div class="agent-card">
                    <div class="agent-avatar" style="background: <?= 
                        $isAdmin ? '#F6AE2D' : 
                        ($isAgent ? '#7FC8F8' : '#3d5c80') 
                    ?>;">
                        <?= strtoupper(substr($agent['username'] ?? 'U', 0, 2)) ?>
                    </div>
                    <div class="agent-info">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="agent-name">
                                <?= htmlspecialchars($agent['username'] ?? 'Unknown') ?>
                                <?php if ($isAdmin): ?>
                                    <span class="badge bg-warning ms-1" style="font-size: 0.6rem;">Admin</span>
                                <?php endif; ?>
                                <?php if ($isSuperAdmin && !empty($agent['department'])): ?>
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.6rem;">
                                        <?= htmlspecialchars($agent['department']) ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <!-- Stats row -->
                        <div class="agent-stats">
                            <span class="text-success" title="Resolved">
                                <i class="bi bi-check-circle-fill"></i> 
                                <?= $agent['resolved'] ?? 0 ?>
                            </span>
                            
                            <span class="text-info" title="Avg Resolution Time">
                                <i class="bi bi-clock-history"></i> 
                                <?= formatDuration($agent['avg_time'] ?? null) ?>
                            </span>
                            
                            <span class="agent-rating" title="Average Rating">
                                <i class="bi bi-star-fill"></i> 
                                <?= number_format($agent['avg_rating'] ?? 0, 1) ?>
                            </span>
                            
                            <?php if (($agent['escalated'] ?? 0) > 0): ?>
                                <span class="text-danger" title="Escalated">
                                    <i class="bi bi-exclamation-triangle-fill"></i> 
                                    <?= $agent['escalated'] ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (($agent['open_tickets'] ?? 0) > 0): ?>
                                <span class="text-warning" title="Open">
                                    <i class="bi bi-envelope"></i> 
                                    <?= $agent['open_tickets'] ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Progress bar for workload -->
                        <?php 
                        $totalActive = ($agent['open_tickets'] ?? 0) + ($agent['in_progress'] ?? 0);
                        $workloadPercent = min(100, round(($totalActive / 15) * 100)); // Assuming 15 is max expected
                        ?>
                        <?php if ($totalActive > 0): ?>
                            <div class="progress mt-2" style="height: 3px;">
                                <div class="progress-bar bg-<?= 
                                    $workloadPercent > 80 ? 'danger' : 
                                    ($workloadPercent > 50 ? 'warning' : 'success') 
                                ?>" style="width: <?= $workloadPercent ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-people fs-1"></i>
                <p class="mt-2">No agent performance data available</p>
                <small class="text-muted">
                    <?= $isSuperAdmin ? 
                        'No agents or admins have tickets assigned yet.' : 
                        'No agents or admins in your department have tickets assigned yet.' 
                    ?>
                </small>
            </div>
        <?php endif; ?>
    </div>
                </div>
            </div>

            <!-- Right Column - Ratings, Feedback & Activity Logs -->
            <div>
                <!-- Department Ratings -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="bi bi-star-fill text-warning"></i> Department Ratings</h3>
                    </div>
                    <div class="p-3">
                        <?php if (($ratings['total_rated'] ?? 0) > 0): ?>
                            <div class="text-center mb-3">
                                <div class="display-4 fw-bold text-primary"><?= number_format($ratings['avg_rating'] ?? 0, 1) ?></div>
                                <div class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?= $i <= round($ratings['avg_rating'] ?? 0) ? '-fill' : '' ?> star-<?= $i <= round($ratings['avg_rating'] ?? 0) ? 'filled' : 'empty' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <small class="text-muted">Based on <?= $ratings['total_rated'] ?> ratings</small>
                            </div>

                            <div class="rating-distribution">
                                <?php
                                $ratingLevels = [
                                    5 => [$ratings['five_star'] ?? 0, 'success'],
                                    4 => [$ratings['four_star'] ?? 0, 'primary'],
                                    3 => [$ratings['three_star'] ?? 0, 'info'],
                                    2 => [$ratings['two_star'] ?? 0, 'warning'],
                                    1 => [$ratings['one_star'] ?? 0, 'danger']
                                ];
                                foreach ($ratingLevels as $stars => $data):
                                    $percentage = $ratings['total_rated'] > 0 ? ($data[0] / $ratings['total_rated']) * 100 : 0;
                                ?>
                                    <div class="rating-row">
                                        <span class="rating-label"><?= $stars ?>★</span>
                                        <div class="rating-bar">
                                            <div class="rating-bar-fill bg-<?= $data[1] ?>" style="width: <?= $percentage ?>%"></div>
                                        </div>
                                        <span class="rating-count"><?= $data[0] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-star fs-1"></i>
                                <p class="mt-2">No ratings yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Feedback -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="bi bi-chat-square-quote"></i> Recent Feedback</h3>
                        <?php if (($ratings['feedback_count'] ?? 0) > 0): ?>
                            <span class="badge bg-light text-dark"><?= $ratings['feedback_count'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="p-3" style="max-height: 300px; overflow-y: auto;">
                        <?php if (!empty($recentFeedback)): ?>
                            <?php foreach ($recentFeedback as $feedback): ?>
                                <div class="feedback-card" onclick="viewTicket(<?= $feedback['ticket_id'] ?>)">
                                    <div class="feedback-header">
                                        <span class="feedback-ticket">#<?= $feedback['ticket_id'] ?></span>
                                        <span class="feedback-user"><?= htmlspecialchars($feedback['user_name'] ?? 'Anonymous') ?></span>
                                    </div>
                                    <div class="rating-stars mb-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="bi bi-star<?= $i <= $feedback['rating'] ? '-fill' : '' ?> star-<?= $i <= $feedback['rating'] ? 'filled' : 'empty' ?>"></i>
                                        <?php endfor; ?>
                                        <span class="feedback-date float-end"><?= $feedback['formatted_date'] ?></span>
                                    </div>
                                    <?php if (!empty($feedback['comment'])): ?>
                                        <div class="feedback-text">"<?= htmlspecialchars($feedback['comment']) ?>"</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-chat-square-text fs-1"></i>
                                <p class="mt-2">No feedback yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Activity Logs -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="bi bi-clock-history"></i> Recent Activity</h3>
                    </div>
                    <div class="p-3" style="max-height: 300px; overflow-y: auto;">
                        <?php if ($activityLogs && $activityLogs->num_rows > 0): ?>
                            <?php while ($log = $activityLogs->fetch_assoc()): ?>
                                <div class="log-item">
                                    <span class="log-time">
                                        <i class="bi bi-clock"></i> <?= date('M d, H:i', strtotime($log['change_date'])) ?>
                                    </span>
                                    <div class="log-action">
                                        <?= htmlspecialchars($log['action_by'] ?? 'System') ?> 
                                        changed ticket #<?= $log['ticket_id'] ?> 
                                        from <span class="badge bg-secondary"><?= $log['old_status'] ?></span> 
                                        to <span class="badge bg-<?= 
                                            $log['new_status'] == 'Resolved' ? 'success' : 
                                            ($log['new_status'] == 'Escalated' ? 'danger' : 
                                            ($log['new_status'] == 'In Progress' ? 'warning' : 'info')) 
                                        ?>"><?= $log['new_status'] ?></span>
                                    </div>
                                    <div class="log-detail">
                                        <?= htmlspecialchars(substr($log['ticket_title'] ?? '', 0, 50)) ?>...
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-clock-history fs-1"></i>
                                <p class="mt-2">No activity logs</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Activity Summary -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="bi bi-calendar-day"></i> Today's Activity</h3>
                    </div>
                    <div class="p-3">
                        <div class="row text-center">
                            <div class="col-3">
                                <div class="p-3">
                                    <div class="display-6 text-success"><?= $todayActivity['resolved_today'] ?? 0 ?></div>
                                    <small class="text-muted">Resolved</small>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="p-3">
                                    <div class="display-6 text-primary"><?= $todayActivity['actions_today'] ?? 0 ?></div>
                                    <small class="text-muted">Actions</small>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="p-3">
                                    <div class="display-6 text-danger"><?= $todayActivity['escalated_today'] ?? 0 ?></div>
                                    <small class="text-muted">Escalated</small>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="p-3">
                                    <div class="display-6 text-warning"><?= $todayActivity['new_today'] ?? 0 ?></div>
                                    <small class="text-muted">New</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
<?php include '../footer.php'; ?>

    <!-- Ticket Modal -->
    <div class="modal fade" id="ticketModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-ticket-perforated me-2"></i>Ticket Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBodyContent">Loading...</div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function goBack() {
            if (document.referrer) {
                window.history.back();
            } else {
                window.location.href = 'user_dashboard.php';
            }
        }

        async function viewTicket(ticketId) {
            try {
                const modal = new bootstrap.Modal(document.getElementById('ticketModal'));
                modal.show();
                
                document.getElementById('modalBodyContent').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading ticket details...</p>
                    </div>
                `;
                
                const response = await fetch(`../ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
                const data = await response.json();
                
                if (data.success && data.ticket) {
                    const t = data.ticket;
                    
                    let modalContent = `
                        <div class="container-fluid">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-muted">Ticket Info</h6>
                                    <ul class="list-unstyled">
                                        <li><strong>ID:</strong> #${escapeHtml(t.id)}</li>
                                        <li><strong>Title:</strong> ${escapeHtml(t.title)}</li>
                                        <li><strong>Status:</strong> <span class="status-badge status-${t.status.toLowerCase().replace(' ', '-')}">${escapeHtml(t.status)}</span></li>
                                        <li><strong>Priority:</strong> <span class="ticket-priority priority-${t.priority.toLowerCase()}">${escapeHtml(t.priority)}</span></li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted">Requester Info</h6>
                                    <ul class="list-unstyled">
                                        <li><strong>Member Type:</strong> ${escapeHtml(t.member_type || 'N/A')}</li>
                                        <li><strong>Phone:</strong> ${escapeHtml(t.phone_number || 'N/A')}</li>
                                        <li><strong>Source:</strong> ${escapeHtml(t.source || 'N/A')}</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="mt-3">
                                <h6 class="text-muted">Description</h6>
                                <p>${escapeHtml(t.description || 'No description provided')}</p>
                            </div>
                    `;
                    
                    if (t.rating) {
                        modalContent += `
                            <div class="mt-3 p-3 bg-light rounded">
                                <h6 class="text-muted">User Rating</h6>
                                <div class="rating-stars">
                                    ${getStarRating(t.rating)}
                                </div>
                                ${t.feedback ? `<p class="mt-2"><i>"${escapeHtml(t.feedback)}"</i></p>` : ''}
                            </div>
                        `;
                    }
                    
                    modalContent += `</div>`;
                    document.getElementById('modalBodyContent').innerHTML = modalContent;
                }
            } catch (error) {
                document.getElementById('modalBodyContent').innerHTML = `
                    <div class="alert alert-danger">Failed to load ticket details.</div>
                `;
            }
        }

        function getStarRating(rating) {
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                stars += `<i class="bi bi-star${i <= rating ? '-fill' : ''} ${i <= rating ? 'star-filled' : 'star-empty'}"></i>`;
            }
            return stars;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh every 2 minutes
        setInterval(() => window.location.reload(), 120000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.altKey && e.key === 'ArrowLeft') goBack();
            if (e.altKey && e.key === 'n') window.location.href = 'query.php';
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>