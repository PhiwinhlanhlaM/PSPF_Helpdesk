<?php
// ticket/ticket_success.php
session_start();

require_once '../mail_config.php';     // getMailer()
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

$_base_url = getBaseUrl();

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

/* ---------------------------
   ROLE SWITCHING
--------------------------- */
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

/* ---------------------------
   VALIDATE TICKET ID
--------------------------- */
if (!isset($_GET['ticket_id']) || !is_numeric($_GET['ticket_id'])) {
    header("Location: ../dashboard.php?error=invalid_ticket_id");
    exit;
}

$ticketId = (int) $_GET['ticket_id'];
$user = getUserFromSession();

/* ---------------------------
   FETCH TICKET DETAILS
--------------------------- */
$stmt = $conn->prepare("
    SELECT 
        t.*,
        u.department,
        u.email AS creator_email,
        d.division_name  AS division_name
    FROM tickets t
    JOIN users u ON t.created_by = u.username
    LEFT JOIN divisions d ON t.division_id = d.id
    WHERE t.id = ?
");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    die("Ticket not found. <a href='../dashboard.php'>Back</a>");
}

/* ---------------------------
   FORMAT DATA
--------------------------- */
$formattedTicketId = "TCK-" . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT);

$assignedEmails = [];
if (!empty($ticket['assigned_to'])) {
    $assignedEmails = array_filter(
        array_map('trim', explode(',', $ticket['assigned_to'])),
        fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
    );
}

/* ---------------------------
   EMAIL: CREATOR CONFIRMATION
--------------------------- */
try {
    $mail = getMailer();
    $mail->addAddress($ticket['creator_email']);
    $mail->isHTML(true);

    if (!empty($ticket['attachment_path']) && file_exists($ticket['attachment_path'])) {
        $mail->addAttachment($ticket['attachment_path']);
    }

    $mail->Subject = "Your Ticket Has Been Logged: $formattedTicketId";
    $mail->Body = "
        <h3>Ticket Logged Successfully</h3>
        <ul>
            <li><strong>Ticket ID:</strong> $formattedTicketId</li>
            <li><strong>Title:</strong> {$ticket['title']}</li>
            <li><strong>Status:</strong> {$ticket['status']}</li>
            <li><strong>Priority:</strong> {$ticket['priority']}</li>
            <li><strong>To Division:</strong> {$ticket['division_name']}</li>
            <li><strong>Date:</strong> {$ticket['query_date']}</li>
            <li><strong>Description:</strong> {$ticket['description']}</li>
        </ul>
        <p>We will keep you updated.</p>
        <p> PSPF CRM</p>
    ";
    $mail->send();
} catch (Exception $e) {
    error_log("Creator email failed: " . $e->getMessage());
}

/* ---------------------------
   EMAIL: ASSIGNED DIVISION
--------------------------- */
foreach ($assignedEmails as $email) {
    try {
        $mail = getMailer();
        $mail->addAddress($email);
        $mail->isHTML(true);

        if (!empty($ticket['attachment_path']) && file_exists($ticket['attachment_path'])) {
        $mail->addAttachment($ticket['attachment_path']);
    }

        $mail->Subject = "New Ticket Assigned: $formattedTicketId";
        $mail->Body = "
            <h3>New Ticket Assigned</h3>
            <ul>
                <li><strong>ID:</strong> $formattedTicketId</li>
                <li><strong>Title:</strong> {$ticket['title']}</li>
                <li><strong>Priority:</strong> {$ticket['priority']}</li>
                <li><strong>Created By:</strong> {$ticket['created_by']}</li>
                <li><strong>From Department:</strong> {$ticket['department']}</li>
                <li><strong>Creation Date:</strong> {$ticket['query_date']}</li>
                <li><strong>Description:</strong> {$ticket['description']}</li>
                <li><strong>To Division:</strong> {$ticket['division_name']}</li>
            </ul>
            <p>
                <a href='<?= htmlspecialchars($_base_url) ?>/api/signin/index.php'>
                    View Ticket
                </a>
            </p>
			<p>Regards,</p>
			<p> PSPF CRM</p>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Assignment email failed to $email");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ticket Successfully Logged - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../style7.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
<!-- Your existing header -->
 <?php  include '../agent/topnav.php'; ?>
      
<div class="success-container">
    <div class="success-grid">
        <!-- Left Side - Success Message -->
        <div class="success-side">
            <div class="success-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            
            <h1 class="display-3 fw-bold mb-1">🎉 Success!</h1>
            <p class="lead mb-2">Your ticket has been successfully logged in our system.</p>
            
            <div class="ticket-id-display">
                <i class="bi bi-ticket-detailed me-2"></i>
                Ticket ID: <?= $formattedTicketId ?>
            </div>
           
            
            <p class="mb-2">
                <i class="bi bi-check-lg me-2"></i>
                Confirmation emails have been sent to you and the assigned team.
            </p>
            
            <div class="info-box">
                <h5 class="mb-2"><i class="bi bi-info-circle me-2"></i>What happens next?</h5>
                <ul class="mb-0 ps-3" style="opacity: 0.9;">
                    <li>The assigned team has been notified</li>
                    <li>You'll receive updates via email</li>
                    <li>Track progress in your dashboard</li>
                </ul>
            </div>
            
            <div class="action-buttons">
                <a href="../user_dashboard.php" class="btn-modern" style="background: var(--success-light); border: 2px solid white;">
                    <i class="bi bi-house-door me-1"></i> Dashboard
                </a>
                <a href="./query.php" class="btn-modern"style="background: var(--success-color); border: 2px solid white;">
                    <i class="bi bi-plus-circle me-1"></i> Create Another
                </a>
            </div>
        </div>
        
        <!-- Right Side - Ticket Details -->
        <div class="details-side">
            <h3 class="section-title">
                <i class="bi bi-ticket-detailed"></i>
                Ticket Details
            </h3>
            
            <div class="row g-3">
                <!-- Column 1 -->
                <div class="col-md-6">
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-card-heading"></i> Title
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['title']) ?>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-activity"></i> Status
                        </div>
                        <div class="detail-value">
                            <span class="priority-badge bg-success bg-opacity-10 text-success border border-success">
                                <i class="bi bi-check-circle me-1"></i>
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-building"></i> Your Department
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['department']) ?>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-person-circle"></i> Created By
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['created_by']) ?>
                        </div>
                    </div>
                </div>
                
                <!-- Column 2 -->
                <div class="col-md-6">
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-calendar-check"></i> Date Logged
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['query_date']) ?>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-list-task"></i> Assigned Division
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['division_name']) ?>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-exclamation-triangle"></i> Priority
                        </div>
                        <div class="detail-value">
                            <?php
                                $priorityClass = ($ticket['priority'] === 'High') ? 'bg-danger' : 
                                                (($ticket['priority'] === 'Medium') ? 'bg-warning text-dark' : 'bg-secondary');
                                $priorityIcon = ($ticket['priority'] === 'High') ? 'bi-exclamation-octagon' : 
                                               (($ticket['priority'] === 'Medium') ? 'bi-exclamation-triangle' : 'bi-info-circle');
                            ?>
                            <span class="priority-badge <?= $priorityClass ?>">
                                <i class="bi <?= $priorityIcon ?> me-1"></i>
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if (!empty($ticket['member_type'])): ?>
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-people"></i> Member Type
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['member_type']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Additional Info -->
            <?php if (!empty($ticket['region']) || !empty($ticket['source']) || !empty($ticket['phone_number'])): ?>
            
            <div class="row g-3">
                <?php if (!empty($ticket['region'])): ?>
                <div class="col-md-4">
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-geo-alt"></i> Region
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['region']) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($ticket['source'])): ?>
                <div class="col-md-4">
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-source"></i> Source
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['source']) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($ticket['phone_number'])): ?>
                <div class="col-md-4">
                    <div class="detail-card">
                        <div class="detail-label">
                            <i class="bi bi-telephone"></i> Phone
                        </div>
                        <div class="detail-value">
                            <?= htmlspecialchars($ticket['phone_number']) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Description -->
            <?php if (!empty($ticket['description'])): ?>
            <div class="detail-card mt-3">
                <div class="detail-label">
                    <i class="bi bi-chat-text"></i> Description
                </div>
                <div class="detail-value" style="white-space: pre-wrap;">
                    <?= htmlspecialchars($ticket['description']) ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Attachment -->
            <?php if (!empty($ticket['attachment_path'])): ?>
            <div class="detail-card mt-3">
                <div class="detail-label">
                    <i class="bi bi-paperclip"></i> Attachment
                </div>
                <div class="detail-value">
                    <!-- For download link -->
                        <a href="../uploads/<?= htmlspecialchars($ticket['attachment_path']) ?>" 
                       target="_blank" rel="noopener noreferrer"
                       class="btn-modern" style="background: var(--success-color);">
                        <i class="bi bi-download me-1"></i> Download Attachment
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<!-- Your existing footer -->
<footer class="footer">
  <div class="footer-container">
    <div class="logout-link">
      <p>&copy; <?= date('Y') ?> All rights reserved to PSPF ICT.</p>
      <p>Version 1.0.0 </p>
      <p><small>Logged in as <?= htmlspecialchars($UserUsername) ?> (<?= getActiveRole() ?>)| <a href="./signin/logout.php">Logout</a></small></p>
    </div>

  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


</body>
</html>
