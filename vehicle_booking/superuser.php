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

// Other roles that may switch between a limited set of views. Drivers also
// raise their own trips, so they can flip to the Staff view to close them.
// The first view listed is the one they land on after signing in.
const VB_ROLE_VIEWS = [
    'driver' => ['driver', 'user'],
];

function vbIsSuperuser(): bool
{
    return ($_SESSION['real_role'] ?? '') === 'superuser';
}

/**
 * The views the signed-in user may switch between, as role => [dashboard, label].
 * Empty when their role has no other views.
 */
function vbSwitchableViews(): array
{
    // Sessions started before driver switching existed have no real_role yet.
    if (!isset($_SESSION['real_role']) && isset(VB_ROLE_VIEWS[$_SESSION['role'] ?? ''])) {
        $_SESSION['real_role'] = $_SESSION['role'];
    }

    $realRole = $_SESSION['real_role'] ?? '';
    if ($realRole === 'superuser') {
        return VB_SUPERUSER_VIEWS;
    }
    if (!isset(VB_ROLE_VIEWS[$realRole])) {
        return [];
    }
    return array_intersect_key(VB_SUPERUSER_VIEWS, array_flip(VB_ROLE_VIEWS[$realRole]));
}

/**
 * Call right after a login sets $_SESSION['role'] from the database. For a
 * superuser, or a role that can switch views, it records the real role and
 * starts them in their default view.
 */
function vbStartSuperuserSession(): void
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'superuser') {
        $_SESSION['real_role'] = 'superuser';
        $_SESSION['role']      = VB_SUPERUSER_DEFAULT_VIEW;
    } elseif (isset(VB_ROLE_VIEWS[$role])) {
        $_SESSION['real_role'] = $role;
        $_SESSION['role']      = VB_ROLE_VIEWS[$role][0];
    } else {
        unset($_SESSION['real_role']);
    }
}
