<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$vehicles = $conn->query("SELECT * FROM vehicles ORDER BY status, registration");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Vehicles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body>
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
    <h3>Manage Vehicles</h3>
    <a href="admin_dashboard.php" class="btn btn-secondary btn-sm mb-3">← Back</a>
    <a href="add_vehicle.php" class="btn btn-primary mb-3">Add Vehicle</a>

    <table class="table table-striped">
        <thead class="table-dark">
            <tr>
                <th>Registration</th>
                <th>Make</th>
                <th>Model</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php while($v = $vehicles->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= htmlspecialchars($v['registration']) ?></td>
                <td><?= htmlspecialchars($v['make']) ?></td>
                <td><?= htmlspecialchars($v['model']) ?></td>
                <td>
                    <span class="badge bg-<?= $v['status']=='available'?'success':'danger' ?>">
                        <?= ucfirst($v['status']) ?>
                    </span>
                </td>
                <td>
                    <a href="edit_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <a href="delete_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn btn-danger btn-sm"
                       onclick="return confirm('Are you sure you want to delete this vehicle?')">Delete</a>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
</body>
  <?php include '../vehicle_booking/footer.php'; ?>
</html>
