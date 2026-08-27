<?php
// session_timeout.php
//
// Idle-based session timeout for the Vehicle Booking System.
//
// Include this at the top of every authenticated page, right after
// session_start(). It logs a user out after a fixed period of inactivity and
// refreshes the activity timestamp on each request while the user stays active.

// Start the session only if it hasn't been started yet.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// How long a session may stay idle (in seconds) before it is terminated.
if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutes
}

// Only enforce the timeout for authenticated sessions.
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {

        // Idle too long — tear the session down and send the user to login.
        $_SESSION = [];
        session_unset();
        session_destroy();

        if (!headers_sent()) {
            header('Location: login.php?timeout=1');
        }
        exit();
    }

    // Still active — record this request as the latest activity.
    $_SESSION['last_activity'] = time();
}
?>
