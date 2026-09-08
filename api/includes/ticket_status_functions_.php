<?php
// includes/ticket_status_functions.php
require_once '../db.php';

function logTicketStatusChange($ticketId, $newStatus, $changedBy, $reason = '') {
    global $conn;
    
    // Get current status
    $stmt = $conn->prepare("SELECT status FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ticket = $result->fetch_assoc();
    $oldStatus = $ticket['status'];
    $stmt->close();
    
    // Update ticket status
    $updateStmt = $conn->prepare("UPDATE tickets SET status = ? WHERE id = ?");
    $updateStmt->bind_param("si", $newStatus, $ticketId);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Log the status change (append, not replace)
    $logStmt = $conn->prepare("
        INSERT INTO ticket_status_logs 
        (ticket_id, old_status, new_status, changed_by, change_reason) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $logStmt->bind_param("issss", $ticketId, $oldStatus, $newStatus, $changedBy, $reason);
    $logStmt->execute();
    $logId = $logStmt->insert_id;
    $logStmt->close();
    
    return $logId;
}

function getTicketStatusHistory($ticketId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            tsl.*,
            u.username AS changed_by_name,
            u.department AS changed_by_department
        FROM ticket_status_logs tsl
        LEFT JOIN users u ON tsl.changed_by = u.username
        WHERE tsl.ticket_id = ?
        ORDER BY tsl.change_date ASC
    ");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $history;
}

function getStatusChangeSummary($ticketId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            new_status,
            COUNT(*) as change_count,
            MIN(change_date) as first_change,
            MAX(change_date) as last_change
        FROM ticket_status_logs 
        WHERE ticket_id = ?
        GROUP BY new_status
        ORDER BY last_change DESC
    ");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $summary;
}

function getStatusIcons($status) {
    $icons = [
        'Open' => 'bi-plus-circle',
        'In Progress' => 'bi-gear',
        'Closed' => 'bi-archive',
        'Reopened' => 'bi-arrow-counterclockwise',
        'Escalated' => 'bi-arrow-up-circle',
        'Cancelled' => 'bi-x-circle'
    ];
    
    return $icons[$status] ?? 'bi-question-circle';
}

function getStatusColor($status) {
    $colors = [
        'Open' => 'info',
        'In Progress' => 'warning',
        'Closed' => 'success',
        'Reopened' => 'primary',
        'Escalated' => 'danger',
        'open' => 'info',
        'in progress' => 'warning',
        'closed' => 'success',
        'reopened' => 'primary',
        'escalated' => 'danger'
    ];
    
    return $colors[$status] ?? 'secondary';
}

function getChangeReason($ticketId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            a.*,
            b.description AS change_reason
        FROM ticket_history a
        JOIN ticket_status_logs b ON a.ticket_id = b.ticket_id
        WHERE a.ticket_id = ?
        GROUP BY b.new_status
        ORDER BY b.last_change DESC
    ");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $reason = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $reason;
}
function sendStatusChangeNotification($ticket, $newStatus, $changedBy, $reason) {
    global $mailConfig;
    
    $mail = getMailer();
    $formattedTicketId = "TCK-" . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT);
    
    // Send to ticket creator
    $mail->addAddress($ticket['creator_email']);
    $mail->Subject = "Ticket Status Updated: $formattedTicketId - Now $newStatus";
    
    $mail->Body = "
        <h3>Ticket Status Updated</h3>
        <p>Your ticket has been updated to: <strong>$newStatus</strong></p>
        
        <table>
            <tr><td><strong>Ticket ID:</strong></td><td>$formattedTicketId</td></tr>
            <tr><td><strong>Title:</strong></td><td>{$ticket['title']}</td></tr>
            <tr><td><strong>Previous Status:</strong></td><td>{$ticket['status']}</td></tr>
            <tr><td><strong>New Status:</strong></td><td>$newStatus</td></tr>
            <tr><td><strong>Changed By:</strong></td><td>$changedBy</td></tr>
            <tr><td><strong>Reason:</strong></td><td>" . nl2br(htmlspecialchars($reason)) . "</td></tr>
        </table>
        
        <p>You can view your ticket at: [Your Dashboard URL]</p>
    ";
    
    try {
        $mail->send();
        error_log("Status change notification sent for ticket {$ticket['id']}");
    } catch (Exception $e) {
        error_log("Failed to send status notification: " . $e->getMessage());
    }
}
// Get ticket timeline with all status changes
function getTicketTimeline($ticketId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            tsl.*,
            t.title,
            t.priority,
            t.query_date as created_date,
            (SELECT MAX(change_date) FROM tickets_status_logs WHERE ticket_id = t.id AND new_status = 'Closed') as closed_date
        FROM tickets_status_logs tsl
        JOIN tickets t ON tsl.ticket_id = t.id
        WHERE tsl.ticket_id = ?
        ORDER BY tsl.change_date
    ");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $timeline = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $timeline;
}

// Calculate ticket lifecycle metrics
function getTicketLifecycleMetrics($ticketId) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            MIN(change_date) as first_status_date,
            MAX(change_date) as last_status_date,
            COUNT(*) as total_status_changes,
            GROUP_CONCAT(DISTINCT new_status ORDER BY change_date) as status_sequence,
            TIMESTAMPDIFF(HOUR, MIN(change_date), MAX(change_date)) as total_duration_hours
        FROM tickets_status_logs 
        WHERE ticket_id = ?
    ");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $result = $stmt->get_result();
    $metrics = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate time in each status
    $durationStmt = $conn->prepare("
        SELECT 
            new_status,
            change_date,
            LEAD(change_date) OVER (ORDER BY change_date) as next_change
        FROM tickets_status_logs 
        WHERE ticket_id = ?
        ORDER BY change_date
    ");
    $durationStmt->bind_param("i", $ticketId);
    $durationStmt->execute();
    $durationResult = $durationStmt->get_result();
    $changes = $durationResult->fetch_all(MYSQLI_ASSOC);
    $durationStmt->close();
    
    $statusDurations = [];
    foreach ($changes as $change) {
        $start = strtotime($change['change_date']);
        $end = $change['next_change'] ? strtotime($change['next_change']) : time();
        $duration = $end - $start;
        
        $statusDurations[$change['new_status']] = isset($statusDurations[$change['new_status']]) 
            ? $statusDurations[$change['new_status']] + $duration 
            : $duration;
    }
    
    $metrics['status_durations'] = $statusDurations;
    return $metrics;
}

// Get department performance metrics
function getDepartmentPerformance($dateFrom, $dateTo) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            u.department,
            COUNT(DISTINCT t.id) as total_tickets,
            SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed_tickets,
            AVG(TIMESTAMPDIFF(HOUR, t.query_date, 
                CASE WHEN t.status = 'Closed' 
                THEN (SELECT MAX(change_date) FROM tickets_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
                ELSE NOW() END)) as avg_resolution_hours,
            COUNT(DISTINCT CASE WHEN t.status = 'Escalated' THEN t.id END) as escalated_tickets
        FROM tickets t
        JOIN users u ON t.created_by = u.username
        WHERE DATE(t.query_date) BETWEEN ? AND ?
        AND u.department IS NOT NULL
        GROUP BY u.department
        ORDER BY total_tickets DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $performance = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $performance;
}

// Get agent performance metrics
function getAgentPerformance($dateFrom, $dateTo) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            u.username,
            u.full_name,
            COUNT(DISTINCT t.id) as assigned_tickets,
            SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as resolved_tickets,
            AVG(TIMESTAMPDIFF(HOUR, t.query_date, 
                CASE WHEN t.status = 'Closed' 
                THEN (SELECT MAX(change_date) FROM tickets_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
                ELSE NOW() END)) as avg_resolution_hours,
            MIN(TIMESTAMPDIFF(HOUR, t.query_date, 
                CASE WHEN t.status = 'Closed' 
                THEN (SELECT MAX(change_date) FROM tickets_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
                ELSE NOW() END)) as min_resolution_hours,
            MAX(TIMESTAMPDIFF(HOUR, t.query_date, 
                CASE WHEN t.status = 'Closed' 
                THEN (SELECT MAX(change_date) FROM tickets_status_logs WHERE ticket_id = t.id AND new_status = 'Closed')
                ELSE NOW() END)) as max_resolution_hours
        FROM tickets t
        JOIN users u ON t.assigned_to LIKE CONCAT('%', u.username, '%')
        WHERE DATE(t.query_date) BETWEEN ? AND ?
        AND u.role IN ('agent', 'admin', 'superadmin')
        GROUP BY u.username, u.full_name
        ORDER BY resolved_tickets DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $performance = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $performance;
}
?>