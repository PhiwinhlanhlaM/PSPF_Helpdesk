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
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
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
                    
                    $success = "Account created successfully! You can now login.";
                    // Clear form
                    $name = $email = $password = $confirm_password = $department = '';
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Vehicle Booking System</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="../vehicle_booking/style5.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #3D5C80 0%, #7FC8F8 100%);
            font-family: 'Titillium Web', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .signup-container {
            width: 100%;
            max-width: 500px;
            padding: 20px;
        }
        
        .signup-card {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            padding: 40px;
            transition: transform 0.3s ease;
        }
        
        .signup-card:hover {
            transform: translateY(-5px);
        }
        
        .signup-header {
            text-align: center;
            color: #3D5C80;
            font-weight: 600;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        .subheader {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
            font-size: 14px;
        }
        
        .form-label {
            color: #3D5C80;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: #3D5C80;
            box-shadow: 0 0 0 0.2rem rgba(61, 92, 128, 0.15);
        }
        
        .btn-signup {
            background-color: #F6AE2D;
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px;
            border-radius: 8px;
            width: 100%;
            margin-top: 20px;
            transition: background-color 0.3s;
        }
        
        .btn-signup:hover {
            background-color: #C62E65;
            color: white;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }
        
        .login-link a {
            color: #3D5C80;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .login-link a:hover {
            color: #C62E65;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            color: white;
            font-size: 12px;
        }
        
        .password-requirements {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<div class="signup-container">
    <div class="signup-card">
        <h2 class="signup-header">Create Account</h2>
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
                    <option value="Sales" <?= ($department ?? '') === 'Sales' ? 'selected' : '' ?>>Sales</option>
                    <option value="HR" <?= ($department ?? '') === 'HR' ? 'selected' : '' ?>>HR</option>
                    <option value="Operations" <?= ($department ?? '') === 'Operations' ? 'selected' : '' ?>>Operations</option>
                    <option value="Finance" <?= ($department ?? '') === 'Finance' ? 'selected' : '' ?>>Finance</option>
                    <option value="IT" <?= ($department ?? '') === 'IT' ? 'selected' : '' ?>>IT</option>
                    <option value="Marketing" <?= ($department ?? '') === 'Marketing' ? 'selected' : '' ?>>Marketing</option>
                    <option value="Other" <?= ($department ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Create a password" required>
                <div class="password-requirements">Minimum 6 characters</div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
            </div>
            
            <button type="submit" class="btn btn-signup">Create Account</button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
    
    <div class="footer">
        &copy; <?= date('Y') ?> PSPF Vehicle Booking System
    </div>
</div>

</body>
</html>
