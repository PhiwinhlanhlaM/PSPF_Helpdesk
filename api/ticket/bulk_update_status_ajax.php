<?php
session_start();
header('Content-Type: application/json');

// Verify request and session
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'it') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['ticket_ids'], $data['status']) || !is_array($data['ticket_ids']) || empty($data['ticket_ids'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$username = $_SESSION['user']['username'];
$statusInput = strtolower(trim($data['status']));

// Normalize status
switch ($statusInput) {
    case 'open':
        $newStatus = 'Open';
        break;
    case 'closed':
        $newStatus = 'Closed';
        break;
    case 'escalate':
    case 'escalated':
        $newStatus = 'Escalated';
        break;
    case 'in progress':
    case 'inprogress':
        $newStatus = 'In Progress';
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        $conn->close();
        exit;
}

$conn->begin_transaction();

try {
    foreach ($data['ticket_ids'] as $ticketId) {
        if (!is_numeric($ticketId)) continue;

        $ticketId = (int)$ticketId;

        // Get current status
        $stmt = $conn->prepare("SELECT status FROM tickets WHERE id = ?");
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result || $result->num_rows === 0) {
            $stmt->close();
            continue; // Skip non-existent ticket
        }
        $currentStatus = strtolower($result->fetch_assoc()['status']);
        $stmt->close();

        // Update main ticket status
        $update = $conn->prepare("UPDATE tickets SET status = ? WHERE id = ?");
        $update->bind_param("si", $newStatus, $ticketId);
        if (!$update->execute()) {
            throw new Exception("Failed to update ticket ID $ticketId");
        }
        $update->close();

        // Logging based on transition
        if ($newStatus === 'Closed') {
            $reason = 'Closed via bulk update';
            $log = $conn->prepare("INSERT INTO ticket_closures (ticket_id, closed_by, closed_at, closure_reason) VALUES (?, ?, NOW(), ?)");
            $log->bind_param("iss", $ticketId, $username, $reason);
            $log->execute();
            $log->close();
        } elseif ($newStatus === 'Escalated') {
            $reason = 'Bulk escalation';
            $log = $conn->prepare("INSERT INTO ticket_escalations (ticket_id, escalated_by, escalated_at, escalation_reason) VALUES (?, ?, NOW(), ?)");
            $log->bind_param("iss", $ticketId, $username, $reason);
            $log->execute();
            $log->close();

            $log2 = $conn->prepare("INSERT INTO escalations (ticket_id, reason, user, created_at) VALUES (?, ?, ?, NOW())");
            $log2->bind_param("iss", $ticketId, $reason, $username);
            $log2->execute();
            $log2->close();
        } elseif ($currentStatus === 'closed' && $newStatus !== 'Closed') {
            $reason = 'Reopened via bulk update';
            $log = $conn->prepare("INSERT INTO ticket_reopens (ticket_id, reopened_by, reopened_at, reopen_reason) VALUES (?, ?, NOW(), ?)");
            $log->bind_param("iss", $ticketId, $username, $reason);
            $log->execute();
            $log->close();
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Status updated for selected tickets.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
}

$conn->close();
