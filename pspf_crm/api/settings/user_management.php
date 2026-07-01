<?php
session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// ---------------------------------------------------------------------------
// AUTHORIZATION — User Management is superadmin-only.
// The page AND every mutating action are gated. It is not enough to hide the
// UI: each POST re-checks the role so a crafted request from a lower-privilege
// account cannot escalate. Uses held-role check via hasRole().
// ---------------------------------------------------------------------------
if (!isLoggedIn() || !hasRole('superadmin')) {
    http_response_code(403);
    echo "<h3>403 - Forbidden</h3><p>User Management is restricted to superadministrators.</p>";
    exit;
}

$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'];
$UserDept     = $_SESSION['user']['department'] ?? '';

$activeRole   = getActiveRole();
// Role flags consumed by the shared topnav (must all be defined to avoid
// "undefined variable" warnings). Access is superadmin-only, but the badge in
// topnav reflects the currently ACTIVE role.
$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');
$role         = $_SESSION['active_role'] ?? 'user';
$roleIcons    = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill',
];
$iconClass    = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------------------------------------------------------
// CSRF — ensure a token exists, and validate it on every POST.
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/** Flash helper: store a message, then redirect (POST-Redirect-GET). */
function flash_redirect(string $kind, string $msg): void {
    $_SESSION['um_flash'] = ['kind' => $kind, 'msg' => $msg];
    // Preserve the current filter query string on redirect.
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . ($qs ? "?$qs" : ''));
    exit;
}

$IT_ROLES = ['it_officer', 'it_director']; // permission roles (grantable here)

// ---------------------------------------------------------------------------
// POST HANDLING — all actions validated (superadmin already enforced above).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($CSRF, $token)) {
        flash_redirect('error', 'Security check failed (invalid CSRF token). Please try again.');
    }

    // ---- Toggle active status ----
    if (isset($_POST['toggle_status'])) {
        $target = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        if ($target === $UserId) {
            flash_redirect('error', 'You cannot disable your own account.');
        }
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newStatus, $target);
        $stmt->execute();
        $stmt->close();
        flash_redirect('success', 'User status updated.');
    }

    // ---- Assign role ----
    if (isset($_POST['assign_role'])) {
        $uid = (int)($_POST['user_id'] ?? 0);
        $rid = (int)($_POST['role_id'] ?? 0);
        // Validate the role exists
        $chk = $conn->prepare("SELECT name FROM roles WHERE id = ?");
        $chk->bind_param("i", $rid);
        $chk->execute();
        $roleRow = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$roleRow) {
            flash_redirect('error', 'Unknown role.');
        }
        $stmt = $conn->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $uid, $rid);
        $stmt->execute();
        $added = $stmt->affected_rows;
        $stmt->close();
        flash_redirect($added ? 'success' : 'info',
            $added ? "Role '{$roleRow['name']}' assigned." : "User already has the '{$roleRow['name']}' role.");
    }

    // ---- Remove role ----
    if (isset($_POST['remove_role'])) {
        $uid = (int)($_POST['user_id'] ?? 0);
        $rid = (int)($_POST['role_id'] ?? 0);
        // Guard: don't let a superadmin strip their OWN superadmin role (lockout).
        $chk = $conn->prepare("SELECT name FROM roles WHERE id = ?");
        $chk->bind_param("i", $rid);
        $chk->execute();
        $roleRow = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($uid === $UserId && ($roleRow['name'] ?? '') === 'superadmin') {
            flash_redirect('error', 'You cannot remove your own superadmin role.');
        }
        // Guard: never remove the LAST superadmin in the system.
        if (($roleRow['name'] ?? '') === 'superadmin') {
            $cntRes = $conn->query("SELECT COUNT(*) AS c FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.name='superadmin'");
            $cnt = (int)$cntRes->fetch_assoc()['c'];
            if ($cnt <= 1) {
                flash_redirect('error', 'Cannot remove the last superadmin in the system.');
            }
        }
        $stmt = $conn->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
        $stmt->bind_param("ii", $uid, $rid);
        $stmt->execute();
        $stmt->close();
        flash_redirect('success', 'Role removed.');
    }

    // ---- Edit full name ----
    if (isset($_POST['save_full_name'])) {
        $uid = (int)($_POST['user_id'] ?? 0);
        $full = trim($_POST['full_name'] ?? '');
        if ($full !== '' && (mb_strlen($full) < 2 || mb_strlen($full) > 150)) {
            flash_redirect('error', 'Full name must be between 2 and 150 characters.');
        }
        $val = ($full === '') ? null : $full;
        $stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
        $stmt->bind_param("si", $val, $uid);
        $stmt->execute();
        $stmt->close();
        flash_redirect('success', 'Full name updated.');
    }

    // ---- Create user ----
    if (isset($_POST['create_user'])) {
        $newUser  = trim($_POST['new_username'] ?? '');
        $newEmail = trim($_POST['new_email'] ?? '');
        $newFull  = trim($_POST['new_full_name'] ?? '');
        $divId    = (int)($_POST['new_division_id'] ?? 0);
        $newPass  = $_POST['new_password'] ?? '';

        if ($newUser === '' || $newEmail === '' || $divId <= 0 || $newPass === '') {
            flash_redirect('error', 'Username, email, division and password are required.');
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            flash_redirect('error', 'Invalid email address.');
        }
        if (strlen($newPass) < 8) {
            flash_redirect('error', 'Password must be at least 8 characters.');
        }
        // Resolve division -> department
        $dv = $conn->prepare("SELECT d.id AS dept_id, d.department_name FROM divisions v JOIN departments d ON d.id = v.department_id WHERE v.id = ?");
        $dv->bind_param("i", $divId);
        $dv->execute();
        $dvRow = $dv->get_result()->fetch_assoc();
        $dv->close();
        if (!$dvRow) {
            flash_redirect('error', 'Selected division is invalid.');
        }
        // Uniqueness
        $ck = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
        $ck->bind_param("ss", $newEmail, $newUser);
        $ck->execute();
        if ($ck->get_result()->fetch_assoc()) {
            $ck->close();
            flash_redirect('error', 'A user with that email or username already exists.');
        }
        $ck->close();

        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $deptName = $dvRow['department_name'];
        $fullVal  = ($newFull === '') ? null : $newFull;
        $ins = $conn->prepare(
            "INSERT INTO users (username, email, full_name, department, division_id, password, is_active, email_verified, password_last_set, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())"
        );
        $ins->bind_param("ssssis", $newUser, $newEmail, $fullVal, $deptName, $divId, $hash);
        if ($ins->execute()) {
            $newId = $conn->insert_id;
            $ins->close();
            // Give the new user the base 'user' role.
            $rr = $conn->query("SELECT id FROM roles WHERE name='user' LIMIT 1")->fetch_assoc();
            if ($rr) {
                $ar = $conn->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
                $ar->bind_param("ii", $newId, $rr['id']);
                $ar->execute();
                $ar->close();
            }
            flash_redirect('success', "User '{$newUser}' created.");
        } else {
            $ins->close();
            flash_redirect('error', 'Could not create user (database error).');
        }
    }

    // ---- Reset password (set a new one directly) ----
    if (isset($_POST['reset_password'])) {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $pass = $_POST['reset_pass_value'] ?? '';
        if (strlen($pass) < 8) {
            flash_redirect('error', 'New password must be at least 8 characters.');
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, password_last_set = NOW(), reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->bind_param("si", $hash, $uid);
        $stmt->execute();
        $stmt->close();
        flash_redirect('success', 'Password reset for the user.');
    }
}

// ---------------------------------------------------------------------------
// READ — filters + data for display
// ---------------------------------------------------------------------------
$flash = $_SESSION['um_flash'] ?? null;
unset($_SESSION['um_flash']);

$search       = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';       // '', 'active', 'inactive'
$filterRole   = $_GET['role'] ?? '';         // '' or role name

// Build filtered user query
$where = [];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR u.department LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($filterStatus === 'active')   { $where[] = "u.is_active = 1"; }
if ($filterStatus === 'inactive') { $where[] = "u.is_active = 0"; }
if ($filterRole !== '') {
    $where[] = "EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id=ur2.role_id WHERE ur2.user_id=u.id AND r2.name = ?)";
    $params[] = $filterRole;
    $types .= 's';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT u.id, u.username, u.email, u.full_name, u.department, u.is_active
        FROM users u $whereSql ORDER BY u.username";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalUsers = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];

$roles = $conn->query("SELECT id, name FROM roles ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Divisions for the create-user dropdown
$divisions = $conn->query(
    "SELECT v.id, v.division_name, d.department_name
     FROM divisions v JOIN departments d ON d.id = v.department_id
     ORDER BY d.department_name, v.division_name"
)->fetch_all(MYSQLI_ASSOC);

// Preload each user's roles (one query, grouped) to avoid N+1
$rolesByUser = [];
$rr = $conn->query(
    "SELECT ur.user_id, r.id AS role_id, r.name
     FROM user_roles ur JOIN roles r ON r.id = ur.role_id"
);
while ($row = $rr->fetch_assoc()) {
    $rolesByUser[(int)$row['user_id']][] = ['id' => (int)$row['role_id'], 'name' => $row['name']];
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management - PSPF CRM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="../style5.css">
<link rel="stylesheet" href="../agent/agent_style.css">
<link rel="icon" type="image/png" href="../uploads/pspflogo.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    .status-badge { padding: 3px 10px; border-radius: 15px; font-size: 0.8em; font-weight: bold; }
    .status-active { background-color: #28a745; color: white; }
    .status-inactive { background-color: #dc3545; color: white; }
    .inactive-user { opacity: 0.65; }
    .role-chip { font-size: .8em; }
    .um-toolbar { gap: .5rem; flex-wrap: wrap; }
    .um-name-input { max-width: 190px; }
</style>
</head>
<body>
    <?php include '../agent/topnav.php'; ?>

<main id="main-content">
  <div class="container-xl mt-4 mb-5">
    <div class="settings-header d-flex justify-content-between align-items-center">
        <h1 class="settings-title">User Management</h1>
        <div class="settings-actions">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-person-plus"></i> New User
            </button>
            <button onclick="window.history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['kind'] === 'success' ? 'success' : ($flash['kind'] === 'info' ? 'info' : 'danger') ?> alert-dismissible fade show" role="alert">
        <?= e($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="d-flex um-toolbar mb-3">
        <input type="text" name="q" value="<?= e($search) ?>" class="form-control" style="max-width:280px"
               placeholder="Search name, email, department">
        <select name="status" class="form-select" style="max-width:160px">
            <option value="">All statuses</option>
            <option value="active"   <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <select name="role" class="form-select" style="max-width:170px">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= e($r['name']) ?>" <?= $filterRole===$r['name']?'selected':'' ?>><?= e(ucfirst($r['name'])) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
        <?php if ($search || $filterStatus || $filterRole): ?>
            <a href="user_management.php" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
            <span>System Users</span>
            <span class="badge bg-light text-dark">Showing <?= count($users) ?> of <?= $totalUsers ?></span>
        </div>
        <div class="card-body table-responsive">
            <table class="table align-middle">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Full name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Roles</th>
                        <th>Add role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $uid = (int)$u['id'];
                    $uRoles = $rolesByUser[$uid] ?? [];
                    $uRoleNames = array_column($uRoles, 'name');
                ?>
                    <tr class="<?= $u['is_active'] ? '' : 'inactive-user' ?>">
                        <td>
                            <strong><?= e($u['username']) ?></strong><br>
                            <small class="text-muted"><?= e($u['department']) ?></small>
                            <?php if ($uid === $UserId): ?><span class="badge bg-info ms-1">You</span><?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="d-flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <input type="text" name="full_name" value="<?= e($u['full_name']) ?>"
                                       class="form-control form-control-sm um-name-input">
                                <button name="save_full_name" class="btn btn-sm btn-outline-primary" title="Save full name">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                        </td>
                        <td><?= e($u['email']) ?></td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="status-badge status-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive"><i class="bi bi-x-circle-fill me-1"></i>Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($uRoles as $r): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove the <?= e($r['name']) ?> role?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                                    <button name="remove_role" class="btn btn-sm btn-outline-danger role-chip me-1 mb-1">
                                        <?= e(ucfirst($r['name'])) ?> &times;
                                    </button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (!$uRoles): ?><span class="text-muted small">none</span><?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="d-flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <select name="role_id" class="form-select form-select-sm" style="min-width:120px">
                                    <?php foreach ($roles as $r): if (in_array($r['name'], $uRoleNames, true)) continue; ?>
                                        <option value="<?= (int)$r['id'] ?>"><?= e(ucfirst($r['name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button name="assign_role" class="btn btn-sm btn-primary" <?= !$u['is_active'] ? 'disabled' : '' ?>>Add</button>
                            </form>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($uid !== $UserId): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('<?= $u['is_active'] ? 'Disable' : 'Enable' ?> this user?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="new_status" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                    <button name="toggle_status" class="btn btn-sm btn-<?= $u['is_active'] ? 'warning' : 'success' ?>">
                                        <i class="bi bi-<?= $u['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                        <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-dark reset-pass-btn"
                                        data-user-id="<?= $uid ?>" data-username="<?= e($u['username']) ?>"
                                        data-bs-toggle="modal" data-bs-target="#resetPassModal">
                                    <i class="bi bi-key"></i> Reset
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No users match the current filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>
</main>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
      <div class="modal-header"><h5 class="modal-title">Create User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Username</label>
          <input name="new_username" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Full name</label>
          <input name="new_full_name" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Email</label>
          <input type="email" name="new_email" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Division / Department</label>
          <select name="new_division_id" class="form-select" required>
            <option value="">Select…</option>
            <?php foreach ($divisions as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= e($d['department_name']) ?> — <?= e($d['division_name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Temporary password (min 8 chars)</label>
          <input type="text" name="new_password" class="form-control" minlength="8" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="create_user" class="btn btn-success">Create user</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPassModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
      <input type="hidden" name="user_id" id="resetUserId" value="">
      <div class="modal-header"><h5 class="modal-title">Reset Password — <span id="resetUsername"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">New password (min 8 chars)</label>
          <input type="text" name="reset_pass_value" class="form-control" minlength="8" required></div>
        <p class="text-muted small mb-0">The user can sign in immediately with this password.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button name="reset_password" class="btn btn-dark">Reset password</button>
      </div>
    </form>
  </div>
</div>

<?php include '../footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Populate the reset-password modal with the chosen user.
    document.querySelectorAll('.reset-pass-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('resetUserId').value = this.dataset.userId;
            document.getElementById('resetUsername').textContent = this.dataset.username;
        });
    });
</script>
</body>
</html>
<?php $conn->close(); ?>
