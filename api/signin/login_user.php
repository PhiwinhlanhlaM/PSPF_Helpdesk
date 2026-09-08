<?php
session_start();
// login_user.php - UNIFIED VERSION

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/auth_functions.php';

$data = json_decode(file_get_contents('php://input'), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (!$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields']);
    exit;
}

try {
    $user = authenticateUser($username, $password, false); // false = isUsername
    
    if ($user) {
        $sessionUser = createUserSession($user);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => '../dashboard.php',
            'user' => [
                'username' => $user['username'],
                'department' => $user['department'] ?? ''
            ]
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>