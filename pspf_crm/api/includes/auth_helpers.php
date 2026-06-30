<?php
// includes/auth_helpers.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * IMPORTANT:
 * DO NOT open the DB connection here.
 * It must ALWAYS be included BEFORE this file.
 *
 * So db.php must be loaded before this file in every script:
 *   require_once '../db.php';
 *   require_once '../includes/auth_helpers.php';
 */

global $conn;

/**
 * Ensures that $conn is valid.
 * Does NOT reload db.php — only verifies.
 */
function ensureDBConnection() {
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
        error_log("Database connection not available in auth_helpers.php");
        return null; // ✔️ return NULL instead of array
    }

    return $conn;
}


/**
 * SESSION HELPERS
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user']['id']);
}

function getUserId(): ?int {
    return isLoggedIn() ? (int) $_SESSION['user']['id'] : null;
}

function getUserFromSession(): ?array {
    return isLoggedIn() ? $_SESSION['user'] : null;
}

/**
 * FETCH USER ROLES FROM DATABASE SAFELY
 */
function getUserRoles() {
    $conn = ensureDBConnection();

    if ($conn === null) {
        return ['user'];   // fallback safely
    }

    $user_id = $_SESSION['user']['id'] ?? null;
    if (!$user_id) return ['user'];

    $sql = "SELECT r.name
            FROM roles r
            INNER JOIN user_roles ur ON ur.role_id = r.id
            WHERE ur.user_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("getUserRoles prepare failed: " . $conn->error);
        return ['user'];
    }


    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $roles = [];
    while ($row = $result->fetch_assoc()) {
        $roles[] = strtolower($row['name']);
    }

    $stmt->close();

    return !empty($roles) ? $roles : ['user'];
}

/**
 * CSRF TOKEN
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * ROLE MANAGEMENT
 */
function getActiveRole() {
    if (!empty($_SESSION['active_role'])) {
        return $_SESSION['active_role'];
    }

    $roles = getUserRoles();
    $_SESSION['active_role'] = $roles[0] ?? 'user';

    return $_SESSION['active_role'];
}

function setActiveRole(string $role): bool {
    $role = strtolower($role);
    $roles = getUserRoles();

    if (in_array($role, $roles, true)) {
        $_SESSION['active_role'] = $role;
        return true;
    }

    return false;
}

function getActiveRoleString() {
    $role = $_SESSION['active_role'] ?? 'user';
    if (is_array($role)) {
        return $role[0] ?? 'user';
    }
    return $role;
}

/**
 * REQUIRED ROLE CHECKS
 */
function requireAnyRole(array $allowed): void {
    if (!isLoggedIn()) {
        header("Location: /pspf_helpdesk/signin/index.php");
        exit;
    }

    $active = getActiveRole();
    if (!in_array($active, $allowed, true)) {
        http_response_code(403);
        echo "<h3>403 - Forbidden</h3><p>Access denied.</p>";
        exit;
    }
}

function requireRole(string $role): void {
    requireAnyRole([$role]);
}

/**
 * ROLE DROPDOWN RENDERER
 * Returns an empty string when the user only has one role (nothing to switch to).
 */
function renderRoleSwitcher(): string {
    if (!isLoggedIn()) return "";

    $roles  = getUserRoles();
    $active = getActiveRole();

    // No toggle needed for single-role users
    if (count($roles) < 2) return "";

    $roleIcons = [
        'superadmin' => 'bi-person-gear',
        'admin'      => 'bi-shield-fill-check',
        'agent'      => 'bi-headset',
        'user'       => 'bi-person-fill',
    ];

    $csrf = $_SESSION['csrf_token'] ?? '';

    $html  = '<li class="nav-item dropdown">';
    $html .= '<a class="nav-link dropdown-toggle d-flex align-items-center gap-1" href="#"'
           . ' id="roleSwitcherDropdown" data-bs-toggle="dropdown" aria-expanded="false"'
           . ' title="Switch role">';
    $html .= '<i class="bi bi-arrow-left-right"></i>';
    $html .= '<span class="d-none d-lg-inline ms-1">' . htmlspecialchars(ucfirst($active)) . '</span>';
    $html .= '</a>';
    $html .= '<ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="roleSwitcherDropdown">';
    $html .= '<li><h6 class="dropdown-header">Switch Role</h6></li>';

    foreach ($roles as $role) {
        $icon    = $roleIcons[$role] ?? 'bi-person-fill';
        $isActive = $role === $active;

        $html .= '<li>';
        $html .= '<form method="POST" action="/pspf_crm/api/switch_role.php" class="d-inline w-100">';
        $html .= '<input type="hidden" name="role" value="' . htmlspecialchars($role) . '">';
        $html .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">';
        $html .= '<button type="submit" class="dropdown-item d-flex align-items-center gap-2'
               . ($isActive ? ' active fw-semibold' : '') . '">';
        $html .= '<i class="bi ' . $icon . '"></i>';
        $html .= htmlspecialchars(ucfirst($role));
        if ($isActive) {
            $html .= ' <i class="bi bi-check2 ms-auto"></i>';
        }
        $html .= '</button></form>';
        $html .= '</li>';
    }

    $html .= '</ul></li>';

    return $html;
}

function hasRole($role) {
    return in_array(strtolower($role), getUserRoles(), true);
}
function hasRoleInDepartment(string $role, string $department): bool {
    return hasRole($role)
        && isset($_SESSION['user']['department'])
        && $_SESSION['user']['department'] === $department;
}
function hasRoleInDivision(string $role, string $division_name): bool {
    return hasRole($role)
        && isset($_SESSION['user']['division_name'])
        && $_SESSION['user']['division_name'] === $division_name;
}
function agentDivisionOnly() {
    if ($_SESSION['role'] === 'agent') {
        return $_SESSION['division_id'];
    }
    return null;
}

function getRoleHomePage($role = null) {
    if (!$role) {
        $role = $_SESSION['active_role'] ?? 'user';
    }

    $map = [
        'user'       => '/pspf_crm/api/user_dashboard.php',
        'agent'      => '/pspf_crm/api/agent/agent_dashboard.php',
        'admin'      => '/pspf_crm/api/admin/admin_dashboard.php',
        'superadmin' => '/pspf_crm/api/admin/admin_dashboard.php'
    ];

    return $map[$role] ?? '/pspf_crm/api/user_dashboard.php';
}

function enforceActiveUser($conn) {
    if (!isset($_SESSION['user']['id'])) return;

    $userId = (int)$_SESSION['user']['id'];

    $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if (!$result || (int)$result['is_active'] === 0) {
        session_destroy();
        header("Location: /pspf_crm/api/signin/index.php?error=disabled");
        exit;
    }
}

function isPasswordExpired($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT Updated_at 
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if (!$result || !$result['Updated_at']) {
        return true; // force reset if missing
    }

    $lastUpdate = strtotime($result['Updated_at']);
    $expiryTime = strtotime("+90 days", $lastUpdate);

    return time() > $expiryTime;
}

function enforcePasswordPolicy($conn) {
    if (!isset($_SESSION['user']['id'])) return;

    if (isPasswordExpired($conn, $_SESSION['user']['id'])) {
        // Allow only profile/password page
        $currentPage = basename($_SERVER['PHP_SELF']);

        if ($currentPage !== 'profile.php') {
            header("Location: /pspf_crm/api/settings/profile.php?expired=1");
            exit;
        }
    }
}

function getPasswordDaysRemaining($conn, $userId) {
    $stmt = $conn->prepare("
        SELECT Updated_at 
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if (!$result || !$result['Updated_at']) return 0;

    $lastUpdate = strtotime($result['Updated_at']);
    $expiryTime = strtotime("+90 days", $lastUpdate);

    return floor(($expiryTime - time()) / (60 * 60 * 24));
}