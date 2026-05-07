<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
      <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include '../vehicle_booking/navbar.php'; ?> 
<div class="container mt-5">
    <h3 class="text-primary">Admin Dashboard</h3>
    <p>Welcome, <?= htmlspecialchars($_SESSION['name']) ?></p>
    <a href="../vehicle_booking/logout.php" class="btn btn-secondary btn-sm float-end">Logout</a>

    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <h4>Manage Users</h4>
                    <p>Add, update, or remove system users and assign roles.</p>
                    <a href="manage_users.php" class="btn btn-primary">Go to Users</a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <h4>Manage Vehicles</h4>
                    <p>Add new vehicles, update info, and manage availability.</p>
                    <a href="manage_vehicles.php" class="btn btn-success">Go to Vehicles</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
