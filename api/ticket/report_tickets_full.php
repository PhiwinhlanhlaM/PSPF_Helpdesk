<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);


$AdminUser = $_SESSION['user']['username'];
// Filter values
$start = $_GET['start_date'] ?? '';
$end = $_GET['end_date'] ?? '';
$date_base = $_GET['date_base'] ?? 'query_date';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter conditions
$where = ["1=1"];
$params = [];
$types = "";

if ($start && $end) {
    $where[] = "$date_base BETWEEN ? AND ?";
    $params[] = $start;
    $params[] = $end;
    $types .= "ss";
}
$whereSQL = implode(" AND ", $where);

// Total count for pagination
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tickets WHERE $whereSQL");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();
$total_pages = ceil($total / $limit);

// Fetch data
$sql = "
SELECT t.id, t.title, t.query_date, t.created_by, t.status,
       tc.closed_at, tc.closed_by,
       (SELECT COUNT(*) FROM ticket_reopens tr WHERE tr.ticket_id = t.id) AS reopens,
       (SELECT COUNT(*) FROM ticket_escalations te WHERE te.ticket_id = t.id) AS escalations,
       (SELECT MAX(changed_at) FROM ticket_history th WHERE th.ticket_id = t.id) AS last_history
FROM tickets t
LEFT JOIN ticket_closures tc ON tc.ticket_id = t.id
WHERE $whereSQL
ORDER BY t.query_date DESC
LIMIT ? OFFSET ?";

$finalParams = [...$params, $limit, $offset];
$finalTypes = $types . "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($finalTypes, ...$finalParams);
$stmt->execute();
$result = $stmt->get_result();

// User-wise summary
$summary = [];
if ($start && $end) {
    $sumSQL = "
        SELECT u.username,
               SUM(t.query_date BETWEEN ? AND ?) AS opened,
               SUM(tc.closed_at BETWEEN ? AND ?) AS closed
        FROM users u
        LEFT JOIN tickets t ON u.username = t.created_by
        LEFT JOIN ticket_closures tc ON t.id = tc.ticket_id
        GROUP BY u.username";
    $sumStmt = $conn->prepare($sumSQL);
    $sumStmt->bind_param("ssss", $start, $end, $start, $end);
    $sumStmt->execute();
    $summary = $sumStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sumStmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ticket Summary Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style2.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body class="container py-4">
    <!-- Top Navigation Bar -->
 <header class="navbar">
  <div class="nav-left">
    <img src="./uploads/pspflogo2.png" alt="Logo" class="logo1"> IT-HELP
    
    <nav class="nav-links">
     <a href="./superadmin_dashboard.php">Home</a>
     
        <div class="dropdown">
          <button class="dropbtn">Tickets Management▾</button>
          <div class="dropdown-content">
            <a href="./superadmin_view.php">View All</a>
            <a href="#">Tickets in progress</a>
            <a href="#">Escalation</a>
          </div>
        </div>

        <div class="dropdown">
          <button class="dropbtn">Report ▾</button>
          <div class="dropdown-content">
            <a class="dropdown-item" href="#" onclick="window.print()">Print Report</a>
            <a href="report_queries.php">Report Queries</a>
            <a href="closure_report.php">Closure Report</a>
            <a href="ticket_status_summary.php">Export Stats PDF</a>
          </div>
        </div>

        <a href="#">Settings</a>
       

    </nav>
</div>

    <div class="nav-right">

    <div class="welcome-msg">
      <?= htmlspecialchars($AdminUser)?>
    </div>
    <div class="profile-tab">
      <img src="./uploads/AvatarMaker.png" alt="Profile" />
    </div>

    <a href="logout.php" class="btn btn-outline-light"><i class="bi bi-box-arrow-right"></i> Logout</a>
    <button id="themeToggleBtn" class="btn btn-sm btn-light ms-2">Toggle Theme</button>
  
</div>
</header>
<h2>Ticket Summary Report</h2>

<form class="row mb-3" method="get">
    <div class="col-md-3">
        <label>Date Field</label>
        <select name="date_base" class="form-select">
            <option value="query_date" <?= $date_base === 'query_date' ? 'selected' : '' ?>>Query Date</option>
            <option value="closed_at" <?= $date_base === 'closed_at' ? 'selected' : '' ?>>Closure Date</option>
        </select>
    </div>
    <div class="col-md-3">
        <label>Start Date</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start) ?>" class="form-control">
    </div>
    <div class="col-md-3">
        <label>End Date</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end) ?>" class="form-control">
    </div>
    <div class="col-md-3 d-flex align-items-end">
        <button class="btn btn-primary me-2">Filter</button>
        <a href="?export=pdf&<?= http_build_query($_GET) ?>" class="btn btn-danger">Export PDF</a>
    </div>
</form>

<table class="table table-bordered table-striped">
    <thead class="table-dark">
    <tr>
        <th>ID</th><th>Title</th><th>Created By</th><th>Status</th>
        <th>Query Date</th><th>Closure</th><th>Closed By</th>
        <th>Reopens</th><th>Escalations</th><th>Last History</th>
    </tr>
    </thead>
    <tbody>
    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?= 'TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></td>
            <td><?= htmlspecialchars($row['title']) ?></td>
            <td><?= htmlspecialchars($row['created_by']) ?></td>
            <td><?= htmlspecialchars($row['status']) ?></td>
            <td><?= $row['query_date'] ?></td>
            <td><?= $row['closed_at'] ?? '-' ?></td>
            <td><?= $row['closed_by'] ?? '-' ?></td>
            <td><?= $row['reopens'] ?></td>
            <td><?= $row['escalations'] ?></td>
            <td><?= $row['last_history'] ?? '-' ?></td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<!-- Pagination -->
<nav>
    <ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>

<?php if (!empty($summary)): ?>
    <h4 class="mt-5">User Activity Summary</h4>
    <canvas id="userChart" height="100"></canvas>
    <script>
        const ctx = document.getElementById('userChart').getContext('2d');
        const userLabels = <?= json_encode(array_column($summary, 'username')) ?>;
        const opened = <?= json_encode(array_column($summary, 'opened')) ?>;
        const closed = <?= json_encode(array_column($summary, 'closed')) ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: userLabels,
                datasets: [
                    { label: 'Opened', backgroundColor: '#0d6efd', data: opened },
                    { label: 'Closed', backgroundColor: '#198754', data: closed }
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });
    </script>
<?php endif; ?>

<?php
// Export to PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    ob_start();
    include __FILE__; // re-render the page
    $html = ob_get_clean();
    $mpdf = new \Mpdf\Mpdf(['orientation' => 'L']);
    $mpdf->SetHTMLHeader('<h3 style="text-align:center;">Ticket Summary Report</h3>');
    $mpdf->WriteHTML($html);
    $mpdf->Output('ticket_summary.pdf', 'D');
    exit;
}

$conn->close();
?>
</body>
</html>
