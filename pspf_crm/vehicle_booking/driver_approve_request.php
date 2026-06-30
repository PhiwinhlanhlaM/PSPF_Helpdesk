<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'driver') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$request_id = $_GET['id'] ?? null;
if (!$request_id) {
    header("Location: driver_dashboard.php");
    exit();
}

// Fetch request
$stmt = $conn->prepare("
    SELECT vr.*, u.email AS requester_email, u.department AS requester_department
    FROM vehicle_requests vr
    JOIN users u ON u.user_id = vr.requester_id
    WHERE vr.request_id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

// Available vehicles
$vehicles = $conn->query("SELECT * FROM vehicles WHERE status = 'available'")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $vehicle_id = $_POST['vehicle_id'];

    // Update request: assign driver and vehicle
    $conn->prepare("
        UPDATE vehicle_requests
        SET driver_id = ?, vehicle_id = ?, status = 'pending_supervisor', updated_at = NOW()
        WHERE request_id = ?
    ")->execute([$_SESSION['user_id'], $vehicle_id, $request_id]);

    // Update vehicle status
    $conn->prepare("UPDATE vehicles SET status='allocated', updated_at=NOW() WHERE vehicle_id=?")->execute([$vehicle_id]);

    // Log action
    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, 'Driver approved and assigned vehicle', NOW())
    ")->execute([$request_id, $_SESSION['user_id']]);

    // Notify supervisor
    sendRequestEmail($conn, $request_id, 'driver_approved');

    $_SESSION['message'] = "Request approved successfully.";
    $_SESSION['message_type'] = "success";

    header("Location: driver_dashboard.php");
    exit();
}
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
    <title>Driver Approval</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">

	<div class="settings-header">   
          <h1 class="settings-title">Vehicle Assignment</h1>
          <div class="settings-actions">
            <!-- Back Button -->
              <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                  <i class="bi bi-arrow-left"></i> Back
              </button>
          </div>
        </div>

    <div class="card shadow p-4">
      <div class="card-header card-color text-center text-white">
        <h4>Approve & Assign Vehicle</h4>
	</div>

        <form method="POST">
            <div class="mb-3">
                <label>Requester</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($request['requester_name']) ?>" readonly>
            </div>
            <div class="mb-3">
                <label>Department</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($request['department']) ?>" readonly>
            </div>
            <div class="mb-3">
                <label>Destination</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($request['destination']) ?>" readonly>
            </div>
             <div class="mb-3">
                <label>Purpose</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($request['purpose']) ?>" readonly>
            </div> 
              <div class="mb-3">
                <label>Date Required</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($request['date_required']) ?>" readonly>
            </div>
              <div class="mb-3">
                <label>Time Required</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($request['time_required']) ?>" readonly>
            </div>
            <div class="mb-3">
                <label>Select Vehicle</label>
                <select name="vehicle_id" class="form-select" required>
                    <option value="">-- Select Available Vehicle --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['vehicle_id'] ?>">
                            <?= $v['registration'] ?> (<?= $v['make'] ?> <?= $v['model'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-success" type="submit">Approve Request</button>
            <a href="driver_dashboard.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>

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
</script>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
