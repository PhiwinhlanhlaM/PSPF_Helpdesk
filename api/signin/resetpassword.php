<?php
session_start();
require_once '../db.php';

$token   = $_GET['token'] ?? '';
$error   = '';
$success = '';

if (!$token) {
    $error = "Invalid password reset link.";
} else {
    $stmt = $conn->prepare("
        SELECT id, reset_expires 
        FROM users 
        WHERE reset_token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || strtotime($user['reset_expires']) < time()) {
        $error = "This password reset link is invalid or has expired.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    $newPassword     = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // 🔐 Enforce strong password server-side
    $hasMinLength = strlen($newPassword) >= 12;
    $hasUpper     = preg_match('/[A-Z]/', $newPassword);
    $hasNumber    = preg_match('/[0-9]/', $newPassword);
    $hasSymbol    = preg_match('/[\W_]/', $newPassword);

    if ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (!$hasMinLength || !$hasUpper || !$hasNumber || !$hasSymbol) {
        $error = "Password must be at least 12 characters and include a capital letter, number, and symbol.";
    } else {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
           UPDATE users 
           SET password = ?, reset_token = NULL, reset_expires = NULL
           WHERE id = ?
        ");
        $stmt->bind_param("si", $hashed, $user['id']);
        $stmt->execute();
        $stmt->close();

        header("Location: index.php?reset=success");
        exit;
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - PSPF CRM</title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link rel="stylesheet" href="loginstyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">

</head>
<body>
<?php include './loader.php'; ?>

<div class="container">
        <div class="left-panel">
          <div class="overlay">
            <img src="../uploads/pspflogo2.png" alt="Company Logo" class="logo-img" />
            <h1 class="welcome">Password Reset </h1>
            <span class="tagline">Reset your password quickly and securely.</span>
            <div class="name-headline">CUSTOMER RELATIONSHIP MANAGEMENT</div>
                <p>Copyright &copy; <?= date('Y') ?>  - All Rights Reserved to PSPF ICT</p>
            </div>
        </div>

    <div class="right-panel">
            <h2 class="login-title">Change Password</h2>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
              <div class="form-group">
      <label for="password" class="form-label">Password</label>
        <div class="password-wrapper">
          <input type="password" class="form-control" id="password" name="password" required />
            <span class="toggle-password" onclick="togglePassword('password', this)" style="cursor:pointer">
  <i class="bi bi-eye"></i>
</span>
        </div>
            <div id="passwordStrength" class="mt-2">
              <div class="progress">
                <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
              </div>
              <small id="strengthText" class="text-muted"></small>
            </div>
        
       </div>

          <div class="form-group">
            <label for="confirm_password" class="form-label">Confirm Password</label>
             <div class="password-wrapper">
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required />
                <span class="toggle-password" onclick="togglePassword('confirm_password', this)"style="cursor:pointer">
                <i class="bi bi-eye"></i>
              </span>
             </div>
          </div>

                <button type="submit" class="login-btn">Update Password</button>
            </form>

        <div class="divider">
            <span>  </span>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("form");
  const passwordInput = document.getElementById("password");
  const confirmInput = document.getElementById("confirm_password");
  const strengthBar = document.getElementById("strengthBar");
  const strengthText = document.getElementById("strengthText");

  // ✅ Password strength rules
  function checkPasswordStrength(password) {
    const hasMinLength = password.length >= 12;
    const hasUpperCase = /[A-Z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSymbol = /[!@#$%^&*(),.?":{}|<>_\-\\[\];'`~]/.test(password);

    return { hasMinLength, hasUpperCase, hasNumber, hasSymbol };
  }

  // 🔐 Update strength bar live
  passwordInput.addEventListener("input", () => {
    const { hasMinLength, hasUpperCase, hasNumber, hasSymbol } =
      checkPasswordStrength(passwordInput.value);

    let strength = 0;
    if (hasMinLength) strength++;
    if (hasUpperCase) strength++;
    if (hasNumber) strength++;
    if (hasSymbol) strength++;

    switch (strength) {
      case 0:
        strengthBar.style.width = "0%";
        strengthBar.className = "progress-bar";
        strengthText.textContent = "";
        break;
      case 1:
        strengthBar.style.width = "25%";
        strengthBar.className = "progress-bar bg-danger";
        strengthText.textContent = "Very Weak — must be at least 12 chars, include a symbol, number, and capital letter";
        break;
      case 2:
        strengthBar.style.width = "50%";
        strengthBar.className = "progress-bar bg-warning";
        strengthText.textContent = "Weak — add more variety";
        break;
      case 3:
        strengthBar.style.width = "75%";
        strengthBar.className = "progress-bar bg-info";
        strengthText.textContent = "Good — one more element to go!";
        break;
      case 4:
        strengthBar.style.width = "100%";
        strengthBar.className = "progress-bar bg-success";
        strengthText.textContent = "Strong password ✅";
        break;
    }
  });

 /* -----------------------------
     Toggle password visibility
  ------------------------------*/
  window.togglePassword = function(id, el) {
    const f = document.getElementById(id);
    const i = el.querySelector("i");
    f.type = f.type === "password" ? "text" : "password";
    i.classList.toggle("bi-eye");
    i.classList.toggle("bi-eye-slash");
  };

  // 📨 Form validation before submit
  form.addEventListener("submit", (e) => {
    const password = passwordInput.value.trim();
    const confirmPassword = confirmInput.value.trim();

    const { hasMinLength, hasUpperCase, hasNumber, hasSymbol } =
      checkPasswordStrength(password);

    if (password !== confirmPassword) {
      e.preventDefault();
      alert("Passwords do not match.");
      return;
    }

    if (!hasMinLength || !hasUpperCase || !hasNumber || !hasSymbol) {
      e.preventDefault();
      alert("Password must be at least 12 characters long and include a capital letter, number, and symbol.");
      return;
    }
  });
});
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
