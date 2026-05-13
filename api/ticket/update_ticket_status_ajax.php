<?php
// =====================================================
// UPDATE TICKET STATUS (AJAX)
// =====================================================

session_start();
header('Content-Type: application/json');

require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

$_config   = parse_ini_file(__DIR__ . '/../includes/confi.ini', true);
$_base_url = rtrim($_config['application']['base_url'] ?? 'http://localhost/pspf_crm/', '/');

// =====================================================
// BASIC VALIDATION
// =====================================================

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_POST['ticket_id'], $_POST['status']) || !is_numeric($_POST['ticket_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

$ticketId  = (int) $_POST['ticket_id'];
$newStatusRaw = trim($_POST['status']);
$details   = trim($_POST['reason'] ?? '');
$userId = $_SESSION['user']['id'];
$username = $_SESSION['user']['username']; // keep for logs/email


// =====================================================
// NORMALIZE STATUS
// =====================================================

$statusMap = [
    'open'        => 'Open',
    'in progress' => 'In Progress',
    'closed'      => 'Closed',
    'escalate'    => 'Escalate'
];

$newLower  = strtolower($newStatusRaw);
$newStatus = $statusMap[$newLower] ?? null;

if (!$newStatus) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit;
}

// =====================================================
// FETCH CURRENT STATUS
// =====================================================

$stmt = $conn->prepare("SELECT status FROM tickets WHERE id = ?");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
    exit;
}

$currentStatus = $result->fetch_assoc()['status'];
$stmt->close();

$currentLower = strtolower($currentStatus);

// =====================================================
// WORKFLOW RULES
// =====================================================

if ($currentLower === 'open' && $newLower === 'closed') {
    echo json_encode(['success' => false, 'message' => 'Move ticket to In Progress before closing.']);
    exit;
}

if ($currentLower === 'open' && $newLower === 'escalate') {
    echo json_encode(['success' => false, 'message' => 'Move ticket to In Progress before escalating.']);
    exit;
}

if ($currentLower === 'closed' && $newLower === 'escalate') {
    echo json_encode(['success' => false, 'message' => 'Closed tickets cannot be escalated.']);
    exit;
}

// =====================================================
// REQUIRE REASONS
// =====================================================

if (in_array($newLower, ['in progress', 'closed', 'escalate']) && empty($details)) {
    echo json_encode(['success' => false, 'message' => 'A reason/description is required.']);
    exit;
}

// =====================================================
// CONVERT CLOSED → PENDING FEEDBACK
// =====================================================

$feedbackLink = null;

if ($newLower === 'closed') {
    $newStatus = 'Pending Feedback';
    $newLower  = 'pending feedback';
}

// =====================================================
// START TRANSACTION
// =====================================================

$conn->begin_transaction();

try {

    // =====================================================
    // UPDATE MAIN TICKET STATUS
    // =====================================================
    $updateStmt = $conn->prepare("
        UPDATE tickets 
        SET status = ?, updated_at = NOW(), last_updated_by = ? 
        WHERE id = ?
    ");
    $updateStmt->bind_param("ssi", $newStatus, $username, $ticketId);

    if (!$updateStmt->execute()) {
        throw new Exception($updateStmt->error);
    }
    $updateStmt->close();

    // =====================================================
    // HANDLE PENDING FEEDBACK (CLOSURE LOG + TOKEN)
    // =====================================================
    if ($newLower === 'pending feedback') {

        // Insert closure record
        $closureStmt = $conn->prepare("
            INSERT INTO ticket_closures (ticket_id, closed_by, closure_reason)
            VALUES (?, ?, ?)
        ");
        $closureStmt->bind_param("iis", $ticketId, $userId, $details);
        $closureStmt->execute();
        $closureStmt->close();

        // Generate feedback token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        $tokenStmt = $conn->prepare("
            INSERT INTO feedback_tokens (ticket_id, token, expires_at, used)
            VALUES (?, ?, ?, 0)
        ");
        $tokenStmt->bind_param("iss", $ticketId, $token, $expiresAt);
        $tokenStmt->execute();
        $tokenStmt->close();

        $feedbackLink = $_base_url . "/api/ticket/feedback.php?token=" . $token;
    }

    // =====================================================
    // HANDLE ESCALATION
    // =====================================================
    if ($newLower === 'escalate') {

        $escStmt = $conn->prepare("
            INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_at, escalation_reason)
            VALUES (?, ?, NOW(), ?)
        ");
        $escStmt->bind_param("iss", $ticketId, $username, $details);
        $escStmt->execute();
        $escStmt->close();
    }

    // =====================================================
    // STATUS HISTORY LOG
    // =====================================================
    $historyStmt = $conn->prepare("
        INSERT INTO ticket_status_logs 
        (ticket_id, changed_by, old_status, new_status, change_reason)
        VALUES (?, ?, ?, ?, ?)
    ");

// =====================================================
// INSERT INTO TICKET PROGRESS
// =====================================================
if (!empty($details)) {

    $progressStmt = $conn->prepare("
        INSERT INTO ticket_progress (ticket_id, description, updated_by)
        VALUES (?, ?, ?)
    ");

    $progressStmt->bind_param("iss", $ticketId, $details, $username);

    if (!$progressStmt->execute()) {
        throw new Exception($progressStmt->error);
    }

    $progressStmt->close();
}
    $historyStmt->bind_param("issss", $ticketId, $username, $currentStatus, $newStatus, $details);
    $historyStmt->execute();
    $historyStmt->close();

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
    exit;
}

// =====================================================
// EMAIL NOTIFICATION
// =====================================================

try {

    $ticketInfoStmt = $conn->prepare("
        SELECT t.title, u.email 
        FROM tickets t
        JOIN users u ON t.created_by = u.username
        WHERE t.id = ?
    ");
    $ticketInfoStmt->bind_param("i", $ticketId);
    $ticketInfoStmt->execute();
    $ticketInfo = $ticketInfoStmt->get_result()->fetch_assoc();
    $ticketInfoStmt->close();

    if ($ticketInfo) {

        require_once '../mail_config.php';
        $mail = getMailer();
        $mail->addAddress($ticketInfo['email']);

        if ($newLower === 'pending feedback') {

            $mail->isHTML(true);
            $mail->Subject = "Ticket #$ticketId - Awaiting Your Feedback";

            $mail->Body = "
                <h3>Hello,</h3>
                <p>Your ticket <strong>#$ticketId</strong> ({$ticketInfo['title']}) has been resolved.</p>
                <p><strong>Closure Details:</strong><br>" . nl2br(htmlspecialchars($details)) . "</p>
                <hr>
                <h4>Please rate our service</h4>
                <p>We would appreciate your feedback to help us improve our support.</p>
                <p>You can leave your feedback <a href='$feedbackLink'><strong>HERE</strong></a></p>
                <p style='font-size:12px;color:#666;'>This link expires in 7 days.</p>
                <br>--<br>PSPF CRM System
            ";

        } else {

            $mail->isHTML(false);
            $mail->Subject = "Ticket #$ticketId - Status Updated";

            $mail->Body = "Hello,

Ticket #$ticketId ({$ticketInfo['title']}) has been updated by $username.

New status: $newStatus

Details: " . ($details ?: 'No details provided') . "

Please log in to the PSPF CRM System for more details.

--
PSPF CRM System";
        }

        $mail->send();
    }

} catch (Exception $e) {
    error_log("Email error: " . $e->getMessage());
}

// =====================================================
// FINAL RESPONSE
// =====================================================

echo json_encode(['success' => true, 'message' => 'Ticket status updated successfully.']);
