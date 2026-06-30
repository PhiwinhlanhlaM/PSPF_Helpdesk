<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'agent') {
    http_response_code(403);
    exit('Access denied.');
}

require __DIR__ . '/vendor/autoload.php';  // Dompdf autoload

use Dompdf\Dompdf;

$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$assignedOnly = isset($_GET['assigned']) && $_GET['assigned'] === 'me';
$username = $_SESSION['user']['username'];

$searchParam = "%" . $filter . "%";
$params = [$searchParam, $searchParam];
$sql = "
    SELECT t.id, t.title, t.status, t.assigned_to, u.department, t.created_by, t.query_date, t.priority
    FROM tickets t
    JOIN users u ON t.created_by = u.username
    WHERE (CAST(t.id AS CHAR) LIKE ? OR t.title LIKE ?)
";
if ($assignedOnly) {
    $sql .= " AND t.assigned_to = ?";
    $params[] = $username;
}

$stmt = $conn->prepare($sql);
if ($assignedOnly) {
    $stmt->bind_param("sss", ...$params);
} else {
    $stmt->bind_param("ss", ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Build HTML for PDF
$html = '<h2 style="text-align:center;">Tickets Export</h2>';
$html .= '<table border="1" cellspacing="0" cellpadding="5" width="100%">
<thead>
<tr>
    <th>ID</th>
    <th>Title</th>
    <th>Status</th>
    <th>Assigned To</th>
    <th>Department</th>
    <th>Created By</th>
    <th>Query Date</th>
    <th>Priority</th>
</tr>
</thead><tbody>';

while ($row = $result->fetch_assoc()) {
    $html .= '<tr>
        <td>' . 'TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT) . '</td>
        <td>' . htmlspecialchars($row['title']) . '</td>
        <td>' . htmlspecialchars($row['status']) . '</td>
        <td>' . htmlspecialchars($row['assigned_to']) . '</td>
        <td>' . htmlspecialchars($row['department']) . '</td>
        <td>' . htmlspecialchars($row['created_by']) . '</td>
        <td>' . htmlspecialchars($row['query_date']) . '</td>
        <td>' . htmlspecialchars($row['priority']) . '</td>
    </tr>';
}
$html .= '</tbody></table>';

$stmt->close();
$conn->close();

// Create PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Output the PDF
$dompdf->stream("tickets_export.pdf", ["Attachment" => true]);
exit;
