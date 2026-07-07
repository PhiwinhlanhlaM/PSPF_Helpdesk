<?php
//admin_view.php
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';
require_once '../includes/ticket_gauge.php'; 

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// ---------------------------
// Access Control - ADMIN/SUPERADMIN ONLY
// ---------------------------
// allow any system role here
requireAnyRole(['user','agent','admin','superadmin']);

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDept      = $_SESSION['user']['department'] ?? '';
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');


$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];

$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------
// Get agents for assignment dropdown (only for admins/superadmins)
// ---------------------------
$agents = [];
$departments = [];

// Fetch divisions list (id + name) instead of deriving department names from users
$divisionSql = "SELECT id, division_name FROM divisions ORDER BY division_name";
$divisionResult = $conn->query($divisionSql);
$allDivisions = $divisionResult ? $divisionResult->fetch_all(MYSQLI_ASSOC) : [];

if ($activeRole === 'superadmin') {
    // Superadmin: Get all agents across all divisions
    $agentsSql = "
        SELECT DISTINCT u.id, u.username, u.email, u.department, u.division_id, d.division_name
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        LEFT JOIN divisions d ON u.division_id = d.id
        WHERE r.name = 'agent' OR r.name = 'admin'
        ORDER BY d.division_name, u.username
    ";

    $departments = $allDivisions;

} elseif ($activeRole === 'admin') {
    // Admin: Get agents only in their division
    $agentsSql = "
        SELECT DISTINCT u.id, u.username, u.email, u.department, u.division_id, d.division_name
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        LEFT JOIN divisions d ON u.division_id = d.id
        WHERE (r.name = 'agent' OR r.name = 'admin')
        AND u.division_id = ?
        ORDER BY u.username
    ";
    
    $agentsStmt = $conn->prepare($agentsSql);
    $agentsStmt->bind_param("i", $UserDivisionId);
    $agentsStmt->execute();
    $agentsResult = $agentsStmt->get_result();
    $agents = $agentsResult->fetch_all(MYSQLI_ASSOC);

    // Departments list should include all divisions (admins can assign to any department)
    $departments = $allDivisions;
} else {
    // Should not happen due to access control, but as fallback
    $agentsResult = $conn->query("SELECT DISTINCT u.id, u.username, u.email, u.department, u.division_id FROM users u LIMIT 0");
    $agents = $agentsResult->fetch_all(MYSQLI_ASSOC);
}

// Execute superadmin query if needed
if ($activeRole === 'superadmin' && !empty($agentsSql)) {
    $agentsResult = $conn->query($agentsSql);
    $agents = $agentsResult->fetch_all(MYSQLI_ASSOC);
}



// Include activity logging helper
require_once __DIR__ . '/../includes/log_activity.php';
// Include mail helper for sending notifications
require_once __DIR__ . '/../mail_config.php';

// ---------------------------
// Handle Ticket Assignment POST
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_ticket'])) {
    $ticketId = (int)$_POST['ticket_id'];
    $assignMode = $_POST['assign_type'] ?? 'user';

    if ($assignMode === 'user') {
        $assignedTo = trim($_POST['assigned_to'] ?? '');
        if (empty($assignedTo)) {
            $_SESSION['admin_message'] = "Please select an agent to assign.";
        } else {
            // Verify the selected agent (email) exists and get their division details
            $checkStmt = $conn->prepare("SELECT division_id, username, email FROM users WHERE email = ? LIMIT 1");
            $checkStmt->bind_param("s", $assignedTo);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();
            $agentRow = $checkRes->fetch_assoc();
            $checkStmt->close();

            if (!$agentRow) {
                $_SESSION['admin_message'] = "Selected agent does not exist.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            $agentDivId = (int)($agentRow['division_id'] ?? 0);
            $agentUsername = $agentRow['username'];

            // If admin, ensure the selected agent is in the same division as the admin
            if ($activeRole === 'admin') {
                if ($agentDivId !== (int)$UserDivisionId) {
                    $_SESSION['admin_message'] = "You can only assign to agents in your department.";
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                }
            }

            // Update ticket assignment to a user and set division_id to the agent's division
            // assigned_to now stores the agent's email
            $updateSql = "UPDATE tickets SET assigned_to = ?, division_id = ?, department_reason = NULL WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            if ($updateStmt === false) {
                $_SESSION['admin_message'] = "Database error (update ticket): " . $conn->error;
            } else {
                $updateStmt->bind_param("sii", $assignedTo, $agentDivId, $ticketId);

                if ($updateStmt->execute()) {
                    $_SESSION['admin_message'] = "Ticket #$ticketId assigned to {$agentRow['email']} successfully.";

                    // Record assignment in ticket_progress (fallback for ticket_assignments)
                    try {
                        $assignDesc = "Assigned to {$assignedTo} (manual)";
                        $pp = $conn->prepare("INSERT INTO ticket_progress (ticket_id, updated_by, description) VALUES (?, ?, ?)");
                        if ($pp) {
                            $pp->bind_param("iss", $ticketId, $UserUsername, $assignDesc);
                            $pp->execute();
                            $pp->close();
                        }
                    } catch (Exception $e) {
                        error_log('Failed to record assignment in ticket_progress: ' . $e->getMessage());
                    }

                    // Send notification email to ticket creator and assigned agent
                    try {
                        $tStmt = $conn->prepare("SELECT t.title, t.created_by, u.email AS creator_email FROM tickets t JOIN users u ON t.created_by = u.username WHERE t.id = ? LIMIT 1");
                        if ($tStmt !== false) {
                            $tStmt->bind_param("i", $ticketId);
                            $tStmt->execute();
                            $tRes = $tStmt->get_result();
                            $tRow = $tRes->fetch_assoc();
                            $tStmt->close();

                            $creator_email = $tRow['creator_email'] ?? '';
                            $ticket_no = 'TCK-' . str_pad($ticketId, 6, '0', STR_PAD_LEFT);

                            $mail = getMailer();
                            if (!empty($creator_email)) $mail->addAddress($creator_email);
                            if (!empty($assignedTo)) $mail->addAddress($assignedTo);

                            $mail->Subject = "Ticket Assigned: $ticket_no";
                            $mail->Body = "Ticket $ticket_no has been assigned to {$agentRow['email']}.\n\nTitle: {$tRow['title']}\nAssigned by: $UserUsername";

                            if (!$mail->send()) {
                                error_log("Assignment email failed: " . $mail->ErrorInfo);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Assignment email exception: " . $e->getMessage());
                    }
                } else {
                    $_SESSION['admin_message'] = "Failed to assign ticket.";
                }
                $updateStmt->close();
            }
        }
    } elseif ($assignMode === 'department') {
        $divisionId = (int)($_POST['assign_department'] ?? 0);
        $reason = trim($_POST['department_reason'] ?? '');

        if ($divisionId <= 0) {
            $_SESSION['admin_message'] = "Please select a department to assign.";
        } else {
            // Get all emails from this department
            $emailStmt = $conn->prepare("SELECT email FROM users WHERE division_id = ?");
            $emailStmt->bind_param("i", $divisionId);
            $emailStmt->execute();
            $emailResult = $emailStmt->get_result();
            
            $deptEmails = [];
            while ($row = $emailResult->fetch_assoc()) {
                $deptEmails[] = $row['email'];
            }
            $emailStmt->close();
            
            // Join emails with commas for assigned_to column
            $assignedToEmails = implode(', ', $deptEmails);
            
            // Update ticket to assign to department users; set division_id and save department_reason
            $updateSql = "UPDATE tickets SET assigned_to = ?, division_id = ?, department_reason = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            if ($updateStmt === false) {
                $_SESSION['admin_message'] = "Database error (update ticket): " . $conn->error;
            } else {
                $updateStmt->bind_param("sisi", $assignedToEmails, $divisionId, $reason, $ticketId);

                if ($updateStmt->execute()) {
                    // Get division name for message/log
                    $dStmt = $conn->prepare("SELECT division_name FROM divisions WHERE id = ? LIMIT 1");
                    if ($dStmt === false) {
                        $dName = $divisionId;
                    } else {
                        $dStmt->bind_param("i", $divisionId);
                        $dStmt->execute();
                        $dRes = $dStmt->get_result();
                        $divisionRow = $dRes->fetch_assoc();
                        $dName = $divisionRow['division_name'] ?? $divisionId;
                        $dStmt->close();
                    }

                    $_SESSION['admin_message'] = "Ticket #$ticketId assigned to department $dName successfully.";

                    // Record department assignment in ticket_progress (fallback for ticket_assignments)
                    try {
                        $assignDesc = "Assigned to department {$dName} (manual). Reason: {$reason}";
                        $pp = $conn->prepare("INSERT INTO ticket_progress (ticket_id, updated_by, description) VALUES (?, ?, ?)");
                        if ($pp) {
                            $pp->bind_param("iss", $ticketId, $UserUsername, $assignDesc);
                            $pp->execute();
                            $pp->close();
                        }
                    } catch (Exception $e) {
                        error_log('Failed to record department assignment in ticket_progress: ' . $e->getMessage());
                    }

                    // Log the reason as an activity entry
                    logActivity($UserUsername, 'Assign Ticket to Department', "Ticket #$ticketId assigned to department '$dName'. Reason: $reason");

                    // Send notification email to all department members and ticket creator
                    try {
                        $tStmt = $conn->prepare("SELECT t.title, t.created_by, u.email AS creator_email FROM tickets t JOIN users u ON t.created_by = u.username WHERE t.id = ? LIMIT 1");
                        if ($tStmt !== false) {
                            $tStmt->bind_param("i", $ticketId);
                            $tStmt->execute();
                            $tRes = $tStmt->get_result();
                            $tRow = $tRes->fetch_assoc();
                            $tStmt->close();

                            $creator_email = $tRow['creator_email'] ?? '';
                            $ticket_no = 'TCK-' . str_pad($ticketId, 6, '0', STR_PAD_LEFT);

                            $mail = getMailer();
                            if (!empty($creator_email)) $mail->addAddress($creator_email);
                            
                            // Add all department members to email
                            foreach ($deptEmails as $deptEmail) {
                                if (!empty($deptEmail)) $mail->addAddress($deptEmail);
                            }

                            $mail->Subject = "Ticket Assigned to Department: $ticket_no";
                            $reasonText = !empty($reason) ? "\nReason: $reason" : '';
                            $mail->Body = "Ticket $ticket_no has been assigned to the {$dName} department.\n\nTitle: {$tRow['title']}\nAssigned by: $UserUsername{$reasonText}";

                            if (!$mail->send()) {
                                error_log("Assignment email failed: " . $mail->ErrorInfo);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Assignment email exception: " . $e->getMessage());
                    }
                } else {
                    $_SESSION['admin_message'] = "Failed to assign ticket.";
                }
                $updateStmt->close();
            }
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ---------------------------
// Handle Status Update POST
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $ticketId = (int)$_POST['ticket_id'];
    $newStatus = trim($_POST['status']);
    $statusReason = trim($_POST['status_reason'] ?? '');
    $allowedStatuses = ['Open', 'In Progress', 'Closed', 'Escalated'];
    
    if (in_array($newStatus, $allowedStatuses)) {
        // Get current status and derive last updater from ticket_progress
        $oldStatusSql = "SELECT status, updated_at FROM tickets WHERE id = ?";
        $oldStmt = $conn->prepare($oldStatusSql);
        $oldStmt->bind_param("i", $ticketId);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $ticketRow = $oldResult->fetch_assoc();
        $oldStmt->close();

        if (!$ticketRow) {
            $_SESSION['admin_message'] = "Ticket not found.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $oldStatus = $ticketRow['status'];

        // Fetch latest updater from ticket_progress (preferred source)
        $lastUpdatedBy = null;
        $lastUpdatedAt = $ticketRow['updated_at'] ?? null;
        $ppStmt = $conn->prepare("SELECT updated_by, created_at FROM ticket_progress WHERE ticket_id = ? ORDER BY created_at DESC LIMIT 1");
        if ($ppStmt) {
            $ppStmt->bind_param("i", $ticketId);
            $ppStmt->execute();
            $ppRes = $ppStmt->get_result();
            $ppRow = $ppRes->fetch_assoc();
            if ($ppRow) {
                $lastUpdatedBy = $ppRow['updated_by'];
                $lastUpdatedAt = $ppRow['created_at'];
            }
            $ppStmt->close();
        }
        
        // ===== ENFORCE STATUS WORKFLOW RULES =====
        // Status transitions are free-form: a ticket may move directly between
        // states (e.g. Open -> Closed) without passing through In Progress first.
        // The only retained restriction is that a Closed ticket cannot be escalated.
        $oldStatusLower = strtolower($oldStatus);
        $newStatusLower = strtolower($newStatus);

        // Closed tickets cannot be escalated
        if ($oldStatusLower === 'closed' && $newStatusLower === 'escalated') {
            $_SESSION['admin_message'] = "Closed tickets cannot be escalated.";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // ===== ENFORCE REASON/DESCRIPTION REQUIREMENTS =====
        if (in_array($newStatusLower, ['in progress', 'closed', 'escalated'])) {
            if (empty($statusReason)) {
                $_SESSION['admin_message'] = "A description/reason is required for this status change.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        }
        
        // Update ticket status and track who updated it (record activity in ticket_progress)
        $updateSql = "UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        if ($updateStmt === false) {
            $_SESSION['admin_message'] = "Database error (update ticket): " . $conn->error;
        } else {
            $updateStmt->bind_param("si", $newStatus, $ticketId);

            if ($updateStmt->execute()) {
                $_SESSION['admin_message'] = "Ticket #$ticketId status updated from '$oldStatus' to '$newStatus'.";

                // Log the status change in ticket_status_logs
                $logSql = "INSERT INTO ticket_status_logs (ticket_id, old_status, new_status, changed_by, change_reason) VALUES (?, ?, ?, ?, ?)";
                $logStmt = $conn->prepare($logSql);
                if ($logStmt !== false) {
                    $logStmt->bind_param("issss", $ticketId, $oldStatus, $newStatus, $UserUsername, $statusReason);
                    $logStmt->execute();
                    $logStmt->close();
                }

                // Record activity/progress in ticket_progress (used as canonical activity log)
                try {
                    $pp = $conn->prepare("INSERT INTO ticket_progress (ticket_id, updated_by, description) VALUES (?, ?, ?)");
                    if ($pp) {
                        $pp->bind_param("iss", $ticketId, $UserUsername, $statusReason);
                        $pp->execute();
                        $pp->close();
                    }
                } catch (Exception $e) {
                    error_log('Failed to record status change in ticket_progress: ' . $e->getMessage());
                }

                // Send notification email
                try {
                    $tStmt = $conn->prepare("SELECT t.title, t.created_by, u.email AS creator_email, t.assigned_to FROM tickets t JOIN users u ON t.created_by = u.username WHERE t.id = ? LIMIT 1");
                    if ($tStmt !== false) {
                        $tStmt->bind_param("i", $ticketId);
                        $tStmt->execute();
                        $tRes = $tStmt->get_result();
                        $tRow = $tRes->fetch_assoc();
                        $tStmt->close();
                        
                        if ($tRow) {
                            $ticket_no = 'TCK-' . str_pad($ticketId, 6, '0', STR_PAD_LEFT);
                            $mail = getMailer();
                            if (!empty($tRow['creator_email'])) $mail->addAddress($tRow['creator_email']);
                            
                            $mail->Subject = "Ticket Status Updated: $ticket_no";
                            $mail->Body = "Ticket $ticket_no status has been updated to: $newStatus\n\nTitle: {$tRow['title']}\n\nDetails: $statusReason\n\nUpdated by: $UserUsername";
                            
                            if (!$mail->send()) {
                                error_log("Status update email failed: " . $mail->ErrorInfo);
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Status update notification error: " . $e->getMessage());
                }
            } else {
                $_SESSION['admin_message'] = "Failed to update ticket status.";
            }
            $updateStmt->close();
        }
    } else {
        $_SESSION['admin_message'] = "Invalid status selected.";
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ---------------------------
// Pagination & Filter
// ---------------------------
$filter = $_GET['filter'] ?? '';
$deptFilter = isset($_GET['department']) && $_GET['department'] !== '' ? $_GET['department'] : '';
$statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : '';
$priorityFilter = isset($_GET['priority']) && $_GET['priority'] !== '' ? $_GET['priority'] : '';
$assignedFilter = isset($_GET['assigned']) && $_GET['assigned'] === 'me';

$itemsPerPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $itemsPerPage;
$searchParam = "%" . $filter . "%";

// ---------------------------
// Build Base Query Based on Role
// ---------------------------
$whereClauses = [];
$params = [];
$types = "";

// Base search conditions
if (!empty($filter)) {
    $whereClauses[] = "(t.title LIKE ? OR t.description LIKE ? OR t.created_by LIKE ? OR t.assigned_to LIKE ? OR CAST(t.id AS CHAR) LIKE ?)";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    $types .= "sssss";
}

// Department filter (superadmin only) - now using division id
if ($activeRole === 'superadmin' && !empty($deptFilter)) {
    $whereClauses[] = "t.division_id = ?";
    $params[] = (int)$deptFilter;
    $types .= "i";
}

// Status filter
if (!empty($statusFilter)) {
    $whereClauses[] = "t.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

// Priority filter
if (!empty($priorityFilter)) {
    $whereClauses[] = "t.priority = ?";
    $params[] = $priorityFilter;
    $types .= "s";
}

// Role-based filtering
if ($activeRole === 'admin') {
    // Admin sees tickets from their division only
    // Only add division filter if admin has a valid division_id (not 0)
    if ($UserDivisionId > 0) {
        $whereClauses[] = "t.division_id = ?";
        $params[] = $UserDivisionId;
        $types .= "i";
    }
    
    // "Assigned to me" filter for admins (now using email instead of username)
    if ($assignedFilter) {
        $whereClauses[] = "t.assigned_to LIKE ?";
        $searchEmail = '%' . $UserEmail . '%';
        $params[] = $searchEmail;
        $types .= "s";
    }
}

// Build WHERE clause
$whereClause = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// ---------------------------
// Main Query for Tickets
// ---------------------------
$sql = "
    SELECT t.*, u.department, u.username as creator_username, d.division_name
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.username
    LEFT JOIN divisions d ON t.division_id = d.id
    $whereClause
    ORDER BY t.query_date DESC
    LIMIT ? OFFSET ?
";

// Prepare and execute main query
$stmt = $conn->prepare($sql);

// Add limit and offset parameters
$params[] = $itemsPerPage;
$params[] = $offset;
$types .= "ii";

// Bind parameters if any
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$tickets = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------------------------
// Count Query for Pagination
// ---------------------------
$countSql = "
    SELECT COUNT(*) as total
    FROM tickets t
    LEFT JOIN users u ON t.created_by = u.username
    $whereClause
";

// Remove limit and offset from params for count query
$countParams = array_slice($params, 0, count($params) - 2);
$countTypes = substr($types, 0, -2);

$countStmt = $conn->prepare($countSql);
if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRow = $countResult->fetch_assoc();
$totalTickets = (int)($totalRow['total'] ?? 0);
$totalPages = max(1, ceil($totalTickets / $itemsPerPage));
$countStmt->close();

// Clamp page safely
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $itemsPerPage;

// ---------------------------
// Badge Helper
// ---------------------------
function getBadgeClass($status) {
    return match (strtolower($status)) {
        'open'             => 'bg-warning text-dark',
        'in progress'      => 'bg-info text-dark',
        'closed'           => 'bg-success',
        'resolved'         => 'bg-success',
        'escalated'        => 'bg-danger',
        'reopened'         => 'bg-primary',
        'pending feedback',
        'pending customer',
        'pending vendor',
        'on hold'          => 'bg-secondary',
        default            => 'bg-secondary',
    };
}

function getPriorityBadgeClass($priority) {
    return match (strtolower($priority)) {
        'high' => 'bg-danger',
        'medium' => 'bg-warning text-dark',
        'low' => 'bg-success',
        default => 'bg-secondary',
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>
        <?= $activeRole === 'superadmin' ? 'All Tickets' : 'Department Tickets' ?> - PSPF CRM
    </title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
   
    <!-- CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../agent/agent_style.css">

    
    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Google Fonts -->
    <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <style>
        .view-ticket-btn, .assign-ticket-btn, .update-status-btn {
            cursor: pointer;
        }
        .table th {
            vertical-align: middle;
        }
        .role-badge {
            font-size: 0.8em;
            padding: 0.2em 0.6em;
        }
        .filter-badge {
            cursor: pointer;
        }
        .table tbody td {
            padding: 0.5rem 0.75rem !important;
            vertical-align: middle !important;
        }
    </style>
    <!-- Excel -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>

    <!-- PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
<?php ticket_gauge_assets(); ?> 
</head>

<body data-bs-theme="light">
<?php include '../agent/topnav.php'; ?>


<div class="container mt-4">
    <div class="settings-header">
        <h1 class="settings-title">
            <?php if ($activeRole === 'admin'): ?>
                <i class="bi bi-building me-2"></i><?= htmlspecialchars($UserDept) ?> Department Tickets
            <?php else: ?>
                <i class="bi bi-shield-check me-2"></i>All Tickets
            <?php endif; ?>
        </h1>
        <div class="settings-actions">
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <?php if (!empty($_SESSION['admin_message'])): ?>
    <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
        <?= htmlspecialchars($_SESSION['admin_message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['admin_message']); ?>
    <?php endif; ?>

    <!-- Admin Division Warning -->
    <?php if ($activeRole === 'admin' && $UserDivisionId == 0): ?>
    <div class="alert alert-warning alert-dismissible fade show mt-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>Warning:</strong> Your division has not been configured. You are viewing all tickets, but you should only see tickets from your department. Please contact an administrator to set your division.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="tab-content mt-3">
        <div class="container mt-5">
            <div class="settings-card">
                <div class="card border-0 shadow-sm">
                    <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
                        <span>
                            <?= $activeRole === 'admin' ? 'Department Tickets Management' : 'Complete Ticket Management' ?>
                        </span>
                        
                        <!-- Filter Buttons -->
                        <div class="mb-0">
                            <?php if ($activeRole === 'admin'): ?>
                            <a href="?assigned=me&filter=<?= urlencode($filter) ?>&department=<?= urlencode($deptFilter) ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>" 
                              class="btn btn-sm <?= $assignedFilter ? 'btn-custom-blue' : 'btn-outline-info' ?>">
                              Assigned to Me
                            </a>
                            <?php endif; ?>

                            <a href="export_admin_excel.php?filter=<?= urlencode($filter) ?>&department=<?= urlencode($deptFilter) ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?>&assigned=<?= $assignedFilter ? 'me' : '' ?>"
                               class="btn btn-sm btn-outline-primary">
                                Export to Excel
                            </a>

                            <button class="btn btn-sm btn-warning btn-outline-light" onclick="exportPDF()">
                            Export to PDF
                            </button>

                        </div>
                    </div>
                    
                    <!-- Search and Filter Form -->
                    <form method="GET" class="mb-3 p-3">
                        <div class="row g-3">
                            <?php if ($activeRole === 'superadmin'): ?>
                            <div class="col-md-2">
                                <select name="department" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= (int)$dept['id'] ?>" 
                                            <?= ((string)$deptFilter === (string)$dept['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['division_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="<?= $activeRole === 'superadmin' ? 'col-md-2' : 'col-md-3' ?>">
                                <select name="status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="Open" <?= $statusFilter === 'Open' ? 'selected' : '' ?>>Open</option>
                                    <option value="In Progress" <?= $statusFilter === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
                                    <option value="Escalated" <?= $statusFilter === 'Escalated' ? 'selected' : '' ?>>Escalated</option>
                                </select>
                            </div>
                            
                            <div class="<?= $activeRole === 'superadmin' ? 'col-md-2' : 'col-md-3' ?>">
                                <select name="priority" class="form-select">
                                    <option value="">All Priority</option>
                                    <option value="High" <?= $priorityFilter === 'High' ? 'selected' : '' ?>>High</option>
                                    <option value="Medium" <?= $priorityFilter === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="Low" <?= $priorityFilter === 'Low' ? 'selected' : '' ?>>Low</option>
                                </select>
                            </div>
                            
                            <div class="<?= $activeRole === 'superadmin' ? 'col-md-4' : 'col-md-4' ?>">
                                <input type="text" name="filter" class="form-control" 
                                       placeholder="Search tickets..." 
                                       value="<?= htmlspecialchars($filter) ?>">
                                <?php if ($assignedFilter): ?>
                                    <input type="hidden" name="assigned" value="me">
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                                <?php if ($filter || $deptFilter || $statusFilter || $priorityFilter): ?>
                                <a href="./admin_view.php" class="btn btn-outline-secondary w-100 mt-2">Clear</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <!-- Active Filters -->
                    <?php if ($filter || $deptFilter || $statusFilter || $priorityFilter || $assignedFilter): ?>
                    <div class="px-3 pb-2">
                        <small class="text-muted">Active filters:</small>
                        <?php if ($filter): ?>
                        <span class="badge bg-info filter-badge" onclick="removeFilter('filter')">
                            Search: <?= htmlspecialchars($filter) ?> ×
                        </span>
                        <?php endif; ?>
                        <?php if ($deptFilter): ?>
                            <?php
                                $deptName = htmlspecialchars($deptFilter);
                                foreach ($allDivisions as $d) {
                                    if ((string)$d['id'] === (string)$deptFilter) {
                                        $deptName = htmlspecialchars($d['division_name']);
                                        break;
                                    }
                                }
                            ?>
                        <span class="badge bg-info filter-badge" onclick="removeFilter('department')">
                            Dept: <?= $deptName ?> ×
                        </span>
                        <?php endif; ?>
                        <?php if ($statusFilter): ?>
                        <span class="badge bg-info filter-badge" onclick="removeFilter('status')">
                            Status: <?= htmlspecialchars($statusFilter) ?> ×
                        </span>
                        <?php endif; ?>
                        <?php if ($priorityFilter): ?>
                        <span class="badge bg-info filter-badge" onclick="removeFilter('priority')">
                            Priority: <?= htmlspecialchars($priorityFilter) ?> ×
                        </span>
                        <?php endif; ?>
                        <?php if ($assignedFilter): ?>
                        <span class="badge bg-info filter-badge" onclick="removeFilter('assigned')">
                            Assigned to Me ×
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table id="ticketsTable" class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Ticket ID</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <?php if ($activeRole === 'superadmin'): ?>
                                    <th>Department</th>
                                    <th>Division</th>
                                    <?php endif; ?>
                                    <th>Created By</th>
                                    <th>Created Date</th>
                                    <th>Assigned To</th>
                                    <th class="no-export">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($tickets)): ?>
                                    <tr>
                                        <td colspan="<?= $activeRole === 'superadmin' ? 10 : 8 ?>" class="text-center">
                                            No tickets found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tickets as $ticket): ?>
                                        <tr <?= ticket_row_attrs($ticket) ?> >
                                            <td><?= 'TCK-' . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                            <td><?= htmlspecialchars($ticket['title']) ?></td>
                                            <td>
                                                <span class="badge <?= getBadgeClass($ticket['status']) ?>">
                                                    <?= htmlspecialchars($ticket['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= getPriorityBadgeClass($ticket['priority']) ?>">
                                                    <?= htmlspecialchars($ticket['priority']) ?>
                                                </span>
                                            </td>
                                            <?php if ($activeRole === 'superadmin'): ?>
                                            <td><?= htmlspecialchars($ticket['department'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($ticket['division_name'] ?? 'N/A') ?></td>
                                            <?php endif; ?>
                                            <td><?= htmlspecialchars($ticket['created_by']) ?></td>
                                            <td><?= htmlspecialchars($ticket['query_date']) ?></td>
                                            <td>
                                                <?php if (!empty($ticket['assigned_to'])): ?>
                                                    <?= htmlspecialchars($ticket['assigned_to']) ?>
                                                    <?php if (!empty($ticket['assigned_agent_department'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($ticket['assigned_agent_department']) ?></small>
                                                    <?php endif; ?>
                                                <?php elseif (!empty($ticket['division_name'])): ?>
                                                    <strong class="text-muted">Department:</strong> <?= htmlspecialchars($ticket['division_name']) ?>
                                                    <?php if (!empty($ticket['department_reason'])): ?>
                                                        <br><small class="text-muted">Reason: <?= htmlspecialchars($ticket['department_reason']) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="no-export">
                                                <div class="d-flex gap-2" style="gap: 4px !important;">
                                                    <!-- View Button -->
                                                    <button type="button" 
                                                            class="btn btn-sm btn-info view-ticket-btn" 
                                                            data-ticket-id="<?= $ticket['id'] ?>"
                                                            title="View Ticket">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Assign Ticket Button -->
                                                    <button type="button" 
                                                            class="btn btn-sm btn-success assign-ticket-btn" 
                                                            data-ticket-id="<?= $ticket['id'] ?>"
                                                            title="Assign Ticket">
                                                        <i class="bi bi-person-check"></i>
                                                    </button>
                                                    
                                                    <!-- Update Status Button -->
                                                    <button type="button" 
                                                            class="btn btn-sm btn-primary update-status-btn" 
                                                            data-ticket-id="<?= $ticket['id'] ?>"
                                                            title="Update Status">
                                                        <i class="bi bi-send"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" 
                                       href="?page=<?= $i ?>&filter=<?= urlencode($filter) ?>&department=<?= urlencode($deptFilter) ?>&status=<?= urlencode($statusFilter) ?>&priority=<?= urlencode($priorityFilter) ?><?= $assignedFilter ? '&assigned=me' : '' ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ticket View Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-labelledby="ticketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header card-color text-white rounded-top">
                <h5 class="modal-title d-flex align-items-center" id="ticketModalLabel">
                    <i class="bi bi-ticket-perforated me-2"></i> Ticket Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" id="modalBodyContent">
                Loading...
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Department Assignment Reason Modal -->
<div class="modal fade" id="deptAssignModal" tabindex="-1" aria-labelledby="deptAssignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="deptAssignForm" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deptAssignModalLabel">Reason for Department Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label for="deptAssignReason" class="form-label">Please provide a reason for assigning to this department:</label>
                        <textarea class="form-control" id="deptAssignReason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm and Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reason Modal -->
<div class="modal fade" id="reasonModal" tabindex="-1" aria-labelledby="reasonModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reasonModalLabel">Additional Information Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="reasonModalForm">
                <div class="modal-body">
                    <p>Please provide a reason for changing the status to <span id="statusType" class="fw-bold"></span>:</p>
                    <input type="hidden" id="modalTicketId" name="ticket_id">
                    <input type="hidden" id="modalNewStatus" name="new_status">
                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason *</label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Ticket Modal -->
<div class="modal fade" id="assignTicketModal" tabindex="-1" aria-labelledby="assignTicketModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header card-color text-white">
                <h5 class="modal-title" id="assignTicketModalLabel">
                    <i class="bi bi-person-check me-2"></i>Assign Ticket
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="assignTicketForm" method="POST">
                <input type="hidden" name="ticket_id" id="assignTicketId" value="">
                <input type="hidden" name="assign_ticket" value="1">
                <input type="hidden" id="adminDivisionId" value="<?= (int)$UserDivisionId ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="assignType" class="form-label">Assign To</label>
                        <select id="assignType" name="assign_type" class="form-select" required>
                            <option value="">-- Select --</option>
                            <option value="user">Agent/User</option>
                            <?php if ($activeRole === 'superadmin' || $activeRole === 'admin'): ?>
                            <option value="department">Department</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div id="userAssignContainer" class="mb-3" style="display:none;">
                        <label for="assignedTo" class="form-label">Select Agent</label>
                        <select id="assignedTo" name="assigned_to" class="form-select">
                            <option value="">-- Select Agent --</option>
                            <?php foreach ($agents as $agent): ?>
                                <option value="<?= htmlspecialchars($agent['email']) ?>" data-dept="<?= htmlspecialchars($agent['division_id'] ?? '') ?>">
                                    <?= htmlspecialchars($agent['email']) ?> (<?= htmlspecialchars($agent['division_name'] ?? 'N/A') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($activeRole === 'admin'): ?>
                        <small class="text-muted mt-2 d-block">
                            <i class="bi bi-info-circle"></i> You can only assign to agents in your department.
                        </small>
                        <?php endif; ?>
                    </div>

                    <div id="deptAssignContainer" class="mb-3" style="display:none;">
                        <label for="assignDepartment" class="form-label">Select Department</label>
                        <select id="assignDepartment" name="assign_department" class="form-select">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['division_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-3">
                            <label for="deptReason" class="form-label">Reason (optional)</label>
                            <textarea id="deptReason" name="department_reason" class="form-control" rows="2" placeholder="Provide a reason for this assignment..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header card-color text-white">
                <h5 class="modal-title" id="updateStatusModalLabel">
                    <i class="bi bi-send me-2"></i>Update Status
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="updateStatusForm" method="POST">
                <input type="hidden" name="ticket_id" id="updateStatusTicketId" value="">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" id="currentTicketStatus" value="">
                <input type="hidden" id="lastUpdatedBy" value="">
                <div class="modal-body">
                    <!-- Concurrent Update Warning -->
                    <div id="concurrentUpdateWarning" class="alert alert-warning d-none" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Notice:</strong> <span id="concurrentUpdateText"></span>
                    </div>

                    <div class="mb-3">
                        <label for="statusSelect" class="form-label">New Status</label>
                        <select id="statusSelect" name="status" class="form-select" required>
                            <option value="">-- Select Status --</option>
                            <option value="Open">Open</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Closed">Closed</option>
                            <option value="Escalated">Escalated</option>
                        </select>
                        <small class="form-text text-muted d-block mt-2" id="statusHint"></small>
                    </div>

                    <!-- Reason/Description Field - shows for In Progress, Closed, and Escalated -->
                    <div class="mb-3 d-none" id="statusReasonContainer">
                        <label for="statusReason" class="form-label" id="statusReasonLabel"></label>
                        <textarea class="form-control" name="status_reason" id="statusReason" rows="3" placeholder="Provide details..."></textarea>
                        <small class="form-text text-muted d-block mt-1" id="statusReasonHint"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Back function
    function goBack() {
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            window.location.href = './admin_dashboard.php';
        }
    }

    // Remove filter function
    function removeFilter(filterName) {
        const url = new URL(window.location.href);
        url.searchParams.delete(filterName);
        window.location.href = url.toString();
    }

    // Toast notification function
    function showToast(message, type = 'success') {
        // Create toast element
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${escapeHtml(message)}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        // Add to toast container or create one
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }
        
        toastContainer.appendChild(toastEl);
        
        // Initialize and show toast
        const toast = new bootstrap.Toast(toastEl, {
            autohide: true,
            delay: 3000
        });
        toast.show();
        
        // Remove toast after hidden
        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });
    }

    // Individual status form submission
    document.querySelectorAll('.status-form').forEach(form => {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            
            const ticketId = this.querySelector('input[name="ticket_id"]').value;
            const selectElement = this.querySelector('.status-select');
            const newStatus = selectElement.value;
            
            if (!newStatus) {
                alert("Please select a status");
                return;
            }
            
            // Check if reason is required
            const requiresReason = newStatus.toLowerCase() === 'escalate' || 
                                   newStatus.toLowerCase() === 'closed';
            
            if (requiresReason) {
                // Clear previous reason input
                document.getElementById('reason').value = '';
                
                // Set modal values
                document.getElementById('modalTicketId').value = ticketId;
                document.getElementById('modalNewStatus').value = newStatus;
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('reasonModal'));
                modal.show();
            } else {
                // Submit directly
                await submitStatusForm(this);
            }
        });
    });

    // Reason modal form submission
    document.getElementById('reasonModalForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        
        const reason = document.getElementById('reason').value.trim();
        
        if (!reason) {
            alert('Please provide a reason before submitting.');
            return;
        }
        
        // Get ticket ID and status from hidden inputs
        const ticketId = document.getElementById('modalTicketId').value;
        const newStatus = document.getElementById('modalNewStatus').value;
        
        // Create form data
        const formData = new FormData();
        formData.append('ticket_id', ticketId);
        formData.append('new_status', newStatus);
        formData.append('reason', reason);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
        
        await submitStatusForm(formData, true);
    });

    // Generic function to submit status form
    async function submitStatusForm(formOrFormData, isModal = false) {
        try {
            const formData = formOrFormData instanceof FormData ? 
                formOrFormData : 
                new FormData(formOrFormData);
            
            const response = await fetch('../ticket/update_ticket_status_ajax.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (isModal) {
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('reasonModal'));
                    modal.hide();
                }
                
                showToast(result.message || 'Status updated successfully.', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(result.message || 'An error occurred.', 'error');
            }
        } catch (error) {
            console.error("AJAX error:", error);
            showToast("Failed to update status. Please try again.", 'error');
        }
    }

    // View Ticket Button
    document.querySelectorAll('.view-ticket-btn').forEach(button => {
        button.addEventListener('click', async function () {
            const ticketId = this.getAttribute('data-ticket-id');
            
            try {
                // Show modal immediately
                const modal = new bootstrap.Modal(document.getElementById('ticketModal'));
                modal.show();
                
                // Set loading state
                document.getElementById('modalBodyContent').innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading ticket details...</p>
                    </div>
                `;
                
                // Fetch ticket details
                const response = await fetch(`../ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
                const data = await response.json();
                
                if (data.success && data.ticket) {
                    const t = data.ticket;
                    
                    // Format date if needed
                    const formatDate = (dateString) => {
                        if (!dateString) return 'N/A';
                        const date = new Date(dateString);
                        return isNaN(date) ? dateString : date.toLocaleString();
                    };
                    
                    // Create modal content
                    let modalContent = `
                        <div class="container-fluid">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2"><i class="bi bi-info-circle me-1"></i> Ticket Info</h6>
                                            <ul class="list-group list-group-flush small">
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>ID:</strong> <span>${escapeHtml(t.ticket_id || `TCK-${String(t.id).padStart(6, '0')}`)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Title:</strong> <span>${escapeHtml(t.title || 'N/A')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Status:</strong> 
                                                    <span class="badge ${getBadgeClass(t.status)}">${escapeHtml(t.status || 'N/A')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Priority:</strong> 
                                                    <span class="badge ${t.priority === 'High' ? 'bg-danger' : (t.priority === 'Medium' ? 'bg-warning' : 'bg-success')}">
                                                        ${escapeHtml(t.priority || 'N/A')}
                                                    </span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Created:</strong> <span>${formatDate(t.query_date || t.created_at)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Source:</strong> <span>${escapeHtml(t.source || 'N/A')}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2"><i class="bi bi-person-lines-fill me-1"></i> Requester Info</h6>
                                            <ul class="list-group list-group-flush small">
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Query Type:</strong> <span>${escapeHtml(t.query_type || 'N/A')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Member Type:</strong> <span>${escapeHtml(t.member_type || 'N/A')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Phone:</strong> <span>${escapeHtml(t.phone_number || 'N/A')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Region:</strong> <span>${escapeHtml(t.region || 'N/A')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Created By:</strong> <span>${escapeHtml(t.created_by || 'N/A')}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="mt-4">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <h6 class="text-muted mb-2"><i class="bi bi-file-text me-1"></i> Description</h6>
                                        <div class="p-3 bg-light rounded border" style="min-height: 80px;">
                                            ${escapeHtml(t.description || 'No description provided')}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Assigned Info -->
                            <div class="mt-3">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <h6 class="text-muted mb-2"><i class="bi bi-person-badge me-1"></i> Assignment Info</h6>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Assigned To:</strong> 
                                                <span>${t.assigned_to ? escapeHtml(t.assigned_to) : '<span class="text-muted">Unassigned</span>'}</span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Division:</strong> 
                                                <span>${escapeHtml(t.division_name || t.department || 'N/A')}</span>
                                                ${t.department_reason ? `<br><small class="text-muted">Reason: ${escapeHtml(t.department_reason)}</small>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                    `;
                    
                    // Add attachment section if exists
                    if (t.attachment_path || t.attachment_url) {
                        const attachmentUrl = t.attachment_url || t.attachment_path;
                        const fileName = attachmentUrl.split('/').pop();
                        const ext = fileName.split('.').pop().toLowerCase();
                        
                        let previewContent = '';
                        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                        
                        if (imageExts.includes(ext)) {
                            previewContent = `<img src="${attachmentUrl}" class="img-fluid border rounded" style="max-height: 300px; max-width: 100%;" alt="Attachment preview">`;
                        } else if (ext === 'pdf') {
                            previewContent = `<iframe src="${attachmentUrl}" class="w-100" style="height: 400px; border: 1px solid #ddd;" title="PDF Preview"></iframe>`;
                        } else {
                            previewContent = `
                                <div class="text-center py-4">
                                    <i class="bi bi-file-earmark-text display-4 text-muted"></i>
                                    <p class="mt-2">${escapeHtml(fileName)}</p>
                                </div>
                            `;
                        }
                        
                        modalContent += `
                            <!-- Attachments -->
                            <div class="mt-4">
                                <h6 class="text-muted mb-2"><i class="bi bi-paperclip me-1"></i> Attachment</h6>
                                <div class="border rounded p-3 bg-white mb-3">
                                    ${previewContent}
                                </div>
                                <a href="${attachmentUrl}" class="btn btn-sm btn-outline-primary" target="_blank" download="${fileName}">
                                    <i class="bi bi-download me-1"></i> Download Attachment
                                </a>
                            </div>
                        `;
                    } else {
                        modalContent += `
                            <!-- Attachments -->
                            <div class="mt-4">
                                <h6 class="text-muted mb-2"><i class="bi bi-paperclip me-1"></i> Attachment</h6>
                                <div class="border rounded p-4 bg-white text-center">
                                    <p class="text-muted mb-0"><i class="bi bi-file-x me-1"></i> No attachment</p>
                                </div>
                            </div>
                        `;
                    }
                    
                    modalContent += `</div>`;
                    document.getElementById('modalBodyContent').innerHTML = modalContent;
                } else {
                    document.getElementById('modalBodyContent').innerHTML = `
                        <div class="alert alert-danger">
                            ${escapeHtml(data.message || 'Failed to load ticket details.')}
                        </div>
                    `;
                }
            } catch (error) {
                console.error("Error loading ticket:", error);
                document.getElementById('modalBodyContent').innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load ticket details. Please try again.
                    </div>
                `;
            }
        });
    });

    // Assign Ticket Button Handler
    document.querySelectorAll('.assign-ticket-btn').forEach(button => {
        button.addEventListener('click', function() {
            const ticketId = this.getAttribute('data-ticket-id');
            document.getElementById('assignTicketId').value = ticketId;
            new bootstrap.Modal(document.getElementById('assignTicketModal')).show();
        });
    });

    // Assign Type Change Handler
    document.getElementById('assignType').addEventListener('change', function() {
        const userContainer = document.getElementById('userAssignContainer');
        const deptContainer = document.getElementById('deptAssignContainer');
        
        if (this.value === 'user') {
            userContainer.style.display = 'block';
            deptContainer.style.display = 'none';
        } else if (this.value === 'department') {
            userContainer.style.display = 'none';
            deptContainer.style.display = 'block';
        } else {
            userContainer.style.display = 'none';
            deptContainer.style.display = 'none';
        }
    });

    // Assign Ticket Form Submit
    document.getElementById('assignTicketForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const assignType = document.getElementById('assignType').value;
        const adminDivisionId = document.getElementById('adminDivisionId').value;
        
        if (!assignType) {
            alert('Please select an assignment type');
            return;
        }
        
        if (assignType === 'user') {
            const selectedAgent = document.getElementById('assignedTo').value;
            if (!selectedAgent) {
                alert('Please select an agent');
                return;
            }
            
            // Verify the selected agent is in the same division (for admins)
            if (adminDivisionId) {
                const selectedOption = document.querySelector(`#assignedTo option[value="${selectedAgent}"]`);
                const agentDivisionId = selectedOption.getAttribute('data-dept');
                
                if (agentDivisionId && agentDivisionId !== adminDivisionId) {
                    alert('You can only assign to agents in your division');
                    return;
                }
            }
        }
        
        if (assignType === 'department' && !document.getElementById('assignDepartment').value) {
            alert('Please select a department');
            return;
        }
        
        // Submit the form
        this.submit();
    });

    // Update Status Button Handler
    document.querySelectorAll('.update-status-btn').forEach(button => {
        button.addEventListener('click', async function() {
            const ticketId = this.getAttribute('data-ticket-id');
            const currentStatusText = this.closest('tr').querySelector('td:nth-child(3)')?.textContent.trim() || '';
            
            document.getElementById('updateStatusTicketId').value = ticketId;
            document.getElementById('currentTicketStatus').value = currentStatusText;
            
            // Fetch latest ticket info to detect concurrent updates
            try {
                const response = await fetch(`./ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
                const data = await response.json();
                
                if (data.success && data.ticket) {
                    const ticket = data.ticket;
                    const currentUser = '<?= htmlspecialchars($UserUsername) ?>';
                    
                    document.getElementById('currentTicketStatus').value = ticket.status;
                    document.getElementById('lastUpdatedBy').value = ticket.last_updated_by || '';
                    
                    // Check for concurrent updates
                    const concurrentWarning = document.getElementById('concurrentUpdateWarning');
                    if (ticket.last_updated_by && ticket.last_updated_by !== currentUser) {
                        concurrentWarning.classList.remove('d-none');
                        document.getElementById('concurrentUpdateText').textContent = 
                            `This ticket was last updated by ${ticket.last_updated_by}. Are you sure you want to modify its status?`;
                    } else {
                        concurrentWarning.classList.add('d-none');
                    }
                    
                    // Disable invalid status transitions
                    updateStatusOptions(ticket.status);
                }
            } catch (error) {
                console.error('Error fetching ticket details:', error);
            }
            
            // Reset form
            document.getElementById('statusSelect').value = '';
            document.getElementById('statusReason').value = '';
            document.getElementById('statusReasonContainer').classList.add('d-none');
            
            new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
        });
    });

    // Update status select options based on current status
    function updateStatusOptions(currentStatus) {
        const select = document.getElementById('statusSelect');
        const currentLower = currentStatus.toLowerCase();
        
        // Remove and re-add options to ensure clean state
        const options = Array.from(select.options);
        
        // Hide all options first
        options.forEach(opt => opt.style.display = 'none');
        
        // Show the default option
        select.options[0].style.display = '';
        
        // Show allowed transitions
        if (currentLower === 'open') {
            // From Open, can only go to In Progress
            options.find(o => o.value === 'In Progress')?.style.display !== 'none' && (options.find(o => o.value === 'In Progress').style.display = '');
        } else if (currentLower === 'in progress') {
            // From In Progress, can go to Closed or Escalated
            options.find(o => o.value === 'Closed')?.style.display !== 'none' && (options.find(o => o.value === 'Closed').style.display = '');
            options.find(o => o.value === 'Escalated')?.style.display !== 'none' && (options.find(o => o.value === 'Escalated').style.display = '');
            options.find(o => o.value === 'Open')?.style.display !== 'none' && (options.find(o => o.value === 'Open').style.display = '');
        } else if (currentLower === 'closed') {
            // From Closed, can reopen to Open
            options.find(o => o.value === 'Open')?.style.display !== 'none' && (options.find(o => o.value === 'Open').style.display = '');
        } else if (currentLower === 'escalated') {
            // From Escalated, can return to Open or In Progress
            options.find(o => o.value === 'Open')?.style.display !== 'none' && (options.find(o => o.value === 'Open').style.display = '');
            options.find(o => o.value === 'In Progress')?.style.display !== 'none' && (options.find(o => o.value === 'In Progress').style.display = '');
        }
    }

    // Status select change handler
    document.getElementById('statusSelect').addEventListener('change', function() {
        const selectedStatus = this.value.toLowerCase();
        const reasonContainer = document.getElementById('statusReasonContainer');
        const reasonLabel = document.getElementById('statusReasonLabel');
        const reasonHint = document.getElementById('statusReasonHint');
        const statusHint = document.getElementById('statusHint');
        
        if (!this.value) {
            reasonContainer.classList.add('d-none');
            statusHint.textContent = '';
            return;
        }
        
        // Show reason field for In Progress, Closed, and Escalated
        if (['in progress', 'closed', 'escalated'].includes(selectedStatus)) {
            reasonContainer.classList.remove('d-none');
            
            if (selectedStatus === 'in progress') {
                reasonLabel.textContent = 'Process Description (Required)';
                reasonHint.textContent = 'Describe the work/process you are doing on this ticket.';
                statusHint.textContent = 'Provide details about what you are working on.';
            } else if (selectedStatus === 'closed') {
                reasonLabel.textContent = 'Closure Reason (Required)';
                reasonHint.textContent = 'Explain why this ticket is being closed.';
                statusHint.textContent = 'A reason is required to close a ticket.';
            } else if (selectedStatus === 'escalated') {
                reasonLabel.textContent = 'Escalation Reason (Required)';
                reasonHint.textContent = 'Explain why this ticket needs to be escalated.';
                statusHint.textContent = 'A reason is required to escalate a ticket.';
            }
            
            document.getElementById('statusReason').required = true;
        } else {
            reasonContainer.classList.add('d-none');
            document.getElementById('statusReason').required = false;
            statusHint.textContent = '';
        }
    });

    // Update Status Form Submit
    document.getElementById('updateStatusForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const newStatus = document.getElementById('statusSelect').value;
        const currentStatus = document.getElementById('currentTicketStatus').value;
        
        if (!newStatus) {
            alert('Please select a status');
            return;
        }
        
        // Client-side validation of transition rules.
        // Transitions are free-form (e.g. Open -> Closed directly); the only
        // retained restriction is that a Closed ticket cannot be escalated.
        const newStatusLower = newStatus.toLowerCase();
        const currentStatusLower = currentStatus.toLowerCase();

        // Closed tickets cannot be escalated
        if (currentStatusLower === 'closed' && newStatusLower === 'escalated') {
            alert('Closed tickets cannot be escalated.');
            return;
        }
        
        // Check if reason is required
        const reasonRequired = ['in progress', 'closed', 'escalated'].includes(newStatusLower);
        if (reasonRequired) {
            const reason = document.getElementById('statusReason').value.trim();
            if (!reason) {
                alert('A description/reason is required for this status change.');
                return;
            }
        }
        
        // Submit the form
        this.submit();
    });

    function getBadgeClass(status) {
        if (!status) return 'bg-secondary';
        
        status = status.toLowerCase();
        switch(status) {
            case 'open': return 'bg-warning text-dark';
            case 'in progress':
            case 'in_progress':
            case 'in-progress': return 'bg-info text-dark';
            case 'closed': return 'bg-success';
            case 'escalated':
            case 'escalate': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize all tooltips
    document.addEventListener('DOMContentLoaded', function() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });

    function prepareTableForExport() {
    const table = document.getElementById('ticketsTable');
    const clone = table.cloneNode(true);

    // 1️⃣ Find column indexes marked no-export (from THEAD)
    const ths = clone.querySelectorAll('thead th');
    const removeIndexes = [];

    ths.forEach((th, index) => {
        if (th.classList.contains('no-export')) {
            removeIndexes.push(index);
        }
    });

    // 2️⃣ Remove those columns from EVERY row (reverse order!)
    clone.querySelectorAll('tr').forEach(row => {
        removeIndexes.slice().reverse().forEach(i => {
            if (row.children[i]) {
                row.children[i].remove();
            }
        });
    });

    return clone;
}

function exportFileName(ext) {
    const d = new Date();
    const date =
        d.getFullYear() + "-" +
        String(d.getMonth() + 1).padStart(2, '0') + "-" +
        String(d.getDate()).padStart(2, '0');

    return `tickets_${date}.${ext}`;
}


    function exportExcel() {
    const cleanTable = prepareTableForExport();
    const wb = XLSX.utils.table_to_book(cleanTable, { sheet: "Tickets" });
    XLSX.writeFile(wb, exportFileName("xlsx"));
}


    const { jsPDF } = window.jspdf;

    function exportPDF() {
        const cleanTable = prepareTableForExport();

        const doc = new jsPDF('l', 'pt', 'a4');
        doc.text("Tickets Report", 40, 30);

        doc.autoTable({
            html: cleanTable,
            startY: 50,
            styles: { fontSize: 8 },
            headStyles: { fillColor: [52, 152, 219] }
        });

        doc.save(exportFileName("pdf"));
    }


    // Assignment form behavior: compact assign type (single dropdown) with user/department flows
    let _currentDeptAssignForm = null;

    document.querySelectorAll('.assign-form').forEach(form => {
        const typeSelect = form.querySelector('.assign-type-select');
        const modeInput = form.querySelector('.assign-mode-input');
        const deptSelect = form.querySelector('.assign-department');
        const agentSelect = form.querySelector('.assign-select');
        const userContainer = form.querySelector('.assign-user-container');
        const deptContainer = form.querySelector('.assign-dept-container');
        const adminDiv = parseInt(form.getAttribute('data-admin-division')) || 0;

        // helper to filter agent options by department/division
        const filterAgentsByDept = (deptVal) => {
            const dept = typeof deptVal !== 'undefined' ? String(deptVal) : (deptSelect?.value || '');
            agentSelect?.querySelectorAll('option').forEach(opt => {
                if (!opt.value) return; // keep placeholder
                const optDept = opt.dataset.dept || '';
                if (!dept) {
                    opt.style.display = '';
                } else {
                    opt.style.display = (String(optDept) === String(dept)) ? '' : 'none';
                }
            });
        };

        function setMode(mode) {
            if (modeInput) modeInput.value = mode;
            if (mode === 'user') {
                if (userContainer) userContainer.style.display = '';
                if (deptContainer) deptContainer.style.display = 'none';
                // Filter agents by admin division (if admin) or by currently selected department
                const filterVal = adminDiv ? adminDiv : (deptSelect?.value || '');
                filterAgentsByDept(filterVal);
            } else if (mode === 'department') {
                if (userContainer) userContainer.style.display = 'none';
                if (deptContainer) deptContainer.style.display = '';

                // If department already selected, show reason modal immediately
                if (deptSelect && deptSelect.value) {
                    _currentDeptAssignForm = form;
                    document.getElementById('deptAssignReason').value = '';
                    new bootstrap.Modal(document.getElementById('deptAssignModal')).show();
                }
            }
        }

        // initialize defaults
        const initialMode = (modeInput && modeInput.value) ? modeInput.value : 'user';
        if (typeSelect && !typeSelect.value) typeSelect.value = initialMode;
        setMode(typeSelect ? typeSelect.value : initialMode);

        // handle type change
        typeSelect?.addEventListener('change', function () {
            setMode(this.value);
        });

        // handle department change
        deptSelect?.addEventListener('change', function () {
            if (!this.value) return;
            if (modeInput && modeInput.value === 'user') {
                filterAgentsByDept(this.value);
            } else if (modeInput && modeInput.value === 'department') {
                _currentDeptAssignForm = form;
                document.getElementById('deptAssignReason').value = '';
                new bootstrap.Modal(document.getElementById('deptAssignModal')).show();
            }
        });

        // Validate on submit
        form.addEventListener('submit', function (e) {
            const mode = this.querySelector('.assign-mode-input')?.value || 'user';

            if (mode === 'department') {
                const deptVal = this.querySelector('.assign-department')?.value || '';
                const reasonVal = this.querySelector('.department-reason-input')?.value.trim() || '';
                if (!deptVal) {
                    e.preventDefault();
                    alert('Please select a department before assigning.');
                    return;
                }
                if (!reasonVal) {
                    e.preventDefault();
                    _currentDeptAssignForm = this;
                    document.getElementById('deptAssignReason').value = '';
                    new bootstrap.Modal(document.getElementById('deptAssignModal')).show();
                    return;
                }
            } else {
                const assignee = this.querySelector('.assign-select')?.value || '';
                if (!assignee) {
                    e.preventDefault();
                    alert('Please select an agent to assign.');
                    return;
                }
                // Client-side guard for admin
                if (adminDiv) {
                    const selectedOpt = this.querySelector('.assign-select option:checked');
                    const selDept = selectedOpt?.dataset?.dept || '';
                    if (String(selDept) !== String(adminDiv)) {
                        e.preventDefault();
                        alert('Selected agent is not in your department.');
                        return;
                    }
                }
            }
        });

        // Initial filter run
        try { if (adminDiv) filterAgentsByDept(adminDiv); else filterAgentsByDept(); } catch (err) { /* ignore */ }
    });

    // Modal confirm handler for department assignment
    document.getElementById('deptAssignForm')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const reason = document.getElementById('deptAssignReason')?.value.trim() || '';
        if (!reason) {
            alert('Please enter a reason.');
            return;
        }
        if (!_currentDeptAssignForm) {
            alert('Unable to find the form to submit.');
            return;
        }
        // Ensure form marks department mode and includes assign flag
        _currentDeptAssignForm.querySelector('.department-reason-input').value = reason;
        const modeInput = _currentDeptAssignForm.querySelector('.assign-mode-input');
        if (modeInput) modeInput.value = 'department';
        const assignHidden = _currentDeptAssignForm.querySelector('.assign-ticket-hidden');
        if (assignHidden) assignHidden.value = '1';
        // Submit the parent form
        _currentDeptAssignForm.submit();
    });

</script>

<?php include '../footer.php'; ?>

</body>
</html>

<?php
// Close the database connection if it exists and is a mysqli instance
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>