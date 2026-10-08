<?php
require_once __DIR__ . '/trip_helpers.php';
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Admin Dashboard'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="bg-light">
    <?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5 mb-5">
    <div class="no-print">
        <h3 class="text-primary">Admin Dashboard</h3>
        <p>Welcome, <?= htmlspecialchars(vbName($_SESSION['name'])) ?></p>

        <div class="row mt-4 g-3">
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

    <div class="mt-5">
        <?php
        $statsView  = 'admin';
        $statsTitle = 'System Statistics';
        require __DIR__ . '/partials/stats_section.php';
        ?>
    </div>
</div>
<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
