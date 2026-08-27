<?php
session_start();
//assign_tasks.php
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

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


$Agentsql = "
    SELECT DISTINCT u.username
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE r.name = 'agent'
";

$agentUsers = $conn->query($Agentsql);

// Fetch open or unassigned tickets
$tickets = $conn->query("SELECT id, title, assigned_to FROM tickets WHERE status = 'open' OR assigned_to IS NULL");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticketId = (int)$_POST['ticket_id'];
    $assignedTo = $_POST['assigned_to'];

    // Update ticket assignment
    $stmt = $conn->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
    $stmt->bind_param("si", $assignedTo, $ticketId);
    $stmt->execute();
    $stmt->close();

    // Fetch ticket details (title, priority, creator)
    $ticketStmt = $conn->prepare("SELECT title, priority, created_by FROM tickets WHERE id = ?");
    $ticketStmt->bind_param("i", $ticketId);
    $ticketStmt->execute();
    $ticket = $ticketStmt->get_result()->fetch_assoc();
    $ticketStmt->close();

    $ticketTitle = $ticket['title'] ?? 'N/A';
    $ticketPriority = $ticket['priority'] ?? 'N/A';
    $ticketCreator = $ticket['created_by'] ?? null;

    // Fetch IT user email
    $userStmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
    $userStmt->bind_param("s", $assignedTo);
    $userStmt->execute();
    $itUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    $itEmail = $itUser['email'] ?? null;

    // Fetch ticket creator email
    $creatorEmail = null;
    if ($ticketCreator) {
        $creatorStmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
        $creatorStmt->bind_param("s", $ticketCreator);
        $creatorStmt->execute();
        $creator = $creatorStmt->get_result()->fetch_assoc();
        $creatorStmt->close();
        $creatorEmail = $creator['email'] ?? null;
    }

    // Send email notifications
    if ($itEmail || $creatorEmail) {
        require '../mail_config.php'; 
        try {
            $mail = getMailer();

            // Add recipients
            if ($itEmail) $mail->addAddress($itEmail);
            if ($creatorEmail) $mail->addAddress($creatorEmail);

            $mail->Subject = "Ticket #$ticketId Assigned to $assignedTo ($ticketPriority)";
            $mail->Body = "
                Hello,

                Ticket #$ticketId has been assigned to $assignedTo.

                Ticket Details:
                - ID: $ticketId
                - Title: $ticketTitle
                - Priority: $ticketPriority

                Please log in to the PSPF CRM to view and take action.

                Regards,
                PSPF CRM
            ";

            if (!$mail->send()) {
                error_log("PHPMailer error: " . $mail->ErrorInfo);
            }
        } catch (Exception $e) {
            error_log("Email failed: " . $e->getMessage());
        }
    }

    header("Location: assign_tasks.php?success=1");
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Assign Tasks - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
   <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body data-bs-theme="light">
<!-- Top Navigation -->

    <!-- Header -->
 <?php include './topnav.php'; ?>

   

       

<div class="container mt-5">
    <div class="container mt-4">

      <div class="settings-header">   
        <h1 class="settings-title">Assign Tickets to Agent</h1>
        <div class="settings-actions">
          <!-- Back Button -->
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
      </div>



    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">Ticket assigned successfully!</div>
    <?php endif; ?>

    <table class="table table-bordered table-striped">
        <thead class="table-dark">
            <tr>
                <th>Ticket</th>
                <th>Assigned To</th>
                <th>Assign</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $tickets->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['title']) ?> (ID: <?= $row['id'] ?>)</td>
                    <td><?= $row['assigned_to'] ? htmlspecialchars($row['assigned_to']) : '<span class="text-muted">Unassigned</span>' ?></td>
                    <td>
                        <form method="POST" class="d-flex gap-2 align-items-center">
                            <input type="hidden" name="ticket_id" value="<?= $row['id'] ?>">
                            <select name="assigned_to" class="form-select form-select-sm w-auto" required>
                                <option value="">Select Agent </option>
                                <?php
                                $agentUsers->data_seek(0);
                                while ($agent = $agentUsers->fetch_assoc()):
                                ?>
                                    <option value="<?= htmlspecialchars($agent['username']) ?>"><?= htmlspecialchars($agent['username']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <button class="btn btn-sm btn-primary">Assign</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Footer -->
   <footer class="footer">
  <div class="footer-container">
    <div class="logout-link">
      <p>&copy; <?= date('Y') ?> All rights reserved to PSPF ICT.</p>
      <p>Version 1.0.0  </p>
      <p><small>Logged in as <?= htmlspecialchars($UserUsername) ?> (<?= getActiveRole() ?>) | <a href="../signin/logout.php">Logout</a></small></p>
    </div>

  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
