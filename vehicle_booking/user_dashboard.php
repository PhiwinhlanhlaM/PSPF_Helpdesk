<?php
session_start();
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
    <h4 class="mb-4">My Vehicle Requests</h4>

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

</body>
</html>
