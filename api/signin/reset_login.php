<?php
session_start();

// Remove role selection sessions
unset($_SESSION['pending_roles']);
unset($_SESSION['active_role']);
unset($_SESSION['user']);

// Optional: destroy entire session
session_destroy();

// Redirect to login page
header("Location: index.php");
exit;