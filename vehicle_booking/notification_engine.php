<?php
require_once 'mail_config.php';

/**
 * Send email using PHPMailer
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
 * Build approval link with login redirect
 */
function buildApprovalLink($role, $request_id) {
    $target = "http://192.168.1.16/pspf_crm/vehicle_booking/{$role}_approve_request.php?id={$request_id}";
    return "http://192.168.1.16/pspf_crm/vehicle_booking/login.php?redirect=" . urlencode($target);
}

/**
 * Build request view link for requester with login redirect
 */
function buildRequestLink($request_id) {
    $target = "http://192.168.1.16/pspf_crm/vehicle_booking/user_dashboard.php?id={$request_id}";
    return "http://192.168.1.16/pspf_crm/vehicle_booking/login.php?redirect=" . urlencode($target);
}

/**
 * Format request details
 */
function formatRequestDetails($request) {
    return "
        <strong>Requester:</strong> {$request['requester_name']}<br>
        <strong>Date Requested:</strong> {$request['date_requested']}<br>
        <strong>Date & Time Required:</strong> {$request['date_required']} {$request['time_required']}<br>
        <strong>Destination:</strong> {$request['destination']}<br>
        <strong>Purpose:</strong> {$request['purpose']}<br>
        <strong>Department Selected:</strong> {$request['department']}<br>
    ";
}

/**
 * Send email notifications based on stage
 */
function sendReturnEscalationEmail($conn, $request_id) {

    $stmt = $conn->prepare("
        SELECT 
            vr.request_id,
            vr.expected_return_date,
            u.name AS requester_name,
            u.email AS requester_email,
            d.name AS driver_name,
            d.email AS driver_email
        FROM vehicle_requests vr
        JOIN users u ON u.user_id = vr.requester_id
        LEFT JOIN users d ON d.user_id = vr.driver_id
        WHERE vr.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) return;

    $message = "
        <strong>Vehicle Return Escalation</strong><br><br>
        Request #{$data['request_id']} has exceeded the expected return date.<br>
        Expected Return Date: {$data['expected_return_date']}<br><br>
        Please submit the vehicle return form immediately.
        <br><br>
        <a href='" . "http://192.168.1.16/pspf_crm/vehicle_booking/login.php?redirect=" . urlencode("http://192.168.1.16/pspf_crm/vehicle_booking/return_vehicle.php?id={$request_id}") . "'>
            Submit Return Form
        </a>
    ";

    // Notify requester
    sendMailTo(
        $data['requester_email'],
        "Vehicle Return Escalation Notice",
        $message
    );

    // Notify driver
    if ($data['driver_email']) {
        sendMailTo(
            $data['driver_email'],
            "Vehicle Return Escalation Notice",
            $message
        );
    }
}

function sendRequestEmail($conn, $request_id, $stage) {

    // Fetch request
    $stmt = $conn->prepare("
        SELECT vr.*, 
               u.email AS requester_email, 
               u.name AS requester_name
        FROM vehicle_requests vr
        JOIN users u ON u.user_id = vr.requester_id
        WHERE vr.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) return;

    $requestDetails = formatRequestDetails($request);

    /** ----------------------------------------------------------
     *  CORRECTION:
     *  Supervisor MUST be determined by the department selected
     *  in the REQUEST FORM, not the requester's department.
     * ---------------------------------------------------------- */

    $supervisorStmt = $conn->prepare("
        SELECT email 
        FROM users 
        WHERE role = 'supervisor' 
        AND department = ?
        LIMIT 1
    ");
    $supervisorStmt->execute([$request['department']]);
    $supervisor = $supervisorStmt->fetch(PDO::FETCH_ASSOC);

    // Get driver
    $driver = $conn->query("SELECT email FROM users WHERE role='driver' LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);

    // Get HRM
    $hrm = $conn->query("SELECT email FROM users WHERE role='hrm' LIMIT 1")
                 ->fetch(PDO::FETCH_ASSOC);

    switch ($stage) {

        case 'request_submitted':
            if ($driver) {
                sendMailTo(
                    $driver['email'],
                    "New Vehicle Request Needs Your Approval",
                    "A new vehicle request (#$request_id) has been submitted:<br><br>" .
                    $requestDetails .
                    "<br><a href='".buildApprovalLink('driver', $request_id)."'>Review Request</a>"
                );
            }
            break;

        case 'driver_approved':
            // Notify requester
            sendMailTo(
                $request['requester_email'],
                "Driver Approved Your Request",
                "Your request (#$request_id) has been approved by the driver and awaits supervisor approval.<br><br>" .
                $requestDetails .
                "<br><a href='".buildRequestLink($request_id)."'>View Request</a>"
            );

            // Notify supervisor (selected department, not requester dept)
            if ($supervisor) {
                sendMailTo(
                    $supervisor['email'],
                    "Vehicle Request Awaiting Your Approval",
                    "Vehicle request (#$request_id) requires your approval:<br><br>" .
                    $requestDetails .
                    "<br><a href='".buildApprovalLink('supervisor', $request_id)."'>Approve/Reject</a>"
                );
            }
            break;

        case 'driver_rejected':
            sendMailTo(
                $request['requester_email'],
                "Driver Rejected Your Vehicle Request",
                "Your request (#$request_id) was rejected by the driver.<br>
                 Reason: {$request['rejection_reason']}<br><br>" .
                 $requestDetails .
                 "<a href='".buildRequestLink($request_id)."'>View Request</a>"
            );
            break;

        case 'supervisor_approved':
            // Notify requester
            sendMailTo(
                $request['requester_email'],
                "Supervisor Approved Your Vehicle Request",
                "Your request (#$request_id) was approved by the supervisor and awaits HRM approval.<br><br>" .
                $requestDetails .
                "<a href='".buildRequestLink($request_id)."'>View Request</a>"
            );

            // Notify HRM
            if ($hrm) {
                sendMailTo(
                    $hrm['email'],
                    "Vehicle Request Awaiting HRM Approval",
                    "Vehicle request (#$request_id) requires your approval:<br><br>" .
                    $requestDetails .
                    "<a href='".buildApprovalLink('hrm', $request_id)."'>Approve/Reject</a>"
                );
            }
            break;

        case 'supervisor_rejected':
            sendMailTo(
                $request['requester_email'],
                "Supervisor Rejected Your Vehicle Request",
                "Your request (#$request_id) was rejected by the supervisor.<br>
                 Reason: {$request['rejection_reason']}<br><br>" .
                 $requestDetails .
                 "<a href='".buildRequestLink($request_id)."'>View Request</a>"
            );
            break;

        case 'hrm_approved':
            sendMailTo(
                $request['requester_email'],
                "Your Vehicle Request is Fully Approved",
                "Your request (#$request_id) has been fully approved.<br><br>" .
                $requestDetails .
                "<a href='".buildRequestLink($request_id)."'>View Request</a>"
            );
            break;

        case 'hrm_rejected':
            sendMailTo(
                $request['requester_email'],
                "HRM Rejected Your Vehicle Request",
                "Your request (#$request_id) was rejected by HRM.<br>
                 Reason: {$request['rejection_reason']}<br><br>" .
                 $requestDetails .
                 "<a href='".buildRequestLink($request_id)."'>View Request</a>"
            );
            break;
    }
}

/**
 * Send email to driver when vehicle is returned - signifies ticket closure
 */
function sendVehicleReturnEmail($conn, $request_id) {

    $stmt = $conn->prepare("
        SELECT 
    vr.request_id,
    vr.destination,
    vr.purpose,
    vr.date_required,
    vr.date_requested,
    vr.time_out,
    vr.time_in,
    vr.mileage_out,
    vr.mileage_in,
    u.name AS requester_name,
    u.email AS requester_email,
    u.department,
    d.name AS driver_name,
    d.email AS driver_email,
    s.email AS supervisor_email,
    CONCAT(v.make, ' ', v.model) AS vehicle_name,
    v.registration AS registration_number
FROM vehicle_requests vr
JOIN users u ON u.user_id = vr.requester_id
LEFT JOIN users d ON d.user_id = vr.driver_id
LEFT JOIN users s 
    ON s.role = 'supervisor' 
   AND s.department = vr.department
JOIN vehicles v ON v.vehicle_id = vr.vehicle_id
WHERE vr.request_id = ?
    ");

    $stmt->execute([$request_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        error_log("Return email failed: No data for request ID " . $request_id);
        return false;
    }

    // Calculate duration
    $time_out_obj = DateTime::createFromFormat('H:i:s', $data['time_out']) 
        ?: DateTime::createFromFormat('H:i', $data['time_out']);

    $time_in_obj = DateTime::createFromFormat('H:i:s', $data['time_in']) 
        ?: DateTime::createFromFormat('H:i', $data['time_in']);

    $duration = ($time_out_obj && $time_in_obj)
        ? $time_out_obj->diff($time_in_obj)->format('%h hrs %i mins')
        : 'N/A';

    $mileage_traveled = $data['mileage_in'] - $data['mileage_out'];

    // Email body
    $message = "
        <h3>Vehicle Return Confirmation (Request #{$data['request_id']})</h3>
        <p>The vehicle has been successfully returned and the request is now <strong>closed</strong>.</p>

        <hr>

        <h4>Trip Details</h4>
        <strong>Requester:</strong> {$data['requester_name']}<br>
        <strong>Driver:</strong> {$data['driver_name']}<br>
        <strong>Vehicle:</strong> {$data['vehicle_name']} ({$data['registration_number']})<br>
        <strong>Destination:</strong> {$data['destination']}<br>
        <strong>Purpose:</strong> {$data['purpose']}<br>
        <strong>Date Required:</strong> {$data['date_required']}<br>

        <hr>

        <h4>Return Information (Submitted Form)</h4>
        <strong>Time Out:</strong> {$data['time_out']}<br>
        <strong>Time In:</strong> {$data['time_in']}<br>
        <strong>Duration:</strong> {$duration}<br>
        <strong>Mileage Out:</strong> {$data['mileage_out']} km<br>
        <strong>Mileage In:</strong> {$data['mileage_in']} km<br>
        <strong>Distance Travelled:</strong> {$mileage_traveled} km<br>

        <hr>
        <strong>Status:</strong> CLOSED
    ";

    $subject = "Vehicle Request #{$request_id} Closed";

    // ✅ Send to DRIVER
    if (!empty($data['driver_email'])) {
        sendMailTo($data['driver_email'], $subject, $message);
    }

    // ✅ Send to REQUESTER
    sendMailTo($data['requester_email'], $subject, $message);

    // ✅ Send to SUPERVISOR
    if (!empty($data['supervisor_email'])) {
        sendMailTo($data['supervisor_email'], $subject, $message);
    }

    return true;
}
?>
