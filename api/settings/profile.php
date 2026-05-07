<?php
session_start();
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

// Ensure database connection
global $conn;
if (!isset($conn) || $conn->connect_errno) {
    die("Database connection error");
}

// Security checks
enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// Check if user is logged in
if (!isset($_SESSION['user']['username'])) {
    header('Location: ../signin/logout.php');
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$message = '';
$messageType = 'info';
$activeRole = getActiveRole();
$UserId = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail = $_SESSION['user']['email'];
$UserDept = $_SESSION['user']['department'] ?? '';

// Role-based permissions
$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin = ($activeRole === 'admin');
$isAgent = ($activeRole === 'agent');
$isUser = ($activeRole === 'user');

$role = $_SESSION['active_role'] ?? 'user';
$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin' => 'bi-shield-fill-check',
    'agent' => 'bi-headset',
    'user' => 'bi-person-fill'
];
$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// Fetch user details with additional info
// First, fetch user details
$stmt = $conn->prepare("
    SELECT u.username, u.email, u.department, u.created_at, u.updated_at, 
           u.last_login_at, u.last_login_ip, u.is_active, u.division_id,
           COALESCE(u.password_last_set, u.updated_at, u.created_at) as password_last_set
    FROM users u 
    WHERE u.username = ?
");
$stmt->bind_param("s", $UserUsername);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: ../signin/logout.php');
    exit;
}

// Fetch divisions with department information
$divisions = [];
$divStmt = $conn->prepare("
    SELECT d.id, d.division_name, d.department_id, dep.department_name as department_name
    FROM divisions d
    LEFT JOIN departments dep ON d.department_id = dep.id
    ORDER BY dep.department_name, d.division_name
");
$divStmt->execute();
$divisionsResult = $divStmt->get_result();
while ($div = $divisionsResult->fetch_assoc()) {
    $divisions[] = $div;
}
$divStmt->close();

// Note: user's division_id and department are already in the $user array from the first query
// No need for an additional query

// Get user activity log
$activityLog = [];
$stmt = $conn->prepare("
    SELECT action, details, ip_address, created_at 
    FROM audit_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->bind_param("i", $UserId);
$stmt->execute();
$activityResult = $stmt->get_result();
while ($log = $activityResult->fetch_assoc()) {
    $activityLog[] = $log;
}
$stmt->close();

// Check password expiry warning
$passwordExpiryWarning = null;
if (!isset($_GET['expired'])) {
    $stmt = $conn->prepare("
        SELECT Updated_at, password_last_set 
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $UserId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result) {
        $lastSet = $result['password_last_set'] ?? $result['Updated_at'];
        if ($lastSet) {
            $lastUpdate = strtotime($lastSet);
            $expiryTime = strtotime("+90 days", $lastUpdate);
            $daysRemaining = floor(($expiryTime - time()) / (60 * 60 * 24));
            
            if ($daysRemaining <= 14 && $daysRemaining > 0) {
                $passwordExpiryWarning = [
                    'days' => $daysRemaining,
                    'message' => "⚠️ Your password will expire in {$daysRemaining} days. Please change it soon."
                ];
            } elseif ($daysRemaining <= 0) {
                $passwordExpiryWarning = [
                    'days' => 0,
                    'message' => "⚠️ Your password has expired. Please change it immediately."
                ];
            }
        }
    }
    $stmt->close();
}

// Helper functions
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 12) {
        $errors[] = "Password must be at least 12 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return ['valid' => empty($errors), 'errors' => $errors];
}

function isPasswordReused($conn, $userId, $newPassword) {
    $stmt = $conn->prepare("
        SELECT password_hash 
        FROM password_history 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (password_verify($newPassword, $row['password_hash'])) {
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

function storePasswordHistory($conn, $userId, $passwordHash) {
    // Insert new password
    $stmt = $conn->prepare("
        INSERT INTO password_history (user_id, password_hash, created_at) 
        VALUES (?, ?, NOW())
    ");
    $stmt->bind_param("is", $userId, $passwordHash);
    $stmt->execute();
    $stmt->close();
    
    // Get count of password history entries
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM password_history 
        WHERE user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = $row['count'];
    $stmt->close();
    
    // If more than 10 entries, delete the oldest ones
    if ($count > 10) {
        $deleteCount = $count - 10;
        $stmt = $conn->prepare("
            DELETE FROM password_history 
            WHERE user_id = ? 
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $stmt->bind_param("ii", $userId, $deleteCount);
        $stmt->execute();
        $stmt->close();
    }
}

function logUserAction($conn, $userId, $action, $details) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("issss", $userId, $action, $details, $ip, $userAgent);
    $stmt->execute();
    $stmt->close();
}



// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Security token invalid. Please try again.";
        $messageType = 'danger';
    } 
    // Update profile
    // In the update_profile section, add division handling
elseif (isset($_POST['update_profile'])) {
    $department = trim($_POST['department'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $divisionId = isset($_POST['division_id']) && !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $messageType = 'danger';
    } elseif (strlen($department) > 100) {
        $message = "Department name is too long (max 100 characters).";
        $messageType = 'danger';
    } else {
        // Check if email is already in use by another user
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND username != ?");
        $checkStmt->bind_param("ss", $email, $UserUsername);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            $message = "Email already in use by another account.";
            $messageType = 'danger';
        } else {
            // Update profile with division
            if ($divisionId) {
                // Verify division exists
                $verifyStmt = $conn->prepare("SELECT id, department_id FROM divisions WHERE id = ?");
                $verifyStmt->bind_param("i", $divisionId);
                $verifyStmt->execute();
                $divisionData = $verifyStmt->get_result()->fetch_assoc();
                
                if ($divisionData) {
                    $department = $divisionData['department_id'];
                    $upd = $conn->prepare("
                        UPDATE users 
                        SET email = ?, 
                            department = ?, 
                            division_id = ?,
                            updated_at = NOW() 
                        WHERE username = ?
                    ");
                    $upd->bind_param("ssis", $email, $department, $divisionId, $UserUsername);
                } else {
                    $message = "Invalid division selected.";
                    $messageType = 'danger';
                    $upd = null;
                }
                $verifyStmt->close();
            } else {
                // Update without division
                $upd = $conn->prepare("
                    UPDATE users 
                    SET email = ?, 
                        department = ?, 
                        division_id = NULL,
                        updated_at = NOW() 
                    WHERE username = ?
                ");
                $upd->bind_param("sss", $email, $department, $UserUsername);
            }
            
            if ($upd && $upd->execute()) {
                $message = "Profile updated successfully.";
                $messageType = 'success';
                // Update session
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['department'] = $department;
                
                // Refresh user data
                $stmt = $conn->prepare("
                    SELECT username, email, department, division_id, created_at, updated_at 
                    FROM users WHERE username = ?
                ");
                $stmt->bind_param("s", $UserUsername);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                // Log the action
                logUserAction($conn, $UserId, 'profile_update', 
                    "Updated profile information. Division ID: " . ($divisionId ?? 'None'));
            } else if ($upd) {
                $message = "Error updating profile: " . $conn->error;
                $messageType = 'danger';
            }
            
            if (isset($upd) && $upd) $upd->close();
        }
        $checkStmt->close();
    }
}
      
    // Change password
    elseif (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        // Rate limiting
        if (!isset($_SESSION['password_attempts'])) {
            $_SESSION['password_attempts'] = 0;
            $_SESSION['last_password_attempt'] = time();
        }
        
        if ($_SESSION['password_attempts'] >= 5 && time() - $_SESSION['last_password_attempt'] < 900) {
            $message = "Too many password attempts. Please try again in 15 minutes.";
            $messageType = 'danger';
        } elseif ($new !== $confirm) {
            $message = "New passwords do not match.";
            $messageType = 'danger';
            $_SESSION['password_attempts']++;
            $_SESSION['last_password_attempt'] = time();
        } else {
            // Validate password strength
            $passwordValidation = validatePasswordStrength($new);
            if (!$passwordValidation['valid']) {
                $message = implode("<br>", $passwordValidation['errors']);
                $messageType = 'danger';
            } else {
                // Get current hash
                $stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
                $stmt->bind_param("s", $UserUsername);
                $stmt->execute();
                $result = $stmt->get_result();
                $hashRow = $result->fetch_assoc();
                $hash = $hashRow['password'] ?? '';
                $stmt->close();
                
                if (!password_verify($current, $hash)) {
                    $message = "Current password is incorrect.";
                    $messageType = 'danger';
                    $_SESSION['password_attempts']++;
                    $_SESSION['last_password_attempt'] = time();
                    
                    // Log failed attempt
                    logUserAction($conn, $UserId, 'password_change_failed', "Failed password change attempt");
                } else {
                    // Check password history (prevent reuse of last 5 passwords)
                    if (isPasswordReused($conn, $UserId, $new)) {
                        $message = "You cannot reuse a recent password. Please choose a different password.";
                        $messageType = 'danger';
                    } else {
                        $newHash = password_hash($new, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("
                            UPDATE users 
                            SET password = ?, 
                                updated_at = NOW(),
                                password_last_set = NOW()
                            WHERE username = ?
                        ");
                        $upd->bind_param("ss", $newHash, $UserUsername);
                        
                        if ($upd->execute()) {
                            $message = "Password changed successfully. Please login again.";
                            $messageType = 'success';
                            $_SESSION['password_attempts'] = 0;
                            
                            // Log successful change
                            logUserAction($conn, $UserId, 'password_changed', "Password changed successfully");
                            
                            // Store password in history
                            storePasswordHistory($conn, $UserId, $newHash);
                            
                            // Clear password expiry warning
                            unset($_SESSION['password_expired_warning']);
                            
                            // Optional: Logout user to force re-login with new password
                            if (isset($_POST['logout_after_change']) && $_POST['logout_after_change'] == '1') {
                                session_destroy();
                                header("Location: ../signin/index.php?message=password_changed");
                                exit;
                            }
                        } else {
                            $message = "Error changing password: " . $conn->error;
                            $messageType = 'danger';
                        }
                        $upd->close();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        .password-strength {
            height: 5px;
            transition: all 0.3s ease;
            margin-top: 5px;
            border-radius: 3px;
        }
        .strength-weak { background-color: #dc3545; width: 25%; }
        .strength-fair { background-color: #ffc107; width: 50%; }
        .strength-good { background-color: #17a2b8; width: 75%; }
        .strength-strong { background-color: #28a745; width: 100%; }
        .activity-log {
            max-height: 300px;
            overflow-y: auto;
        }
        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .back-btn:hover {
            transform: translateX(-3px);
            transition: all 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar -->
<?php include '../agent/topnav.php'; ?>

<!-- Loading indicator -->
<div id="loading" class="loading-indicator" style="display: none;">
    <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>

<!-- Main Content -->
<main id="main-content">
    <div class="container mt-4 fade-in">
        <div class="settings-header">
            <h1 class="settings-title">
                <i class="<?= $iconClass ?> me-2"></i>
                My Profile
            </h1>
            <div class="settings-actions">
                <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
            </div>
        </div>

        <!-- Password Expiry Warning -->
        <?php if ($passwordExpiryWarning): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($passwordExpiryWarning['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Message Display -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Account Meta -->
        <div class="card shadow-sm mt-3">
            <div class="card-body card-color text-white">
                <div class="row">
                    <div class="col-md-3">
                        <p><strong><i class="bi bi-calendar-plus"></i> Account Created:</strong><br>
                        <?= date('M j, Y', strtotime($user['created_at'])) ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong><i class="bi bi-clock-history"></i> Last Updated:</strong><br>
                        <?= date('M j, Y', strtotime($user['updated_at'] ?? $user['created_at'])) ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong><i class="bi bi-person-badge"></i> Roles:</strong><br>
                        <?= implode(', ', getUserRoles()) ?></p>
                    </div>
                    <div class="col-md-3">
                        <p><strong><i class="bi bi-shield-check"></i> Active Role:</strong><br>
                        <?= ucfirst(getActiveRole()) ?></p>
                    </div>
                </div>
                
            </div>
        </div>

        <div class="row g-4 mt-2">
            <!-- Profile Info -->
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header card-color text-white">
                        <i class="bi bi-person-circle me-2"></i>
                        Profile Information
                    </div>
                    <div class="card-body">
                        <form method="POST" id="profileForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-person"></i> Username
                                </label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                            </div>


                             <div class="mb-3">
                              <label class="form-label">
                                  <i class="bi bi-diagram-3"></i> Division
                              </label>
                              <select id="division" name="division_id" class="form-select" required>
                                  <option value="">Select Division</option>
                                  <?php 
                                  $currentDepartment = '';
                                  foreach ($divisions as $d): 
                                      // Group by department
                                      if ($currentDepartment != $d['department_name']): 
                                          if ($currentDepartment != ''): ?>
                                              </optgroup>
                                          <?php endif; ?>
                                          <optgroup label="<?= htmlspecialchars($d['department_name']) ?>">
                                          <?php $currentDepartment = $d['department_name']; 
                                      endif; 
                                  ?>
                                      <option value="<?= $d['id'] ?>" 
                                              data-department="<?= htmlspecialchars($d['department_name']) ?>"
                                              <?= (isset($user['division_id']) && $user['division_id'] == $d['id']) ? 'selected' : '' ?>>
                                          <?= htmlspecialchars($d['division_name']) ?>
                                      </option>
                                  <?php endforeach; ?>
                                  <?php if ($currentDepartment != ''): ?>
                                      </optgroup>
                                  <?php endif; ?>
                              </select>
                          </div>

                          <div class="mb-3">
                              <label class="form-label">
                                  <i class="bi bi-building"></i> Department
                              </label>
                              <input type="text" id="department_display" name="department_display" 
                                    class="form-control" readonly 
                                    value="<?= htmlspecialchars($user['department'] ?? $user['division_department'] ?? '') ?>">
                              <input type="hidden" id="department" name="department" 
                                    value="<?= htmlspecialchars($user['department'] ?? $user['division_department'] ?? '') ?>">
                              <small class="text-muted">Department is automatically set based on division selection</small>
                          </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-envelope"></i> Email Address
                                </label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= htmlspecialchars($user['email']) ?>" required>
                            </div>

                            <button type="submit" name="update_profile" class="btn btn-primary" onclick="showLoading()">
                                <i class="bi bi-save"></i> Update Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Password Change -->
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header card-color text-white">
                        <i class="bi bi-key me-2"></i>
                        Change Password
                    </div>
                    <div class="card-body">
                        <form method="POST" id="passwordForm" onsubmit="return validatePasswordForm()">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-lock"></i> Current Password
                                </label>
                                <input type="password" name="current_password" id="current_password" 
                                       class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-key-fill"></i> New Password
                                </label>
                                <input type="password" name="new_password" id="new_password" 
                                       class="form-control" required minlength="12">
                                <div id="passwordStrength" class="password-strength mt-2"></div>
                                <small class="text-muted">
                                    Password must be at least 12 characters and include uppercase, lowercase, 
                                    number, and special character.
                                </small>
                                <div id="passwordRequirements" class="small mt-1"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-check-circle"></i> Confirm Password
                                </label>
                                <input type="password" name="confirm_password" id="confirm_password" 
                                       class="form-control" required minlength="12">
                                <div id="passwordMatch" class="small mt-1"></div>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" name="logout_after_change" id="logout_after_change" value="1">
                                <label class="form-check-label" for="logout_after_change">
                                    Logout after password change (recommended)
                                </label>
                            </div>

                            <button type="submit" name="change_password" class="btn btn-warning" onclick="showLoading()">
                                <i class="bi bi-shield-lock"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Log -->
        <?php if (!empty($activityLog)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header card-color text-white">
                        <i class="bi bi-clock-history me-2"></i>
                        Recent Activity (Last 10 actions)
                    </div>
                    <div class="card-body activity-log">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    
                                        <th><i class="bi bi-calendar"></i> Time</th>
                                        <th><i class="bi bi-activity"></i> Action</th>
                                        <th><i class="bi bi-info-circle"></i> Details</th>
                                        <th><i class="bi bi-hdd-network"></i> IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activityLog as $log): ?>
                                    <tr>
                                        <td><?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                                        <td><code><?= htmlspecialchars($log['ip_address'] ?? '-') ?></code></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Password strength checker
document.getElementById('new_password')?.addEventListener('input', function() {
    const password = this.value;
    const strength = checkPasswordStrength(password);
    updateStrengthMeter(strength);
    updatePasswordRequirements(password);
    
    // Check password match
    const confirm = document.getElementById('confirm_password')?.value;
    if (confirm) {
        checkPasswordMatch();
    }
});

document.getElementById('confirm_password')?.addEventListener('input', function() {
    checkPasswordMatch();
});

function checkPasswordStrength(password) {
    let strength = 0;
    
    if (password.length >= 12) strength++;
    if (password.match(/[a-z]/)) strength++;
    if (password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    if (password.match(/[^a-zA-Z0-9]/)) strength++;
    
    if (strength <= 2) return 'weak';
    if (strength === 3) return 'fair';
    if (strength === 4) return 'good';
    return 'strong';
}

function updateStrengthMeter(strength) {
    const meter = document.getElementById('passwordStrength');
    if (!meter) return;
    
    meter.className = 'password-strength mt-2';
    
    switch(strength) {
        case 'weak':
            meter.classList.add('strength-weak');
            break;
        case 'fair':
            meter.classList.add('strength-fair');
            break;
        case 'good':
            meter.classList.add('strength-good');
            break;
        case 'strong':
            meter.classList.add('strength-strong');
            break;
    }
}

function updatePasswordRequirements(password) {
    const requirements = document.getElementById('passwordRequirements');
    if (!requirements) return;
    
    let html = '<small>';
    html += password.length >= 12 ? '✓ ' : '✗ ';
    html += 'At least 12 characters<br>';
    html += /[a-z]/.test(password) ? '✓ ' : '✗ ';
    html += 'Lowercase letter<br>';
    html += /[A-Z]/.test(password) ? '✓ ' : '✗ ';
    html += 'Uppercase letter<br>';
    html += /[0-9]/.test(password) ? '✓ ' : '✗ ';
    html += 'Number<br>';
    html += /[^a-zA-Z0-9]/.test(password) ? '✓ ' : '✗ ';
    html += 'Special character';
    html += '</small>';
    
    requirements.innerHTML = html;
}

function checkPasswordMatch() {
    const password = document.getElementById('new_password')?.value;
    const confirm = document.getElementById('confirm_password')?.value;
    const matchDiv = document.getElementById('passwordMatch');
    
    if (!matchDiv) return;
    
    if (confirm === '') {
        matchDiv.innerHTML = '';
        return;
    }
    
    if (password === confirm) {
        matchDiv.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Passwords match</span>';
        return true;
    } else {
        matchDiv.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Passwords do not match</span>';
        return false;
    }
}

function validatePasswordForm() {
    const password = document.getElementById('new_password')?.value;
    const confirm = document.getElementById('confirm_password')?.value;
    
    if (password !== confirm) {
        alert('Passwords do not match!');
        return false;
    }
    
    const strength = checkPasswordStrength(password);
    if (strength === 'weak') {
        if (!confirm('Your password is weak. Are you sure you want to use this password?')) {
            return false;
        }
    }
    
    return true;
}

function showLoading() {
    const loadingDiv = document.getElementById('loading');
    if (loadingDiv) {
        loadingDiv.style.display = 'block';
    }
}


function goBack() {
    const previousPages = <?= json_encode($_SESSION['page_history'] ?? []) ?>;
    
    if (previousPages.length > 1) {
        previousPages.pop();
        const previousPage = previousPages[previousPages.length - 1];
        window.location.href = previousPage;
    } else {
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            // Determine dashboard based on role
            const role = '<?= $activeRole ?>';
            if (role === 'superadmin' || role === 'admin') {
                window.location.href = '../admin/admin_dashboard.php';
            } else if (role === 'agent') {
                window.location.href = '../agent/agent_dashboard.php';
            } else {
                window.location.href = '../user_dashboard.php';
            }
        }
    }
}

// Enhanced division handling with validation
document.addEventListener('DOMContentLoaded', function() {
    const divisionSelect = document.getElementById('division');
    const departmentDisplay = document.getElementById('department_display');
    const departmentHidden = document.getElementById('department');
    
    if (divisionSelect) {
        divisionSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            // Get department from optgroup label or data attribute
            const parentOptgroup = selectedOption.parentElement;
            let departmentName = selectedOption.getAttribute('data-department');
            
            if (!departmentName && parentOptgroup && parentOptgroup.tagName === 'OPTGROUP') {
                departmentName = parentOptgroup.getAttribute('label');
            }
            
            if (departmentName) {
                departmentDisplay.value = departmentName;
                departmentHidden.value = departmentName;
                departmentDisplay.classList.remove('is-invalid');
            } else {
                departmentDisplay.value = '';
                departmentHidden.value = '';
                if (this.value === '') {
                    departmentDisplay.classList.add('is-invalid');
                }
            }
        });
        
        // Trigger change on page load
        if (divisionSelect.value) {
            divisionSelect.dispatchEvent(new Event('change'));
        }
    }
});
        
        // Trigger change on page load
        if (divisionSelect.value) {
            divisionSelect.dispatchEvent(new Event('change'));
        }
  
    
    // Form validation
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            const division = divisionSelect?.value;
            const email = document.querySelector('input[name="email"]')?.value;
            
            if (!division) {
                e.preventDefault();
                alert('Please select a division.');
                return false;
            }
            
            if (!email || !email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return false;
            }
            
            return true;
        });
    }

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});
</script>
</body>
</html>