<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$escalations = $conn->prepare("
    SELECT re.*, vr.destination, vr.expected_return_date
    FROM return_escalations re
    JOIN vehicle_requests vr ON vr.request_id = re.request_id
    WHERE re.resolved = 0
      AND (vr.requester_id = ? OR vr.driver_id = ?)
");
$escalations->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$activeEscalations = $escalations->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------
   PAGINATION CONFIGURATION
--------------------------------*/
$records_per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $records_per_page;

/* -------------------------------
   COUNT TOTAL RECORDS
--------------------------------*/
$count_stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM vehicle_requests 
    WHERE requester_id = ?
");
$count_stmt->execute([$user_id]);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

/* -------------------------------
   FETCH PAGINATED RECORDS
--------------------------------*/
$stmt = $conn->prepare("
    SELECT vr.*, v.registration
    FROM vehicle_requests vr
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.requester_id = ?
    ORDER BY vr.created_at DESC
    LIMIT $records_per_page OFFSET $offset
");
$stmt->execute([$user_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Vehicle Requests</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>

<body class="page-bg bg-user">

<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-5">
    
<div class="settings-header">
        <h1 class="settings-title">
            <i class="bi bi-person-circle me-2"></i>My Vehicle Requests
        </h1>
        <div class="settings-actions">
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <div class="table-responsive table-wrapper">
        <table class="table table-bordered table-striped table-hover styled-table table-universal">
            <thead class="table-dark text-center">
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Purpose</th>
                    <th>Destination</th>
                    <th>Vehicle</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody class="text-center align-middle">
            <?php if (count($requests) === 0): ?>
                <tr>
                    <td colspan="6" class="py-4 text-muted">
                        No vehicle requests found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($requests as $req): ?>
                <tr>
                    <td><?= $req['request_id'] ?></td>
                    <td><?= date('Y-m-d', strtotime($req['created_at'])) ?></td>
                    <td><?= htmlspecialchars($req['purpose']) ?></td>
                    <td><?= htmlspecialchars($req['destination']) ?></td>
                    <td><?= $req['registration'] ?? 'Pending Allocation' ?></td>
                    <td>
                        <button type="button" class="btn btn-outline-primary btn-sm mb-2"
                                data-bs-toggle="modal" data-bs-target="#requestModal<?= $req['request_id'] ?>"
                                title="View request details">
                            <i class="fa fa-eye"></i> View
                        </button>
                        <br>
                        <?php
                        $status = $req['status'] ?? 'unknown';

                        switch ($status) {
                            case 'pending_driver':
                                echo '<span class="badge bg-secondary">Awaiting Driver Approval</span>';
                                break;

                            case 'pending_supervisor':
                                echo '<span class="badge bg-info text-dark">Awaiting Supervisor Approval</span>';
                                break;

                            case 'pending_hrm':
                                echo '<span class="badge bg-primary">Awaiting HRM Approval</span>';
                                break;

                            case 'approved':
                                echo '<span class="badge bg-success mb-1 d-inline-block">Approved</span><br>';
                                echo '<a href="return_form.php?id=' . $req['request_id'] . '" 
                                        class="btn btn-sm btn-warning mt-2">
                                        Return Vehicle
                                      </a>';
                                break;

                            case 'rejected':
                                echo '<span class="badge bg-danger">Rejected</span>';
                                break;

                            case 'closed':
                                echo '<span class="badge bg-dark">Trip Completed</span>';
                                break;

                            default:
                                echo '<span class="badge bg-light text-dark">Unknown</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Request detail modals (read-only) ──────────────────────────── -->
    <?php
    $statusLabels = [
        'pending_driver'     => ['secondary', 'Awaiting Driver Approval'],
        'pending_supervisor' => ['info',      'Awaiting Supervisor Approval'],
        'pending_hrm'        => ['primary',   'Awaiting HRM Approval'],
        'approved'           => ['success',   'Approved'],
        'rejected'           => ['danger',    'Rejected'],
        'closed'             => ['dark',      'Trip Completed'],
    ];
    foreach ($requests as $req):
        [$badgeColor, $badgeText] = $statusLabels[$req['status']] ?? ['light text-dark', 'Unknown'];
    ?>
    <div class="modal fade" id="requestModal<?= $req['request_id'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content text-start">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="fa fa-car me-2"></i>Request #<?= $req['request_id'] ?> &mdash; Details</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3"><span class="badge bg-<?= $badgeColor ?>"><?= $badgeText ?></span></div>
            <div class="row g-3">
              <div class="col-md-6"><small class="text-muted">Department</small><div class="fw-semibold"><?= htmlspecialchars($req['department']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Destination</small><div class="fw-semibold"><?= htmlspecialchars($req['destination']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Purpose</small><div class="fw-semibold"><?= htmlspecialchars($req['purpose']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Passengers</small><div class="fw-semibold"><?= htmlspecialchars($req['passengers']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Date Required</small><div class="fw-semibold"><?= htmlspecialchars($req['date_required']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Time Required</small><div class="fw-semibold"><?= htmlspecialchars($req['time_required']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Expected Return</small><div class="fw-semibold"><?= htmlspecialchars($req['expected_return_date']) ?></div></div>
              <div class="col-md-6"><small class="text-muted">Assigned Vehicle</small><div class="fw-semibold"><?= htmlspecialchars($req['registration'] ?? 'Pending Allocation') ?></div></div>
              <div class="col-md-6"><small class="text-muted">Date Requested</small><div class="fw-semibold"><?= htmlspecialchars(date('Y-m-d', strtotime($req['created_at']))) ?></div></div>
            </div>
            <?php if ($req['status'] === 'rejected' && !empty($req['rejection_reason'])): ?>
            <div class="alert alert-danger mt-3 mb-0">
              <strong>Rejection Reason:</strong> <?= htmlspecialchars($req['rejection_reason']) ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <?php if ($req['status'] === 'approved'): ?>
              <a href="return_form.php?id=<?= $req['request_id'] ?>" class="btn btn-warning">
                <i class="fa fa-undo me-1"></i>Return Vehicle
              </a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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

function goBack() {
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            window.location.href = './user_dashboard.php';
        }
    }

</script>
<?php endif; ?>

<?php include '../vehicle_booking/footer.php'; ?>

</body>
</html>
