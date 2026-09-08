<?php
//get_new_escalations.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'agent') {
    echo json_encode(['new_escalations' => []]);
    exit;
}

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
