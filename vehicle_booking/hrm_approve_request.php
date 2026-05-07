<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hrm') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$request_id = $_GET['id'] ?? null;
if (!$request_id) header("Location: hrm_dashboard.php");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    if ($action == 'approve') {
        $conn->prepare("
            UPDATE vehicle_requests
            SET hrm_id=?, status='approved', updated_at=NOW()
            WHERE request_id=?
        ")->execute([$_SESSION['user_id'], $request_id]);

        $conn->prepare("
            INSERT INTO request_logs (request_id, action_by, action, created_at)
            VALUES (?, ?, 'HRM approved request', NOW())
        ")->execute([$request_id, $_SESSION['user_id']]);

        sendRequestEmail($conn, $request_id, 'hrm_approved');
    } else {
        $reason = $_POST['rejection_reason'] ?? '';
        $conn->prepare("
            UPDATE vehicle_requests
            SET status='rejected', rejection_reason=?, updated_at=NOW()
            WHERE request_id=?
        ")->execute([$reason, $request_id]);

        $conn->prepare("
            INSERT INTO request_logs (request_id, action_by, action, created_at)
            VALUES (?, ?, 'HRM rejected request', NOW())
        ")->execute([$request_id, $_SESSION['user_id']]);

        sendRequestEmail($conn, $request_id, 'hrm_rejected');
    }

    header("Location: hrm_dashboard.php");
    exit();
}

// Fetch request
$stmt = $conn->prepare("
    SELECT vr.*, u.name AS requester_name
    FROM vehicle_requests vr
    JOIN users u ON u.user_id = vr.requester_id
    WHERE vr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HRM Approval</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
<div class="card p-4 shadow">
    <h4>HRM Review</h4>
    <form method="POST">
        <p><strong>Purpose:</strong> <?= htmlspecialchars($request['purpose']) ?></p>
        <p><strong>Destination:</strong> <?= htmlspecialchars($request['destination']) ?></p>
        <p><strong>Date Required:</strong> <?= htmlspecialchars($request['date_required']) ?></p>
        <p><strong>Time Required:</strong> <?= htmlspecialchars($request['time_required']) ?></p>
        <p><strong>Passengers:</strong> <?= htmlspecialchars($request['passengers']) ?></p> 
        <p><strong>Department:</strong> <?= htmlspecialchars($request['department']) ?></p>
        <p><strong>Requested By:</strong> <?= htmlspecialchars($request['requester_name']) ?></p>
        <div class="mb-3">
            <button type="submit" name="action" value="approve" class="btn btn-success">Approve</button>
            <button type="button" class="btn btn-danger" data-bs-toggle="collapse" data-bs-target="#rejectReason">Reject</button>
        </div>
        <div id="rejectReason" class="collapse mb-3">
            <textarea name="rejection_reason" class="form-control" placeholder="Enter rejection reason"></textarea>
            <button type="submit" name="action" value="reject" class="btn btn-danger mt-2">Submit Rejection</button>
        </div>
    </form>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
