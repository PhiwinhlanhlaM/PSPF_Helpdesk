<?php
//get_tickets_by_id.php
session_start();
header('Content-Type: application/json');
require_once '../db.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}



$id = $_GET['id'] ?? 0;

$sql = "
  SELECT t.*, e.escalation_reason, u.department
  FROM tickets t
  LEFT JOIN ticket_escalations e ON t.id = e.ticket_id
  LEFT JOIN users u ON t.assigned_to = u.username
  WHERE t.id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'ticket' => $row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Ticket not found']);
}

$stmt->close();
$conn->close();
