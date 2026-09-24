<?php
session_start();

// ══════════════════════════════════════════════════════════════════
//  AUTO-LOGIN via SSO cookie set by the CRM on login
// ══════════════════════════════════════════════════════════════════

if (!isset($_SESSION['user_id']) && !isset($_GET['logout']) && !isset($_GET['timeout'])) {

    $hd_email = $_COOKIE['crm_sso_email'] ?? null;

    if ($hd_email) {
        try {
            $vehicle_conn = new PDO(
                "mysql:host=localhost;dbname=vehicle_requisition",
                'root', ''
            );
            $vehicle_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $vehicle_conn->prepare(
                "SELECT user_id, name, role, department, active, password_reset_required
                 FROM users
                 WHERE LOWER(email) = LOWER(?)"
            );
            $stmt->execute([$hd_email]);
            $vr_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($vr_user && $vr_user['active'] == 1) {

                $_SESSION['user_id']    = $vr_user['user_id'];
                $_SESSION['name']       = $vr_user['name'];
                $_SESSION['role']       = $vr_user['role'];
                $_SESSION['email']      = $hd_email;
                $_SESSION['department'] = $vr_user['department'];

                if ($vr_user['password_reset_required'] == 1) {
                    header("Location: force_change_password.php");
                    exit();
                }

                $dashboards = [
                    'user'       => 'user_dashboard.php',
                    'driver'     => 'driver_dashboard.php',
                    'supervisor' => 'supervisor_dashboard.php',
                    'hrm'        => 'hrm_dashboard.php',
                    'admin'      => 'admin_dashboard.php',
                    'viewer'     => 'view.php',
                ];

                header("Location: " . ($dashboards[$vr_user['role']] ?? 'login.php'));
                exit();
            }

        } catch (PDOException $e) {
            // Auto-login failed silently — fall through to manual login form
        }
    }
}

// ══════════════════════════════════════════════════════════════════
//  If already fully logged in, skip the form altogether
// ══════════════════════════════════════════════════════════════════
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $dashboards = [
        'user'       => 'user_dashboard.php',
        'driver'     => 'driver_dashboard.php',
        'supervisor' => 'supervisor_dashboard.php',
        'hrm'        => 'hrm_dashboard.php',
        'admin'      => 'admin_dashboard.php',
        'viewer'     => 'view.php',
    ];
    header("Location: " . ($dashboards[$_SESSION['role']] ?? 'login.php'));
    exit();
}

// ══════════════════════════════════════════════════════════════════
//  Normal manual login (original code)
// ══════════════════════════════════════════════════════════════════

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {

        try {
            $helpdesk_conn = new PDO(
                "mysql:host=localhost;dbname=pspf_helpdesk",
                'root', ''
            );
            $helpdesk_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Helpdesk DB connection failed: " . $e->getMessage());
        }

        try {
            $vehicle_conn = new PDO(
                "mysql:host=localhost;dbname=vehicle_requisition",
                'root', ''
            );
            $vehicle_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Vehicle DB connection failed: " . $e->getMessage());
        }

        $stmt = $helpdesk_conn->prepare(
            "SELECT id, Username, Email, Password, is_active
             FROM users
             WHERE LOWER(Email) = LOWER(?)"
        );
        $stmt->execute([$email]);
        $hd_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($hd_user && password_verify($password, $hd_user['Password'])) {

            if ($hd_user['is_active'] == 0) {
                $error = "Your account has been deactivated. Please contact the admin.";
            } else {

                $stmt2 = $vehicle_conn->prepare(
                    "SELECT user_id, name, role, department, active, password_reset_required
                     FROM users
                     WHERE LOWER(email) = LOWER(?)"
                );
                $stmt2->execute([$email]);
                $vr_user = $stmt2->fetch(PDO::FETCH_ASSOC);

                if (!$vr_user) {
                    $error = "Your account is not registered in the Vehicle Booking System. Please contact the admin.";
                } elseif ($vr_user['active'] == 0) {
                    $error = "Your Vehicle Booking account has been deactivated. Please contact the admin.";
                } else {

                    $_SESSION['user_id']    = $vr_user['user_id'];
                    $_SESSION['name']       = $hd_user['Username'];
                    $_SESSION['role']       = $vr_user['role'];
                    $_SESSION['email']      = $hd_user['Email'];
                    $_SESSION['department'] = $vr_user['department'];

                    if ($vr_user['password_reset_required'] == 1) {
                        header("Location: force_change_password.php");
                        exit();
                    }

                    switch ($vr_user['role']) {
                        case 'user':        header("Location: user_dashboard.php");       break;
                        case 'driver':      header("Location: driver_dashboard.php");     break;
                        case 'supervisor':  header("Location: supervisor_dashboard.php"); break;
                        case 'hrm':         header("Location: hrm_dashboard.php");        break;
                        case 'admin':       header("Location: admin_dashboard.php");      break;
                        case 'viewer':      header("Location: view.php");                 break;
                        default:            header("Location: login.php");
                    }
                    exit();
                }
            }

        } else {
            $error = "Invalid email or password.";
        }

    } else {
        $error = "Please enter both email and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Login'; $legacyCss = false; require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="vb-auth">

<div class="container">
  <div class="row justify-content-center">
    <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
      <div class="login-card">
        <div class="vb-auth-brand">
          <img src="PSPFlogo.png" alt="PSPF">
          <h1>Transport Booking</h1>
          <p>Sign in with your PSPF email to continue</p>
        </div>

        <?php if (isset($_GET['timeout'])): ?>
          <div class="alert alert-warning">Your session expired due to inactivity. Please log in again.</div>
        <?php endif; ?>

        <?php if (isset($_GET['logout'])): ?>
          <div class="alert alert-success">You have been logged out.</div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control"
                   placeholder="Enter your email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control"
                   placeholder="Enter your password" required>
          </div>

          <button class="btn btn-primary w-100 mt-2" type="submit">
            Sign in
          </button>
        </form>

        <hr class="my-4">

        <div class="auth-switch text-center small text-muted">
          Don't have an account? <a href="signup.php">Sign up here</a>
        </div>
      </div>

      <div class="footer">
        &copy; <?= date('Y') ?> PSPF Vehicle Booking System
      </div>
    </div>
  </div>
</div>

</body>
</html>