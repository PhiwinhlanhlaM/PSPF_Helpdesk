<?php
session_start();
require '../vehicle_booking/db.php';
require_once '../vehicle_booking/notification_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'supervisor') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$request_id = $_GET['id'] ?? null;
if (!$request_id) {
    header("Location: supervisor_dashboard.php");
    exit();
}

// Fetch request details + requester email
$stmt = $conn->prepare("
    SELECT vr.*, u.email AS requester_email
    FROM vehicle_requests vr
    JOIN users u ON vr.requester_id = u.user_id
    WHERE vr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    $_SESSION['message'] = "Request not found!";
    $_SESSION['message_type'] = "danger";
    header("Location: supervisor_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = $_POST['reason'] ?? '';

    // Update request status to rejected
  // Supervisor rejection
$conn->prepare("
    UPDATE vehicle_requests
    SET status = 'rejected', rejection_reason = ?, updated_at = NOW()
    WHERE request_id = ?
")->execute([$reason, $request_id]);
    // Log action
    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, 'Supervisor rejected request: $reason', NOW())
    ")->execute([$request_id, $_SESSION['user_id']]);

    // Notify requester
    sendRequestEmail($conn, $request_id, 'supervisor_rejected', $reason);

    $_SESSION['message'] = "Request has been rejected.";
    $_SESSION['message_type'] = "warning";

    header("Location: supervisor_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reject Vehicle Request</title>
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
                <textarea name="reason" class="form-control" placeholder="Enter reason for rejection" required></textarea>
            </div>
            <button class="btn btn-danger" type="submit">Reject Request</button>
            <a href="supervisor_dashboard.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
