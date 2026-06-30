<?php
session_start();
require '../vehicle_booking/db.php';

if (isset($_SESSION['message'])) {
    echo "<div class='alert alert-{$_SESSION['message_type']} alert-dismissible fade show mt-3' role='alert'>
            {$_SESSION['message']}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
          </div>";
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}


if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../vehicle_booking/login.php");
    exit();
}
// Pagination setup
$limit = 10; // users per page
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Count total users
$totalStmt = $conn->query("SELECT COUNT(*) FROM users");
$totalUsers = $totalStmt->fetchColumn();
$totalPages = ceil($totalUsers / $limit);

// Fetch users for current page
$stmt = $conn->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$users = $conn->query("SELECT * FROM users ORDER BY role, name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="../vehicle_booking/style5.css" rel="stylesheet">
</head>
<body>
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-5">
    <h3>Manage Users</h3>
    <a href="admin_dashboard.php" class="btn btn-secondary btn-sm mb-3">← Back</a>
    <a href="add_users.php" class="btn btn-primary mb-3">Add User</a>

    <table class="table table-striped">
        <thead class="table-dark">
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Department</th>
                <th>Role</th>
                <th>Actions</th>
                <th>Status</th> 
            </tr>
        </thead>
        <tbody>
        <?php while($u = $users->fetch(PDO::FETCH_ASSOC)): ?>
            <tr>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['department']) ?></td>
                <td><span class="badge bg-info"><?= htmlspecialchars($u['role']) ?></span></td>
                <td>
                    <a href="edit_users.php?id=<?= $u['user_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <a href="delete_user.php?id=<?= $u['user_id'] ?>" class="btn btn-danger btn-sm"
                       onclick="return confirm('Are you sure you want to deactivate this user?')">Deactivate</a>
                       <a href="delete_user.php?id=<?= $u['user_id'] ?>" class="btn btn-success"
                       onclick="return confirm('Are you sure you want to Activate this user?')">Activate</a>
                </td>
                          <td>
    <?= $u['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Deactivated</span>' ?>
</td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
       <!-- Pagination Links -->
    <nav aria-label="Page navigation example">
        <ul class="pagination justify-content-center">
            <?php if($page > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $page-1 ?>">Previous</a></li>
            <?php endif; ?>
            
            <?php for($i=1; $i<=$totalPages; $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>

            <?php if($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $page+1 ?>">Next</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
