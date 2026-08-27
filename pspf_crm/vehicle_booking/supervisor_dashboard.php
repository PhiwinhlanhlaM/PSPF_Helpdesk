<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require '../vehicle_booking/mail_config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'supervisor') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

if (isset($_SESSION['message'])) {
    echo "<div class='alert alert-{$_SESSION['message_type']} alert-dismissible fade show mt-3' role='alert'>
            {$_SESSION['message']}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
          </div>";
    unset($_SESSION['message'], $_SESSION['message_type']);
}

$department = $_SESSION['department'] ?? '';

// ── Requests awaiting THIS supervisor's action ─────────────────────────────
$stmtPending = $conn->prepare("
    SELECT vr.*, u.name AS requester_name, v.registration
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.status = 'pending_supervisor'
      AND vr.department = ?
    ORDER BY vr.date_required ASC
");
$stmtPending->execute([$department]);
$pendingRows = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

// ── Requests still waiting for driver confirmation (awareness only) ────────
$stmtDriver = $conn->prepare("
    SELECT vr.*, u.name AS requester_name
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    WHERE vr.status = 'pending_driver'
      AND vr.department = ?
    ORDER BY vr.date_required ASC
");
$stmtDriver->execute([$department]);
$driverRows = $stmtDriver->fetchAll(PDO::FETCH_ASSOC);

// ── Already processed requests ────────────────────────────────────────────
$stmtProcessed = $conn->prepare("
    SELECT vr.*, u.name AS requester_name, v.registration
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.status IN ('pending_hrm', 'approved', 'rejected', 'completed')
      AND vr.department = ?
    ORDER BY vr.updated_at DESC
");
$stmtProcessed->execute([$department]);
$processedRows = $stmtProcessed->fetchAll(PDO::FETCH_ASSOC);

$statusBadge = [
    'pending_driver'     => 'secondary',
    'pending_supervisor' => 'warning',
    'pending_hrm'        => 'info',
    'approved'           => 'success',
    'rejected'           => 'danger',
    'completed'          => 'primary',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supervisor Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>Welcome, <?= htmlspecialchars($_SESSION['name']) ?> <span class="text-muted fs-5">(Supervisor – <?= htmlspecialchars($department) ?>)</span></h3>
        <a href="../vehicle_booking/logout.php" class="btn btn-secondary btn-sm">Logout</a>
    </div>

    <!-- ── Pending your approval ─────────────────────────────────────── -->
    <h4 class="mt-4 text-primary">
        Pending Your Approval
        <?php if (count($pendingRows)): ?>
            <span class="badge bg-danger ms-2"><?= count($pendingRows) ?></span>
        <?php endif; ?>
    </h4>

    <?php if (empty($pendingRows)): ?>
        <div class="alert alert-info">No requests currently awaiting your approval.</div>
    <?php else: ?>
    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Requester</th>
                <th>Destination</th>
                <th>Date Required</th>
                <th>Time</th>
                <th>Vehicle</th>
                <th>Purpose</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendingRows as $req): ?>
            <tr>
                <td><?= htmlspecialchars($req['requester_name']) ?></td>
                <td><?= htmlspecialchars($req['destination']) ?></td>
                <td><?= htmlspecialchars($req['date_required']) ?></td>
                <td><?= htmlspecialchars($req['time_required']) ?></td>
                <td><?= htmlspecialchars($req['registration'] ?? '—') ?></td>
                <td><?= htmlspecialchars($req['purpose']) ?></td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-primary btn-sm"
                            data-bs-toggle="modal" data-bs-target="#requestModal<?= $req['request_id'] ?>"
                            title="View & action request">
                        <i class="fa fa-eye"></i> View
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ── Request detail / action modals ─────────────────────────────── -->
    <?php foreach ($pendingRows as $req): ?>
    <div class="modal fade" id="requestModal<?= $req['request_id'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="fa fa-car me-2"></i>Request #<?= $req['request_id'] ?> &mdash; Details</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6"><small class="text-muted">Requester</small><div class="fw-semibold"><?= htmlspecialchars($req['requester_name']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Department</small><div class="fw-semibold"><?= htmlspecialchars($req['department']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Destination</small><div class="fw-semibold"><?= htmlspecialchars($req['destination']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Purpose</small><div class="fw-semibold"><?= htmlspecialchars($req['purpose']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Date Required</small><div class="fw-semibold"><?= htmlspecialchars($req['date_required']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Time Required</small><div class="fw-semibold"><?= htmlspecialchars($req['time_required']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Passengers</small><div class="fw-semibold"><?= htmlspecialchars($req['passengers']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Expected Return</small><div class="fw-semibold"><?= htmlspecialchars($req['expected_return_date']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Vehicle</small><div class="fw-semibold"><?= htmlspecialchars($req['registration'] ?? '—') ?></div></div>
            </div>

            <!-- Rejection reason (revealed on demand) -->
            <div class="collapse mt-4" id="rejectBox<?= $req['request_id'] ?>">
              <hr>
              <form method="POST" action="supervisor_approve_request.php?id=<?= $req['request_id'] ?>">
                <input type="hidden" name="action" value="reject">
                <label class="form-label fw-bold text-danger">Reason for Rejection</label>
                <textarea name="rejection_reason" class="form-control mb-2" rows="3"
                          placeholder="Enter a reason for rejecting this request" required></textarea>
                <button type="submit" class="btn btn-danger"><i class="fa fa-times me-1"></i>Confirm Rejection</button>
              </form>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger"
                    data-bs-toggle="collapse" data-bs-target="#rejectBox<?= $req['request_id'] ?>">
              <i class="fa fa-times-circle me-1"></i>Reject
            </button>
            <form method="POST" action="supervisor_approve_request.php?id=<?= $req['request_id'] ?>" class="d-inline">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success"><i class="fa fa-check-circle me-1"></i>Approve</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── Awaiting driver confirmation (FYI) ────────────────────────── -->
    <?php if (!empty($driverRows)): ?>
    <h4 class="mt-5 text-secondary">
        Awaiting Driver Confirmation
        <span class="badge bg-secondary ms-2"><?= count($driverRows) ?></span>
    </h4>
    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Requester</th>
                <th>Destination</th>
                <th>Date Required</th>
                <th>Purpose</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($driverRows as $req): ?>
            <tr>
                <td><?= htmlspecialchars($req['requester_name']) ?></td>
                <td><?= htmlspecialchars($req['destination']) ?></td>
                <td><?= htmlspecialchars($req['date_required']) ?></td>
                <td><?= htmlspecialchars($req['purpose']) ?></td>
                <td><span class="badge bg-secondary">Waiting for driver</span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Processed requests ─────────────────────────────────────────── -->
    <h4 class="mt-5 text-success">Processed Requests</h4>

    <?php if (empty($processedRows)): ?>
        <div class="alert alert-secondary">No processed requests yet.</div>
    <?php else: ?>
    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Requester</th>
                <th>Destination</th>
                <th>Date Required</th>
                <th>Vehicle</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($processedRows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['requester_name']) ?></td>
                <td><?= htmlspecialchars($r['destination']) ?></td>
                <td><?= htmlspecialchars($r['date_required']) ?></td>
                <td><?= htmlspecialchars($r['registration'] ?? '—') ?></td>
                <td>
                    <span class="badge bg-<?= $statusBadge[$r['status']] ?? 'secondary' ?>">
                        <?= str_replace('_', ' ', $r['status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const rowsPerPage = 8;
    document.querySelectorAll("table").forEach(table => {
        const tbody = table.querySelector("tbody");
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll("tr"));
        const totalPages = Math.ceil(rows.length / rowsPerPage);
        if (totalPages <= 1) return;
        let currentPage = 1;
        const paginate = () => {
            tbody.innerHTML = "";
            rows.slice((currentPage - 1) * rowsPerPage, currentPage * rowsPerPage)
                .forEach(r => tbody.appendChild(r));
            updateButtons();
        };
        const pagination = document.createElement("div");
        pagination.className = "table-pagination";
        const updateButtons = () => {
            pagination.innerHTML = "";
            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement("button");
                btn.textContent = i;
                btn.className = i === currentPage ? "active" : "";
                btn.onclick = () => { currentPage = i; paginate(); };
                pagination.appendChild(btn);
            }
        };
        table.insertAdjacentElement("afterend", pagination);
        paginate();
    });
});
</script>

<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
