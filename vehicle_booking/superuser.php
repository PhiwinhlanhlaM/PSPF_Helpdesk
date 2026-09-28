<?php
// superuser.php
//
// IT superusers can switch between every role's dashboard with full rights,
// while still raising requests as themselves.
//
// How it works: at login a superuser gets $_SESSION['real_role'] = 'superuser'
// and $_SESSION['role'] is set to the view they are currently using. Every page
// already guards on $_SESSION['role'], so switching the view is enough for all
// existing dashboards and actions to work unchanged. user_id never changes, so
// anything they do is logged under their own name.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Views a superuser can switch between: role => [dashboard, label]
const VB_SUPERUSER_VIEWS = [
    'user'       => ['user_dashboard.php',       'Staff'],
    'driver'     => ['driver_dashboard.php',     'Driver'],
    'supervisor' => ['supervisor_dashboard.php', 'Supervisor'],
    'hrm'        => ['hrm_dashboard.php',        'HRM'],
    'admin'      => ['admin_dashboard.php',      'Administrator'],
    'viewer'     => ['view.php',                 'Viewer'],
];

// The view a superuser lands on after signing in.
const VB_SUPERUSER_DEFAULT_VIEW = 'user';

function vbIsSuperuser(): bool
{
    return ($_SESSION['real_role'] ?? '') === 'superuser';
}

/**
 * Call right after a login sets $_SESSION['role'] from the database. For a
 * superuser it records the real role and starts them in the default view.
 */
function vbStartSuperuserSession(): void
{
    if (($_SESSION['role'] ?? '') === 'superuser') {
        $_SESSION['real_role'] = 'superuser';
        $_SESSION['role']      = VB_SUPERUSER_DEFAULT_VIEW;
    } else {
        unset($_SESSION['real_role']);
    }
}
