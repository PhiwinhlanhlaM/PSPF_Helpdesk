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

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hrm') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$stmtPending = $conn->query("
    SELECT vr.*, u.name AS requester_name, v.registration 
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.status = 'pending_hrm'
    ORDER BY vr.created_at DESC
");

$stmtProcessed = $conn->query("
    SELECT vr.*, u.name AS requester_name, v.registration 
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.status IN ('approved','rejected')
    ORDER BY vr.updated_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HRM Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>


<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?> 
<div class="container mt-4">

	<div class="settings-header">   
          <h1 class="settings-title">Welcome, <?= htmlspecialchars($_SESSION['name']) ?> (HRM)</h1>
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
    	<h4>Pending Final Approvals</h4>
	</div>
    <table class="table table-striped">
        <thead class="table-dark">
            <tr>
                <th>Requester</th>
                <th>Department</th>
                <th>Vehicle</th>
                <th>Destination</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($req = $stmtPending->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= htmlspecialchars($req['requester_name']) ?></td>
                <td><?= htmlspecialchars($req['department']) ?></td>
                <td><?= htmlspecialchars($req['registration']) ?></td>
                <td><?= htmlspecialchars($req['destination']) ?></td>
                <td><?= htmlspecialchars($req['date_required']) ?></td>
                <td>
                    <a href="hrm_approve_request.php?id=<?= $req['request_id'] ?>" class="btn btn-success btn-sm">Approve</a>
                    <a href="hrm_reject_request.php?id=<?= $req['request_id'] ?>" class="btn btn-danger btn-sm">Reject</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>  
</div> 

<div class="row mb-4">
        <div class="col-12">

 <div class="card border-0 shadow-sm">
        <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
    <h4>Processed Requests</h4>
</div>
    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Requester</th>
                <th>Vehicle</th>
                <th>Destination</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php while($r = $stmtProcessed->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= htmlspecialchars($r['requester_name']) ?></td>
                <td><?= htmlspecialchars($r['registration']) ?></td>
                <td><?= htmlspecialchars($r['destination']) ?></td>
                <td>
                    <span class="badge bg-<?= $r['status']=='approved'?'success':'danger' ?>">
                        <?= ucfirst($r['status']) ?>
                    </span>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>  
</div> 
</div>  
</div> 
</body>
<?php include '../vehicle_booking/footer.php'; ?>
<script>
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


</html>
