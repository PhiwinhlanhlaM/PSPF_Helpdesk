<?php
session_start();
header('Content-Type: application/json');

require_once '../db.php';        // mysqli or PDO connection
require_once '../mail_config.php'; // getMailer()

$_config   = parse_ini_file(__DIR__ . '/../includes/confi.ini', true);
$_base_url = rtrim($_config['application']['base_url'] ?? 'http://localhost/pspf_crm/', '/');

// Allow only AJAX
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request.'
    ]);
    exit;
}

$email = trim($_POST['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
    exit;
}

/* ---------------------------------------------------
   1. Check if user exists
--------------------------------------------------- */
$stmt = $conn->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // SECURITY: do not reveal if email exists
    echo json_encode([
        'success' => true,
        'message' => 'If the email exists, a password reset link has been sent.'
    ]);
    exit;
}

$user = $result->fetch_assoc();

/* ---------------------------------------------------
   2. Generate secure token
--------------------------------------------------- */
$token = bin2hex(random_bytes(32)); // 64 chars
$expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

/* ---------------------------------------------------
   3. Save token
--------------------------------------------------- */
$update = $conn->prepare("
    UPDATE users 
    SET reset_token = ?, reset_expires = ? 
    WHERE id = ?
");
$update->bind_param("ssi", $token, $expires, $user['id']);
$update->execute();

/* ---------------------------------------------------
   4. Send reset email
--------------------------------------------------- */
$resetLink = $_base_url . "/api/signin/resetpassword.php?token=$token";

try {
    $mail = getMailer();
    $mail->addAddress($email);
    $mail->Subject = 'PSPF CRM Password Reset Request';
    $mail->isHTML(true);
    $mail->Body = "
        <p>Hello,</p>
        <p>You requested a password reset.</p>
        <p>
            <a href='{$resetLink}' 
               style='padding:10px 15px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:4px;'>
                Reset Password
            </a>
        </p>
        <p>This link expires in 1 hour.</p>
        <p>If you did not request this, please ignore this email.</p>
    ";

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'If the email exists, a password reset link has been sent.'
    ]);
} catch (Exception $e) {
    error_log($e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Unable to send reset email. Please try again later.'
    ]);
}
