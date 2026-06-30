<?php
require '../db.php';

$rows = $conn->query("
    SELECT v.id, v.division_name, d.department_name
    FROM divisions v
    JOIN departments d ON v.department_id = d.id
    ORDER BY d.department_name, v.division_name
");

$divisions = [];
while ($r = $rows->fetch_assoc()) {
    $divisions[] = $r;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign up CRM</title>
    <link rel="icon" type="image/png" href="../uploads/crmlogo.png">
    <link rel="stylesheet" href="loginstyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
    <style>
        .password-wrapper {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            z-index: 10;
        }
        .progress {
            height: 5px;
            margin-top: 5px;
        }
        .alert {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .mt-2 {
            margin-top: 8px;
        }
        .mt-3 {
            margin-top: 15px;
        }
        .text-muted {
            color: #6c757d;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <?php include './loader.php'; ?>
    <div class="container">
        <div class="left-panel">
            <div class="overlay">
                <img src="../uploads/pspflogo2.png" alt="Company Logo" class="logo-img" />
                <h1 class="welcome">Register</h1>
                <span class="tagline">Quick assistance from your desk.</span>
                <div class="name-headline">CUSTOMER RELATIONSHIP MANAGEMENT</div>
                <p>Copyright &copy; <?= date('Y') ?> - All Rights Reserved to PSPF ICT</p>
            </div>          
        </div>

        <div class="right-panel">
            <h2 class="login-title">Sign up</h2>

            <?php if (isset($error) && !empty($error)): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            
            <div id="alertMessage" class="mt-3"></div>

            <form id="registrationForm" method="POST" action="">
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required maxlength="50" />
                    <small class="text-muted">3-50 characters (letters, numbers, dots, underscores, hyphens)</small>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required />
                </div>

                <!-- Division -->
                <div class="form-group">
                    <label>Division</label>
                    <select id="division" class="form-select" required>
                        <option value="">Select Division</option>
                        <?php foreach ($divisions as $d): ?>
                        <option value="<?= $d['id'] ?>" data-department="<?= htmlspecialchars($d['department_name']) ?>">
                            <?= htmlspecialchars($d['department_name']) ?> — <?= htmlspecialchars($d['division_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Department auto -->
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" id="department" class="form-control" readonly required>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="password" name="password" required maxlength="32" />
                        <span class="toggle-password" onclick="togglePassword('password', this)">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                    <div id="passwordStrength" class="mt-2">
                        <div class="progress">
                            <div id="strengthBar" class="progress-bar" role="progressbar" style="width: 0%;"></div>
                        </div>
                        <small id="strengthText" class="text-muted"></small>
                    </div>
                    <small class="text-muted">12-32 characters with uppercase, lowercase, number, and special character</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required maxlength="32" />
                        <span class="toggle-password" onclick="togglePassword('confirm_password', this)">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" class="signup-btn">Sign Up</button>
                <button type="reset" class="register-btn" onclick="resetForm()">Cancel</button>
            </form>

            <div class="divider">
                <span></span>
            </div>

            <div class="signup-link">
                Already have an account? <a href="./index.php">Sign in</a>
            </div>
        </div>
    </div>

    <script>
    // Toggle password visibility
    window.togglePassword = function(id, el) {
        const input = document.getElementById(id);
        const icon = el.querySelector("i");
        
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("bi-eye");
            icon.classList.add("bi-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("bi-eye-slash");
            icon.classList.add("bi-eye");
        }
    };
    
    // Reset form function
    window.resetForm = function() {
        document.getElementById("registrationForm").reset();
        document.getElementById("strengthBar").style.width = "0%";
        document.getElementById("strengthText").textContent = "";
        document.getElementById("alertMessage").innerHTML = "";
    };
    
    document.addEventListener("DOMContentLoaded", () => {
        const form = document.getElementById("registrationForm");
        const divisionSel = document.getElementById("division");
        const deptInput = document.getElementById("department");
        const usernameInput = document.getElementById("username");
        const emailInput = document.getElementById("email");
        const pw = document.getElementById("password");
        const confirmPw = document.getElementById("confirm_password");
        const bar = document.getElementById("strengthBar");
        const txt = document.getElementById("strengthText");
        const alertBox = document.getElementById("alertMessage");
        
        // Division → Department autofill
        divisionSel.addEventListener("change", () => {
            const opt = divisionSel.options[divisionSel.selectedIndex];
            deptInput.value = opt.dataset.department || "";
        });
        
        // Password strength meter with max length check
        function updatePasswordStrength() {
            const password = pw.value;
            const length = password.length;
            
            const hasMin = length >= 12;
            const hasMax = length <= 32;
            const hasUpper = /[A-Z]/.test(password);
            const hasLower = /[a-z]/.test(password);
            const hasNum = /[0-9]/.test(password);
            const hasSym = /[^A-Za-z0-9]/.test(password);
            
            // Check length first
            if (length === 0) {
                bar.style.width = "0%";
                bar.className = "progress-bar";
                txt.textContent = "";
                return;
            }
            
            if (!hasMin) {
                bar.style.width = "0%";
                bar.className = "progress-bar bg-danger";
                txt.textContent = "❌ Password must be at least 12 characters";
                return;
            }
            
            if (!hasMax) {
                bar.style.width = "0%";
                bar.className = "progress-bar bg-danger";
                txt.textContent = "❌ Password cannot exceed 32 characters";
                return;
            }
            
            // Calculate strength (count valid criteria)
            let strength = 0;
            if (hasMin && hasMax) strength++;
            if (hasUpper) strength++;
            if (hasLower) strength++;
            if (hasNum) strength++;
            if (hasSym) strength++;
            
            // Update UI based on strength
            switch (strength) {
                case 1:
                    bar.style.width = "20%";
                    bar.className = "progress-bar bg-danger";
                    txt.textContent = "Very Weak — need uppercase, lowercase, number & special character";
                    break;
                case 2:
                    bar.style.width = "40%";
                    bar.className = "progress-bar bg-danger";
                    txt.textContent = "Weak — add more variety";
                    break;
                case 3:
                    bar.style.width = "60%";
                    bar.className = "progress-bar bg-warning";
                    txt.textContent = "Fair — add uppercase, numbers, or special characters";
                    break;
                case 4:
                    bar.style.width = "80%";
                    bar.className = "progress-bar bg-info";
                    txt.textContent = "Good — almost strong";
                    break;
                case 5:
                    bar.style.width = "100%";
                    bar.className = "progress-bar bg-success";
                    txt.textContent = "✓ Strong password (12-32 characters with good variety)";
                    break;
                default:
                    bar.style.width = "0%";
                    bar.className = "progress-bar";
                    txt.textContent = "";
            }
        }
        
        // Real-time password strength check
        pw.addEventListener("input", updatePasswordStrength);
        
        // Form submission
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            
            const username = usernameInput.value.trim();
            const email = emailInput.value.trim();
            const department = deptInput.value;
            const division_id = divisionSel.value;
            const password = pw.value;
            const confirm = confirmPw.value;
            
            // Clear previous alerts
            alertBox.innerHTML = '';
            
            // Validation checks
            if (!division_id) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Please select a division</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            if (password !== confirm) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Passwords do not match</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            // Password length validation
            if (password.length < 12) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Password must be at least 12 characters long</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            if (password.length > 32) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Password cannot exceed 32 characters</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            // Password strength validation
            const hasUpper = /[A-Z]/.test(password);
            const hasLower = /[a-z]/.test(password);
            const hasNum = /[0-9]/.test(password);
            const hasSym = /[^A-Za-z0-9]/.test(password);
            
            if (!hasUpper || !hasLower || !hasNum || !hasSym) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Password must contain uppercase, lowercase, number, and special character</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            // Username validation
            if (!/^[a-zA-Z0-9_\-\.]{3,50}$/.test(username)) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Username must be 3-50 characters and contain only letters, numbers, dots, underscores, and hyphens</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
            if (!emailRegex.test(email)) {
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Please enter a valid email address</div>';
                alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                return;
            }
            
            // Disable submit button to prevent double submission
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
            
            try {
                const res = await fetch("./register_user.php", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify({
                        username,
                        email,
                        department,
                        division_id: parseInt(division_id),
                        password
                    })
                });
                
                const text = await res.text();
                console.log("RAW RESPONSE:", text);
                
                try {
                    const data = JSON.parse(text);
                    
                    if (data.success) {
                        alertBox.innerHTML = '<div class="alert alert-success">✓ ' + data.message + '</div>';
                        form.reset();
                        updatePasswordStrength(); // Reset strength meter
                        setTimeout(() => {
                            window.location.href = "./index.php";
                        }, 3000);
                    } else {
                        alertBox.innerHTML = '<div class="alert alert-danger">⚠️ ' + data.message + '</div>';
                        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                    
                } catch (e) {
                    console.error("JSON parse error:", e);
                    alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Server returned an invalid response. Please try again.</div>';
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
                
            } catch (err) {
                console.error("Fetch error:", err);
                alertBox.innerHTML = '<div class="alert alert-danger">⚠️ Network error. Please check your connection and try again.</div>';
                submitBtn.disabled = false;
                submitBtn.textContent = originalBtnText;
            }
        });
    });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>