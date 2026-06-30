<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registration = $_POST['registration'];
    $make = $_POST['make'];
    $model = $_POST['model'];
    $status = 'available';

    $stmt = $conn->prepare("INSERT INTO vehicles (registration, make, model, status) VALUES (?, ?, ?, ?)");
    $stmt->execute([$registration, $make, $model, $status]);

     $_SESSION['message'] = "Vehicle has been added successfully.";
$_SESSION['message_type'] = $newStatus == 1 ? "success" : "warning";

header("Location: manage_users.php");
exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Vehicle</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body>
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
    <h3>Add New Vehicle</h3>
    <form method="POST">
        <div class="mb-3">
            <label>Registration</label>
            <input type="text" name="registration" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Make</label>
            <input type="text" name="make" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Model</label>
            <input type="text" name="model" class="form-control" required>
        </div>
        <button class="btn btn-success">Add Vehicle</button>
    </form>
</div>

</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
