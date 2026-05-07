<?php
require '../db.php';
require '../../vendor/autoload.php';
use Dompdf\Dompdf;


// Filters from GET (expand as per your report page)
$filters = [
    'member_type' => $_GET['member_type'] ?? '',
    'region' => $_GET['region'] ?? '',
    'source' => $_GET['source'] ?? '',
    'query_type' => $_GET['query_type'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'status' => $_GET['status'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? ''
];

// Build WHERE clause and bind params
$whereClauses = [];
$params = [];
$types = '';

foreach ($filters as $field => $value) {
    if (in_array($field, ['start_date', 'end_date'])) continue;
    if (!empty($value)) {
        $whereClauses[] = "$field = ?";
        $params[] = $value;
        $types .= 's';
    }
}

// Handle date range filtering
if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
    $whereClauses[] = "query_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];
    $types .= 'ss';
} else if (!empty($filters['start_date'])) {
    $whereClauses[] = "query_date >= ?";
    $params[] = $filters['start_date'];
    $types .= 's';
} else if (!empty($filters['end_date'])) {
    $whereClauses[] = "query_date <= ?";
    $params[] = $filters['end_date'];
    $types .= 's';
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Fetch tickets matching filters (no pagination for full export)
$sql = "SELECT id, title, query_type, region, source, priority, status, phone_number, query_date FROM tickets $whereSql ORDER BY query_date DESC";
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Start output buffer to build HTML for PDF
ob_start();
?>

<!DOCTYPE html>
<html>
<head>
  <style>
    body { font-family: Titillium Web, sans-serif; margin: 20px; }
    h2 { text-align: center; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { border: 1px solid #444; padding: 8px; font-size: 12px; text-align: center; }
    th { background-color: #3D5C80; color: white; }
  </style>
</head>
<body>

<h2>Ticket logging Report</h2>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Title</th>
      <th>Type</th>
      <th>Region</th>
      <th>Source</th>
      <th>Priority</th>
      <th>Status</th>
      <th>Phone</th>
      <th>Date</th>
    </tr>
  </thead>
  <tbody>
    <?php while ($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= 'TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></td>
        <td><?= htmlspecialchars($row['title']) ?></td>
        <td><?= htmlspecialchars($row['query_type']) ?></td>
        <td><?= htmlspecialchars($row['region']) ?></td>
        <td><?= htmlspecialchars($row['source']) ?></td>
        <td><?= htmlspecialchars($row['priority']) ?></td>
        <td><?= htmlspecialchars($row['status']) ?></td>
        <td><?= htmlspecialchars($row['phone_number']) ?></td>
        <td><?= htmlspecialchars($row['query_date']) ?></td>
      </tr>
    <?php endwhile; ?>
  </tbody>
</table>

</body>
</html>

<?php
$html = ob_get_clean();

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape'); // better for wide tables
$dompdf->render();
$dompdf->stream("Filtered_Ticket_Report.pdf", ["Attachment" => false]);

$stmt->close();
$conn->close();
exit;
?>
