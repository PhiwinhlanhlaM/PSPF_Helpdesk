<?php
session_start();

require_once '../includes/log_activity.php';
logActivity("Logout", "User logged out");

// Unset all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Optionally, delete the session cookie (for extra security)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
// logout.php
header("Location: index.php?logout=success");
exit();

?>
