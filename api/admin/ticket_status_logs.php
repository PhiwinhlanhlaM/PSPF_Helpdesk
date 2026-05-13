<?php
// reports/ticket_status_logs.php
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/ticket_status_functions.php';
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

// Check if specific ticket view
$ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : null;

// Pagination
$limit = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filters
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$changedBy = $_GET['changed_by'] ?? '';

// Build query
$whereConditions = [];
$params = [];
$types = '';

if ($ticketId) {
    $whereConditions[] = "tsl.ticket_id = ?";
    $params[] = $ticketId;
    $types .= 'i';
}

if ($statusFilter) {
    $whereConditions[] = "tsl.new_status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($dateFrom) {
    $whereConditions[] = "DATE(tsl.change_date) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo) {
    $whereConditions[] = "DATE(tsl.change_date) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

if ($changedBy) {
    $whereConditions[] = "tsl.changed_by LIKE ?";
    $params[] = "%$changedBy%";
    $types .= 's';
}

$whereClause = $whereConditions ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$countSql = "SELECT COUNT(*) as total FROM ticket_status_logs tsl $whereClause";
$countStmt = $conn->prepare($countSql);
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$countStmt->close();

// Get logs
$sql = "
    SELECT 
        tsl.*,
        t.title AS ticket_title,
        t.priority AS ticket_priority,
        t.query_type,
        u.username AS changed_by_name,
        u.department AS changed_by_department
    FROM ticket_status_logs tsl
    LEFT JOIN tickets t ON tsl.ticket_id = t.id
    LEFT JOIN users u ON tsl.changed_by = u.username
    $whereClause
    ORDER BY tsl.change_date DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get distinct statuses for filter
$statusStmt = $conn->query("SELECT DISTINCT new_status FROM ticket_status_logs ORDER BY new_status");
$allStatuses = $statusStmt->fetch_all(MYSQLI_ASSOC);
$statusStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Status Logs Report - PSPF CRM</title>
    
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
   <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <style>
        :root {
            --primary-blue: #406997;
            --secondary-blue: #3D5C80;
            --light-bg: #f8f9fa;
        }
        
        .status-timeline {
            position: relative;
            padding-left: 30px;
            margin: 20px 0;
        }
        
        .status-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -30px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 3px var(--status-color);
        }
        
        .timeline-content {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid var(--status-color);
        }
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .filter-card {
            background: #406997;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .export-buttons {
            margin-bottom: 20px;
        }
        
        .status-arrow {
            color: #6c757d;
            margin: 0 10px;
        }
        
        .ticket-link {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
        }
        
        .ticket-link:hover {
            text-decoration: underline;
            color: var(--secondary-blue);
        }
        
        .change-reason {
            background-color: #f8f9fa;
            border-left: 3px solid #6c757d;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 0 5px 5px 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
   
<?php include '../agent/topnav.php'; ?>

    <div class="container mt-4">
    <div class="settings-header">   
        <h1 class="settings-title"><i class="bi bi-clock-history me-2"></i>
                        Ticket Status Change Logs</h1>
        <div class="settings-actions">
            <!-- Back Button -->
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
         </div>
    </div>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <i class="bi bi-activity fs-1"></i>
                            <div class="stat-number"><?= $totalRows ?></div>
                            <div>Total Status Changes</div>
                        </div>
                    </div>
                    
                    <?php
                    // Get status distribution
                    $distStmt = $conn->query("
                        SELECT new_status, COUNT(*) as count 
                        FROM ticket_status_logs 
                        GROUP BY new_status 
                        ORDER BY count DESC 
                        LIMIT 3
                    ");
                    $topStatuses = $distStmt->fetch_all(MYSQLI_ASSOC);
                    $distStmt->close();
                    
                    foreach ($topStatuses as $index => $status): 
                        $colors = ['#28a745', '#ffc107', '#dc3545'];
                    ?>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: linear-gradient(135deg, <?= $colors[$index] ?>, <?= $colors[$index] ?>cc);">
                            <i class="bi <?= getStatusIcons($status['new_status']) ?> fs-1"></i>
                            <div class="stat-number"><?= $status['count'] ?></div>
                            <div><?= htmlspecialchars($status['new_status']) ?> Changes</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Filters -->
                <div class="filter-card">
                    <h5><i class="bi bi-funnel text-white"></i> Filter Logs</h5>
                    <form method="GET" class="row g-3">
                        <?php if (!$ticketId): ?>
                        <div class="col-md-3">
                            <label class="form-label text-white">Ticket ID</label>
                            <input type="number" class="form-control" name="ticket_id" 
                                   placeholder="Enter Ticket ID" value="<?= htmlspecialchars($_GET['ticket_id'] ?? '') ?>">
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label class="form-label text-white">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Statuses</option>
                                <?php foreach ($allStatuses as $status): ?>
                                <option value="<?= htmlspecialchars($status['new_status']) ?>" 
                                    <?= ($statusFilter == $status['new_status']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status['new_status']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label text-white">Date From</label>
                            <input type="date" class="form-control" name="date_from" 
                                   value="<?= htmlspecialchars($dateFrom) ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label text-white">Date To</label>
                            <input type="date" class="form-control" name="date_to" 
                                   value="<?= htmlspecialchars($dateTo) ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label text-white">Changed By</label>
                            <input type="text" class="form-control" name="changed_by" 
                                   placeholder="Username" value="<?= htmlspecialchars($changedBy) ?>">
                        </div>
                       
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Apply Filters
                                </button>
                                <a href="ticket_status_logs.php" class="btn btn-info">
                                    <i class="bi bi-x-circle"></i> Clear Filters
                                </a>
                           
                                <button class="btn btn-secondary" onclick="printReport()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                                <a href="export_status_logs_excel.php?ticket_id=<?= $ticketId ?>&status=<?= urlencode($statusFilter) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&changed_by=<?= urlencode($changedBy) ?>"
                                   class="btn btn-primary">
                                    <i class="bi bi-file-earmark-excel"></i> Export
                                </a>
                            </div>
                        
                    </form>
                </div>
                
                <!-- Timeline View for Single Ticket -->
                <?php if ($ticketId && count($logs) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header card-color text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-diagram-3"></i>
                            Status Timeline for Ticket #TCK-<?= str_pad($ticketId, 6, '0', STR_PAD_LEFT) ?>
                            - <?= htmlspecialchars($logs[0]['ticket_title']) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="status-timeline">
                            <?php foreach ($logs as $log): ?>
                            <div class="timeline-item">
                                <?php 
                                $statusColor = getStatusColor($log['new_status']);
                                echo "<style>.timeline-item:nth-child(" . ($index+1) . ")::before { --status-color: var(--bs-$statusColor); }</style>";
                                ?>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <span class="badge bg-<?= $statusColor ?> badge-status">
                                                <i class="bi <?= getStatusIcons($log['new_status']) ?> me-1"></i>
                                                <?= htmlspecialchars($log['new_status']) ?>
                                            </span>
                                            <?php if ($log['old_status']): ?>
                                            <span class="text-muted ms-3">
                                                <i class="bi bi-arrow-right status-arrow"></i>
                                                Changed from: 
                                                <span class="badge bg-secondary">
                                                    <?= htmlspecialchars($log['old_status']) ?>
                                                </span>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('M d, Y h:i A', strtotime($log['change_date'])) ?>
                                        </small>
                                    </div>
                                    
                                    <div class="mt-2">
                                        <i class="bi bi-person-circle me-1"></i>
                                        <strong>Changed by:</strong>
                                        <?= htmlspecialchars($log['changed_by_name'] ?: $log['changed_by']) ?>
                                        <?php if ($log['changed_by_department']): ?>
                                        <span class="text-muted ms-2">
                                            (<?= htmlspecialchars($log['changed_by_department']) ?>)
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($log['change_reason'])): ?>
                                    <div class="change-reason mt-2">
                                        <i class="bi bi-chat-left-text me-1"></i>
                                        <strong>Reason:</strong> <?= htmlspecialchars($log['change_reason']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Table View -->
                <div class="card">
                    <div class="card-header card-color text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-table"></i>
                            Status Change History
                            <span class="badge bg-secondary ms-2"><?= $totalRows ?> records</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($logs)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted"></i>
                            <h5 class="mt-3">No status change logs found</h5>
                            <p class="text-muted">Try adjusting your filters or check back later.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="logsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Ticket</th>
                                        <th>Status Change</th>
                                        <th>Changed By</th>
                                        <th>Date & Time</th>
                                        <th>Reason</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $index => $log): ?>
                                    <tr>
                                        <td><?= $offset + $index + 1 ?></td>
                                        <td>
                                            <a href="../ticket/view_ticket.php?id=<?= $log['ticket_id'] ?>" 
                                               class="ticket-link">
                                                <strong>TCK-<?= str_pad($log['ticket_id'], 6, '0', STR_PAD_LEFT) ?></strong>
                                            </a><br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars(substr($log['ticket_title'], 0, 50)) ?>...
                                            </small><br>
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars($log['query_type']) ?>
                                            </span>
                                            <span class="badge bg-<?= $log['ticket_priority'] == 'High' ? 'Medium' : 'Low' ?>">
                                                <?= htmlspecialchars($log['ticket_priority']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['old_status']): ?>
                                            <span class="badge bg-<?= getStatusColor($log['old_status']) ?>">
                                                <?= htmlspecialchars($log['old_status']) ?>
                                            </span>
                                            <i class="bi bi-arrow-right status-arrow"></i>
                                            <?php endif; ?>
                                            <span class="badge bg-<?= getStatusColor($log['new_status']) ?>">
                                                <i class="bi <?= getStatusIcons($log['new_status']) ?> me-1"></i>
                                                <?= htmlspecialchars($log['new_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($log['changed_by_name'] ?: $log['changed_by']) ?>
                                            <?php if ($log['changed_by_department']): ?>
                                            <br><small class="text-muted">
                                                <?= htmlspecialchars($log['changed_by_department']) ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('M d, Y', strtotime($log['change_date'])) ?><br>
                                            <small class="text-muted">
                                                <?= date('h:i A', strtotime($log['change_date'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if (!empty($log['change_reason'])): ?>
                                            <span class="d-inline-block text-truncate" style="max-width: 200px;" 
                                                  title="<?= htmlspecialchars($log['change_reason']) ?>">
                                                <?= htmlspecialchars($log['change_reason']) ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-muted">No reason provided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?ticket_id=<?= $log['ticket_id'] ?>" 
                                               class="btn btn-sm btn-outline-info" 
                                               title="View timeline">
                                                <i class="bi bi-timeline"></i>
                                            </a>
                                            <a href="../ticket/view_ticket.php?id=<?= $log['ticket_id'] ?>" 
                                               class="btn btn-sm btn-outline-primary" 
                                               title="View ticket">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <nav aria-label="Page navigation" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        Previous
                                    </a>
                                </li>
                                
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        Next
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    
    
    <!-- Footer -->
    <?php include '../footer.php'; ?>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#logsTable').DataTable({
            pageLength: 25,
            order: [[4, 'desc']],
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf'
            ]
        });
        
        // Auto-apply date range if only one is selected
        $('input[type="date"]').on('change', function() {
            var from = $('input[name="date_from"]').val();
            var to = $('input[name="date_to"]').val();
            
            if (from && !to) {
                $('input[name="date_to"]').val(from);
            }
            if (to && !from) {
                $('input[name="date_from"]').val(to);
            }
        });
    });
    
    function printReport() {
        window.print();
    }
    
    function exportToExcel() {
        // Get filter values for filename
        var filters = {
            ticket_id: '<?= $ticketId ?>',
            status: '<?= $statusFilter ?>',
            date_from: '<?= $dateFrom ?>',
            date_to: '<?= $dateTo ?>'
        };
        
        var filename = 'ticket_status_logs';
        if (filters.ticket_id) filename += '_ticket_' + filters.ticket_id;
        if (filters.status) filename += '_' + filters.status;
        if (filters.date_from) filename += '_from_' + filters.date_from;
        if (filters.date_to) filename += '_to_' + filters.date_to;
        filename += '.xlsx';
        
        // Trigger DataTable export
        $('#logsTable').DataTable().button('.buttons-excel').trigger();
    }
    
    // Auto-refresh every 60 seconds if on a ticket timeline view
    <?php if ($ticketId): ?>
    setTimeout(function() {
        window.location.reload();
    }, 60000);
    <?php endif; ?>

    
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
                    window.location.href = '../user_dashboard.php';
                }
            }
        }

    
    </script>
</body>
</html>