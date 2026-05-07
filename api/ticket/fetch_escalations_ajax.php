<?php
//fetch_escalations_ajax.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$department = $_SESSION['user']['department'];
$username = $_SESSION['user']['username'];

$sql = "
    SELECT t.id AS ticket_id, t.created_by AS user, t.escalation_reason AS reason
    FROM tickets t
    WHERE t.status = 'escalate'
      AND t.department = ?
      AND NOT EXISTS (
          SELECT 1 FROM escalation_reads er
          WHERE er.ticket_id = t.id AND er.username = ?
      )
    ORDER BY t.query_date DESC
    LIMIT 5
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $department, $username);
$stmt->execute();
$result = $stmt->get_result();

$escalations = [];
while ($row = $result->fetch_assoc()) {
    $escalations[] = $row;
}

// Mark as read
if (!empty($escalations)) {
    $insertStmt = $conn->prepare("INSERT IGNORE INTO escalation_reads (ticket_id, username) VALUES (?, ?)");
    foreach ($escalations as $e) {
        $insertStmt->bind_param("is", $e['ticket_id'], $username);
        $insertStmt->execute();
    }
    $insertStmt->close();
}

echo json_encode(['success' => true, 'escalations' => $escalations]);

$stmt->close();
$conn->close();
?>
