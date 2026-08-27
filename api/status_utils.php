<?php
function process_status_change(mysqli $conn, int $ticketId, string $newStatus, string $username, ?string $reason = null): array {
    // Debug start
    error_log("=== STARTING STATUS CHANGE PROCESS ===");
    error_log("Ticket ID: $ticketId, New Status: $newStatus, Username: $username");

    $statusMap = [
        'open' => 'Open',
        'closed' => 'Closed',
        'escalated' => 'Escalated',
        'pending' => 'Pending',
        'in progress' => 'In Progress',
        'escalate' => 'Escalated',
    ];

    $newStatusLower = strtolower($newStatus);
    $newStatusFinal = $statusMap[$newStatusLower] ?? null;

    if (!$newStatusFinal) {
        error_log("Invalid status value provided: $newStatus");
        return ['success' => false, 'message' => 'Invalid status value.'];
    }

    // 1. Verify database connection
    if ($conn->connect_error) {
        error_log("Database connection error: " . $conn->connect_error);
        return ['success' => false, 'message' => 'Database connection failed'];
    }

    // 2. Get current ticket status with enhanced error handling
    $currentStatus = null;
    try {
        $stmt = $conn->prepare("SELECT status FROM tickets WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            return ['success' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param("i", $ticketId);
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            return ['success' => false, 'message' => 'Failed to fetch ticket'];
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            error_log("Ticket not found: $ticketId");
            return ['success' => false, 'message' => 'Ticket not found.'];
        }
        $currentStatus = strtolower($result->fetch_assoc()['status']);
        $stmt->close();
        error_log("Current status retrieved: $currentStatus");
    } catch (Exception $e) {
        error_log("Error getting current status: " . $e->getMessage());
        return ['success' => false, 'message' => 'System error'];
    }

    // 3. Update ticket status with transaction
    $conn->begin_transaction();
    try {
        // Update main ticket status
        $stmt = $conn->prepare("UPDATE tickets SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatusFinal, $ticketId);
        if (!$stmt->execute()) {
            throw new Exception("Status update failed: " . $stmt->error);
        }
        $stmt->close();
        error_log("Ticket status updated to $newStatusFinal");

        // Handle special cases
        if ($newStatusFinal === 'Closed') {
            error_log("Processing closure for ticket $ticketId");
            
            // Verify closure table exists
            $tableCheck = $conn->query("SHOW TABLES LIKE 'ticket_closures'");
            if ($tableCheck->num_rows === 0) {
                throw new Exception("ticket_closures table doesn't exist");
            }
            
            $stmt = $conn->prepare("INSERT INTO ticket_closures (ticket_id, closed_by, closed_at, status) VALUES (?, ?, NOW(), ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("iss", $ticketId, $username, $newStatusFinal);
            
            if (!$stmt->execute()) {
                throw new Exception("Closure insert failed: " . $stmt->error);
            }
            $affectedRows = $conn->affected_rows;
            $stmt->close();
            error_log("Closure recorded. Affected rows: $affectedRows");
            
            // Verify the insert
            $verify = $conn->query("SELECT * FROM ticket_closures WHERE ticket_id = $ticketId ORDER BY closed_at DESC LIMIT 1");
            if ($verify->num_rows === 0) {
                throw new Exception("Closure verification failed - no rows inserted");
            }
            error_log("Closure verified: " . json_encode($verify->fetch_assoc()));
        }
        // [Rest of your status handling code...]

        $conn->commit();
        error_log("Transaction committed successfully");
        return ['success' => true, 'message' => 'Ticket status updated successfully.'];
    } catch (Exception $e) {
        $conn->rollback();
        error_log("ERROR: " . $e->getMessage());
        return ['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()];
    }
}
?>