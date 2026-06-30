<?php
// session_timeout.php

// Start session only if it hasn't been started yet
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set timeout duration (in seconds)
$timeoutDuration = 300; // 5 minutes

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: ./signin/index.php");
    exit();
}

// Check last activity
if (isset($_SESSION['last_activity'])) {
    $duration = time() - $_SESSION['last_activity'];
    if ($duration > $timeoutDuration) {
        // Session expired
        session_unset();
        session_destroy();
        header("Location: ./signin/index.php?message=Session expired. Please login again.");
        exit();
    }
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();
?>
