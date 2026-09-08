<?php
require_once '../db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$ticketNo = trim($data['ticket_no'] ?? '');

if ($ticketNo === '') {
    echo json_encode(['success'=>false,'message'=>'Ticket number required']);
    exit;
}

/* 1️⃣ Fetch ticket */
$stmt = $conn->prepare("
    SELECT id, title, description, status, query_date
    FROM tickets
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("s", $ticketNo);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

if (!$ticket) {
    echo json_encode(['success'=>false,'message'=>'Ticket not found']);
    exit;
}

/* 2️⃣ Fetch status timeline */
$logStmt = $conn->prepare("
    SELECT old_status, new_status, changed_by, changed_at
    FROM ticket_status_logs
    WHERE ticket_id = ?
    ORDER BY changed_at ASC
");
$logStmt->bind_param("i", $ticket['id']);
$logStmt->execute();
$logs = $logStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* 3️⃣ Check feedback */
$fbStmt = $conn->prepare("
    SELECT id FROM ticket_feedback WHERE ticket_id = ?
");
$fbStmt->bind_param("i", $ticket['id']);
$fbStmt->execute();
$feedbackExists = $fbStmt->get_result()->num_rows > 0;

echo json_encode([
    'success' => true,
    'ticket' => $ticket,
    'timeline' => $logs,
    'feedback_required' => ($ticket['status'] === 'Closed' && !$feedbackExists)
]);
$stmt->close();
$logStmt->close();
$fbStmt->close();
$conn->close(); 
?>