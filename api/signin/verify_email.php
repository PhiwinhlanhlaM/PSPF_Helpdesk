<?php
require '../db.php';
require_once '../includes/auth_helpers.php';

$_base_url = getBaseUrl();

if (!isset($_GET['token']) || empty($_GET['token'])) {
    die("Invalid verification link");
}

$token = $_GET['token'];

$stmt = $conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE verification_token = ? AND email_verified = 0");
$stmt->bind_param("s", $token);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo "<h2>Email Verified Successfully!</h2>";
    echo "<p>Your email has been verified. You can now <a href='" . htmlspecialchars($_base_url) . "/api/signin/index.php'>login</a> to your account.</p>";
} else {
    echo "<h2>Invalid or Expired Link</h2>";
    echo "<p>The verification link is invalid or your email has already been verified.</p>";
}

$stmt->close();
$conn->close();
?>