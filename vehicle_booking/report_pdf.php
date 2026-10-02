<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require_once __DIR__ . '/trip_helpers.php';
require '../vendor/autoload.php';

use Dompdf\Dompdf;

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

/* Filters (shared with the report page and Excel export) */
$params = [];
$where = vbReportFilters($_GET, $params);
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
<th>Request #</th><th>Date Required</th><th>Time Required</th><th>Requester</th><th>Department</th><th>Destination</th>
<th>Vehicle</th><th>Status</th><th>Mileage In</th><th>Mileage Out</th><th>Trip Mileage</th>
</tr>";

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $trip = max(0, (int)$r['mileage_in'] - (int)$r['mileage_out']);
    $totalMileage += $trip;
    $c = array_map(
        fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'),
        $r + ['status_label' => vbStatusLabel(vbTripStatus($r)), 'time_label' => vbTime($r['time_required'])]
    );

    $html .= "<tr>
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

$html .= "<tr>
<td colspan='10'><strong>Total Mileage</strong></td>
<td><strong>{$totalMileage} km</strong></td>
</tr></table>";

/* Render PDF */
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("transport_report.pdf", ["Attachment" => true]);
