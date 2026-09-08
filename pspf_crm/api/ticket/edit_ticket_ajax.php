<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

// ✅ Ensure user is authenticated
if (empty($_SESSION['user']['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $_SESSION['user']['username'];
$ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);

if (!$ticketId) {
    echo json_encode(['success' => false, 'message' => 'Invalid ticket ID']);
    exit;
}

// ✅ Fetch ticket safely
$stmt = $conn->prepare("
    SELECT status, created_by 
    FROM tickets 
    WHERE id = ?
");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$result = $stmt->get_result();
$ticket = $result->fetch_assoc();
$stmt->close();

// ❌ Validation rules
if (!$ticket) {
    echo json_encode(['success' => false, 'message' => 'Ticket not found']);
    exit;
}

if ($ticket['created_by'] !== $user) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($ticket['status'] !== 'Open') {
    echo json_encode(['success' => false, 'message' => 'Only open tickets can be edited']);
    exit;
}

// ✅ Sanitize inputs
$title        = trim($_POST['title'] ?? '');
$priority     = trim($_POST['priority'] ?? '');
$query_type   = trim($_POST['query_type'] ?? '');
$region       = trim($_POST['region'] ?? '');
$phone_number = trim($_POST['phone_number'] ?? '');
$description  = trim($_POST['description'] ?? '');

// ❌ Required fields check
if ($title === '' || $description === '') {
    echo json_encode(['success' => false, 'message' => 'Title and description are required']);
    exit;
}

// ✅ Update ticket
$update = $conn->prepare("
    UPDATE tickets SET
        title = ?,
        priority = ?,
        query_type = ?,
        region = ?,
        phone_number = ?,
        description = ?,
        updated_at = NOW()
    WHERE id = ?
");

$update->bind_param(
    "ssssssi",
    $title,
    $priority,
    $query_type,
    $region,
    $phone_number,
    $description,
    $ticketId
);

$update->execute();
$update->close();

// ✅ Log change
$log = $conn->prepare("
    INSERT INTO ticket_logs (ticket_id, action, performed_by)
    VALUES (?, ?, ?)
");

$action = 'User edited ticket';
$log->bind_param("iss", $ticketId, $action, $user);
$log->execute();
$log->close();

$conn->close();

// ✅ Final response
echo json_encode([
    'success' => true,
    'message' => 'Ticket updated successfully'
]);
