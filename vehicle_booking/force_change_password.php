<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';

/* User must be logged in */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Fetch user and verify reset is required */
$stmt = $conn->prepare("SELECT password_reset_required FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['password_reset_required'] != 1) {
    header("Location: user_dashboard.php");
    exit();
}

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {

        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $update = $conn->prepare("
            UPDATE users 
            SET password = ?, password_reset_required = 0 
            WHERE user_id = ?
        ");
        $update->execute([$hashed_password, $_SESSION['user_id']]);

        $success = "Password updated successfully. Redirecting...";
        header("refresh:2;url=user_dashboard.php");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Change Password'; $legacyCss = false; require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="vb-auth">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
            <div class="change-card">
                <h4 class="text-center mb-3">Change Your Password</h4>
                <p class="text-muted text-center">
                    You are required to change your password before continuing.
                </p>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
