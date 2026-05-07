<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$request_id = $_GET['id'] ?? null;
if (!$request_id) {
    header("Location: user_dashboard.php");
    exit();
}

// Fetch vehicle linked to request
$stmt = $conn->prepare("
    SELECT vehicle_id 
    FROM vehicle_requests 
    WHERE request_id = ?
");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) {
    die("Invalid request.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $time_out    = $_POST['time_out'];
    $time_in     = $_POST['time_in'];
    $mileage_out = $_POST['mileage_out'];
    $mileage_in  = $_POST['mileage_in'];

    // Update request → AUTO set actual_return_date
    $conn->prepare("
        UPDATE vehicle_requests
        SET 
            time_out = ?,
            time_in = ?,
            mileage_out = ?,
            mileage_in = ?,
            actual_return_date = NOW(),
            status = 'closed',
            updated_at = NOW()
        WHERE request_id = ?
    ")->execute([
        $time_out,
        $time_in,
        $mileage_out,
        $mileage_in,
        $request_id
    ]);

    // Release vehicle
    $conn->prepare("
        UPDATE vehicles 
        SET status = 'available', updated_at = NOW() 
        WHERE vehicle_id = ?
    ")->execute([$req['vehicle_id']]);

    // Resolve escalation if exists
    $conn->prepare("
        UPDATE return_escalations
        SET resolved_at = NOW()
        WHERE request_id = ?
          AND resolved_at IS NULL
    ")->execute([$request_id]);

    // Log action
    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, 'Vehicle returned', NOW())
    ")->execute([$request_id, $_SESSION['user_id']]);

// Send email AFTER commit
$emailSent = sendVehicleReturnEmail($conn, $request_id);

if (!$emailSent) {
    error_log("Vehicle return email failed for request ID $request_id");
}

echo "<script>
    alert('Trip return recorded successfully.');
    window.location='user_dashboard.php';
</script>";
exit();
    
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Return Vehicle</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>

<body>
<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-5">
    <div class="card shadow p-4 mx-auto" style="max-width: 600px;">
        <h4 class="mb-4">Trip Return Form</h4>

        <form method="POST">

            <div class="mb-3">
                <label class="form-label">Time Out</label>
                <input type="time" name="time_out" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Time In</label>
                <input type="time" name="time_in" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Mileage Out</label>
                <input type="number" name="mileage_out" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Mileage In</label>
                <input type="number" name="mileage_in" class="form-control" required>
            </div>

            <div class="d-flex justify-content-between">
                <button class="btn btn-primary">Submit Return</button>
                <a href="user_dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>

<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
