<?php
session_start();
require '../vehicle_booking/db.php';
require '../vehicle_booking/mail_config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_SESSION['user_id'];
    $department = $_POST['department'];
    $purpose = $_POST['purpose'];
    $destination = $_POST['destination'];
    $date_required = $_POST['date_required'];
    $passengers = $_POST['passengers'];

    $stmt = $conn->prepare("INSERT INTO vehicle_requests 
        (requester_id, department, purpose, destination, date_required, passengers, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending_driver', NOW())");
    $stmt->execute([$user_id, $department, $purpose, $destination, $date_required, $passengers]);

    sendMail('driver@company.com', 'New Vehicle Request', 'A new vehicle request has been submitted.');

   $_SESSION['message'] = "User has been {$actionType}d successfully.";
$_SESSION['message_type'] = $newStatus == 1 ? "success" : "warning";

header("Location: user_dashboard.php");
exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request Vehicle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
    <div class="card shadow p-4">
        <h4>Vehicle Request Form</h4>
        <form method="POST">
            <div class="row mb-3">
                <div class="col">
                    <label>Department</label>
                    <input type="text" name="department" class="form-control" required>
                </div>
                <div class="col">
                    <label>Date Required</label>
                    <input type="date" name="date_required" class="form-control" required>
                </div>
            </div>

            <div class="mb-3">
                <label>Destination</label>
                <input type="text" name="destination" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Purpose of Trip</label>
                <textarea name="purpose" class="form-control" required></textarea>
            </div>

            <div class="mb-3">
                <label>Passengers</label>
                <textarea name="passengers" class="form-control" required></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
    </div>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
