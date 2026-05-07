<?php
// session_config.php
ini_set('session.cookie_lifetime', 86400); // 24 hours
ini_set('session.gc_maxlifetime', 86400);  // 24 hours
ini_set('session.cookie_secure', 0);       // Set to 1 if using HTTPS
ini_set('session.cookie_httponly', 1);     // Prevent JavaScript access
ini_set('session.use_strict_mode', 1);     // Enhanced security

// Set custom session path if needed
// ini_set('session.save_path', '/custom/session/path');

// Start session with proper configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>