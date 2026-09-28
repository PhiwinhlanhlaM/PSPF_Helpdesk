<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/superuser.php';
$role = $_SESSION['role'] ?? '';
$isSuperuser = vbIsSuperuser();

// Determine dashboard based on role
$dashboardMap = [
    'user' => 'user_dashboard.php',
    'driver' => 'driver_dashboard.php',
    'supervisor' => 'supervisor_dashboard.php',
    'hrm' => 'hrm_dashboard.php',
    'admin' => 'admin_dashboard.php',
    'viewer' => 'view.php'
];
$dashboard = $dashboardMap[$role] ?? 'user_dashboard.php';

$roleLabels = [
    'user' => 'Staff',
    'driver' => 'Driver',
    'supervisor' => 'Supervisor',
    'hrm' => 'HRM',
    'admin' => 'Administrator',
    'viewer' => 'Viewer'
];

// Links shown in the bar: [href, icon, label, roles allowed (null = everyone)]
$navLinks = [
    [$dashboard,           'fa-house',        'Dashboard',   null],
    // Superusers can always raise their own requests, whichever view they're in.
    ['request_form.php',   'fa-circle-plus',  'New Request', $isSuperuser ? null : ['user', 'driver', 'supervisor', 'hrm', 'admin']],
    ['manage_users.php',   'fa-users',        'Users',       ['admin']],
    ['manage_vehicles.php','fa-car',          'Vehicles',    ['admin']],
    ['report_page.php',    'fa-chart-line',   'Report',      ['driver', 'admin']],
    // Relative link so it follows whichever host/folder the app is served from.
    ['../api/signin/index.php', 'fa-headset', 'Helpdesk',   null],
];

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$userName    = $_SESSION['name'] ?? '';
?>
<nav class="navbar navbar-expand-lg navbar-dark main-navbar">
    <div class="container-fluid">

        <a class="navbar-brand d-flex align-items-center" href="<?= htmlspecialchars($dashboard) ?>">
            <img src="PSPFlogo.png" alt="PSPF" class="navbar-logo">
            <span>Transport Booking</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar"
                aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="mainNavbar">
            <ul class="navbar-nav align-items-lg-center gap-lg-1">
                <?php foreach ($navLinks as [$href, $icon, $label, $roles]): ?>
                    <?php if ($roles !== null && !in_array($role, $roles, true)) continue; ?>
                    <?php $isActive = basename($href) === $currentPage; ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($href) ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <i class="fa <?= $icon ?> nav-icon"></i><?= $label ?>
                        </a>
                    </li>
                <?php endforeach; ?>

                <?php if ($isSuperuser): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa fa-repeat nav-icon"></i>Switch View
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">IT Superuser: view as</h6></li>
                            <?php foreach (VB_SUPERUSER_VIEWS as $viewRole => [$viewHref, $viewLabel]): ?>
                                <li>
                                    <form method="post" action="switch_role.php">
                                        <input type="hidden" name="view" value="<?= htmlspecialchars($viewRole) ?>">
                                        <button type="submit" class="dropdown-item<?= $viewRole === $role ? ' active' : '' ?>">
                                            <?= htmlspecialchars($viewLabel) ?>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if ($userName !== ''): ?>
                    <li class="nav-item nav-user d-flex align-items-center">
                        <span class="nav-user-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr($userName, 0, 1))) ?></span>
                        <span class="nav-user-text">
                            <span class="nav-user-name"><?= htmlspecialchars($userName) ?></span>
                            <span class="nav-user-role"><?= htmlspecialchars(($isSuperuser ? 'IT Superuser · ' : '') . ($roleLabels[$role] ?? ucfirst($role))) ?></span>
                        </span>
                    </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a class="nav-link nav-logout" href="logout.php"><i class="fa fa-arrow-right-from-bracket nav-icon"></i>Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
