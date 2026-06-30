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
 * Format request details as HTML
 */
function formatRequestDetails($request) {
    return "
        <strong>Requester:</strong> {$request['requester_name']}<br>
        <strong>Date Requested:</strong> {$request['date_requested']}<br>
        <strong>Date &amp; Time Required:</strong> {$request['date_required']} {$request['time_required']}<br>
        <strong>Destination:</strong> {$request['destination']}<br>
        <strong>Purpose:</strong> {$request['purpose']}<br>
        <strong>Department:</strong> {$request['department']}<br>
    ";
}

/**
 * Send an email to all active supervisors in a given department.
 * Every supervisor in that department sees the request — whoever is available can act.
 */
function notifyAllSupervisors($conn, $department, $subject, $message) {
    $stmt = $conn->prepare("SELECT email FROM users WHERE role = 'supervisor' AND active = 1 AND department = ?");
    $stmt->execute([$department]);
    $supervisors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($supervisors as $email) {
        sendMailTo($email, $subject, $message);
    }
}

/**
 * Send an email to all active drivers
 

function notifyAllDrivers($conn, $subject, $message) {
    $driver_stmt = $conn->prepare("SELECT email FROM users WHERE role = 'driver' AND active = 1");
    $driver_stmt->execute();
    $drivers = $driver_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($drivers as $email) {
        sendMailTo($email, $subject, $message);

}
}
*/

/**
 * Send email notifications based on workflow stage
 */
function sendRequestEmail($conn, $request_id, $stage) {

    // Fetch full request + requester details
    $stmt = $conn->prepare("
        SELECT vr.*,
               u.email AS requester_email,
               u.name  AS requester_name
        FROM vehicle_requests vr
        JOIN users u ON u.user_id = vr.requester_id
        WHERE vr.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        error_log("sendRequestEmail: no request found for id=$request_id");
        return;
    }

    $requestDetails = formatRequestDetails($request);

    // Driver (first active driver found)
    $driver = $conn->query("SELECT email FROM users WHERE role = 'driver' AND active = 1")
                   ->fetch(PDO::FETCH_ASSOC);

    // HRM (first active HRM found)
    $hrm = $conn->query("SELECT email FROM users WHERE role = 'hrm' AND active = 1")
                 ->fetch(PDO::FETCH_ASSOC);

    switch ($stage) {

        // ── New request submitted ──────────────────────────────────────
        case 'request_submitted':

            // Notify driver
			sendMailTo(
                    $driver['email'],
            //notifyAllDrivers(
            //   $conn
             
                    "New Vehicle Request – Action Required (#$request_id)",
                    "A new vehicle request has been submitted and requires your availability confirmation.<br><br>" .
                    $requestDetails .
                    "<br><a href='" . buildApprovalLink('driver', $request_id) . "'>Review &amp; Confirm Availability</a>"
               );
            

            // Notify supervisors in the request's department — FYI, whoever is available can act when driver confirms
          //  notifyAllSupervisors(
               // $conn,
              //  $request['department'],
             //   "New Vehicle Request Submitted (#$request_id)",
              //  "A vehicle request has been submitted and is pending driver confirmation.<br><br>" .
              //  $requestDetails .
              //  "<br>You will receive a separate email once the driver confirms and your approval is required."
          //  );

            // Confirm receipt to the requester
            sendMailTo(
                $request['requester_email'],
                "Vehicle Request Received (#$request_id)",
                "Your vehicle request has been submitted successfully and is now pending driver availability.<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Your Request</a>"
            );
            break;

        // ── Driver approved → now needs supervisor sign-off ───────────
        case 'driver_approved':

            // Notify requester
            sendMailTo(
                $request['requester_email'],
                "Driver Confirmed – Awaiting Supervisor Approval (#$request_id)",
                "The driver has confirmed availability for your request. It now awaits supervisor approval.<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Request</a>"
            );

            // Notify supervisors in the request's department — action required, first available approves
            notifyAllSupervisors(
                $conn,
                $request['department'],
                "Vehicle Request Requires Supervisor Approval (#$request_id)",
                "A vehicle request has been confirmed by the driver and requires supervisor approval.<br><br>" .
                $requestDetails .
                "<br><a href='" . buildApprovalLink('supervisor', $request_id) . "'>Approve / Reject</a>"
            );
            break;

        // ── Driver rejected ────────────────────────────────────────────
        case 'driver_rejected':
            sendMailTo(
                $request['requester_email'],
                "Driver Unavailable – Request Rejected (#$request_id)",
                "Unfortunately the driver is unable to fulfil your request at this time.<br>" .
                "Reason: {$request['rejection_reason']}<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Request</a>"
            );
            break;

        // ── Supervisor approved → HRM sign-off ────────────────────────
        case 'supervisor_approved':

            sendMailTo(
                $request['requester_email'],
                "Supervisor Approved – Awaiting HRM (#$request_id)",
                "Your request has been approved by the supervisor and is now awaiting HRM authorisation.<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Request</a>"
            );

            if ($hrm) {
                sendMailTo(
                    $hrm['email'],
                    "Vehicle Request Requires HRM Authorisation (#$request_id)",
                    "A vehicle request has been approved by the supervisor and requires your authorisation.<br><br>" .
                    $requestDetails .
                    "<br><a href='" . buildApprovalLink('hrm', $request_id) . "'>Approve / Reject</a>"
                );
            }
            break;

        // ── Supervisor rejected ────────────────────────────────────────
        case 'supervisor_rejected':
            sendMailTo(
                $request['requester_email'],
                "Supervisor Rejected Your Request (#$request_id)",
                "Your vehicle request was not approved by the supervisor.<br>" .
                "Reason: {$request['rejection_reason']}<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Request</a>"
            );
            break;

        // ── HRM approved (fully authorised) ───────────────────────────
        case 'hrm_approved':
            sendMailTo(
                $request['requester_email'],
                "Request Fully Approved (#$request_id)",
                "Your vehicle request has been fully authorised. Please proceed as planned.<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Request</a>"
            );
            break;

        // ── HRM rejected ──────────────────────────────────────────────
        case 'hrm_rejected':
            sendMailTo(
                $request['requester_email'],
                "HRM Rejected Your Request (#$request_id)",
                "Your vehicle request was not authorised by HRM.<br>" .
                "Reason: {$request['rejection_reason']}<br><br>" .
                $requestDetails .
                "<br><a href='" . buildRequestLink($request_id) . "'>View Request</a>"
            );
            break;

        default:
            error_log("sendRequestEmail: unknown stage '$stage' for request_id=$request_id");
    }
}

/**
 * Escalation email when vehicle is overdue for return
 */
function sendReturnEscalationEmail($conn, $request_id) {

    $stmt = $conn->prepare("
        SELECT
            vr.request_id,
            vr.expected_return_date,
            u.name  AS requester_name,
            u.email AS requester_email,
            d.name  AS driver_name,
            d.email AS driver_email
        FROM vehicle_requests vr
        JOIN users u ON u.user_id = vr.requester_id
        LEFT JOIN users d ON d.user_id = vr.driver_id
        WHERE vr.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) return;

    $returnUrl = "http://192.168.1.16/pspf_crm/vehicle_booking/login.php?redirect=" .
                 urlencode("http://192.168.1.16/pspf_crm/vehicle_booking/return_vehicle.php?id={$request_id}");

    $message = "
        <strong>Vehicle Return Escalation – Request #{$data['request_id']}</strong><br><br>
        This request has exceeded its expected return date of <strong>{$data['expected_return_date']}</strong>.<br>
        Please submit the vehicle return form immediately.<br><br>
        <a href='{$returnUrl}'>Submit Return Form</a>
    ";

    sendMailTo($data['requester_email'], "Action Required: Vehicle Overdue (#$request_id)", $message);

    if (!empty($data['driver_email'])) {
        sendMailTo($data['driver_email'], "Action Required: Vehicle Overdue (#$request_id)", $message);
    }
}

/**
 * Closure email sent to all parties when vehicle is returned
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
            u.name  AS requester_name,
            u.email AS requester_email,
            vr.department,
            d.name  AS driver_name,
            d.email AS driver_email,
            CONCAT(v.make, ' ', v.model) AS vehicle_name,
            v.registration AS registration_number
        FROM vehicle_requests vr
        JOIN users u ON u.user_id = vr.requester_id
        LEFT JOIN users d ON d.user_id = vr.driver_id
        JOIN vehicles v ON v.vehicle_id = vr.vehicle_id
        WHERE vr.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        error_log("sendVehicleReturnEmail: no data for request_id=$request_id");
        return false;
    }

    $time_out_obj = DateTime::createFromFormat('H:i:s', $data['time_out'])
        ?: DateTime::createFromFormat('H:i', $data['time_out']);
    $time_in_obj  = DateTime::createFromFormat('H:i:s', $data['time_in'])
        ?: DateTime::createFromFormat('H:i', $data['time_in']);

    $duration = ($time_out_obj && $time_in_obj)
        ? $time_out_obj->diff($time_in_obj)->format('%h hrs %i mins')
        : 'N/A';

    $mileage_traveled = $data['mileage_in'] - $data['mileage_out'];

    $message = "
        <h3>Vehicle Return Confirmation – Request #{$data['request_id']}</h3>
        <p>The vehicle has been returned and this request is now <strong>closed</strong>.</p>
        <hr>
        <h4>Trip Details</h4>
        <strong>Requester:</strong> {$data['requester_name']}<br>
        <strong>Driver:</strong> {$data['driver_name']}<br>
        <strong>Vehicle:</strong> {$data['vehicle_name']} ({$data['registration_number']})<br>
        <strong>Destination:</strong> {$data['destination']}<br>
        <strong>Purpose:</strong> {$data['purpose']}<br>
        <strong>Date Required:</strong> {$data['date_required']}<br>
        <hr>
        <h4>Return Information</h4>
        <strong>Time Out:</strong> {$data['time_out']}<br>
        <strong>Time In:</strong> {$data['time_in']}<br>
        <strong>Duration:</strong> {$duration}<br>
        <strong>Mileage Out:</strong> {$data['mileage_out']} km<br>
        <strong>Mileage In:</strong> {$data['mileage_in']} km<br>
        <strong>Distance Travelled:</strong> {$mileage_traveled} km<br>
        <hr>
        <strong>Status:</strong> CLOSED
    ";

    $subject = "Vehicle Request #{$request_id} – Closed";

    sendMailTo($data['requester_email'], $subject, $message);

    if (!empty($data['driver_email'])) {
        sendMailTo($data['driver_email'], $subject, $message);
    }

    // Notify supervisors in the request's department of the closure
    notifyAllSupervisors($conn, $data['department'], $subject, $message);

    return true;
}
?>