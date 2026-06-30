<?php
// it_access/profile_name.php
// GET  -> { full_name: string|null }
// POST -> save the logged-in user's full name. Body: { full_name }, header X-CSRF-Token.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

$userId = (int)$_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['full_name' => $row['full_name'] ?? null]);
    exit;
}

// POST
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// CSRF validation
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token mismatch']);
    exit;
}

$fullName = trim($body['full_name'] ?? '');
// Collapse internal whitespace, strip control chars.
$fullName = preg_replace('/\s+/', ' ', $fullName);
if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
    http_response_code(422);
    echo json_encode(['error' => 'Full name must be between 2 and 150 characters']);
    exit;
}
$fullNameClean = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');

$stmt = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
$stmt->bind_param("si", $fullNameClean, $userId);
$stmt->execute();
$stmt->close();

// Keep the session in step so the rest of the page reflects it immediately.
$_SESSION['user']['full_name'] = $fullNameClean;

echo json_encode(['ok' => true, 'full_name' => $fullNameClean]);
