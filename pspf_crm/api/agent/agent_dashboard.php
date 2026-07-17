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

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

$agentId      = $_SESSION['user']['id'];
$divisionId   = $_SESSION['user']['division_id'];
$userUsername   = $_SESSION['user']['username'];
$agentEmail   = $_SESSION['user']['email'];
$userDept = $_SESSION['user']['division_name'];


$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];

$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------
// TODAY'S ACTIVITY
// ---------------------------
$today = date('Y-m-d');
$todayActivitySql = "
    SELECT 
        SUM(status = 'Resolved' AND DATE(updated_at) = ?) as resolved_today,
        SUM(DATE(query_date) = ?) as actions_today,
        SUM(status = 'Escalated' AND DATE(updated_at) = ?) as escalated_today,
        SUM(status = 'Open' AND DATE(query_date) = ?) as new_today
    FROM tickets 
    WHERE FIND_IN_SET(?, assigned_to)
";

$todayStmt = $conn->prepare($todayActivitySql);
$todayStmt->bind_param("sssss", $today, $today, $today, $today, $UserEmail);
$todayStmt->execute();
$todayResult = $todayStmt->get_result();
$todayActivity = $todayResult->fetch_assoc();
$todayStmt->close();

// ---------------------------
// RATINGS & FEEDBACK DATA
// ---------------------------
$ratingsSql = "
    SELECT 
        COUNT(*) as total_rated,
        AVG(rating) as avg_rating,
        SUM(rating = 5) as five_star,
        SUM(rating = 4) as four_star,
        SUM(rating = 3) as three_star,
        SUM(rating = 2) as two_star,
        SUM(rating = 1) as one_star,
        COUNT(CASE WHEN comment IS NOT NULL AND comment != '' THEN 1 END) as feedback_count
    FROM ticket_feedback tf
    JOIN tickets t ON tf.ticket_id = t.id
    WHERE FIND_IN_SET(?, t.assigned_to)
";

$ratingsStmt = $conn->prepare($ratingsSql);
$ratingsStmt->bind_param("s", $UserEmail);
$ratingsStmt->execute();
$ratingsResult = $ratingsStmt->get_result();
$ratings = $ratingsResult->fetch_assoc();
$ratingsStmt->close();

// ---------------------------
// RECENT FEEDBACK
// ---------------------------
$feedbackSql = "
    SELECT 
        tr.*,
        t.title as ticket_title,
        t.id as ticket_id,
        DATE_FORMAT(tr.created_at, '%M %d, %Y') as formatted_date
    FROM ticket_feedback tr
    JOIN tickets t ON tr.ticket_id = t.id
    WHERE FIND_IN_SET(?, t.assigned_to)
      AND tr.comment IS NOT NULL 
      AND tr.comment != ''
    ORDER BY tr.created_at DESC
    LIMIT 5
";

$feedbackStmt = $conn->prepare($feedbackSql);
$feedbackStmt->bind_param("s", $UserEmail);
$feedbackStmt->execute();
$feedbackResult = $feedbackStmt->get_result();
$recentFeedback = [];
while ($row = $feedbackResult->fetch_assoc()) {
    $recentFeedback[] = $row;
}
$feedbackStmt->close();

// ---------------------------
// PERFORMANCE DATA
// ---------------------------
$performanceSql = "
    SELECT
        COUNT(*) AS resolved_count,
        ROUND(
            AVG(
                TIMESTAMPDIFF(
                    MINUTE,
                    t.query_date,
                    " . RESOLVED_AT_SQL . "
                )
            ), 2
        ) AS avg_resolution_time
    FROM tickets t
    WHERE FIND_IN_SET(?, t.assigned_to)
      AND t.status IN ('Resolved', 'Closed')
";
                   
$perfStmt = $conn->prepare($performanceSql);
$perfStmt->bind_param("s", $UserEmail);
$perfStmt->execute();
$perfResult = $perfStmt->get_result();
$performance = $perfResult->fetch_assoc();
$perfStmt->close();

// ---------------------------
// TICKET STATS
// ---------------------------
$statsSql = "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Open') AS open_count,
        SUM(status = 'In Progress') AS in_progress,
        SUM(status = 'Pending Feedback') AS pending_feedback,
        SUM(status = 'Resolved') AS resolved,
        SUM(status = 'Escalated') AS escalated,
        SUM(status NOT IN (" . TERMINAL_TICKET_STATUSES . ")
            AND query_date < DATE_SUB(NOW(), INTERVAL 3 DAY)
            ) AS overdue,
        SUM(status = 'Open' AND DATE(query_date) = CURDATE()) AS new_today
    FROM tickets
    WHERE FIND_IN_SET(?, assigned_to)
";

$statsStmt = $conn->prepare($statsSql);
$statsStmt->bind_param("s", $UserEmail);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$myTickets    = (int)($stats['total'] ?? 0);
$myOpen       = (int)($stats['open_count'] ?? 0);
$myInProgress = (int)($stats['in_progress'] ?? 0);
$myPendingFeedback     = (int)($stats['pending_feedback'] ?? 0);
$myResolved   = (int)($stats['resolved'] ?? 0);
$myEscalated  = (int)($stats['escalated'] ?? 0);
$myOverdue    = (int)($stats['overdue'] ?? 0);
$newToday     = (int)($stats['new_today'] ?? 0);

// ---------------------------
// NEW TICKETS (UNASSIGNED OR NEW)
// ---------------------------
$newTicketsSql = "
    SELECT 
        t.*,
        u.username as created_by_name,
        TIMESTAMPDIFF(MINUTE, t.query_date, NOW()) as minutes_old
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.status = 'Open'
      AND (t.assigned_to IS NULL OR t.assigned_to = '')
    ORDER BY t.query_date DESC
    LIMIT 10
";

$newTicketsResult = $conn->query($newTicketsSql);

// ---------------------------
// ESCALATED TICKETS
// ---------------------------
$escalatedTicketsSql = "
    SELECT 
        t.*,
        u.username as created_by_name,
        TIMESTAMPDIFF(HOUR, t.query_date, NOW()) as hours_old,
        (SELECT COUNT(*) FROM ticket_status_logs WHERE ticket_id = t.id) as activity_count
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.id    WHERE t.status = 'Escalated'
      AND FIND_IN_SET(?, t.assigned_to)
    ORDER BY t.updated_at DESC
    LIMIT 10
";

$escStmt = $conn->prepare($escalatedTicketsSql);
$escStmt->bind_param("s", $UserEmail);
$escStmt->execute();
$escalatedTickets = $escStmt->get_result();
$escStmt->close();

// ---------------------------
// RECENT TICKETS
// ---------------------------
$recentSql = "
    SELECT 
        t.*,
        u.username as created_by_name,
        DATEDIFF(NOW(), t.query_date) as days_old
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.id
    WHERE FIND_IN_SET(?, t.assigned_to)
    ORDER BY t.updated_at DESC
    LIMIT 15
";

$recentStmt = $conn->prepare($recentSql);
$recentStmt->bind_param("s", $UserEmail);
$recentStmt->execute();
$recentTickets = $recentStmt->get_result();
$recentStmt->close();

// Calculate satisfaction score
$satisfactionScore = 0;
if (($ratings['total_rated'] ?? 0) > 0) {
    $satisfactionScore = round(($ratings['avg_rating'] ?? 0) * 20); // Convert to percentage
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agent Dashboard - PSPF Helpdesk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="./agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
       
</head>

<body>

<?php include './topnav_agent.php'; ?>
    <!-- Header -->
    <div class="container-xl mt-4 mb-2">
    <div class="settings-header">
        <h1 class="settings-title">
            <i class="bi bi-person-circle me-2"></i>Agent Dashboard
        </h1>
        <div class="settings-actions">
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>
    

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="agent_view.php?filter=open" class="action-btn">
            <i class="bi bi-clock"></i> My Open (<?= $myOpen ?>)
        </a>
        <a href="agent_view.php?filter=in progress" class="action-btn">
            <i class="bi bi-arrow-repeat"></i> In Progress (<?= $myInProgress ?>)
        </a>
        <a href="agent_view.php?filter=escalate" class="action-btn danger">
            <i class="bi bi-exclamation-triangle"></i> Escalated (<?= $myEscalated ?>)
        </a>
        <a href="agent_view.php?filter=pending feedback" class="action-btn" style="color: var(--success);">
            <i class="bi bi-check-circle"></i> Pending Feedback (<?= $myPendingFeedback ?>)
        </a>
        <a href="agent_view.php?filter=resolved" class="action-btn" style="color: var(--info);">
            <i class="bi bi-check-lg"></i> Resolved (<?= $myResolved ?>)
        </a>
        <a href="/pspf_crm/api/ticket/query.php" class="action-btn primary">
            <i class="bi bi-plus-circle"></i> New Ticket
        </a>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card <?= $newToday > 0 ? 'urgent' : '' ?>">
            <div class="stat-icon primary">
                <i class="bi bi-ticket-perforated"></i>
            </div>
            <div class="stat-value"><?= $newToday ?></div>
            <div class="stat-label">New Today</div>
            <?php if ($newToday > 0): ?>
                <div class="stat-badge">🔥 New</div>
            <?php endif; ?>
        </div>

        <div class="stat-card <?= $myOverdue > 0 ? 'urgent' : '' ?>">
            <div class="stat-icon danger">
                <i class="bi bi-exclamation-circle"></i>
            </div>
            <div class="stat-value"><?= $myOverdue ?></div>
            <div class="stat-label">Overdue</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon success">
                <i class="bi bi-star-fill"></i>
            </div>
            <div class="stat-value"><?= $satisfactionScore ?>%</div>
            <div class="stat-label">Satisfaction</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon info">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="stat-value"><?= formatDuration($performance['avg_resolution_time'] ?? null) ?></div>
            <div class="stat-label">Avg Resolution</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="bi bi-bar-chart"></i>
            </div>
            <div class="stat-value"><?= $ratings['total_rated'] ?? 0 ?></div>
            <div class="stat-label">Ratings</div>
        </div>
    </div>

    <!-- Alert Cards - NEW and ESCALATED tickets -->
    <div class="alert-cards">
        <!-- New Tickets Alert -->
        <div class="alert-card <?= $newToday > 0 ? 'urgent' : '' ?>">
            <div class="alert-header <?= $newToday > 0 ? 'urgent' : '' ?>">
                <i class="bi bi-bell-fill"></i>
                <span>New Tickets (<?= $newToday ?>)</span>
            </div>
            <div class="alert-body">
                <?php if ($newTicketsResult && $newTicketsResult->num_rows > 0): ?>
                    <?php while ($ticket = $newTicketsResult->fetch_assoc()): ?>
                        <div class="ticket-item <?= $ticket['priority'] == 'High' ? 'urgent' : '' ?>" 
                             onclick="viewTicket(<?= $ticket['id'] ?>)">
                            <div class="ticket-meta">
                                <span class="ticket-id">#<?= $ticket['id'] ?></span>
                                <span class="ticket-time">
                                    <i class="bi bi-clock"></i>
                                    <?= $ticket['minutes_old'] ?>m ago
                                </span>
                            </div>
                            <div class="ticket-title"><?= htmlspecialchars(substr($ticket['title'], 0, 40)) ?>...</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="ticket-priority priority-<?= strtolower($ticket['priority']) ?>">
                                    <?= $ticket['priority'] ?>
                                </span>
                                <small class="text-muted"><?= htmlspecialchars($ticket['member_type'] ?? 'N/A') ?></small>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox display-6"></i>
                        <p class="mt-2">No new tickets</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="p-2 border-top text-center">
                <a href="agent_view.php?filter=open" class="text-decoration-none small">View All New Tickets →</a>
            </div>
        </div>

        <!-- Escalated Tickets Alert -->
        <div class="alert-card <?= $myEscalated > 0 ? 'warning' : '' ?>">
            <div class="alert-header <?= $myEscalated > 0 ? 'warning' : '' ?>">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>Escalated Tickets (<?= $myEscalated ?>)</span>
            </div>
            <div class="alert-body">
                <?php if ($escalatedTickets && $escalatedTickets->num_rows > 0): ?>
                    <?php while ($ticket = $escalatedTickets->fetch_assoc()): ?>
                        <div class="ticket-item urgent" onclick="viewTicket(<?= $ticket['id'] ?>)">
                            <div class="ticket-meta">
                                <span class="ticket-id">#<?= $ticket['id'] ?></span>
                                <span class="ticket-time">
                                    <i class="bi bi-hourglass-split"></i>
                                    <?= $ticket['hours_old'] ?>h
                                </span>
                            </div>
                            <div class="ticket-title"><?= htmlspecialchars(substr($ticket['title'], 0, 40)) ?>...</div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="ticket-priority priority-high">
                                    <?= $ticket['priority'] ?>
                                </span>
                                <small class="text-muted">
                                    <i class="bi bi-chat"></i> <?= $ticket['activity_count'] ?>
                                </small>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-check-circle display-6 text-success"></i>
                        <p class="mt-2">No escalated tickets</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="p-2 border-top text-center">
                <a href="agent_view.php?filter=escalated" class="text-decoration-none small ">View All Escalated →</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area - Two Column Layout -->
    <div class="main-content">
        <div class="two-column">
            <!-- Left Column - Recent Tickets & Performance -->
            <div>
                <!-- Recent Tickets Table -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="bi bi-clock-history me-2"></i>Recent Tickets</h3>
                        <a href="agent_view.php" class="text-decoration-none small">View All →</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Age</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentTickets && $recentTickets->num_rows > 0): ?>
                                    <?php while ($ticket = $recentTickets->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="fw-semibold">#<?= $ticket['id'] ?></span></td>
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
                                                <?php if ($ticket['days_old'] == 0): ?>
                                                    <span class="badge bg-warning">Today</span>
                                                <?php else: ?>
                                                    <?= $ticket['days_old'] ?>d
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
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            No tickets found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Today's Activity -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="bi bi-calendar-day me-2"></i>Today's Activity</h3>
                    </div>
                    <div class="p-3">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="p-3">
                                    <div class="display-6 text-success"><?= $todayActivity['resolved_today'] ?? 0 ?></div>
                                    <small class="text-muted">Resolved</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3">
                                    <div class="display-6 text-primary"><?= $todayActivity['actions_today'] ?? 0 ?></div>
                                    <small class="text-muted">Actions</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3">
                                    <div class="display-6 text-danger"><?= $todayActivity['escalated_today'] ?? 0 ?></div>
                                    <small class="text-muted">Escalated</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Ratings & Feedback -->
            <div>
                <!-- Ratings Summary -->
                <div class="table-container mb-4">
                    <div class="table-header">
                        <h3><i class="bi bi-star-fill me-2 text-warning"></i>Your Ratings</h3>
                    </div>
                    <div class="p-3">
                        <?php if (($ratings['total_rated'] ?? 0) > 0): ?>
                            <div class="text-center mb-4">
                                <div class="display-1 fw-bold" style="color: var(--primary);">
                                    <?= number_format($ratings['avg_rating'] ?? 0, 1) ?>
                                </div>
                                <div class="rating-stars mb-2">
                                    <?php 
                                    $avg = round($ratings['avg_rating'] ?? 0);
                                    for ($i = 1; $i <= 5; $i++): 
                                    ?>
                                        <?php if ($i <= $avg): ?>
                                            <i class="bi bi-star-fill star-filled"></i>
                                        <?php else: ?>
                                            <i class="bi bi-star star-empty"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                <small class="text-muted">Based on <?= $ratings['total_rated'] ?> ratings</small>
                            </div>

                            <div class="rating-distribution">
                                <div class="mb-2 d-flex align-items-center">
                                    <span class="me-2" style="min-width: 40px;">5★</span>
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?= ($ratings['five_star'] / $ratings['total_rated']) * 100 ?>%"></div>
                                    </div>
                                    <span class="ms-2 small"><?= $ratings['five_star'] ?></span>
                                </div>
                                <div class="mb-2 d-flex align-items-center">
                                    <span class="me-2" style="min-width: 40px;">4★</span>
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar bg-primary" style="width: <?= ($ratings['four_star'] / $ratings['total_rated']) * 100 ?>%"></div>
                                    </div>
                                    <span class="ms-2 small"><?= $ratings['four_star'] ?></span>
                                </div>
                                <div class="mb-2 d-flex align-items-center">
                                    <span class="me-2" style="min-width: 40px;">3★</span>
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar bg-info" style="width: <?= ($ratings['three_star'] / $ratings['total_rated']) * 100 ?>%"></div>
                                    </div>
                                    <span class="ms-2 small"><?= $ratings['three_star'] ?></span>
                                </div>
                                <div class="mb-2 d-flex align-items-center">
                                    <span class="me-2" style="min-width: 40px;">2★</span>
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar bg-warning" style="width: <?= ($ratings['two_star'] / $ratings['total_rated']) * 100 ?>%"></div>
                                    </div>
                                    <span class="ms-2 small"><?= $ratings['two_star'] ?></span>
                                </div>
                                <div class="mb-2 d-flex align-items-center">
                                    <span class="me-2" style="min-width: 40px;">1★</span>
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar bg-danger" style="width: <?= ($ratings['one_star'] / $ratings['total_rated']) * 100 ?>%"></div>
                                    </div>
                                    <span class="ms-2 small"><?= $ratings['one_star'] ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-star display-4"></i>
                                <p class="mt-2">No ratings yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Feedback -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="bi bi-chat-square-quote me-2"></i>Recent Feedback</h3>
                        <?php if (($ratings['feedback_count'] ?? 0) > 0): ?>
                            <span class="badge bg-primary"><?= $ratings['feedback_count'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="p-3" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($recentFeedback)): ?>
                            <?php foreach ($recentFeedback as $feedback): ?>
                                <div class="feedback-card">
                                    <div class="feedback-header">
                                        <span class="feedback-ticket">Ticket #<?= $feedback['ticket_id'] ?></span>
                                        <span class="feedback-date">
                                            <i class="bi bi-calendar3"></i> <?= $feedback['formatted_date'] ?>
                                        </span>
                                    </div>
                                    <div class="rating-stars mb-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?php if ($i <= $feedback['rating']): ?>
                                                <i class="bi bi-star-fill star-filled"></i>
                                            <?php else: ?>
                                                <i class="bi bi-star star-empty"></i>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="feedback-text">
                                        "<?= htmlspecialchars($feedback['comment']) ?>"
                                    </div>
                                    <div class="mt-2 small text-muted">
                                        <i class="bi bi-tag"></i> <?= htmlspecialchars($feedback['ticket_title'] ?? '') ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-chat-square-text display-4"></i>
                                <p class="mt-2">No feedback yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div2 mb-2>
    </div>
</div>

   
<?php include '../footer.php'; ?>

    <!-- Ticket Modal -->
    <div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-ticket-perforated me-2"></i>Ticket Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalBodyContent">
                    Loading...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Back function
        function goBack() {
            if (document.referrer) {
                window.history.back();
            } else {
                window.location.href = '/pspf_crm/api/user_dashboard.php';
            }
        }

        // View ticket details
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
                    
                    // Add rating section if exists
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
                } else {
                    document.getElementById('modalBodyContent').innerHTML = `
                        <div class="alert alert-danger">
                            Failed to load ticket details.
                        </div>
                    `;
                }
            } catch (error) {
                console.error("Error loading ticket:", error);
                document.getElementById('modalBodyContent').innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load ticket details. Please try again.
                    </div>
                `;
            }
        }

        function getStarRating(rating) {
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                if (i <= rating) {
                    stars += '<i class="bi bi-star-fill star-filled"></i>';
                } else {
                    stars += '<i class="bi bi-star star-empty"></i>';
                }
            }
            return stars;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh dashboard every 2 minutes
        setInterval(() => {
            window.location.reload();
        }, 120000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + Left Arrow for back
            if (e.altKey && e.key === 'ArrowLeft') {
                goBack();
            }
            // Alt + N for new ticket
            if (e.altKey && e.key === 'n') {
                window.location.href = 'query.php';
            }
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>