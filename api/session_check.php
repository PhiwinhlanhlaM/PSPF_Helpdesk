<?php
// session_check.php
header('Content-Type: application/json');

require_once 'session_config.php';

$response = [
    'session_id' => session_id(),
    'session_status' => session_status(),
    'session_exists' => !empty($_SESSION),
    'user_logged_in' => false,
    'user_data' => []
];

if (isset($_SESSION['user_id'])) {
    $response['user_logged_in'] = true;
    $response['user_data'] = [
        'user_id' => $_SESSION['user_id'],
        'user_name' => $_SESSION['user_name'] ?? 'Unknown',
        'user_role' => $_SESSION['user_role'] ?? 'Unknown'
    ];
} elseif (isset($_SESSION['user']['id'])) {
    $response['user_logged_in'] = true;
    $response['user_data'] = $_SESSION['user'];
}

echo json_encode($response);
?>