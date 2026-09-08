<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Filters
$assigned_to = $_GET['assigned_to'] ?? '';
$start_date  = $_GET['start_date'] ?? '';
$end_date    = $_GET['end_date'] ?? '';

// Fetch assigned users for dropdown
$userRes = $conn->query("SELECT DISTINCT assigned_to FROM tickets WHERE assigned_to <> '' ORDER BY assigned_to");
if (!$userRes) die("DB error fetching users: " . $conn->error);
$users = $userRes->fetch_all(MYSQLI_ASSOC);

// Build WHERE clause with CSV-safe filtering
$conditions = [];
$params = []; 
$types = '';

if ($assigned_to) {
    $conditions[] = "FIND_IN_SET(?, assigned_to)";
    $params[] = $assigned_to;
    $types .= 's';
}
if ($start_date) {
    $conditions[] = "query_date >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if ($end_date) {
    $conditions[] = "query_date <= ?";
    $params[] = $end_date;
    $types .= 's';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Fetch ticket counts by status
$sql = "SELECT status, COUNT(*) AS cnt FROM tickets $where GROUP BY status";
$stmt = $conn->prepare($sql);
if (!$stmt) die("Prepare failed: " . $conn->error);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$counts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Map counts to all statuses
$labels = ['Open','In Progress','Closed','Escalated'];
$data = array_fill_keys($labels, 0);
foreach ($counts as $row) {
    if (in_array($row['status'], $labels)) {
        $data[$row['status']] = (int)$row['cnt'];
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Ticket Status Summary - PSPF CRM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../style5.css" />
<link rel="stylesheet" href="../style4.css" />
<link rel="stylesheet" href="../agent/agent_style.css" />
<link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
<link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
</head>

<body>

<?php include '../agent/topnav.php'; ?>

<div class="container mt-4">
        <div class="settings-header">
            <h1 class="settings-title">Ticket Status Summary</h1>
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
                    <span> Status graph</span>
                </div>

        <form method="GET" class="row g-3 mb-4">
          <div class="col-md-3">
            <label class="form-label">Assigned To</label>
            <select name="assigned_to" class="form-select">
              <option value="">All</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= htmlspecialchars($u['assigned_to']) ?>" <?= ($assigned_to === $u['assigned_to']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($u['assigned_to']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary me-2">Apply</button>
            <a href="ticket_status_summary.php" class="btn btn-secondary">Reset</a>
          </div>
        </form>
            </div>

<div class="mb-4">
  <canvas id="statusChart" height="150"></canvas>
</div>

<table class="table table-bordered text-center">
  <thead class="table-dark">
    <tr>
      <?php foreach ($labels as $status): ?>
        <th><?= strtoupper($status) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <tr>
      <?php foreach ($labels as $status): ?>
        <td><?= $data[$status] ?></td>
      <?php endforeach; ?>
    </tr>
  </tbody>
</table>
</div>
  </div>
</div>
</div>

  <!-- Footer -->
  <?php include '../footer.php'; ?>

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

const ctx = document.getElementById('statusChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_values($labels)) ?>,
        datasets: [{
            label: 'Tickets by Status',
            data: <?= json_encode(array_values($data)) ?>,
            backgroundColor: [
                'rgba(255, 205, 86, 0.7)',
                'rgba(54, 162, 235, 0.7)',
                'rgba(75, 192, 192, 0.7)',
                'rgba(255, 99, 132, 0.7)'
            ],
            borderColor: [
                'rgb(255, 205, 86)',
                'rgb(54, 162, 235)',
                'rgb(75, 192, 192)',
                'rgb(255, 99, 132)'
            ],
            borderWidth: 1
        }]
    },
    options: { scales: { y: { beginAtZero: true, precision: 0 } } }
});
</script>
</body>
</html>
