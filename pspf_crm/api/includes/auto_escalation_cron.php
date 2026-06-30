<?php
// ======================================================
// AUTO ESCALATION CRON (WITH EMAIL NOTIFICATION)
// ======================================================

require_once '../db.php';
require_once '../mail_config.php'; // must contain getMailer()

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");

if ($conn->connect_error) {
    exit("Database connection failed\n");
}

$thresholds = [
    'High'   => 24,
    'Medium' => 72,
    'Low'    => 120
];

// Fetch open or in-progress tickets
$sql = "
    SELECT id, priority, query_date, status, assigned_to, created_by
    FROM tickets
    WHERE status IN ('Open', 'In Progress')
";

$result = $conn->query($sql);

while ($ticket = $result->fetch_assoc()) {

    $priority = $ticket['priority'];
    $created  = strtotime($ticket['query_date']);
    $ticketId = $ticket['id'];

    if (!isset($thresholds[$priority])) continue;

    $slaHours = $thresholds[$priority];
    $deadline = $created + ($slaHours * 3600);

    if (time() >= $deadline) {

        // Double check not already escalated
        $check = $conn->prepare("SELECT status FROM tickets WHERE id=?");
        $check->bind_param("i", $ticketId);
        $check->execute();
        $current = $check->get_result()->fetch_assoc();
        $check->close();

        if (strtolower($current['status']) !== 'escalated') {

            $conn->begin_transaction();

            try {

                // Update ticket status
                $update = $conn->prepare("
                    UPDATE tickets 
                    SET status='Escalated', updated_at=NOW(), last_updated_by='SYSTEM'
                    WHERE id=?
                ");
                $update->bind_param("i", $ticketId);
                $update->execute();
                $update->close();

                // Insert escalation record
                $reason = "Automatically escalated due to SLA timeout.";
                $insert = $conn->prepare("
                    INSERT INTO ticket_escalations 
                    (ticket_id, escalation_reason, escalated_by, escalated_at)
                    VALUES (?, ?, 'SYSTEM', NOW())
                ");
                $insert->bind_param("is", $ticketId, $reason);
                $insert->execute();
                $insert->close();

                // Insert status history log
                $history = $conn->prepare("
                    INSERT INTO ticket_status_logs
                    (ticket_id, changed_by, old_status, new_status, change_reason)
                    VALUES (?, 'SYSTEM', ?, 'Escalated', ?)
                ");
                $history->bind_param("iss", $ticketId, $current['status'], $reason);
                $history->execute();
                $history->close();

                $conn->commit();

                echo "Ticket {$ticketId} escalated.\n";

                // ======================================================
                // SEND EMAIL NOTIFICATIONS
                // ======================================================
// ======================================================
// SEND EMAIL NOTIFICATIONS (CLEAN VERSION)
// ======================================================

try {

    $mailer = getMailer();
    $mailer->isHTML(false);

    // ------------------------------
    // 1?Notify Assigned Agent(s)
    // ------------------------------

    if (!empty($ticket['assigned_to'])) {

        // Convert comma-separated emails into array
        $assignedEmails = array_unique(
            array_filter(
                array_map('trim', explode(',', $ticket['assigned_to']))
            )
        );

        foreach ($assignedEmails as $email) {

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mailer->addAddress($email);
            }
        }

        if (count($mailer->getToAddresses()) > 0) {

            $mailer->Subject = "URGENT: Ticket #{$ticketId} Auto Escalated";

            $mailer->Body = "Hello,

Ticket #{$ticketId} has been automatically escalated due to SLA timeout.

Priority: {$priority}
Original Status: {$current['status']}

Please review immediately.

--
PSPF CRM System";

            $mailer->send();
            $mailer->clearAddresses();
        }
    }

    // ------------------------------
    // 2?Notify Ticket Creator
    // ------------------------------

    if (!empty($ticket['created_by'])) {

        $creatorStmt = $conn->prepare("
            SELECT email 
            FROM users 
            WHERE username = ?
        ");

        $creatorStmt->bind_param("s", $ticket['created_by']);
        $creatorStmt->execute();
        $creator = $creatorStmt->get_result()->fetch_assoc();
        $creatorStmt->close();

        if (!empty($creator['email']) && filter_var($creator['email'], FILTER_VALIDATE_EMAIL)) {

            $mailer->addAddress($creator['email']);

            $mailer->Subject = "Your Ticket #{$ticketId} Has Been Escalated";

            $mailer->Body = "Hello,

Your ticket #{$ticketId} has been escalated due to SLA timeout.

Our team has been notified and will prioritize it.

--
PSPF CRM System";

            $mailer->send();
            $mailer->clearAddresses();
        }
    }

} catch (Exception $mailException) {

    // Log email errors but DO NOT break cron execution
    error_log("Email Error for Ticket {$ticketId}: " . $mailException->getMessage());
}
        }
    }
}

$conn->close();