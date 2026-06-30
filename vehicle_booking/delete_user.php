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

// Fetch user info
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found!");
}

// Prevent admin from deactivating themselves
if ($user['user_id'] == $_SESSION['user_id']) {
    die("<div style='margin:50px; color:red;'>⚠️ You cannot deactivate or activate your own account.</div>");
}

$actionType = $user['active'] == 1 ? "Deactivate" : "Activate";
$newStatus = $user['active'] == 1 ? 0 : 1;

// Handle submit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $update = $conn->prepare("UPDATE users SET active = ? WHERE user_id = ?");
    $update->execute([$newStatus, $user_id]);

    // Log action
    $log = $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (NULL, ?, '".$actionType."d user: {$user['name']} ({$user['email']})', NOW())
    ");
    $log->execute([$_SESSION['user_id']]);

$_SESSION['message'] = "User has been modified successfully.";
$_SESSION['message_type'] = $newStatus == 1 ? "success" : "warning";

header("Location: manage_users.php");
exit();

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $actionType ?> User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body>
<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-5">
    <div class="card p-4 shadow">
        <h4 class="<?= $newStatus == 1 ? 'text-success' : 'text-warning' ?>">
            Confirm <?= $actionType ?>
        </h4>

        <p>Are you sure you want to <strong><?= strtolower($actionType) ?></strong> this user?</p>

        <ul class="list-group mb-3">
            <li class="list-group-item"><strong>Name:</strong> <?= htmlspecialchars($user['name']) ?></li>
            <li class="list-group-item"><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></li>
            <li class="list-group-item"><strong>Department:</strong> <?= htmlspecialchars($user['department']) ?></li>
            <li class="list-group-item"><strong>Role:</strong> <?= htmlspecialchars($user['role']) ?></li>
            <li class="list-group-item">
                <strong>Status:</strong>
                <?= $user['active'] == 1 ? "Active" : "Inactive" ?>
            </li>
        </ul>

        <form method="POST">
            <button type="submit" class="btn <?= $newStatus == 1 ? 'btn-success' : 'btn-warning' ?>">
                Yes, <?= $actionType ?> User
            </button>
            <a href="manage_users.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
