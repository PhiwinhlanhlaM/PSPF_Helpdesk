<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_helpers.php';

if (!isLoggedIn()) {
    header('Location: /pspf_crm/api/signin/index.php');
    exit;
}

// Accept POST (form submit) or GET (direct link fallback)
$requested = isset($_POST['role']) ? $_POST['role'] : ($_GET['role'] ?? '');
$requested = strtolower(trim($requested));

if ($requested === '') {
    header('Location: ' . getRoleHomePage());
    exit;
}

// CSRF check — only enforce on POST to keep GET links working during dev
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo "Invalid CSRF token.";
        exit;
    }
}

if (!setActiveRole($requested)) {
    // Role not assigned to this user — silently redirect to current home
    header('Location: ' . getRoleHomePage());
    exit;
}

// Redirect to the new role's home page
header('Location: ' . getRoleHomePage($requested));
exit;
