<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

function buildFilters(array $source, array &$params): array {
    $where = [];

    if (!empty($source['from_date'])) {
        $where[] = "vr.created_at >= ?";
        $params[] = $source['from_date']." 00:00:00";
    }
    if (!empty($source['to_date'])) {
        $where[] = "vr.created_at <= ?";
        $params[] = $source['to_date']." 23:59:59";
    }
    if (!empty($source['department'])) {
        $where[] = "vr.department LIKE ?";
        $params[] = "%{$source['department']}%";
    }
    if (!empty($source['destination'])) {
        $where[] = "vr.destination LIKE ?";
        $params[] = "%{$source['destination']}%";
    }
    if (!empty($source['requester'])) {
        $where[] = "u.name LIKE ?";
        $params[] = "%{$source['requester']}%";
    }
    if (!empty($source['vehicle_id'])) {
        $where[] = "vr.vehicle_id = ?";
        $params[] = $source['vehicle_id'];
    }
    if (isset($source['mileage_min']) && $source['mileage_min'] !== '') {
        $where[] = "vr.mileage_out >= ?";
        $params[] = $source['mileage_min'];
    }
    if (isset($source['mileage_max']) && $source['mileage_max'] !== '') {
        $where[] = "vr.mileage_in <= ?";
        $params[] = $source['mileage_max'];
    }

    return $where;
}

$params = [];
$where = buildFilters($_GET, $params);
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
<th>Date</th>
<th>Requester</th>
<th>Department</th>
<th>Destination</th>
<th>Vehicle</th>
<th>Mileage In</th>
<th>Mileage Out</th>
<th>Trip Mileage</th>
</tr>";

$totalMileage = 0;

foreach ($rows as $r) {
    $trip = max(0, $r['mileage_in'] - $r['mileage_out']);
    $totalMileage += $trip;

    echo "<tr>
        <td>{$r['created_at']}</td>
        <td>{$r['requester']}</td>
        <td>{$r['department']}</td>
        <td>{$r['destination']}</td>
        <td>{$r['registration']}</td>
        <td>{$r['mileage_in']}</td>
        <td>{$r['mileage_out']}</td>
        <td>{$trip}</td>
    </tr>";
}

echo "<tr>
    <td colspan='7'><strong>Total Mileage</strong></td>
    <td><strong>{$totalMileage}</strong></td>
</tr>";

echo "</table>";
