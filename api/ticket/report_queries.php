<?php
session_start();
//require '../session_timeout.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

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

// Collect filters from GET, including dates
$filters = [
    'member_type' => $_GET['member_type'] ?? '',
    'region' => $_GET['region'] ?? '',
    'source' => $_GET['source'] ?? '',
    'query_type' => $_GET['query_type'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'status' => $_GET['status'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? ''
];

// Build WHERE clause and params for prepared statement
$whereClauses = ["1=1"];
$params = [];
$types = "";

foreach ($filters as $field => $value) {
    if (in_array($field, ['start_date', 'end_date'])) continue; // Skip here, handle below
    if (!empty($value)) {
        $whereClauses[] = "$field = ?";
        $params[] = $value;
        $types .= "s";
    }
}

// Handle date range filtering
if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
    $whereClauses[] = "query_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];
    $types .= "ss";
} else if (!empty($filters['start_date'])) {
    $whereClauses[] = "query_date >= ?";
    $params[] = $filters['start_date'];
    $types .= "s";
} else if (!empty($filters['end_date'])) {
    $whereClauses[] = "query_date <= ?";
    $params[] = $filters['end_date'];
    $types .= "s";
}

$whereSql = implode(" AND ", $whereClauses);

// Pagination settings
$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Count total records for pagination
$countSql = "SELECT COUNT(*) AS total FROM tickets WHERE $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($totalRows / $limit);
$countStmt->close();

// Fetch page data with limit and offset
$sql = "
SELECT 
    t.id,
    t.title,
    t.query_type,
    t.region,
    t.source,
    t.priority,
    t.status,
    t.phone_number,
    t.query_date,

    CASE 
        WHEN t.status = 'Closed' OR t.status = 'Resolved' 
            THEN f.comment
        ELSE tsl.change_reason
    END AS reason

FROM tickets t

LEFT JOIN (
    SELECT ticket_id, change_reason
    FROM ticket_status_logs
    WHERE id IN (
        SELECT MAX(id) FROM ticket_status_logs GROUP BY ticket_id
    )
) tsl ON t.id = tsl.ticket_id

LEFT JOIN ticket_feedback f ON t.id = f.ticket_id

WHERE $whereSql
ORDER BY t.query_date DESC
LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

// Bind parameters (including limit and offset)
if (!empty($params)) {
    $stmtTypes = $types . "ii";
    $stmtParams = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($stmtTypes, ...$stmtParams);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>General Report - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body data-bs-theme="light">
<!-- Top Navigation -->

    <!-- Header -->
<!-- Top Navigation Bar -->
 <?php include '../agent/topnav.php'; ?>

<div class="container mt-4">
        <div class="settings-header">
            <h1 class="settings-title">General Query Loggings Report</h1>
            <div class="settings-actions">
                <!-- Back Button -->
                    <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                </div>
        </div>
    
    <!-- Users Management -->
    <div class="container mt-5">
        <div class="settings-card">
            <div class="card border-0 shadow-sm">
                <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
                    <span> Query Loggings</span>
        
                </div>
            </div>

            <form method="GET" class="row g-3 mb-4">
                <?php
                $options = [
                    'member_type' => ['Active', 'Annuitant', 'Spouse', 'Dependent', 'Employee'],
                    'region' => ['Manzini', 'Hhohho', 'Shiselweni', 'Lubombo'],
                    'source' => ['Phone', 'E-mail', 'Walk-in', 'Social Media', 'PSPF Staff'],
                    'priority' => ['Low', 'Medium', 'High'],
                    'status' => ['Open', 'In Progress', 'Closed']
                ];
                foreach ($options as $field => $choices): ?>
                    <div class="col-md-4">
                        <label class="form-label"><?= ucwords(str_replace('_', ' ', $field)) ?></label>
                        <select name="<?= $field ?>" class="form-select">
                            <option value="">All</option>
                            <?php foreach ($choices as $choice): ?>
                                <option value="<?= $choice ?>" <?= ($filters[$field] === $choice) ? 'selected' : '' ?>><?= $choice ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>

                <!-- Date Range Filter -->
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($filters['start_date']) ?>" class="form-control" />
                </div>

                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($filters['end_date']) ?>" class="form-control" />
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="report_queries.php" class="btn btn-secondary">Reset</a>
                    <a href="export_stats_pdf.php?<?= http_build_query($filters) ?>" class="btn btn-danger">Export PDF</a>
                    <a href="export_stats_excel.php?<?= http_build_query($filters) ?>" class="btn btn-success">Export Excel</a>
                </div>
            </form>
                        

            
            <table class="table table-bordered table-striped">
                <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Assigned unit</th>
                    <th>Region</th>
                    <th>Source</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Phone</th>
                    <th>Date</th>
                    <th>Reason/Feedback</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($result->num_rows): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= 'TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></td>
                            <td><?= htmlspecialchars($row['title']) ?></td>
                            <td><?= htmlspecialchars($row['query_type']) ?></td>
                            <td><?= htmlspecialchars($row['region']) ?></td>
                            <td><?= htmlspecialchars($row['source']) ?></td>
                            <td><?= htmlspecialchars($row['priority']) ?></td>
                            <td><?= htmlspecialchars($row['status']) ?></td>
                            <td><?= htmlspecialchars($row['phone_number']) ?></td>
                            <td><?= htmlspecialchars($row['query_date']) ?></td>
                            <td><?= htmlspecialchars($row['reason'] ?? '') ?></td> <!-- NEW -->
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="text-center">No results found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <nav>
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </div>
</div>

   <!-- Footer -->
        <?php include '../footer.php'; ?>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
        // Back function
function goBack() {
    const previousPages = <?= json_encode($_SESSION['page_history'] ?? []) ?>;
    
    if (previousPages.length > 1) {
        // Remove current page from history
        previousPages.pop();
        // Get the previous page
        const previousPage = previousPages[previousPages.length - 1];
        window.location.href = previousPage;
    } else {
        // Fallback to browser history or default page
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            // If no referrer or from different domain, go to home
            window.location.href = 'user_dashboard.php';
        }
    }
}
    </script>

</body>
</html>

<?php $conn->close(); ?>
