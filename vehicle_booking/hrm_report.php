<?php
// hrm_report.php
//
// HRM Report: transport statistics for Human Resources (requests per
// department, approval rate and turnaround, how decisions were made,
// vehicle usage and distance) with CSV export and Print / PDF.

require_once __DIR__ . '/trip_helpers.php';
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require_once __DIR__ . '/admin_stats.php';

// HRM, plus admins previewing the HRM report.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['hrm', 'admin'], true)) {
    header("Location: ../vehicle_booking/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'HRM Report'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="bg-light">
<?php include '../vehicle_booking/navbar.php'; ?>
<div class="container mt-4 mb-5">
    <div class="settings-header no-print">
        <h1 class="settings-title">HRM Report</h1>
        <div class="settings-actions">
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <?php
    $statsView  = vbStatsViewFor($_SESSION['role'], 'hrm');
    $statsTitle = 'Transport Statistics';
    require __DIR__ . '/partials/stats_section.php';
    ?>
</div>
<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
