<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require_once __DIR__ . '/superuser.php';

// Only IT superusers may switch dashboards.
if (!isset($_SESSION['user_id']) || !vbIsSuperuser()) {
    header("Location: login.php");
    exit();
}

$view = $_POST['view'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset(VB_SUPERUSER_VIEWS[$view])) {
    header("Location: " . (VB_SUPERUSER_VIEWS[$_SESSION['role']][0] ?? 'user_dashboard.php'));
    exit();
}

$_SESSION['role'] = $view;
header("Location: " . VB_SUPERUSER_VIEWS[$view][0]);
exit();
