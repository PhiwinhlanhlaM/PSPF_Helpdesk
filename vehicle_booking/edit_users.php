<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$user_id = $_GET['id'] ?? null;
if (!$user_id) {
    header("Location: manage_users.php");
    exit();
}

// Fetch user
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found!");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $role = $_POST['role'];
    $expiry_date = !empty($_POST['role_expiry_date']) ? $_POST['role_expiry_date'] : null;

    // Update role and expiry date
    $update = $conn->prepare("UPDATE users SET role = ?, role_expiry_date = ? WHERE user_id = ?");
    $update->execute([$role, $expiry_date, $user_id]);

  // Handle admin-set password reset
if (!empty($_POST['new_password']) || !empty($_POST['confirm_password'])) {

    if ($_POST['new_password'] !== $_POST['confirm_password']) {
        $error = "Passwords do not match.";
    } elseif (strlen($_POST['new_password']) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        $hashed_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

        $reset = $conn->prepare("
            UPDATE users 
            SET password = ?, password_reset_required = 1 
            WHERE user_id = ?
        ");
        $reset->execute([$hashed_password, $user_id]);

        $reset_msg = "Temporary password set successfully. User will be forced to change it on next login.";
    }
}


    $success = "User details updated successfully.";
    $stmt->execute([$user_id]); // reload updated data
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body>
<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-5">
    <div class="card p-4 shadow">
        <h4>Edit User - <?= htmlspecialchars($user['name']) ?></h4>
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <?php if (!empty($reset_msg)): ?><div class="alert alert-info"><?= $reset_msg ?></div><?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label>Email</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
            </div>
            <div class="mb-3">
                <label>Department</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user['department']) ?>" readonly>
            </div>
            <div class="mb-3">
                <label>Role</label>
                <select name="role" class="form-select">
                    <option value="user" <?= $user['role']=='user'?'selected':'' ?>>User</option>
                    <option value="driver" <?= $user['role']=='driver'?'selected':'' ?>>Driver</option>
                    <option value="supervisor" <?= $user['role']=='supervisor'?'selected':'' ?>>Supervisor</option>
                    <option value="hrm" <?= $user['role']=='hrm'?'selected':'' ?>>HRM</option>
                    <option value="admin" <?= $user['role']=='admin'?'selected':'' ?>>Admin</option>
                </select>
            </div>
            <div class="mb-3">
                <label>Role Expiry Date (optional)</label>
                <input type="date" name="role_expiry_date" class="form-control" value="<?= $user['role_expiry_date'] ?? '' ?>">
                <small class="text-muted">Leave blank for indefinite role.</small>
            </div>
         <div class="mb-3">
    <label>New Temporary Password</label>
    <input type="password" name="new_password" class="form-control">
</div>

<div class="mb-3">
    <label>Confirm Temporary Password</label>
    <input type="password" name="confirm_password" class="form-control">
</div>

<small class="text-muted">
    User will be forced to change this password on next login.
</small>

            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="manage_users.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
