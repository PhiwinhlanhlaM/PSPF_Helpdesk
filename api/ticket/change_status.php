<?php
// ticket/change_status.php
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/ticket_status_functions.php';
require_once '../db.php';
require_once '../mail_config.php';

requireAnyRole(['agent', 'admin', 'superadmin']);

$ticketId = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;

if (!$ticketId) {
    $_SESSION['error'] = "No ticket specified";
    header("Location: ../agent_dashboard.php");
    exit;
}

// Get ticket details
$stmt = $conn->prepare("
    SELECT t.*,th.*, u.email AS creator_email 
    FROM tickets t 
    JOIN users u ON t.created_by = u.username 
    WHERE t.id = ?
");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    $_SESSION['error'] = "Ticket not found";
    header("Location: ../agent/agent_dashboard.php");
    exit;
}


// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = trim($_POST['new_status'] ?? '');
    $changeReason = trim($_POST['change_reason'] ?? '');
    $changedBy = $_SESSION['user']['username'];
    
    // Allowed status transitions
    $allowedStatuses = ['Open', 'In Progress', 'Pending Customer', 'Pending Vendor', 
                       'On Hold', 'Resolved', 'Closed', 'Reopened', 'Escalated'];
    
    if (in_array($newStatus, $allowedStatuses) && $newStatus !== $ticket['status']) {
        // Log the status change
        $logId = logTicketStatusChange($ticketId, $newStatus, $changedBy, $changeReason);
        
        // Send notification email if status is Escalated or Closed
        if (in_array($newStatus, ['Escalated', 'Closed', 'Resolved'])) {
            sendStatusChangeNotification($ticket, $newStatus, $changedBy, $changeReason);
        }
        
        $_SESSION['success'] = "Ticket status changed from {$ticket['status']} to $newStatus";
        header("Location: ../agent/agent_dashboard.php?ticket_id=$ticketId");
        exit;
    } else {
        $error = "Invalid status or same status selected";
    }
}

// Get status history
$history = getTicketStatusHistory($ticketId);
$summary = getStatusChangeSummary($ticketId);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Ticket Status - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="container mt-4">
        <h2>
            <i class="bi bi-pencil-square"></i>
            Change Ticket Status
            <small class="text-muted">- TCK-<?= str_pad($ticketId, 6, '0', STR_PAD_LEFT) ?></small>
        </h2>
        
        <!-- Current Status Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Current Status</h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <span class="badge bg-<?= getStatusColor($ticket['status']) ?> fs-6 me-3">
                        <i class="bi <?= getStatusIcons($ticket['status']) ?> me-1"></i>
                        <?= $ticket['status'] ?>
                    </span>
                    <div>
                        <strong><?= htmlspecialchars($ticket['title']) ?></strong><br>
                        <small class="text-muted">
                            Created by <?= htmlspecialchars($ticket['created_by']) ?> on 
                            <?= date('M d, Y', strtotime($ticket['query_date'])) ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Status Change Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Change Status</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">New Status</label>
                        <select class="form-select" name="new_status" required>
                            <option value="">Select new status</option>
                            <option value="In Progress" <?= $ticket['status'] == 'In Progress' ? 'disabled' : '' ?>>
                                In Progress
                            </option>
                            <option value="Pending Customer" <?= $ticket['status'] == 'Pending Customer' ? 'disabled' : '' ?>>
                                Pending Customer Response
                            </option>
                            <option value="Pending Vendor" <?= $ticket['status'] == 'Pending Vendor' ? 'disabled' : '' ?>>
                                Pending Vendor Response
                            </option>
                            <option value="On Hold" <?= $ticket['status'] == 'On Hold' ? 'disabled' : '' ?>>
                                On Hold
                            </option>
                            <option value="Resolved" <?= $ticket['status'] == 'Resolved' ? 'disabled' : '' ?>>
                                Resolved
                            </option>
                            <option value="Closed" <?= $ticket['status'] == 'Closed' ? 'disabled' : '' ?>>
                                Closed
                            </option>
                            <option value="Reopened" <?= $ticket['status'] == 'Reopened' ? 'disabled' : '' ?>>
                                Reopened
                            </option>
                            <option value="Escalated" <?= $ticket['status'] == 'Escalated' ? 'disabled' : '' ?>>
                                Escalated
                            </option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Change</label>
                        <textarea class="form-control" name="change_reason" rows="3" 
                                  placeholder="Explain why you're changing the status..."></textarea>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="../agent_dashboard.php" class="btn btn-outline-secondary me-2">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Status History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Status Change History</h5>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                <p class="text-muted">No status changes recorded yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Changed By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $log): ?>
                            <tr>
                                <td><?= date('M d, Y h:i A', strtotime($log['change_date'])) ?></td>
                                <td>
                                    <?php if ($log['old_status']): ?>
                                    <span class="badge bg-secondary"><?= $log['old_status'] ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= getStatusColor($log['new_status']) ?>">
                                        <?= $log['new_status'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($log['changed_by_name'] ?: $log['changed_by']) ?></td>
                                <td>
                                    <?php if ($log['change_reason']): ?>
                                    <?= htmlspecialchars($log['change_reason']) ?>
                                    <?php else: ?>
                                    <span class="text-muted">No reason provided</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>