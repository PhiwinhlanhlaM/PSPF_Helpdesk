<?php
//get_all_escalated_tickets.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$sql = "
    SELECT 
        t.id, t.title, t.status, t.priority, t.assigned_to,
        e.escalation_reason, e.escalated_at,
        u.department
    FROM tickets t
    JOIN ticket_escalations e ON t.id = e.ticket_id
    LEFT JOIN users u ON t.assigned_to = u.username
    WHERE t.status = 'Escalated'
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'SQL prepare failed: ' . $conn->error]);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();

$tickets = [];
while ($row = $result->fetch_assoc()) {
    $tickets[] = $row;
}

echo json_encode(['success' => true, 'tickets' => $tickets]);

$stmt->close();
$conn->close();
?>
