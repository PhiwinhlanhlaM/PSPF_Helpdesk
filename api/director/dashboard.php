<?php
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn()) {
    header('Location: /pspf_crm/api/signin/index.php');
    exit;
}
if (!hasRole('it_director')) {
    http_response_code(403);
    echo "<h3>403 – Forbidden</h3><p>Director access required.</p>";
    exit;
}

$activeRole   = getActiveRole();
$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'];
$UserDept     = $_SESSION['user']['department'] ?? '';

$iconClass = 'bi-person-check-fill';
$initials = strtoupper(substr($UserUsername, 0, 1) . substr($UserUsername, -1));

// Scope: all queries scoped to director's department via departments table
// tickets.division_id → divisions.department_id → departments.department_name
$scopeJoin = "
    JOIN divisions dv ON t.division_id = dv.id
    JOIN departments dp ON dv.department_id = dp.id
";
$scopeWhere = "dp.department_name = ?";

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
        SUM(t.status != 'Resolved'
            AND t.query_date < DATE_SUB(NOW(), INTERVAL 3 DAY)
            ) AS overdue,
        SUM(t.status = 'Open' AND DATE(t.query_date) = CURDATE()) AS new_today
    FROM tickets t
    {$scopeJoin}
    WHERE {$scopeWhere}
";
$statsStmt = $conn->prepare($statsSql);
$statsStmt->bind_param("s", $UserDept);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$deptTickets    = (int)($stats['total']            ?? 0);
$deptOpen       = (int)($stats['open_count']       ?? 0);
$deptInProgress = (int)($stats['in_progress']      ?? 0);
$deptPending    = (int)($stats['pending_feedback'] ?? 0);
$deptResolved   = (int)($stats['resolved']         ?? 0);
$deptEscalated  = (int)($stats['escalated']        ?? 0);
$deptOverdue    = (int)($stats['overdue']           ?? 0);
$deptNewToday   = (int)($stats['new_today']         ?? 0);

// ---------------------------
// PERFORMANCE METRICS
// ---------------------------
$perfSql = "
    SELECT
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.query_date, t.updated_at)), 2) AS avg_resolution_time,
        ROUND(AVG(f.rating), 2) AS avg_rating
    FROM tickets t
    {$scopeJoin}
    LEFT JOIN ticket_feedback f ON t.id = f.ticket_id
    WHERE {$scopeWhere}
      AND t.status = 'Resolved'
";
$perfStmt = $conn->prepare($perfSql);
$perfStmt->bind_param("s", $UserDept);
$perfStmt->execute();
$performance = $perfStmt->get_result()->fetch_assoc();
$perfStmt->close();

// ---------------------------
// RATINGS
// ---------------------------
$ratingsSql = "
    SELECT
        COUNT(*) AS total_rated,
        AVG(f.rating) AS avg_rating,
        SUM(f.rating = 5) AS five_star,
        SUM(f.rating = 4) AS four_star,
        SUM(f.rating = 3) AS three_star,
        SUM(f.rating = 2) AS two_star,
        SUM(f.rating = 1) AS one_star,
        COUNT(CASE WHEN f.comment IS NOT NULL AND f.comment != '' THEN 1 END) AS feedback_count
    FROM ticket_feedback f
    JOIN tickets t ON f.ticket_id = t.id
    {$scopeJoin}
    WHERE {$scopeWhere}
";
$ratingsStmt = $conn->prepare($ratingsSql);
$ratingsStmt->bind_param("s", $UserDept);
$ratingsStmt->execute();
$ratings = $ratingsStmt->get_result()->fetch_assoc();
$ratingsStmt->close();

$satisfactionScore = 0;
if (($ratings['total_rated'] ?? 0) > 0) {
    $satisfactionScore = round(($ratings['avg_rating'] ?? 0) * 20);
}

// ---------------------------
// AGENT PERFORMANCE (dept-scoped)
// ---------------------------
$agentPerformance = null;
try {
    $agentSql = "
        SELECT
            u.id,
            u.username,
            GROUP_CONCAT(DISTINCT r.name) AS roles,
            COUNT(DISTINCT t.id) AS total_assigned,
            SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved,
            ROUND(AVG(CASE
                WHEN t.status = 'Resolved' AND t.updated_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, t.query_date, t.updated_at)
                ELSE NULL
            END), 0) AS avg_time,
            ROUND(AVG(f.rating), 1) AS avg_rating,
            SUM(CASE WHEN t.status = 'Escalated' THEN 1 ELSE 0 END) AS escalated,
            SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) AS open_tickets,
            SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        LEFT JOIN tickets t ON FIND_IN_SET(u.email, t.assigned_to)
        LEFT JOIN ticket_feedback f ON t.id = f.ticket_id
        WHERE r.name IN ('agent', 'admin')
          AND u.department = ?
          AND (u.is_active = 1 OR u.is_active IS NULL)
        GROUP BY u.id, u.username
        HAVING total_assigned > 0 OR resolved > 0
        ORDER BY resolved DESC, avg_rating DESC
        LIMIT 15
    ";
    $agentStmt = $conn->prepare($agentSql);
    $agentStmt->bind_param("s", $UserDept);
    $agentStmt->execute();
    $agentPerformance = $agentStmt->get_result();
    $agentStmt->close();
} catch (Exception $e) {
    error_log("Director agent performance query failed: " . $e->getMessage());
}

// ---------------------------
// RECENT TICKETS
// ---------------------------
$recentSql = "
    SELECT
        t.id, t.title, t.priority, t.status, t.query_date,
        u.username AS created_by_name,
        a.username AS assigned_agent,
        DATEDIFF(NOW(), t.query_date) AS days_old
    FROM tickets t
    {$scopeJoin}
    LEFT JOIN users u ON t.created_by = u.id
    LEFT JOIN users a ON FIND_IN_SET(a.email, t.assigned_to)
    WHERE {$scopeWhere}
    ORDER BY t.updated_at DESC
    LIMIT 15
";
$recentStmt = $conn->prepare($recentSql);
$recentStmt->bind_param("s", $UserDept);
$recentStmt->execute();
$recentTickets = $recentStmt->get_result();
$recentStmt->close();

// ---------------------------
// TODAY'S ACTIVITY
// ---------------------------
$today = date('Y-m-d');
$todaySql = "
    SELECT
        SUM(t.status = 'Resolved' AND DATE(t.updated_at) = ?) AS resolved_today,
        SUM(DATE(t.query_date) = ?) AS actions_today,
        SUM(t.status = 'Escalated' AND DATE(t.updated_at) = ?) AS escalated_today,
        SUM(t.status = 'Open' AND DATE(t.query_date) = ?) AS new_today
    FROM tickets t
    {$scopeJoin}
    WHERE {$scopeWhere}
";
$todayStmt = $conn->prepare($todaySql);
$todayStmt->bind_param("sssss", $today, $today, $today, $today, $UserDept);
$todayStmt->execute();
$todayActivity = $todayStmt->get_result()->fetch_assoc();
$todayStmt->close();

// ---------------------------
// IT ACCESS APPROVAL QUEUE
// ---------------------------
$itQueueSql = "
    SELECT
        r.id, r.ref_number, r.employee_name, r.department,
        r.submitted_at,
        u.username AS submitted_by_name,
        GROUP_CONCAT(s.system_id ORDER BY s.id SEPARATOR ', ') AS systems_summary
    FROM it_access_requests r
    LEFT JOIN users u ON r.submitted_by = u.id
    LEFT JOIN it_request_systems s ON s.request_id = r.id
    WHERE r.status = 'awaiting-director'
    GROUP BY r.id
    ORDER BY r.submitted_at ASC
";
$itQueueResult = $conn->query($itQueueSql);
$itQueue = [];
if ($itQueueResult) {
    while ($row = $itQueueResult->fetch_assoc()) {
        $itQueue[] = $row;
    }
}
$pendingCount = count($itQueue);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Dashboard — PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<div class="container-xl mt-5 mb-4">

    <!-- Header -->
    <div class="settings-header mb-4">
        <h1 class="settings-title">
            <i class="bi bi-person-check-fill me-2"></i>
            Director Dashboard —
            <span class="scope-badge"><?= htmlspecialchars($UserDept) ?></span>
        </h1>
        <div class="settings-actions">
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="quick-actions">
        <a href="#it-access-queue" class="action-btn <?= $pendingCount > 0 ? 'danger' : '' ?>">
            <i class="bi bi-shield-check"></i> IT Access Queue
            <?php if ($pendingCount > 0): ?>
                <span class="ms-1 badge bg-danger"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <a href="#recent-tickets" class="action-btn">
            <i class="bi bi-clock-history"></i> Recent Tickets
        </a>
        <a href="#agent-performance" class="action-btn">
            <i class="bi bi-people"></i> Agent Performance
        </a>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card <?= $deptNewToday > 0 ? 'urgent' : '' ?>">
            <div class="stat-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="stat-value"><?= $deptNewToday ?></div>
            <div class="stat-label">New Today</div>
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
            <div class="stat-value"><?= isset($performance['avg_resolution_time']) ? round($performance['avg_resolution_time']) : 0 ?>m</div>
            <div class="stat-label">Avg Resolution</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div class="stat-value"><?= $deptTickets ?></div>
            <div class="stat-label">Total Tickets</div>
        </div>

        <?php if ($pendingCount > 0): ?>
        <div class="stat-card urgent">
            <div class="stat-icon"><i class="bi bi-shield-exclamation"></i></div>
            <div class="stat-value"><?= $pendingCount ?></div>
            <div class="stat-label">IT Access Pending</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- IT Access Approval Queue -->
    <div class="table-container mb-4" id="it-access-queue">
        <div class="table-header <?= $pendingCount > 0 ? 'urgent' : '' ?>">
            <h3>
                <i class="bi bi-shield-check"></i>
                IT Access Approval Queue
                <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-danger ms-2"><?= $pendingCount ?> pending</span>
                <?php endif; ?>
            </h3>
            <a href="/pspf_crm/api/it_access/index.php" class="btn btn-sm btn-light">
                Open Full Form <i class="bi bi-box-arrow-up-right ms-1"></i>
            </a>
        </div>
        <div class="table-responsive">
            <?php if (!empty($itQueue)): ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Systems</th>
                        <th>Submitted By</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itQueue as $req): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($req['ref_number']) ?></code></td>
                        <td><?= htmlspecialchars($req['employee_name']) ?></td>
                        <td><?= htmlspecialchars($req['department']) ?></td>
                        <td>
                            <small class="text-muted">
                                <?= htmlspecialchars($req['systems_summary'] ?? '—') ?>
                            </small>
                        </td>
                        <td><?= htmlspecialchars($req['submitted_by_name'] ?? '—') ?></td>
                        <td>
                            <small><?= date('d M Y', strtotime($req['submitted_at'])) ?></small>
                        </td>
                        <td>
                            <a href="/pspf_crm/api/it_access/index.php?request=<?= urlencode($req['ref_number']) ?>"
                               class="btn btn-sm btn-primary">
                                <i class="bi bi-pen me-1"></i>Review
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-shield-check fs-1 text-success"></i>
                <p class="mt-2">No IT access requests awaiting your approval.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Two-column layout -->
    <div class="two-column">
        <!-- Left: Recent Tickets + Agent Performance -->
        <div>

            <!-- Recent Tickets -->
            <div class="table-container mb-4" id="recent-tickets">
                <div class="table-header">
                    <h3><i class="bi bi-clock-history"></i> Recent Tickets</h3>
                    <span class="text-white small"><?= htmlspecialchars($UserDept) ?></span>
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
            <div class="table-container" id="agent-performance">
                <div class="table-header">
                    <h3><i class="bi bi-people-fill"></i> Agent Performance</h3>
                    <span class="text-white small">Top 15 by resolution</span>
                </div>
                <div class="p-3" style="max-height: 500px; overflow-y: auto;">
                    <?php if ($agentPerformance && $agentPerformance->num_rows > 0): ?>
                        <?php while ($agent = $agentPerformance->fetch_assoc()):
                            $agentRoles = explode(',', $agent['roles'] ?? '');
                            $agentIsAdmin = in_array('admin', $agentRoles);
                        ?>
                        <div class="agent-card">
                            <div class="agent-avatar" style="background: <?= $agentIsAdmin ? '#F6AE2D' : '#7FC8F8' ?>;">
                                <?= strtoupper(substr($agent['username'] ?? 'U', 0, 2)) ?>
                            </div>
                            <div class="agent-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="agent-name">
                                        <?= htmlspecialchars($agent['username'] ?? 'Unknown') ?>
                                        <?php if ($agentIsAdmin): ?>
                                            <span class="badge bg-warning ms-1" style="font-size: 0.6rem;">Admin</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="agent-stats">
                                    <span class="text-success" title="Resolved">
                                        <i class="bi bi-check-circle-fill"></i> <?= $agent['resolved'] ?? 0 ?>
                                    </span>
                                    <span class="text-info" title="Avg Resolution Time">
                                        <i class="bi bi-clock-history"></i>
                                        <?= ($agent['avg_time'] ?? 0) > 0 ? $agent['avg_time'] . 'm' : '0m' ?>
                                    </span>
                                    <span class="agent-rating" title="Average Rating">
                                        <i class="bi bi-star-fill"></i> <?= number_format($agent['avg_rating'] ?? 0, 1) ?>
                                    </span>
                                    <?php if (($agent['escalated'] ?? 0) > 0): ?>
                                    <span class="text-danger" title="Escalated">
                                        <i class="bi bi-exclamation-triangle-fill"></i> <?= $agent['escalated'] ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if (($agent['open_tickets'] ?? 0) > 0): ?>
                                    <span class="text-warning" title="Open">
                                        <i class="bi bi-envelope"></i> <?= $agent['open_tickets'] ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $totalActive     = ($agent['open_tickets'] ?? 0) + ($agent['in_progress'] ?? 0);
                                $workloadPercent = min(100, round(($totalActive / 15) * 100));
                                ?>
                                <?php if ($totalActive > 0): ?>
                                <div class="progress mt-2" style="height: 3px;">
                                    <div class="progress-bar bg-<?= $workloadPercent > 80 ? 'danger' : ($workloadPercent > 50 ? 'warning' : 'success') ?>"
                                         style="width: <?= $workloadPercent ?>%"></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-people fs-1"></i>
                        <p class="mt-2">No agent performance data available</p>
                        <small>No agents in <?= htmlspecialchars($UserDept) ?> have tickets assigned yet.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Ratings + Today's Activity -->
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
                            1 => [$ratings['one_star'] ?? 0, 'danger'],
                        ];
                        foreach ($ratingLevels as $stars => $data):
                            $pct = $ratings['total_rated'] > 0 ? ($data[0] / $ratings['total_rated']) * 100 : 0;
                        ?>
                        <div class="rating-row">
                            <span class="rating-label"><?= $stars ?>★</span>
                            <div class="rating-bar">
                                <div class="rating-bar-fill bg-<?= $data[1] ?>" style="width: <?= $pct ?>%"></div>
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

            <!-- Today's Activity -->
            <div class="table-container mb-4">
                <div class="table-header">
                    <h3><i class="bi bi-calendar-day"></i> Today's Activity</h3>
                </div>
                <div class="p-3">
                    <div class="row text-center">
                        <div class="col-6 col-lg-3">
                            <div class="p-3">
                                <div class="display-6 text-success"><?= $todayActivity['resolved_today'] ?? 0 ?></div>
                                <small class="text-muted">Resolved</small>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="p-3">
                                <div class="display-6 text-primary"><?= $todayActivity['actions_today'] ?? 0 ?></div>
                                <small class="text-muted">Actions</small>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="p-3">
                                <div class="display-6 text-danger"><?= $todayActivity['escalated_today'] ?? 0 ?></div>
                                <small class="text-muted">Escalated</small>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="p-3">
                                <div class="display-6 text-warning"><?= $todayActivity['new_today'] ?? 0 ?></div>
                                <small class="text-muted">New</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ticket status summary -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="bi bi-bar-chart"></i> Ticket Summary</h3>
                </div>
                <div class="p-3">
                    <div class="d-flex flex-column gap-2">
                        <?php
                        $statusItems = [
                            ['label' => 'Open',            'value' => $deptOpen,       'color' => 'warning'],
                            ['label' => 'In Progress',     'value' => $deptInProgress, 'color' => 'primary'],
                            ['label' => 'Pending Feedback','value' => $deptPending,    'color' => 'info'],
                            ['label' => 'Resolved',        'value' => $deptResolved,   'color' => 'success'],
                            ['label' => 'Escalated',       'value' => $deptEscalated,  'color' => 'danger'],
                            ['label' => 'Overdue',         'value' => $deptOverdue,    'color' => 'danger'],
                        ];
                        foreach ($statusItems as $item):
                            $pct = $deptTickets > 0 ? round(($item['value'] / $deptTickets) * 100) : 0;
                        ?>
                        <div>
                            <div class="d-flex justify-content-between mb-1">
                                <small><?= $item['label'] ?></small>
                                <small class="fw-semibold"><?= $item['value'] ?></small>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-<?= $item['color'] ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

<!-- Ticket modal -->
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
    if (document.referrer) window.history.back();
    else window.location.href = '/pspf_crm/api/director/dashboard.php';
}

async function viewTicket(ticketId) {
    const modal = new bootstrap.Modal(document.getElementById('ticketModal'));
    modal.show();
    document.getElementById('modalBodyContent').innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2">Loading...</p>
        </div>`;
    try {
        const res  = await fetch(`/pspf_crm/api/ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
        const data = await res.json();
        if (data.success && data.ticket) {
            const t = data.ticket;
            document.getElementById('modalBodyContent').innerHTML = `
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-muted">Ticket Info</h6>
                            <ul class="list-unstyled">
                                <li><strong>ID:</strong> #${escHtml(t.id)}</li>
                                <li><strong>Title:</strong> ${escHtml(t.title)}</li>
                                <li><strong>Status:</strong> <span class="status-badge">${escHtml(t.status)}</span></li>
                                <li><strong>Priority:</strong> ${escHtml(t.priority)}</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Requester</h6>
                            <ul class="list-unstyled">
                                <li><strong>Member Type:</strong> ${escHtml(t.member_type || 'N/A')}</li>
                                <li><strong>Phone:</strong> ${escHtml(t.phone_number || 'N/A')}</li>
                                <li><strong>Source:</strong> ${escHtml(t.source || 'N/A')}</li>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-3">
                        <h6 class="text-muted">Description</h6>
                        <p>${escHtml(t.description || 'No description provided')}</p>
                    </div>
                </div>`;
        }
    } catch (e) {
        document.getElementById('modalBodyContent').innerHTML =
            '<div class="alert alert-danger">Failed to load ticket details.</div>';
    }
}

function escHtml(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = String(text);
    return d.innerHTML;
}

setInterval(() => window.location.reload(), 120000);
</script>
</body>
</html>
<?php $conn->close(); ?>
