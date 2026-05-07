<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/mail_config.php';

if (isset($_SESSION['message'])) {
    echo "<div class='alert alert-{$_SESSION['message_type']} alert-dismissible fade show mt-3' role='alert'>
            {$_SESSION['message']}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
          </div>";
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'driver') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}
$escalations = $conn->prepare("
    SELECT re.*, vr.destination, vr.expected_return_date
    FROM return_escalations re
    JOIN vehicle_requests vr ON vr.request_id = re.request_id
    WHERE re.resolved = 0
      AND (vr.requester_id = ? OR vr.driver_id = ?)
");
$escalations->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$activeEscalations = $escalations->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending requests
$pendingStmt = $conn->query("
    SELECT vr.*, u.name AS requester_name 
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    WHERE vr.status = 'pending_driver'
    ORDER BY vr.created_at DESC
");

// Fetch already approved by driver
$approvedStmt = $conn->query("
    SELECT vr.*, u.name AS requester_name, v.registration
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.status = 'pending_supervisor'
    ORDER BY vr.updated_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Driver Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
      <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>

<body class="bg-light">
     <?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-4">

	<div class="settings-header">   
          <h1 class="settings-title">Welcome, <?= $_SESSION['name'] ?> (Driver)</h1>
          <div class="settings-actions">
            <!-- Back Button -->
              <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                  <i class="bi bi-arrow-left"></i> Back
              </button>
          </div>
        </div>


 <div class="row mb-4">
        <div class="col-12">
	<div class="card border-0 shadow-sm">
        <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
    		<h4>Pending Requests</h4>
		</div>
        
        <div class="card-body">
    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Requester</th>
                <th>Department</th>
                <th>Destination</th>
                <th>Date Required</th>
                <th>Purpose</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while($req = $pendingStmt->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= htmlspecialchars($req['requester_name']) ?></td>
                <td><?= htmlspecialchars($req['department']) ?></td>
                <td><?= htmlspecialchars($req['destination']) ?></td>
                <td><?= htmlspecialchars($req['date_required']) ?></td>
                <td><?= htmlspecialchars($req['purpose']) ?></td>
                <td>
                    <a href="driver_approve_request.php?id=<?= $req['request_id'] ?>" class="btn btn-success btn-sm">Approve & Assign</a>
                    <a href="driver_reject_request.php?id=<?= $req['request_id'] ?>" class="btn btn-danger btn-sm">Reject</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>
</div>
</div>


    <div class="row mb-4">
        <div class="col-12">
	<div class="card border-0 shadow-sm">
  		<div class="card-header card-color text-white d-flex justify-content-between align-items-center">
    		<h4>Approved by You (Awaiting Supervisor)</h4>
		</div>

	<div class="card-body">
    		<table class="table table-bordered">
        	<thead class="table-dark">
            	<tr>
                <th>Requester</th>
                <th>Vehicle</th>
                <th>Destination</th>
                <th>Date Required</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $approvedStmt->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= htmlspecialchars($row['requester_name']) ?></td>
                <td><?= htmlspecialchars($row['registration'] ?? 'Not Assigned') ?></td>
                <td><?= htmlspecialchars($row['destination']) ?></td>
                <td><?= htmlspecialchars($row['date_required']) ?></td>
                <td><span class="badge bg-warning"><?= $row['status'] ?></span></td>

            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>
</div>
</div>
</div>

</body>

<script>

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
            window.location.href = 'driver_dashboard.php';
        }
    }
}

document.addEventListener("DOMContentLoaded", function () {

    const rowsPerPage = 8; // CHANGE THIS IF NEEDED

    document.querySelectorAll("table").forEach((table, index) => {

        // Add universal class
        table.classList.add("table-universal");

        const tbody = table.querySelector("tbody");
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll("tr"));
        const totalRows = rows.length;
        const totalPages = Math.ceil(totalRows / rowsPerPage);

        if (totalPages <= 1) return;

        let currentPage = 1;

        const paginate = () => {
            tbody.innerHTML = "";
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            rows.slice(start, end).forEach(r => tbody.appendChild(r));
            updateButtons();
        };

        // Pagination wrapper
        const pagination = document.createElement("div");
        pagination.className = "table-pagination";

        const updateButtons = () => {
            pagination.innerHTML = "";
            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement("button");
                btn.textContent = i;
                btn.className = (i === currentPage) ? "active" : "";
                btn.onclick = () => { currentPage = i; paginate(); };
                pagination.appendChild(btn);
            }
        };

        // Insert pagination after table
        table.insertAdjacentElement("afterend", pagination);

        paginate();
    });
});

</script>
<?php if (!empty($activeEscalations)): ?>
<div class="modal fade" id="escalationModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">⚠ Vehicle Return Escalation</h5>
      </div>

      <div class="modal-body">
        <?php foreach ($activeEscalations as $e): ?>
            <div class="alert alert-warning">
                <strong>Request #<?= $e['request_id'] ?></strong><br>
                Destination: <?= htmlspecialchars($e['destination']) ?><br>
                Expected Return Date: <?= $e['expected_return_date'] ?><br><br>
                <a href="return_vehicle.php?id=<?= $e['request_id'] ?>" 
                   class="btn btn-danger btn-sm">
                   Submit Return Form
                </a>
            </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    new bootstrap.Modal(document.getElementById("escalationModal")).show();
});
</script>
<?php endif; ?>

<?php include '../vehicle_booking/footer.php'; ?>
</html>
