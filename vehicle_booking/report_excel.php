<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require_once __DIR__ . '/trip_helpers.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$params = [];
$where = vbReportFilters($_GET, $params);
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

$sql = "
    SELECT vr.*, u.name AS requester, v.registration
    FROM vehicle_requests vr
    LEFT JOIN users u ON u.user_id = vr.requester_id
    LEFT JOIN vehicles v ON v.vehicle_id = vr.vehicle_id
    $whereSQL
    ORDER BY vr.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=transport_report.xls");

echo "<table border='1'>
<tr>
<th>Request #</th>
<th>Date Required</th>
<th>Time Required</th>
<th>Requester</th>
<th>Department</th>
<th>Destination</th>
<th>Vehicle</th>
<th>Status</th>
<th>Mileage In</th>
<th>Mileage Out</th>
<th>Trip Mileage</th>
</tr>";

$totalMileage = 0;

foreach ($rows as $r) {
    $trip = max(0, (int)$r['mileage_in'] - (int)$r['mileage_out']);
    $totalMileage += $trip;
    $c = array_map(
        fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'),
        $r + ['status_label' => vbStatusLabel(vbTripStatus($r)), 'time_label' => vbTime($r['time_required'])]
    );

    echo "<tr>
        <td>{$c['request_id']}</td>
        <td>{$c['date_required']}</td>
        <td>{$c['time_label']}</td>
        <td>{$c['requester']}</td>
        <td>{$c['department']}</td>
        <td>{$c['destination']}</td>
        <td>{$c['registration']}</td>
        <td>{$c['status_label']}</td>
        <td>{$c['mileage_in']}</td>
        <td>{$c['mileage_out']}</td>
        <td>{$trip}</td>
    </tr>";
}

echo "<tr>
    <td colspan='10'><strong>Total Mileage</strong></td>
    <td><strong>{$totalMileage}</strong></td>
</tr>";

echo "</table>";
