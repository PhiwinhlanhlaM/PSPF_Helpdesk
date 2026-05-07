<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $department = $_POST['department'];
    $role = $_POST['role'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (name, email, department, role, password) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $department, $role, $password]);

       $_SESSION['message'] = "User has been added successfully.";
$_SESSION['message_type'] = $newStatus == 1 ? "success" : "warning";

header("Location: manage_users.php");
exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body>
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
    <h3>Add New User</h3>
    <form method="POST">
        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Email</label>
            <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Department</label>
            <input type="text" name="department" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Role</label>
            <select name="role" class="form-select" required>
                <option value="user">User</option>
                <option value="driver">Driver</option>
                <option value="supervisor">Supervisor</option>
                <option value="hrm">HRM</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <div class="mb-3">
            <label>Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-success">Add User</button>
    </form>
</div>

</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
