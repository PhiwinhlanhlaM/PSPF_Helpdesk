<?php
session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// allow any system role here
requireAnyRole(['user','agent','admin','superadmin']);

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDept      = $_SESSION['user']['department'] ?? '';
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];

$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------
// HANDLE USER DISABLE/ENABLE
// ---------------------------
if (isset($_POST['toggle_status'])) {
    $target_user_id = (int)$_POST['user_id'];
    $new_status = (int)$_POST['new_status']; // 1 = active, 0 = inactive
    
    // Prevent disabling yourself
    if ($target_user_id !== $UserId) {
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $target_user_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Assign role
if (isset($_POST['assign_role'])) {
    $user_id = (int)$_POST['user_id'];
    $role_id = (int)$_POST['role_id'];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $role_id);
    $stmt->execute();
}

// Remove role
if (isset($_POST['remove_role'])) {
    $user_id = (int)$_POST['user_id'];
    $role_id = (int)$_POST['role_id'];
    
    $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
    $stmt->bind_param("ii", $user_id, $role_id);
    $stmt->execute();
}

// Fetch users with status
$users = $conn->query("SELECT id, username, email, department, is_active FROM users ORDER BY username")->fetch_all(MYSQLI_ASSOC);

// Fetch roles
$roles = $conn->query("SELECT id, name FROM roles ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get user roles helper
function getRolesForUser($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT r.id, r.name
        FROM roles r
        JOIN user_roles ur ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<meta charset="UTF-8">
<title>User Management - PSPF CRM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../style5.css">
<link rel="stylesheet" href="../agent/agent_style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="icon" type="image/png" href="../uploads/pspflogo.png">
<link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    /* Status badge styles */
    .status-badge {
        padding: 3px 10px;
        border-radius: 15px;
        font-size: 0.8em;
        font-weight: bold;
    }
    .status-active {
        background-color: #28a745;
        color: white;
    }
    .status-inactive {
        background-color: #dc3545;
        color: white;
    }
    /* Inactive row style */
    .inactive-user {
        opacity: 0.6;
        background-color: #f8f9fa;
    }
</style>
</head>
<body>
    <!-- Header Navigation -->
    <?php include '../agent/topnav.php'; ?>

<main id="main-content">
    <div class="container-xl mt-4 mb-5">
        <div class="settings-header">   
            <h1 class="settings-title">User Management</h1>
            <div class="settings-actions">
                <!-- Back Button -->
                <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
                <span>System Users</span>
                <span class="badge bg-light text-dark">Total: <?= count($users) ?></span>
            </div>
            <div class="card-body table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Roles</th>
                            <th>Add Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): 
                            $rowClass = $u['is_active'] ? '' : 'inactive-user';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td>
                                <strong><?= htmlspecialchars($u['username']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($u['department']) ?></small>
                                <?php if ($u['id'] == $UserId): ?>
                                    <span class="badge bg-info ms-1">You</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="status-badge status-active">
                                        <i class="bi bi-check-circle-fill me-1"></i>Active
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">
                                        <i class="bi bi-x-circle-fill me-1"></i>Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach (getRolesForUser($conn, $u['id']) as $r): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove role?');">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                        <button name="remove_role" class="btn btn-sm btn-outline-danger me-1">
                                            <?= ucfirst($r['name']) ?> ✕
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-flex gap-1">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <select name="role_id" class="form-select form-select-sm">
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= $role['id'] ?>"><?= ucfirst($role['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button name="assign_role" class="btn btn-sm btn-primary" 
                                            <?= !$u['is_active'] ? 'disabled' : '' ?>>Add</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($u['id'] != $UserId): // Don't show for current user ?>
                                    <form method="POST" class="d-inline" 
                                          onsubmit="return confirm('<?= $u['is_active'] ? 'Disable' : 'Enable' ?> this user?');">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                        <button type="submit" name="toggle_status" 
                                                class="btn btn-sm btn-<?= $u['is_active'] ? 'warning' : 'success' ?>">
                                            <i class="bi bi-<?= $u['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function goBack() {
        window.history.back();
    }
</script>
</body>
</html>

<?php
$conn->close();
?>