<?php  
session_start();

require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';
require_once '../../vendor/autoload.php';
require_once '../includes/xlsx_styles.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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


//require 'session_timeout.php';
  require '../../vendor/autoload.php'; // dompdf
    use Dompdf\Dompdf; 


// Input filters
$searchText   = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$createdBy    = $_GET['created_by'] ?? '';
$startDate    = $_GET['start_date'] ?? '';
$endDate      = $_GET['end_date'] ?? '';

// Pagination
$limit  = 10;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Dynamic WHERE building
$where  = ["1"];
$params = [];
$types  = "";

if ($searchText !== '') {
    $where[] = "(t.created_by LIKE ? OR c.closed_by LIKE ? OR ts.department LIKE ?)";
    $like = "%$searchText%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= "sss";
}

if ($statusFilter !== '') {
    $where[] = "t.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($createdBy !== '') {
    $where[] = "t.created_by = ?";
    $params[] = $createdBy;
    $types .= "s";
}

if ($startDate !== '' && $endDate !== '') {
    $where[] = "t.query_date BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
    $types .= "ss";
}

// Count total
$countSql = "
    SELECT COUNT(*) AS total
    FROM ticket_closures c
    JOIN tickets t ON c.ticket_id = t.id
    JOIN ticket_success ts ON t.id = ts.ticket_id
    WHERE " . implode(" AND ", $where);

$countStmt = $conn->prepare($countSql);
if (!empty($types)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$countStmt->close();
// ===============================
// EXPORT LOGIC (PDF or CSV)
// ===============================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    ob_start();
    $sql_all = "
        SELECT c.ticket_id, t.title, t.member_type, t.source, t.priority,
               t.created_by, t.query_date, c.closed_by, c.closed_at,
               ts.department, t.status, c.closure_reason, t.description
        FROM ticket_closures c
        JOIN tickets t ON c.ticket_id = t.id
        JOIN ticket_success ts ON t.id = ts.ticket_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY c.closed_at DESC";

    $stmt_all = $conn->prepare($sql_all);
    if (!empty($types)) {
        $stmt_all->bind_param($types, ...$params);
    }
    $stmt_all->execute();
    $exportRows = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_all->close();

    $xlsHeaders = [
        'Ticket ID', 'Title', 'Member Type', 'Source', 'Priority',
        'Created By', 'Date Submitted', 'Closed By', 'Closed At',
        'Department', 'Status', 'Closure Reason', 'Description',
    ];

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Closure Report');

    $sheet->fromArray([$xlsHeaders], null, 'A1');

    $rowNum = 2;
    foreach ($exportRows as $r) {
        $sheet->fromArray([[
            'TCK-' . str_pad($r['ticket_id'], 6, '0', STR_PAD_LEFT),
            $r['title'],
            $r['member_type']    ?? '',
            $r['source']         ?? '',
            $r['priority']       ?? '',
            $r['created_by'],
            $r['query_date'],
            $r['closed_by'],
            $r['closed_at'],
            $r['department'],
            $r['status'],
            $r['closure_reason'] ?? '',
            $r['description']    ?? '',
        ]], null, 'A' . $rowNum);
        $rowNum++;
    }

    applyXlsxStyles($sheet, $xlsHeaders, count($exportRows), 'Closure Report');

    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Closure_Report_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
// ✅ EXPORT PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {

    // You can decide to export all or just current page — here we’ll export all filtered
    $sql_all = "
        SELECT c.ticket_id, t.created_by, t.query_date, c.closed_by, c.closed_at, ts.department, t.status
        FROM ticket_closures c
        JOIN tickets t ON c.ticket_id = t.id
        JOIN ticket_success ts ON t.id = ts.ticket_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY c.closed_at DESC";

    $stmt_all = $conn->prepare($sql_all);
    if (!empty($types)) {
        $stmt_all->bind_param($types, ...$params);
    }
    $stmt_all->execute();
    $res = $stmt_all->get_result();

    ob_start();
    // ===============================
// PAGINATED FETCH FOR TABLE DISPLAY
// ===============================
$sql = "
    SELECT c.ticket_id, t.created_by, t.query_date, c.closed_by, c.closed_at, ts.department, t.status
    FROM ticket_closures c
    JOIN tickets t ON c.ticket_id = t.id
    JOIN ticket_success ts ON t.id = ts.ticket_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY c.closed_at DESC
    LIMIT ? OFFSET ?";

$typesWithPagination = $types . "ii";
$paramsWithPagination = [...$params, $limit, $offset];

$stmt = $conn->prepare($sql);
if (!empty($typesWithPagination)) {
    $stmt->bind_param($typesWithPagination, ...$paramsWithPagination);
}
$stmt->execute();
$result = $stmt->get_result();
?>
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Titillium+Web&display=swap');
            body {
                font-family: 'Titillium Web', sans-serif;
                margin: 30px;
            }
            .header {
                text-align: center;
                font-size: 24px;
                font-weight: bold;
                margin-bottom: 10px;
            }
            .logo {
                position: absolute;
                top: 20px;
                left: 20px;
                width: 100px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th, td {
                border: 1px solid #000;
                padding: 8px;
                font-size: 12px;
                text-align: center;
            }
            th {
                background-color: #3D5C80;
                color: white;
            }
        </style>
    </head>
    <body>
        <img src="../uploads/pspflogo1.png" class="logo" />
        <div class="header">Closure Report</div>

        <table>
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Created By</th>
                    <th>Query Date</th>
                    <th>Closed By</th>
                    <th>Closed At</th>
                    <th>Department</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($r = $res->fetch_assoc()): ?>
                    <tr>
                        <td><?= 'TCK-' . str_pad($r['ticket_id'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td><?= htmlspecialchars($r['created_by']) ?></td>
                        <td><?= htmlspecialchars($r['query_date']) ?></td>
                        <td><?= htmlspecialchars($r['closed_by']) ?></td>
                        <td><?= htmlspecialchars($r['closed_at']) ?></td>
                        <td><?= htmlspecialchars($r['department']) ?></td>
                        <td><?= htmlspecialchars($r['status']) ?></td>
                    </tr>
                <?php endwhile; ?>
                <?php if ($res->num_rows === 0): ?>
                    <tr><td colspan="7">No records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    $html = ob_get_clean();
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("Closure_Report_" . date('Y-m-d') . ".pdf", ["Attachment" => true]);
    exit;
}
// ===============================
// PAGINATED FETCH FOR TABLE DISPLAY
// ===============================
$sql = "
    SELECT c.ticket_id, t.created_by, t.query_date, c.closed_by, c.closed_at, ts.department, t.status
    FROM ticket_closures c
    JOIN tickets t ON c.ticket_id = t.id
    JOIN ticket_success ts ON t.id = ts.ticket_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY c.closed_at DESC
    LIMIT ? OFFSET ?";

$typesWithPagination = $types . "ii";
$paramsWithPagination = [...$params, $limit, $offset];

$stmt = $conn->prepare($sql);
if (!empty($typesWithPagination)) {
    $stmt->bind_param($typesWithPagination, ...$paramsWithPagination);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Closure Report - PSPF CRM</title>
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
   <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body data-bs-theme="light">
<!-- Top Navigation -->

    <!-- Header -->
<!-- Top Navigation Bar -->
<?php include './topnav.php';  ?>

       


<div class="container mt-4">
 <div class="settings-header">   
        <h1 class="settings-title">Closed Tickets Report</h1>
        <div class="settings-actions">
            <!-- Back Button -->
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
         </div>
    </div>

    <div class="container mt-5">
        <div class="settings-card">
            <div class="card border-0 shadow-sm">
                <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
                    <span> Tickets</span>

                </div>

<form method="get" class="row g-3 mb-3">
    <div class="col"><input type="text" name="search" class="form-control" placeholder="Search text" value="<?= htmlspecialchars($searchText) ?>"></div>
    <div class="col"><input type="text" name="created_by" class="form-control" placeholder="Created by" value="<?= htmlspecialchars($createdBy) ?>"></div>
    <div class="col">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
            <option value="Resolved" <?= $statusFilter === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
        </select>
    </div>
    <div class="col"><input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>"></div>
    <div class="col"><input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>"></div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">Filter</button>
        <button type="submit" name="export" value="excel" class="btn btn-outline-success">
  <i class="bi bi-file-earmark-excel"></i> Export Excel
</button>
        <button type="submit" name="export" value="pdf" class="btn btn-outline-secondary">Export PDF</button>
    </div>
</form>
            </div>
        
                
<table class="table table-bordered">
    <thead class="table-dark">
        <tr>
            <th>Ticket ID</th>
            <th>Created By</th>
            <th>Query Date</th>
            <th>Closed By</th>
            <th>Closed At</th>
            <th>Department</th>
            <th>Status</th>
            <th>View</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($r = $result->fetch_assoc()): ?>
        <tr>
            <td><?= 'TCK-' . str_pad($r['ticket_id'], 6, '0', STR_PAD_LEFT) ?></td>
            <td><?= htmlspecialchars($r['created_by']) ?></td>
            <td><?= htmlspecialchars($r['query_date']) ?></td>
            <td><?= htmlspecialchars($r['closed_by']) ?></td>
            <td><?= htmlspecialchars($r['closed_at']) ?></td>
            <td><?= htmlspecialchars($r['department']) ?></td>
            <td><span class="badge bg-success"><?= htmlspecialchars($r['status']) ?></span></td>
            <td><a href="ticket_success.php?ticket_id=<?= urlencode($r['ticket_id']) ?>" class="btn btn-sm btn-outline-primary">View</a></td>
        </tr>
    <?php endwhile; ?>
    <?php if ($result->num_rows === 0): ?>
        <tr><td colspan="8" class="text-center">No records found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<nav>
<ul class="pagination">
    <?php if ($page > 1): ?>
        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a></li>
    <?php endif; ?>
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </li>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a></li>
    <?php endif; ?>
</ul>
</nav>
</div>
        </div>
    
</div>
<footer class="footer">
  <div class="footer-container">
    <div class="logout-link">
      <p>&copy; <?= date('Y') ?> All rights reserved to PSPF ICT.</p>
      <p>Version 1.0.0  </p>
      <p><small>Logged in as <?= htmlspecialchars($_SESSION['user']['username']) ?> (<?= getActiveRole() ?>)| <a href="../signin/logout.php">Logout</a></small></p>
    </div>

  </div>
</footer>
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
            window.location.href = '../user_dashboard.php';
        }
    }
}
    </script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
