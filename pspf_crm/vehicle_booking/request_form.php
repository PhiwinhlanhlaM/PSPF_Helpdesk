<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$date_requested = date('Y-m-d H:i:s');

// Fetch all distinct departments
$departments = $conn->query("SELECT DISTINCT department FROM users ORDER BY department ASC")
                    ->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $department        = $_POST['department'];
    $purpose           = $_POST['purpose'];
    $destination       = $_POST['destination'];
    $passengers        = $_POST['passengers'];
    $date_required     = $_POST['date_required'];
    $time_required     = $_POST['time_required'];
    $expected_return_date = $_POST['expected_return_date'];

    // Insert request — selected_supervisor left NULL, all supervisors will see it
    $stmt = $conn->prepare("
        INSERT INTO vehicle_requests
        (requester_id, department, purpose, destination, passengers, date_requested,
         date_required, time_required, expected_return_date, selected_supervisor, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'pending_driver')
    ");

    $stmt->execute([
        $user_id, $department, $purpose, $destination, $passengers,
        $date_requested, $date_required, $time_required, $expected_return_date
    ]);

    $request_id = $conn->lastInsertId();

    // Log action
    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, 'Request submitted', NOW())
    ")->execute([$request_id, $user_id]);

    // Send notification to driver, all supervisors, and requester
    sendRequestEmail($conn, $request_id, 'request_submitted');

    echo "<script>alert('Vehicle request submitted successfully!'); window.location='user_dashboard.php';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vehicle Request Form</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vehicle_booking/style5.css" rel="stylesheet">

</head>
<body class="bg-light">

<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-5">
    <div class="card shadow p-4 mx-auto" style="max-width: 800px;">

        <h3 class="card-title mb-4">Vehicle Request Form</h3>

        <form method="POST">

            <div class="mb-3">
                <label>Date Requested</label>
                <input type="text" class="form-control" value="<?= $date_requested ?>" readonly>
            </div>

            <div class="mb-3">
                <label>Department</label>
                <select name="department" class="form-select" required>
                    <option value="">-- Select Department --</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label>Purpose of Trip</label>
                <textarea name="purpose" class="form-control" required></textarea>
            </div>

            <div class="mb-3">
                <label>Destination</label>
                <input type="text" name="destination" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Passengers</label>
                <input type="text" name="passengers" class="form-control" placeholder="Comma-separated names" required>
            </div>

            <div class="row mb-3">
                <div class="col">
                    <label>Date Required</label>
                    <input type="date" name="date_required" class="form-control" required>
                </div>
                <div class="col">
                    <label>Time Required</label>
                    <input type="time" name="time_required" class="form-control" required>
                </div>
            </div>

            <div class="mb-3">
                <label>Expected Return Date</label>
                <input type="date" name="expected_return_date" class="form-control" required>
            </div>

            <div class="text-center">
                <button class="btn btn-primary px-5">Submit Request</button>
            </div>

        </form>

    </div>
</div>

<?php include '../vehicle_booking/footer.php'; ?>

</body>
</html>