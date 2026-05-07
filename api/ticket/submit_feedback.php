<?php
require_once '../db.php';
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    // -----------------------------
    // 1️⃣ Get JSON input
    // -----------------------------
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data || !isset($data['token'], $data['rating'])) {
        throw new Exception("Invalid request data.");
    }

    $token  = trim($data['token']);
    $rating = intval($data['rating']);
    $comment = trim($data['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        throw new Exception("Rating must be between 1 and 5.");
    }

    $conn->begin_transaction();

    // -----------------------------
    // 2️⃣ Validate token
    // -----------------------------
    $stmt = $conn->prepare("
        SELECT ft.ticket_id, t.title
        FROM feedback_tokens ft
        JOIN tickets t ON t.id = ft.ticket_id
        WHERE ft.token = ? AND ft.used = 0 AND ft.expires_at > NOW()
        LIMIT 1

    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $tokenData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tokenData) {
        throw new Exception("Invalid or expired feedback link.");
    }

    $ticket_id = intval($tokenData['ticket_id']);

    // -----------------------------
    // 3️⃣ Get ticket info (created_by)
    // -----------------------------
    $ticketStmt = $conn->prepare("
    SELECT u.id AS user_id
    FROM tickets t
    JOIN users u ON t.created_by = u.username  -- or t.created_by = u.email
    WHERE t.id = ?
");
$ticketStmt->bind_param("i", $ticket_id);
$ticketStmt->execute();
$ticket = $ticketStmt->get_result()->fetch_assoc();
$ticketStmt->close();

$user_id = intval($ticket['user_id']);

    // feedback author

    if (empty($ticket['user_id'])) {
    throw new Exception("Ticket has no valid owner.");
}

$user_id = intval($ticket['user_id']);

// Verify user exists
$checkUser = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$checkUser->bind_param("i", $user_id);
$checkUser->execute();
$userExists = $checkUser->get_result()->fetch_assoc();
$checkUser->close();

if (!$userExists) {
    throw new Exception("Referenced user does not exist in users table.");
}


    // -----------------------------
    // 4️⃣ Get closing agent
    // -----------------------------
    $agentStmt = $conn->prepare("
        SELECT closed_by
        FROM ticket_closures
        WHERE ticket_id = ?
        ORDER BY closure_id DESC
        LIMIT 1
    ");
    $agentStmt->bind_param("i", $ticket_id);
    $agentStmt->execute();
    $agentRow = $agentStmt->get_result()->fetch_assoc();
    $agentStmt->close();

    if (!$agentRow || empty($agentRow['closed_by'])) {
        throw new Exception("Closing agent not found.");
    }

    $closed_by = intval($agentRow['closed_by']);

    // -----------------------------
    // 5️⃣ Insert feedback
    // -----------------------------
    $insert = $conn->prepare("
        INSERT INTO ticket_feedback
        (ticket_id, user_id, rating, comment, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $insert->bind_param("iiis", $ticket_id, $user_id, $rating, $comment);
    $insert->execute();
    $insert->close();

    // -----------------------------
    // 6️⃣ Insert into ticket_resolved
    // -----------------------------
    $resolved = $conn->prepare("
        INSERT INTO ticket_resolved
        (ticket_id, resolved_by, closed_by, rating, comment, resolved_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $resolved->bind_param("iiiis", $ticket_id, $user_id, $closed_by, $rating, $comment);
    $resolved->execute();
    $resolved->close();

    // -----------------------------
    // 7️⃣ Mark token as used
    // -----------------------------
    $updateToken = $conn->prepare("
        UPDATE feedback_tokens
        SET used = 1
        WHERE token = ?
    ");
    $updateToken->bind_param("s", $token);
    $updateToken->execute();
    $updateToken->close();

    // -----------------------------
    // 8️⃣ Update ticket status
    // -----------------------------
    $updateTicket = $conn->prepare("
        UPDATE tickets
        SET status = 'Resolved'
        WHERE id = ?
    ");
    $updateTicket->bind_param("i", $ticket_id);
    $updateTicket->execute();
    $updateTicket->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your feedback!'
    ]);

} catch (Exception $e) {
    if ($conn->errno === 0) { $conn->rollback(); }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
