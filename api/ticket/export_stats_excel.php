<?php
session_start();

require '../db.php';


// Collect filters (same logic as report_queries.php)
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

// Build WHERE clause dynamically
$whereClauses = ["1=1"];
$params = [];
$types = "";

foreach ($filters as $field => $value) {
    if (in_array($field, ['start_date', 'end_date'])) continue;
    if (!empty($value)) {
        $whereClauses[] = "$field = ?";
        $params[] = $value;
        $types .= "s";
    }
}

// Handle date range
if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
    $whereClauses[] = "query_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];
    $types .= "ss";
} else if (!empty($filters['start_date'])) {
    $whereClauses[] = "query_date >= ?";
    $params[] = $filters['start_date'];
    $types .= "s";
} else if (!empty($filters['end_date'])) {
    $whereClauses[] = "query_date <= ?";
    $params[] = $filters['end_date'];
    $types .= "s";
}

$whereSql = implode(" AND ", $whereClauses);

// Prepare query
$sql = "SELECT id, title, query_type, region, source, priority, status, phone_number, query_date FROM tickets WHERE $whereSql ORDER BY query_date DESC";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Set headers to force download as Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Helpdesk_Report_" . date('Y-m-d') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

// Output Excel table
echo "<table border='1'>";
echo "<tr style='background-color:#198754; color:white; font-weight:bold;'>
        <th>ID</th>
        <th>Title</th>
        <th>Query Type</th>
        <th>Region</th>
        <th>Source</th>
        <th>Priority</th>
        <th>Status</th>
        <th>Phone</th>
        <th>Date</th>
      </tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>TCK-" . str_pad($row['id'], 6, '0', STR_PAD_LEFT) . "</td>";
    echo "<td>" . htmlspecialchars($row['title']) . "</td>";
    echo "<td>" . htmlspecialchars($row['query_type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['region']) . "</td>";
    echo "<td>" . htmlspecialchars($row['source']) . "</td>";
    echo "<td>" . htmlspecialchars($row['priority']) . "</td>";
    echo "<td>" . htmlspecialchars($row['status']) . "</td>";
    echo "<td>" . htmlspecialchars($row['phone_number']) . "</td>";
    echo "<td>" . htmlspecialchars($row['query_date']) . "</td>";
    echo "</tr>";
}

echo "</table>";

$stmt->close();
$conn->close();
?>
