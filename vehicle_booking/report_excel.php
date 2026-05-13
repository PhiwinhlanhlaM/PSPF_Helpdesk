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

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=transport_report.csv");

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Requester', 'Department', 'Destination', 'Vehicle', 'Mileage In', 'Mileage Out', 'Trip Mileage']);

$totalMileage = 0;

foreach ($rows as $r) {
    $trip = max(0, $r['mileage_in'] - $r['mileage_out']);
    $totalMileage += $trip;
    fputcsv($out, [
        $r['created_at'],
        $r['requester'],
        $r['department'],
        $r['destination'],
        $r['registration'],
        $r['mileage_in'],
        $r['mileage_out'],
        $trip,
    ]);
}

fputcsv($out, ['', '', '', '', '', '', 'Total Mileage', $totalMileage]);
fclose($out);
