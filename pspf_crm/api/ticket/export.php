<?php
require_once 'vendor/autoload.php'; // Dompdf autoload

use Dompdf\Dompdf;

$mysqli = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error);

$assigned_to = $_GET['assigned_to'] ?? '';
$start_date  = $_GET['start_date'] ?? '';
$end_date    = $_GET['end_date'] ?? '';

// Build WHERE clause
$conditions = [];
$params = []; $types = '';

if ($assigned_to) {
    $conditions[] = "assigned_to = ?";
    $params[] = $assigned_to;
    $types .= 's';
}
if ($start_date) {
    $conditions[] = "query_date >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if ($end_date) {
    $conditions[] = "query_date <= ?";
    $params[] = $end_date;
    $types .= 's';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$sql = "SELECT status, COUNT(*) AS cnt FROM tickets $where GROUP BY status";
$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$counts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();

$labels = ['Open', 'In Progress', 'Closed', 'Escalated'];
$data = array_fill_keys($labels, 0);
foreach ($counts as $row) {
    if (in_array($row['status'], $labels)) {
        $data[$row['status']] = (int)$row['cnt'];
    }
}

$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@import url("https://fonts.googleapis.com/css2?family=Titillium+Web&display=swap");
body {
    font-family: "Titillium Web", sans-serif;
    margin: 30px;
    color: #333;
}
h1 {
    text-align: center;
    margin-bottom: 20px;
}
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}
th, td {
    border: 1px solid #000;
    padding: 8px;
    text-align: center;
    font-size: 14px;
}
th {
    background-color: #406997;
    color: white;
}
</style>
</head>
<body>
    <h1>Ticket Status Summary</h1>
    <table>
        <thead>
            <tr>';
foreach ($labels as $status) {
    $html .= "<th>" . htmlspecialchars(strtoupper($status)) . "</th>";
}
$html .= '
            </tr>
        </thead>
        <tbody>
            <tr>';
foreach ($labels as $status) {
    $html .= "<td>" . (int)$data[$status] . "</td>";
}
$html .= '
            </tr>
        </tbody>
    </table>
</body>
</html>';

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('ticket_status_summary.pdf', ["Attachment" => 0]);
exit;  // Make sure script stops after streaming PDF
