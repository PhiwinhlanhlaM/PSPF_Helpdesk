<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'driver') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$request_id = $_GET['id'] ?? null;
if (!$request_id) header("Location: driver_dashboard.php");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reason = $_POST['rejection_reason'] ?? '';

    // Update request
    $conn->prepare("
        UPDATE vehicle_requests
        SET status='rejected', rejection_reason=?, updated_at=NOW()
        WHERE request_id=?
    ")->execute([$reason, $request_id]);

    // Log action
    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, 'Driver rejected request', NOW())
    ")->execute([$request_id, $_SESSION['user_id']]);

    // Notify requester
    sendRequestEmail($conn, $request_id, 'driver_rejected');

    $_SESSION['message'] = "Request rejected.";
    $_SESSION['message_type'] = "warning";
    header("Location: driver_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Driver Reject</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
    <div class="card p-4 shadow">
        <h4>Reject Vehicle Request</h4>
        <form method="POST">
            <div class="mb-3">
                <label>Reason for Rejection</label>
                <textarea name="rejection_reason" class="form-control" required></textarea>
            </div>
            <button type="submit" class="btn btn-danger">Reject Request</button>
            <a href="driver_dashboard.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
