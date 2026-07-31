<?php
// session_timeout.php
//
// Idle-based session timeout for the Helpdesk (CRM).
//
// This file is included from includes/auth_helpers.php, so every page that
// requires the auth layer is protected automatically. It logs a user out
// after a fixed period of inactivity and refreshes the activity timestamp on
// each request while the user stays active.

// Start the session only if it hasn't been started yet.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// How long a session may stay idle (in seconds) before it is terminated.
if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutes
}

// Support both session shapes used across the app.
$__isLoggedIn = isset($_SESSION['user']['id']) || isset($_SESSION['user_id']);

// Never redirect away from the sign-in pages themselves — that would create a
// redirect loop when an expired session lands back on the login screen.
$__onSigninPage = strpos($_SERVER['PHP_SELF'] ?? '', '/signin/') !== false;

if ($__isLoggedIn) {
    if (isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {

        // Idle too long — tear the session down completely.
        $_SESSION = [];
        session_unset();
        session_destroy();

        if (!$__onSigninPage && !headers_sent()) {
            header('Location: /pspf_crm/api/signin/index.php?timeout=1');
            exit();
        }
        // On the sign-in page we simply fall through with a cleared session so
        // the login form renders normally.
    } else {
        // Still active — record this request as the latest activity.
        $_SESSION['last_activity'] = time();
    }
}
?>
