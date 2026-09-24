<?php
session_start();
require '../vehicle_booking/db.php';

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $department = trim($_POST['department'] ?? '');

    // Validation
    if (empty($name)) {
        $error = "Username is required.";
    } elseif (empty($email)) {
        $error = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (empty($password)) {
        $error = "Password is required.";
    } elseif (strlen($password) < 12) {
        $error = "Password must be at least 12 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number.";
    } elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'"<>,.?\/ ]/', $password)) {
        $error = "Password must contain at least one special character (!@#$%^&* etc).";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (empty($department)) {
        $error = "Department is required.";
    } else {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT * FROM users WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->rowCount() > 0) {
            $error = "Username already exists. Please choose another.";
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $error = "Email already registered. Please use another.";
            } else {
                // Hash password and insert user
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                try {
                    $stmt = $conn->prepare("INSERT INTO users (name, email, password, department, role, active, password_reset_required) 
                                          VALUES (?, ?, ?, ?, 'user', 1, 0)");
                    $stmt->execute([$name, $email, $hashed_password, $department]);
                    
                   // Redirect to login page after successful registration
header("Location: login.php?success=1");
exit();
                } catch (Exception $e) {
                    $error = "Registration failed. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Sign Up'; $legacyCss = false; require __DIR__ . '/partials/head.php'; ?>
    <style>
        .signup-container { width: 100%; max-width: 480px; }
        .subheader { text-align: center; color: var(--ink-500); margin-bottom: 1.5rem; font-size: .92rem; }
        .login-link { text-align: center; margin-top: 1.25rem; font-size: .9rem; color: var(--ink-500); }
        .password-requirements {
            font-size: 13px;
            margin-top: 10px;
            padding: 12px;
            background-color: var(--ink-50);
            border-radius: 8px;
            border-left: 4px solid var(--ink-200);
        }
        .password-requirement-item { margin: 6px 0; display: flex; align-items: center; color: #c92a2a; font-size: 12px; }
        .password-requirement-item.met { color: #15915a; }
        .requirement-icon { margin-right: 8px; font-weight: bold; }
    </style>
</head>
<body class="vb-auth">

<div class="signup-container">
    <div class="signup-card">
        <h2 class="signup-header text-center mb-2">Create Account</h2>
        <p class="subheader">Join the Vehicle Booking System</p>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="name" class="form-control" placeholder="Choose a username" 
                       value="<?= htmlspecialchars($name ?? '') ?>" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="Enter your email" 
                       value="<?= htmlspecialchars($email ?? '') ?>" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Department</label>
                <select name="department" class="form-control" required>
                    <option value="">Select a department</option>
                    <option value="Facilities" <?= ($department ?? '') === 'Facilities' ? 'selected' : '' ?>>Facilities</option>
                    <option value="HR" <?= ($department ?? '') === 'HR' ? 'selected' : '' ?>>HR</option>
                    <option value="Benefits" <?= ($department ?? '') === 'Benefits' ? 'selected' : '' ?>>Benefits</option>
                    <option value="Accounting" <?= ($department ?? '') === 'Accounting' ? 'selected' : '' ?>>Accounting</option>
                    <option value="ICT" <?= ($department ?? '') === 'ICT' ? 'selected' : '' ?>>ICT</option>
                    <option value="CEO's Office" <?= ($department ?? '') === 'CEOs Office' ? 'selected' : '' ?>>CEO's Office</option>
                    <option value="Investments" <?= ($department ?? '') === 'Investments' ? 'selected' : '' ?>>Investments</option>
			<option value="Investment Monitoring" <?= ($department ?? '') === 'Investment Monitoring' ? 'selected' : '' ?>>Investment Monitoring</option>
		    <option value="Legal" <?= ($department ?? '') === 'Legal' ? 'selected' : '' ?>>Legal</option>
		    <option value="Audit" <?= ($department ?? '') === 'Audit' ? 'selected' : '' ?>>Audit</option>
			<option value="Marketing" <?= ($department ?? '') === 'Marketing' ? 'selected' : '' ?>>Marketing</option>
			<option value="Company Secretary" <?= ($department ?? '') === 'Company Secretary' ? 'selected' : '' ?>>Company Secretary</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required onkeyup="validatePassword()">
                <div class="password-requirements">
                    <div class="password-requirement-item" id="length-req">
                        <span class="requirement-icon">✕</span>
                        At least 12 characters
                    </div>
                    <div class="password-requirement-item" id="uppercase-req">
                        <span class="requirement-icon">✕</span>
                        At least one uppercase letter (A-Z)
                    </div>
                    <div class="password-requirement-item" id="number-req">
                        <span class="requirement-icon">✕</span>
                        At least one number (0-9)
                    </div>
                    <div class="password-requirement-item" id="symbol-req">
                        <span class="requirement-icon">✕</span>
                        At least one special character (!@#$%^&* etc)
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
            </div>
            
            <button type="submit" class="btn btn-signup w-100 mt-3">Create Account</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
    
    <div class="footer">
        &copy; <?= date('Y') ?> PSPF Vehicle Booking System
    </div>
</div>

<script>
function validatePassword() {
    const password = document.getElementById('password').value;
    
    // Check each requirement
    const lengthMet = password.length >= 12;
    const uppercaseMet = /[A-Z]/.test(password);
    const numberMet = /[0-9]/.test(password);
    const symbolMet = /[!@#$%^&*()_+\-=\[\]{};:'"<>,.?\/\\ ]/.test(password);
    
    // Update UI for each requirement
    updateRequirement('length-req', lengthMet);
    updateRequirement('uppercase-req', uppercaseMet);
    updateRequirement('number-req', numberMet);
    updateRequirement('symbol-req', symbolMet);
}

function updateRequirement(elementId, isMet) {
    const element = document.getElementById(elementId);
    const icon = element.querySelector('.requirement-icon');
    
    if (isMet) {
        element.classList.add('met');
        icon.textContent = '✓';
    } else {
        element.classList.remove('met');
        icon.textContent = '✕';
    }
}
</script>

</body>
</html>
