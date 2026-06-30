<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require '../mail_config.php';
require '../db.php';

// Rate limiting setup
function checkRateLimit($ip) {
    $rateLimitFile = sys_get_temp_dir() . '/rate_limit_' . md5($ip);
    $now = time();
    $window = 3600; // 1 hour window
    $maxAttempts = 5;
    
    if (file_exists($rateLimitFile)) {
        $data = json_decode(file_get_contents($rateLimitFile), true);
        if ($data && isset($data['attempts']) && isset($data['first_attempt'])) {
            if ($now - $data['first_attempt'] > $window) {
                $data = ['attempts' => 1, 'first_attempt' => $now];
            } else {
                $data['attempts']++;
            }
        } else {
            $data = ['attempts' => 1, 'first_attempt' => $now];
        }
    } else {
        $data = ['attempts' => 1, 'first_attempt' => $now];
    }
    
    file_put_contents($rateLimitFile, json_encode($data));
    
    if ($data['attempts'] > $maxAttempts) {
        http_response_code(429);
        echo json_encode([
            "success" => false,
            "message" => "Too many registration attempts. Please try again in 1 hour."
        ]);
        exit();
    }
    
    return true;
}

// Validate password with specific requirements
function validatePassword($password, &$errors = []) {
    $isValid = true;
    
    $length = strlen($password);
    if ($length < 12) {
        $errors[] = "at least 12 characters";
        $isValid = false;
    }
    if ($length > 32) {
        $errors[] = "no more than 32 characters";
        $isValid = false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "at least one uppercase letter";
        $isValid = false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "at least one lowercase letter";
        $isValid = false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "at least one number";
        $isValid = false;
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "at least one special character";
        $isValid = false;
    }
    
    return $isValid;
}

// Check database connection
if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Service unavailable. Please try again later."]);
    exit();
}

// Apply rate limiting
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
checkRateLimit($client_ip);

// Get and decode input
$input = file_get_contents("php://input");
$data = json_decode($input);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON data"]);
    exit();
}

// Validate required fields
$required = ['username', 'email', 'department', 'division_id', 'password'];
$missing = [];
foreach ($required as $field) {
    if (empty($data->$field)) {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields: " . implode(', ', $missing)
    ]);
    exit();
}

// Sanitize inputs
$username = htmlspecialchars(trim($data->username), ENT_QUOTES, 'UTF-8');
$email = filter_var(trim($data->email), FILTER_SANITIZE_EMAIL);
$department = htmlspecialchars(trim($data->department), ENT_QUOTES, 'UTF-8');
$division_id = filter_var($data->division_id, FILTER_VALIDATE_INT);
$raw_password = $data->password;

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid email address format"]);
    exit();
}

// Validate username
if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,50}$/', $username)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username must be 3-50 characters and contain only letters, numbers, dots, underscores, and hyphens"]);
    exit();
}

// Validate division_id
if ($division_id === false || $division_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid division selection"]);
    exit();
}

// Validate password
$passwordErrors = [];
if (!validatePassword($raw_password, $passwordErrors)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Password must contain: " . implode(', ', $passwordErrors)
    ]);
    exit();
}

// Hash password
$password = password_hash($raw_password, PASSWORD_DEFAULT);

// Start transaction
$conn->begin_transaction();

try {
    // Validate division exists and get department info
    $chkDiv = $conn->prepare("SELECT department_id, division_name FROM divisions WHERE id = ?");
    if (!$chkDiv) {
        throw new Exception("Database error occurred");
    }
    
    $chkDiv->bind_param("i", $division_id);
    $chkDiv->execute();
    $divResult = $chkDiv->get_result();
    
    if ($divResult->num_rows === 0) {
        throw new Exception("Selected division does not exist", 400);
    }
    
    $divisionData = $divResult->fetch_assoc();
    $division_name = $divisionData['division_name'];
    $department_id = $divisionData['department_id'];
    $chkDiv->close();
    
    // Verify department matches
    $deptCheck = $conn->prepare("SELECT department_name FROM departments WHERE id = ?");
    $deptCheck->bind_param("i", $department_id);
    $deptCheck->execute();
    $deptResult = $deptCheck->get_result();
    $deptData = $deptResult->fetch_assoc();
    
    if ($deptData['department_name'] !== $department) {
        $department = $deptData['department_name'];
        error_log("Department mismatch corrected for user $username");
    }
    $deptCheck->close();
    
    // Check for duplicate email
    $check = $conn->prepare("SELECT id, email_verified FROM users WHERE LOWER(email) = LOWER(?)");
    if (!$check) {
        throw new Exception("Database error occurred");
    }
    
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $check->bind_result($existing_id, $email_verified);
        $check->fetch();
        
        if ($email_verified) {
            throw new Exception("Email already registered. Please login or use a different email.", 409);
        } else {
            $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $deleteStmt->bind_param("i", $existing_id);
            if (!$deleteStmt->execute()) {
                throw new Exception("Unable to process registration");
            }
            $deleteStmt->close();
        }
    }
    $check->close();
    
    // Check for duplicate username
    $checkUser = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $checkUser->bind_param("s", $username);
    $checkUser->execute();
    $checkUser->store_result();
    
    if ($checkUser->num_rows > 0) {
        throw new Exception("Username already taken. Please choose a different username.", 409);
    }
    $checkUser->close();
    
    // Check if email_verified column exists, if not, alter table
    $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
    if ($checkColumn->num_rows == 0) {
        $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
    }
    
    $checkColumn2 = $conn->query("SHOW COLUMNS FROM users LIKE 'verification_token'");
    if ($checkColumn2->num_rows == 0) {
        $conn->query("ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) NULL");
    }
    
    $checkColumn3 = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'");
    if ($checkColumn3->num_rows == 0) {
        $conn->query("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    
    // Insert user
    $stmt = $conn->prepare("
        INSERT INTO users (username, email, department, division_id, password, email_verified, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    
    if (!$stmt) {
        throw new Exception("Database error occurred");
    }
    
    $stmt->bind_param("sssis", $username, $email, $department, $division_id, $password);
    
    if (!$stmt->execute()) {
        throw new Exception("Unable to create account. Please try again.");
    }
    
    $user_id = $stmt->insert_id;
    $stmt->close();
    
    // Get default role - using 'name' column (correct for your table)
    $roleQuery = "SELECT id FROM roles WHERE name = 'user' LIMIT 1";
    $roleRes = $conn->query($roleQuery);
    
    if (!$roleRes || $roleRes->num_rows === 0) {
        // If 'user' role doesn't exist, try to get any role
        $roleRes = $conn->query("SELECT id FROM roles LIMIT 1");
        if (!$roleRes || $roleRes->num_rows === 0) {
            throw new Exception("No roles found in system. Please contact administrator.", 500);
        }
    }
    
    $roleRow = $roleRes->fetch_assoc();
    $userRoleId = (int)$roleRow['id'];
    $roleRes->free();
    
    // Check if user_roles table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_roles'");
    if ($tableCheck->num_rows == 0) {
        // Create user_roles table if it doesn't exist
        $conn->query("
            CREATE TABLE IF NOT EXISTS user_roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                role_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_role (user_id, role_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
            )
        ");
    }
    
    // Assign role
    $roleStmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
    if (!$roleStmt) {
        throw new Exception("Database error occurred");
    }
    
    $roleStmt->bind_param("ii", $user_id, $userRoleId);
    
    if (!$roleStmt->execute()) {
        throw new Exception("Unable to complete registration");
    }
    $roleStmt->close();
    
    // Generate email verification token
    $verification_token = bin2hex(random_bytes(32));
    $tokenStmt = $conn->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
    $tokenStmt->bind_param("si", $verification_token, $user_id);
    $tokenStmt->execute();
    $tokenStmt->close();
    
    // Commit transaction
    $conn->commit();
    
    // Send verification email
    $emailSent = false;
    try {
        if (function_exists('getMailer')) {
            $mail = getMailer(true);
            $mail->addAddress($email);
            $mail->Subject = "PSPF CRM - Verify Your Email Address";
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $verification_link = $protocol . "://" . $host . "/pspf_crm/api/signin/verify_email.php?token=" . $verification_token;
            
            $mail->Body = "Dear $username,\n\n" .
                         "Thank you for registering with PSPF CRM.\n\n" .
                         "Please verify your email address by clicking the link below:\n" .
                         "$verification_link\n\n" .
                         "Registration Details:\n" .
                         "Department: $department\n" .
                         "Unit: $division_name\n\n" .
                         "Password Requirements:\n" .
                         "- 12 to 32 characters in length\n" .
                         "- At least one uppercase letter\n" .
                         "- At least one lowercase letter\n" .
                         "- At least one number\n" .
                         "- At least one special character\n\n" .
                         "If you did not create this account, please ignore this email.\n\n" .
                         "Regards\nPSPF CRM Team";
            
            $mail->AltBody = "Dear $username,\n\n" .
                            "Thank you for registering with PSPF CRM.\n\n" .
                            "Please verify your email by visiting: $verification_link\n\n" .
                            "Registration Details:\n" .
                            "Department: $department\n" .
                            "Unit: $division_name\n\n" .
                            "Regards\nPSPF CRM Team";
            
            if ($mail->send()) {
                $emailSent = true;
            } else {
                error_log("Failed to send verification email to: $email");
            }
        } else {
            error_log("Mailer function not available");
        }
    } catch (Exception $e) {
        error_log("Mail error for $email: " . $e->getMessage());
    }
    
    // Return success response
    $responseMessage = $emailSent ? 
        "Registration successful! Please check your email to verify your account." :
        "Registration successful! You can now login. (Verification email could not be sent)";
    
    echo json_encode([
        "success" => true,
        "message" => $responseMessage,
        "user_id" => $user_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    $conn->rollback();
    
    $statusCode = $e->getCode();
    if ($statusCode === 400 || $statusCode === 409) {
        http_response_code($statusCode);
    } else {
        http_response_code(500);
        error_log("Registration error: " . $e->getMessage());
    }
    
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    
} finally {
    // Clean up
    if (isset($conn)) $conn->close();
}
?>