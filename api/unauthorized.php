<?php
// unauthorized.php
session_start();

// Include auth helpers to get role information
require_once './includes/auth_helpers.php';
require_once './includes/role_switcher.php';
require_once './includes/division_helpers.php';
require_once './includes/auth_functions.php';

$currentUser = isset($_SESSION['user']) ? $_SESSION['user']['username'] : 'Guest';
$currentRole = isLoggedIn() ? getActiveRole() : 'Not logged in';
$availableRoles = isLoggedIn() ? requireAnyRole() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="./uploads/pspflogo2.png">
    <style> 
        body {
            background: linear-gradient(135deg, #7FC8F8 0%, #406997 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .unauthorized-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
            margin: 20px;
        }
        .header-section {
            background: #7FC8F8;
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .content-section {
            padding: 2rem;
        }
        .role-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        .icon-large {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="unauthorized-card">
        <!-- Header Section -->
        <div class="header-section">
            <i class="bi bi-shield-x icon-large"></i>
            <h1 class="h3 mb-2">Access Denied</h1>
            <p class="mb-0 opacity-75">You don't have permission to access this resource</p>
        </div>

        <!-- Content Section -->
        <div class="content-section">
            <!-- Main Message -->
            <div class="text-center mb-4">
                <h4 class="text-danger mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Unauthorized Access Attempt
                </h4>
                <p class="text-muted">
                    Your current role does not have the required permissions to view this page.
                </p>
            </div>

            <!-- User & Role Information -->
            <div class="role-info">
                <h6 class="mb-3">
                    <i class="bi bi-person-badge me-2"></i>
                    Current Session Information
                </h6>
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted d-block">User</small>
                        <strong><?= htmlspecialchars($currentUser) ?></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">Active Role</small>
                        <strong class="text-primary"><?= ucfirst($currentRole) ?></strong>
                    </div>
                </div>
                
                <?php if (!empty($availableRoles) && count($availableRoles) > 1): ?>
                <div class="mt-3">
                    <small class="text-muted d-block">Your Available Roles</small>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <?php foreach ($availableRoles as $role): ?>
                            <span class="badge bg-<?= $role === $currentRole ? 'primary' : 'secondary' ?>">
                                <?= ucfirst($role) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Possible Reasons -->
            <div class="alert alert-warning">
                <h6 class="alert-heading">
                    <i class="bi bi-info-circle me-2"></i>
                    Why am I seeing this page?
                </h6>
                <ul class="mb-0 ps-3">
                    <li>Your account doesn't have the required role permissions</li>
                    <li>You may need to switch to a different role</li>
                    <li>The page requires specific administrative privileges</li>
                    <li>Your session might have expired</li>
                </ul>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <?php if (isLoggedIn() && count($availableRoles) > 1): ?>
                    <a href="dashboard.php?switch_role=<?= urlencode(getActiveRole()) ?>" 
                       class="btn btn-primary flex-fill">
                        <i class="bi bi-arrow-left-right me-1"></i>
                        Switch to Primary Role
                    </a>
                <?php endif; ?>
                
                <a href="./dashboard.php" class="btn btn-success flex-fill">
                    <i class="bi bi-house me-1"></i>
                    Go to Dashboard
                </a>
                
                <?php if (isLoggedIn()): ?>
                    <a href="./signin/logout.php" class="btn btn-outline-danger">
                        <i class="bi bi-box-arrow-right me-1"></i>
                        Logout
                    </a>
                <?php else: ?>
                    <a href="./signin/index.php" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        Login
                    </a>
                <?php endif; ?>
            </div>

            <!-- Contact Support -->
            <div class="text-center mt-4">
                <small class="text-muted">
                    If you believe this is an error, please contact 
                    <a href="mailto:it@pspf.co.sz?subject=Access%20Denied%20Error" class="text-decoration-none">
                        system administrator
                    </a>
                </small>
            </div>

            <!-- Debug Information (Visible only in development) -->
            <?php if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false): ?>
            <div class="debug-info">
                <h6 class="mb-2">
                    <i class="bi bi-bug me-1"></i>
                    Debug Information
                </h6>
                <small>
                    <strong>Requested URL:</strong> <?= $_SERVER['REQUEST_URI'] ?><br>
                    <strong>Referrer:</strong> <?= $_SERVER['HTTP_REFERER'] ?? 'Direct access' ?><br>
                    <strong>User Agent:</strong> <?= $_SERVER['HTTP_USER_AGENT'] ?>
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Optional: Auto-redirect after 10 seconds -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-redirect to dashboard after 10 seconds
            setTimeout(function() {
                window.location.href = './dashboard.php';
            }, 10000);

            // Show countdown
            let seconds = 10;
            const countdownElement = document.createElement('div');
            countdownElement.className = 'text-center mt-3 text-muted';
            countdownElement.innerHTML = `<small>Redirecting to dashboard in <span id="countdown">${seconds}</span> seconds...</small>`;
            document.querySelector('.content-section').appendChild(countdownElement);

            const countdown = setInterval(function() {
                seconds--;
                document.getElementById('countdown').textContent = seconds;
                if (seconds <= 0) {
                    clearInterval(countdown);
                }
            }, 1000);
        });
    </script>
</body>
</html>