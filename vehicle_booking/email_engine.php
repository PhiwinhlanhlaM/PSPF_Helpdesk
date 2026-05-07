<?php
require_once '../vehicle_booking/db.php';
require_once '../vehicle_booking/mail_config.php';

/**
 * Sends an email using the mail_config.php settings
 */
function sendMailTo($email, $subject, $message) {
    $mail = getMailer();
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = nl2br($message);

    return $mail->send();
}

/**
 * Builds approval link for a role
 */
function buildApprovalLink($role, $request_id) {
    return "http://localhost/pspf_crm/vehicle_booking/{$role}_approve_request.php?id={$request_id}";
}

/**
 * Builds requester link
 */
function buildRequesterLink($request_id) {
    return "http://localhost/pspf_crm/vehicle_booking/user_dashboard.php?id={$request_id}";
}

/**
 * Main function to send emails based on request stage
 * @param PDO $conn
 * @param int $request_id
 * @param string $stage ('driver','supervisor','hrm')
 * @param string $action ('approved','rejected', optional)
 */
function sendRequestEmail(PDO $conn, $request_id, $stage, $action = null) {
    // Fetch request details along with requester email
    $sql = "SELECT vr.*, u.email AS requester_email, u.name AS requester_name, u.department
            FROM vehicle_requests vr
            JOIN users u ON vr.requester_id = u.user_id
            WHERE vr.request_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) return false;

    $requester_email = $request['requester_email'];
    $department = $request['department'];

    switch ($stage) {
        // ---------------- DRIVER ----------------
        case 'driver':
            // Notify requester
            sendMailTo(
                $requester_email,
                "Your Vehicle Request #$request_id Has Been Reviewed",
                "Your request has been approved by the driver. It is now awaiting Supervisor approval.<br>
                 <a href='" . buildRequesterLink($request_id) . "'>View Your Request</a>"
            );

            // Notify Driver (if you want the driver to know a request requires their approval)
            sendMailTo(
                $request['driver_id'] ? $request['driver_id'] : 'driver@example.com',
                "New Vehicle Request Requires Your Approval",
                "A vehicle request #$request_id requires your approval.<br>
                 <a href='" . buildApprovalLink('driver', $request_id) . "'>Approve or Reject Request</a>"
            );

            // Notify Supervisor
            $sup_stmt = $conn->prepare("SELECT email FROM users WHERE role='supervisor' AND department=? LIMIT 1");
            $sup_stmt->execute([$department]);
            $supervisor = $sup_stmt->fetch(PDO::FETCH_ASSOC);

            if ($supervisor) {
                sendMailTo(
                    $supervisor['email'],
                    "Vehicle Request #$request_id Awaiting Your Approval",
                    "A vehicle request needs your approval.<br>
                     <a href='" . buildApprovalLink('supervisor', $request_id) . "'>Approve or Reject Request</a>"
                );
            }
            break;

        // ---------------- SUPERVISOR ----------------
        case 'supervisor':
            // Notify requester
            sendMailTo(
                $requester_email,
                "Your Vehicle Request #$request_id Has Been Reviewed",
                "Your request has been approved by the supervisor. It is now awaiting HRM approval.<br>
                 <a href='" . buildRequesterLink($request_id) . "'>View Your Request</a>"
            );

            // Notify HRM
            $hrm_stmt = $conn->prepare("SELECT email FROM users WHERE role='hrm' LIMIT 1");
            $hrm_stmt->execute();
            $hrm = $hrm_stmt->fetch(PDO::FETCH_ASSOC);

            if ($hrm) {
                sendMailTo(
                    $hrm['email'],
                    "Vehicle Request #$request_id Awaiting HRM Approval",
                    "A vehicle request needs your approval.<br>
                     <a href='" . buildApprovalLink('hrm', $request_id) . "'>Approve or Reject Request</a>"
                );
            }
            break;

        // ---------------- HRM ----------------
        case 'hrm':
            if ($action === 'approved') {
                sendMailTo(
                    $requester_email,
                    "Vehicle Request #$request_id Approved",
                    "Your vehicle request has been fully approved.<br>
                     <a href='" . buildRequesterLink($request_id) . "'>View Your Request</a>"
                );
            } elseif ($action === 'rejected') {
                sendMailTo(
                    $requester_email,
                    "Vehicle Request #$request_id Rejected",
                    "Your vehicle request has been rejected by HRM.<br>
                     <a href='" . buildRequesterLink($request_id) . "'>View Your Request</a>"
                );
            }
            break;

        default:
            return false;
    }

    return true;
}
