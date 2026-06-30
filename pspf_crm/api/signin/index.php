<?php
// signin/index.php
session_start();

session_regenerate_id(true);
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// load DB first (so auth_helpers can use $conn)
require_once __DIR__ . '/../db.php';

// auth helpers provide getUserRoles(), setActiveRole() etc.
// make sure path matches your project
require_once __DIR__ . '/../includes/auth_helpers.php';

// optional activity logger
if (file_exists(__DIR__ . '/../includes/log_activity.php')) {
    require_once __DIR__ . '/../includes/log_activity.php';
}

// If already logged in, go to dashboard
if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    header('Location: ../dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        // fetch user by email
        $stmt = $conn->prepare("SELECT 
                                    u.id,
                                    u.username,
                                    u.email,
                                    u.password,
                                    u.department,
                                    d.id AS department_id,
                                    v.id AS division_id,
                                    v.division_name
                                FROM users u
                                LEFT JOIN departments d 
                                    ON d.department_name = u.department
                                LEFT JOIN divisions v 
                                    ON v.id = u.division_id
                                WHERE u.email = ?
                                LIMIT 1
                                ");
        if (!$stmt) {
            error_log("DB prepare failed: " . $conn->error);
            $error = 'Internal error. Please try again later.';
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $dbRow = $result->fetch_assoc();
                $stmt->close();

                // ensure fields exist
                $dbId       = $dbRow['id'] ?? null;
                $dbUser     = $dbRow['username'] ?? '';
                $dbEmail    = $dbRow['email'] ?? '';
                $dbHash     = $dbRow['password'] ?? '';

                if ($dbId === null) {
                    $error = 'Invalid email or password.';
                } else {
                    // verify password
                    $loginOk = false;

                // bcrypt
                if (str_starts_with($dbHash, '$2y$')) {
                    $loginOk = password_verify($password, $dbHash);
                }
                // legacy MD5 support (optional migration)
                elseif (strlen($dbHash) === 32) {
                    $loginOk = (md5($password) === $dbHash);

                    // upgrade to bcrypt automatically
                    if ($loginOk) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $up = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $up->bind_param("si", $newHash, $dbId);
                        $up->execute();
                        $up->close();
                    }
                }

                if ($loginOk) {
                    // LOGIN SUCCESS

                        // set session user array
                        $_SESSION['user'] = [
                                            'id'            => (int)$dbRow['id'],
                                            'username'      => $dbRow['username'],
                                            'email'         => $dbRow['email'],
                                            'department'    => $dbRow['department'],
                                            'department_id' => (int)($dbRow['department_id'] ?? 0),
                                            'division_id'   => (int)($dbRow['division_id'] ?? 0),
                                            'division_name' => $dbRow['division_name'] ?? ''
                                        ];

                        // SSO cookie so vehicle booking can auto-login
                        setcookie('crm_sso_email', $dbRow['email'], [
                            'expires'  => 0,
                            'path'     => '/',
                            'samesite' => 'Lax',
                        ]);

                        // load roles for this user (auth_helpers must define getUserRoles)
                        $roles = [];
                        if (function_exists('getUserRoles')) {
                            $roles = getUserRoles((int)$dbId); // returns array of role names
                        }

                        // it_officer / it_director are permissions, not selectable personas.
                        // They are excluded from the role chooser but still grant access via
                        // hasRole() checks elsewhere.
                        $NON_SELECTABLE_ROLES = ['it_officer', 'it_director'];
                        $selectableRoles = array_values(array_diff($roles, $NON_SELECTABLE_ROLES));

                        if (empty($roles)) {
                            $_SESSION['active_role'] = 'user';
                            header('Location: ../dashboard.php');
                            exit;
                        }

                        // No selectable persona (e.g. user only holds it_officer/it_director):
                        // fall back to the standard 'user' role so they can still sign in.
                        if (empty($selectableRoles)) {
                            $_SESSION['active_role'] = 'user';
                            header('Location: ../dashboard.php');
                            exit;
                        }

                        if (count($selectableRoles) === 1) {
                            $_SESSION['active_role'] = $selectableRoles[0];
                            header('Location: ../dashboard.php');
                            exit;
                        }

                        // MULTIPLE SELECTABLE ROLES → choose role
                        $_SESSION['pending_roles'] = $selectableRoles;

                        header('Location: select_role.php');
                        exit;


                        // log activity if available
                        if (function_exists('logActivity')) {
                            logActivity('Login', "User ID {$dbId} ({$dbEmail}) logged in");
                        }

                        header('Location: ../dashboard.php');
                        exit;
                    } else {
                        $error = 'Invalid email or password.';
                    }
                }
            } else {
                // no user found
                $stmt->close();
                $error = 'Invalid email or password.';
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
    <title>Sign in CRM</title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="loginstyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">

    <style>
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
        }
        .error {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .success {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="overlay">
            <img src="../uploads/pspflogo2.png" alt="Company Logo" class="logo-img" />
            <h1 class="welcome">WELCOME</h1>
            <span class="tagline">Quick assistance from your desk.</span>
            <div class="name-headline">CUSTOMER RELATIONSHIP MANAGEMENT</div>
                <p>Copyright &copy; <?= date('Y') ?>  - All Rights Reserved to PSPF ICT</p>
            </div>          
        </div>
        
        <div class="right-panel">
            <h2 class="login-title">Sign in</h2>

            <!-- Display error messages -->
            <?php if (!empty($error)): ?>
                <div class="error">
                    <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['debug'])): ?>
                <div class="debug-info">
                    <strong>Debug Information:</strong><br>
                    PHP Version: <?= phpversion() ?><br>
                    Session ID: <?= session_id() ?><br>
                    Database Host: localhost<br>
                    <!-- Add more debug info as needed -->
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
                <div class="success">
                    You have successfully logged out.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                <div class="success">
                    Your password has been updated successfully. Please sign in.
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                         <input type="password" class="form-control" id="password" name="password" placeholder="Enter Password"required />
                <span class="toggle-password" onclick="togglePassword('password', this)" style="cursor:pointer">
                <i class="bi bi-eye"></i>
              </span>
                </div>
                
                <div class="form-options">
                    
                    <a href="./forgot_password.php" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit" class="login-btn">Sign in</button>
            </form>
            
            <div class="divider">
                <span></span>
            </div>
            
            <div class="signup-link">
                Don't have an account? <a href="./registration.php">Sign up</a>
               
            </div>

        </div>
    </div>
    
    <script src="script.js" defer></script>
    <script>
        // Toggle password visibility
        window.togglePassword = function (fieldId, iconWrapper) {
        const field = document.getElementById(fieldId);
        const icon = iconWrapper.querySelector("i");

        if (!field || !icon) {
            console.error("Password field or icon not found");
            return;
        }
              if (field.type === "password") {
                    field.type = "text";
                    icon.classList.remove("bi-eye");
                    icon.classList.add("bi-eye-slash");
                } else {
                    field.type = "password";
                    icon.classList.remove("bi-eye-slash");
                    icon.classList.add("bi-eye");
                }

        }

        // Auto-focus on email field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>