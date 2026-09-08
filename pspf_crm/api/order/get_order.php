<?php
require_once './db.php';
require_once './includes/auth_helpers.php';
header('Content-Type: application/json');

if (!isLoggedIn() || empty($_GET['id'])) {
    echo json_encode(null);
    exit;
}

$orderId = (int)$_GET['id'];
$userId  = $_SESSION['user']['id'];

$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode($order);
