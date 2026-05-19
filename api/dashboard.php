<?php
// dashboard.php (central redirector for all users)

session_start();

// Correct path: dashboard.php is inside /api/, helpers are in /includes/
require_once __DIR__ . '/includes/auth_helpers.php';
require_once __DIR__ . '/includes/role_switcher.php';

// User must be logged in
if (!isLoggedIn()) {
    header('Location: ../signin/index.php');
    exit;
}

$role = getActiveRole();

// Redirect based on role
switch ($role) {
    case 'user':
        header("Location: /pspf_crm/api/user_dashboard.php");
        exit;

    case 'agent':
        header("Location: /pspf_crm/api/agent/agent_dashboard.php");
        exit;

    case 'admin':
        header("Location: /pspf_crm/api/admin/admin_dashboard.php");
        exit;

    case 'superadmin':
        header("Location: /pspf_crm/api/admin/admin_dashboard.php");
        exit;

    case 'it_director':
        header("Location: /pspf_crm/api/director/dashboard.php");
        exit;

    default:
        // if role is somehow missing
        header("Location: /pspf_crm/api/signin/logout.php");
        exit;
}
?>
