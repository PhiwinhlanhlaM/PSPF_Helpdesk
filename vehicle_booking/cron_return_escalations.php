<?php
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';

$escalations = $conn->query("
    SELECT 
        vr.request_id,
        vr.requester_id,
        u.email AS requester_email,
        d.email AS driver_email
    FROM vehicle_requests vr
    JOIN users u ON u.user_id = vr.requester_id
    JOIN users d ON d.role = 'driver'
    WHERE 
        vr.status = 'approved'
        AND vr.actual_return_date IS NULL
        AND vr.expected_return_date < NOW() - INTERVAL 72 HOUR
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($escalations as $row) {

    // Log escalation (prevent duplicates if needed)
    $conn->prepare("
        INSERT IGNORE INTO ticket_escalations 
        (request_id, reason, created_at)
        VALUES (?, 'Return form overdue (72 hours)', NOW())
    ")->execute([$row['request_id']]);

    // Notify requester
    sendMailTo(
        $row['requester_email'],
        "URGENT: Vehicle Return Overdue",
        "Your vehicle return form for request #{$row['request_id']} is overdue.
         Please submit the return form immediately."
    );

    // Notify driver
    sendMailTo(
        $row['driver_email'],
        "Vehicle Return Escalation",
        "Vehicle request #{$row['request_id']} has not been returned after 72 hours."
    );
}
