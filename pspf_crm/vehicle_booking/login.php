<?php
session_start();

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {

        // ── 1. Connect to pspf_helpdesk (authentication) ──────────────
        try {
            $helpdesk_conn = new PDO(
                "mysql:host=localhost;dbname=pspf_helpdesk",
                'root', ''
            );
            $helpdesk_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Helpdesk DB connection failed: " . $e->getMessage());
        }

        // ── 2. Connect to vehicle_requisition (roles) ──────────────────
        try {
            $vehicle_conn = new PDO(
                "mysql:host=localhost;dbname=vehicle_requisition",
                'root', ''
            );
            $vehicle_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Vehicle DB connection failed: " . $e->getMessage());
        }

        // ── 3. Verify credentials against helpdesk ─────────────────────
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

                // ── 4. Look up role in vehicle_requisition ─────────────
                $stmt2 = $vehicle_conn->prepare(
                    "SELECT user_id, name, role, department, active, password_reset_required
                     FROM users
                     WHERE LOWER(email) = LOWER(?)"
                );
                $stmt2->execute([$email]);
                $vr_user = $stmt2->fetch(PDO::FETCH_ASSOC);

                if (!$vr_user) {
                    // User exists in helpdesk but has no vehicle_requisition record yet
                    $error = "Your account is not registered in the Vehicle Booking System. Please contact the admin.";
                } elseif ($vr_user['active'] == 0) {
                    $error = "Your Vehicle Booking account has been deactivated. Please contact the admin.";
                } else {

                    // ── 5. Set session variables ───────────────────────
                    $_SESSION['user_id']   = $vr_user['user_id'];
                    $_SESSION['name']      = $hd_user['Username'];
                    $_SESSION['role']      = $vr_user['role'];
                    $_SESSION['email']     = $hd_user['Email'];
                    $_SESSION['department'] = $vr_user['department'];

                    // ── 6. Force password change if flagged ────────────
                    if ($vr_user['password_reset_required'] == 1) {
                        header("Location: force_change_password.php");
                        exit();
                    }

                    // ── 7. Redirect based on role ──────────────────────
                    switch ($vr_user['role']) {
                        case 'user':
                            header("Location: user_dashboard.php");
                            break;
                        case 'driver':
                            header("Location: driver_dashboard.php");
                            break;
                        case 'supervisor':
                            header("Location: supervisor_dashboard.php");
                            break;
                        case 'hrm':
                            header("Location: hrm_dashboard.php");
                            break;
                        case 'admin':
                            header("Location: admin_dashboard.php");
                            break;
                        case 'viewer':
                            header("Location: view.php");
                            break;
                        default:
                            header("Location: login.php");
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
  <meta charset="UTF-8">
  <title>Login - Vehicle Booking System</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600&display=swap" rel="stylesheet">
  <link href="../vehicle_booking/style.css" rel="stylesheet">

  <style>
    body {
      background: linear-gradient(135deg, #3D5C80 0%, #7FC8F8 100%);
      font-family: 'Titillium Web', sans-serif;
    }
    .login-card {
      background: #fff;
      border-radius: 15px;
      box-shadow: 0 6px 20px rgba(0,0,0,0.2);
      padding: 30px;
      margin-top: 10%;
      transition: transform 0.3s ease;
    }
    .login-card:hover {
      transform: translateY(-5px);
    }
    .login-header {
      text-align: center;
      color: #3D5C80;
      font-weight: 600;
      margin-bottom: 20px;
    }
    .btn-primary {
      background-color: #F6AE2D;
      border-color: #F6AE2D;
      font-weight: 600;
    }
    .btn-primary:hover {
      background-color: #C62E65;
      border-color: #C62E65;
    }
    .footer {
      text-align: center;
      margin-top: 40px;
      color: white;
      font-size: 14px;
    }
    .form-label {
      color: #3D5C80;
      font-weight: 600;
    }
  </style>
</head>
<body>

<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-5">
      <div class="login-card">
        <h3 class="login-header">Vehicle Booking System</h3>

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
            Login
          </button>
        </form>

        <hr style="margin: 20px 0; border: 1px solid #ddd;">

        <div style="text-align: center; font-size: 14px; color: #666;">
          Don't have an account? <a href="signup.php" style="color: #3D5C80; font-weight: 600; text-decoration: none;">Sign up here</a>
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