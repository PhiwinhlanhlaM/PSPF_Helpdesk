<?php
//get_latest_escalated_tickets.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$sql = "
    SELECT t.*, e.reason 
    FROM tickets t
    JOIN ticket_escalations e ON t.id = e.ticket_id
    WHERE t.assigned_to = ?
    ORDER BY e.escalated_at DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $_SESSION['user']['username']);
$stmt->execute();
$result = $stmt->get_result();

if ($ticket = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'ticket' => $ticket]);
} else {
    echo json_encode(['success' => false, 'message' => 'No escalated tickets found']);
}

$stmt->close();
$conn->close();
