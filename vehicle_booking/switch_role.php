<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require_once __DIR__ . '/superuser.php';

// Only IT superusers and roles with extra views (e.g. drivers) may switch dashboards.
$views = vbSwitchableViews();
if (!isset($_SESSION['user_id']) || !$views) {
    header("Location: login.php");
    exit();
}

$view = $_POST['view'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($views[$view])) {
    header("Location: " . (VB_SUPERUSER_VIEWS[$_SESSION['role']][0] ?? 'user_dashboard.php'));
    exit();
}

$_SESSION['role'] = $view;
header("Location: " . $views[$view][0]);
exit();
