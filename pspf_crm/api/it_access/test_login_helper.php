<?php
/**
 * Test-only session bootstrap for the IT Access integration test runner.
 *
 * SECURITY: Only works when the calling IP is 127.0.0.1 AND a shared secret
 * matches. Never deploy this on a production server.
 *
 * POST body (JSON):
 *   { "user_id": 123, "active_role": "it_officer", "secret": "ita_test_secret_2026" }
 *
 * Returns JSON:
 *   { "ok": true, "csrf_token": "...", "user_id": 123 }
 */

// Lock down to localhost only
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — localhost only']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Secret check
if (($body['secret'] ?? '') !== 'ita_test_secret_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid secret']);
    exit;
}

$userId     = (int)($body['user_id']     ?? 0);
$activeRole = trim($body['active_role']  ?? '');

if (!$userId || !$activeRole) {
    http_response_code(422);
    echo json_encode(['error' => 'user_id and active_role required']);
    exit;
}

// Bootstrap session and DB
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../db.php';

// Fetch user from DB
$stmt = $conn->prepare("SELECT id, username, email, department FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found or inactive']);
    exit;
}

// Validate the requested active_role exists for this user
$roleStmt = $conn->prepare("
    SELECT r.name FROM roles r
    JOIN user_roles ur ON ur.role_id = r.id
    WHERE ur.user_id = ?
");
$roleStmt->bind_param("i", $userId);
$roleStmt->execute();
$dbRoles = array_column($roleStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
$roleStmt->close();

if (!in_array($activeRole, $dbRoles, true)) {
    http_response_code(403);
    echo json_encode(['error' => "User does not have role '$activeRole'. Has: " . implode(', ', $dbRoles)]);
    exit;
}

// Populate session exactly as signin/index.php does
$_SESSION['user'] = [
    'id'         => (int)$user['id'],
    'username'   => $user['username'],
    'email'      => $user['email'],
    'department' => $user['department'],
];
$_SESSION['active_role'] = $activeRole;

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Content-Type: application/json');
echo json_encode([
    'ok'         => true,
    'csrf_token' => $_SESSION['csrf_token'],
    'user_id'    => (int)$user['id'],
    'username'   => $user['username'],
    'role'       => $activeRole,
]);
