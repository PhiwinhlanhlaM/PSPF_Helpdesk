<?php
require 'db.php';

if (!isset($_GET['request_id'])) {
    echo "Missing request_id";
    exit;
}

$request_id = $_GET['request_id'];

$stmt = $conn->prepare("
    SELECT vr.*, 
           u.name AS requester_name, 
           u.department,
           v.registration
    FROM vehicle_requests vr
    LEFT JOIN users u ON vr.requester_id = u.user_id
    LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
    WHERE vr.request_id = ?
");

$stmt->execute([$request_id]);

$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo "No record found";
    exit;
}
?>

<div class="container">
    <p><strong>Requester:</strong> <?= htmlspecialchars($data['requester_name']) ?></p>
    <p><strong>Department:</strong> <?= htmlspecialchars($data['department']) ?></p>
    <p><strong>Destination:</strong> <?= htmlspecialchars($data['destination']) ?></p>
    <p><strong>Date Required:</strong> <?= htmlspecialchars($data['date_required']) ?></p>
    <p><strong>Expected Return:</strong> <?= htmlspecialchars($data['expected_return_date']) ?></p>
    <p><strong>Purpose:</strong> <?= htmlspecialchars($data['purpose']) ?></p>
    <p><strong>Assigned Vehicle:</strong> <?= htmlspecialchars($data['registration'] ?? 'Not Assigned') ?></p>
</div>
