<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require '../mail_config.php'; // Uses getMailer()
require_once '../db.php';


// Get POST data
$data = json_decode(file_get_contents("php://input"));
if (!$data || !isset($data->ticket_id)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing ticket ID."]);
    $conn->close();
    exit();
}

$ticket_id = intval($data->ticket_id);
$assigned_to = isset($data->assigned_to) ? trim($data->assigned_to) : null;

// Fetch ticket + both user emails
$stmt = $conn->prepare("
    SELECT 
        t.id, 
        t.title, 
        t.priority, 
        t.query_date, 
        t.created_by, 
        t.assigned_to, 
        t.member_type, 
        t.source,
        creator.email AS creator_email,
        assignee.email AS assignee_email
    FROM tickets t
    JOIN users creator ON t.created_by = creator.username
    LEFT JOIN users assignee ON t.assigned_to = assignee.username
    WHERE t.id = ?
");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Ticket not found."]);
    $conn->close();
    exit();
}

// Assign emails
$creator_email = $ticket['creator_email'];
$it_email = $ticket['assignee_email'];
$ticket_no = 'TCK-' . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT);

// Build and send email
try {
    $mail = getMailer();

    if (!empty($creator_email)) $mail->addAddress($creator_email);
    if (!empty($it_email)) $mail->addAddress($it_email);

    // Fallback if no recipient found
    if (empty($creator_email) && empty($it_email)) {
        $mail->addAddress("you@example.com");
        error_log("⚠️ No valid creator or assignee email found, using fallback.");
    }

    if ($assigned_to) {
        $mail->Subject = "New Ticket Assigned: $ticket_no";
        $mail->Body = "Dear {$ticket['created_by']},
		\n\nYour Ticket has been assigned to staff ($assigned_to).
		\n\nTicket Details:
		\n- Ticket ID: $ticket_no
		\n- Title: {$ticket['title']}
		\n- Priority: {$ticket['priority']}
		\n- Assigned To: $assigned_to
		\n- Created Date: {$ticket['query_date']}
		\n\nRegards,
		\nPSPF CRM";
    } else {
        $mail->Subject = "PSPF CRM Ticket Confirmation - $ticket_no";
        $mail->Body = "Dear {$ticket['created_by']},
		\n\nYour Ticket has been successfully logged.
		\n\nTicket Details:
		\n- Ticket ID: $ticket_no
		\n- Status: Open\n- Team: {$ticket['member_type']}
		\n- Created Date: {$ticket['query_date']}
		\n\nRegards,
		\nPSPF CRM";
    }

    if (!$mail->send()) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        echo json_encode(["success" => false, "message" => "Failed to send email."]);
    } else {
        echo json_encode(["success" => true, "message" => "Notification email sent."]);
    }
} catch (Exception $e) {
    error_log("Email sending failed: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Error while sending email."]);
}

$conn->close();
?>
