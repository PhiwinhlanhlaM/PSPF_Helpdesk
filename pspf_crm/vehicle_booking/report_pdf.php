<?php
session_start();
require '../vehicle_booking/db.php';
require '../vendor/autoload.php';

use Dompdf\Dompdf;

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

/* Build filters (SAME AS EXCEL) */
$where = [];
$params = [];

if (!empty($_GET['from_date'])) {
    $where[] = "vr.created_at >= ?";
    $params[] = $_GET['from_date'] . " 00:00:00";
}

if (!empty($_GET['to_date'])) {
    $where[] = "vr.created_at <= ?";
    $params[] = $_GET['to_date'] . " 23:59:59";
}

if (!empty($_GET['requester'])) {
    $where[] = "u.name LIKE ?";
    $params[] = "%" . $_GET['requester'] . "%";
}

if (!empty($_GET['department'])) {
    $where[] = "vr.department LIKE ?";
    $params[] = "%" . $_GET['department'] . "%";
}

if (!empty($_GET['destination'])) {
    $where[] = "vr.destination LIKE ?";
    $params[] = "%" . $_GET['destination'] . "%";
}

if (!empty($_GET['vehicle_id'])) {
    $where[] = "vr.vehicle_id = ?";
    $params[] = $_GET['vehicle_id'];
}

if (isset($_GET['mileage_min']) && $_GET['mileage_min'] !== '') {
    $where[] = "vr.mileage_out >= ?";
    $params[] = $_GET['mileage_min'];
}

if (isset($_GET['mileage_max']) && $_GET['mileage_max'] !== '') {
    $where[] = "vr.mileage_in <= ?";
    $params[] = $_GET['mileage_max'];
}

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

/* Query */
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

/* Build HTML */
$totalMileage = 0;
$html = "<h3>Transport Report</h3><table border='1' width='100%' cellspacing='0' cellpadding='5'>
<tr>
<th>Date</th><th>Requester</th><th>Department</th><th>Destination</th>
<th>Vehicle</th><th>Mileage In</th><th>Mileage Out</th><th>Trip Mileage</th>
</tr>";

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $trip = max(0, $r['mileage_in'] - $r['mileage_out']);
    $totalMileage += $trip;

    $html .= "<tr>
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

$html .= "<tr>
<td colspan='7'><strong>Total Mileage</strong></td>
<td><strong>{$totalMileage} km</strong></td>
</tr></table>";

/* Render PDF */
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("transport_report.pdf", ["Attachment" => true]);
