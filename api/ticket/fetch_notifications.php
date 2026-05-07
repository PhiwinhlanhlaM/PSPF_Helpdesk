<?php
session_start();
if (!isset($_SESSION['user'])) {
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit();
}

$user = $_SESSION['user']['username'];
$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) die("DB Connection failed: " . $conn->connect_error);

$stmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE recipient = ? AND is_read = 0 ORDER BY created_at DESC");
$stmt->bind_param("s", $user);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => $row['id'],
        'message' => $row['message'],
        'created_at' => $row['created_at']
    ];
}

echo json_encode(['count' => count($notifications), 'notifications' => $notifications]);
$stmt->close();
$conn->close();
