<?php
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
header('Content-Type: application/json');
require '../mail_config.php';  // <-- Include mail settings


$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    echo json_encode(['new_escalations' => []]);
    exit;
}

// Adjust logic as needed for your escalation system (e.g., "seen" flag)
$sql = "SELECT id, title, escalated_by, created_at, reason FROM escalations WHERE seen = 0 ORDER BY created_at DESC LIMIT 5";
$result = $conn->query($sql);

$escalations = [];
while ($row = $result->fetch_assoc()) {
    $escalations[] = $row;
}

// Optional: mark as seen (could be delayed or handled separately)
$conn->query("UPDATE escalations SET seen = 1 WHERE seen = 0");

echo json_encode(['new_escalations' => $escalations]);
$conn->close();
