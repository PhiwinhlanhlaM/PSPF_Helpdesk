<?php
session_start();
$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once '../includes/log_activity.php';
logActivity("Ticket Updated", "Ticket ID: $ticketId, new status: $status");


$ticket_id = intval($_POST['ticket_id']);
$new_status = $conn->real_escape_string($_POST['status']);

// Get current ticket info
$stmt = $conn->prepare("
    SELECT t.created_by, u.email, t.status, t.department, t.query_date, t.logged_at 
    FROM tickets t
    INNER JOIN users u ON t.created_by = u.username
    WHERE t.id = ?
");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$result = $stmt->get_result();
$ticket = $result->fetch_assoc();
$stmt->close();

if (!$ticket) {
    die("Ticket not found.");
}

$previous_status = $ticket['status'];
$user_email = $ticket['email'];
$user_name = $ticket['created_by'];

// Update ticket status
$update = $conn->prepare("UPDATE tickets SET status = ? WHERE id = ?");
$update->bind_param("si", $new_status, $ticket_id);
$update->execute();
$update->close();

// Send email
$subject = "Helpdesk Ticket Status Update";
$message = "Dear $user_name,\n\nYour ticket #TCK-" . str_pad($ticket_id, 6, '0', STR_PAD_LEFT) . " has been updated to: $new_status.\n\nThank you,\nPSPF Helpdesk";
$headers = "From: helpdesk@pspf.com";
@mail($user_email, $subject, $message, $headers);

// If closed, ensure it's in ticket_success first
if (strtolower(trim($new_status)) === 'closed') {
    $insertSuccess = $conn->prepare("INSERT IGNORE INTO ticket_success (ticket_id, status, department, created_by, query_date, logged_at) 
        VALUES (?, ?, ?, ?, ?, ?)");
    $insertSuccess->bind_param("isssss", $ticket_id, $new_status, $ticket['department'], $ticket['created_by'], $ticket['query_date'], $ticket['logged_at']);
    $insertSuccess->execute();
    $insertSuccess->close();

    // Then insert into query_closures
    $closed_by = $_SESSION['user']['username'] ?? 'Admin';
    $log = $conn->prepare("INSERT INTO ticket_closures (ticket_id, closed_by, final_status) VALUES (?, ?, ?)");
    $log->bind_param("iss", $ticket_id, $closed_by, $new_status);
    $log->execute();
    $log->close();
}

$conn->close();
header("Location: ../agent_dashboard.php");
exit();
