<?php
$mysqli = new mysqli("localhost", "root", "", "pspf_helpdesk");

$assigned_to = $_GET['assigned_to'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$conditions = [];
$params = [];
$types = '';

if (!empty($assigned_to)) {
    $conditions[] = "assigned_to = ?";
    $params[] = $assigned_to;
    $types .= 's';
}
if (!empty($start_date)) {
    $conditions[] = "query_date >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if (!empty($end_date)) {
    $conditions[] = "query_date <= ?";
    $params[] = $end_date;
    $types .= 's';
}

$whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$sql = "
    SELECT 
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN status = 'Escalated' THEN 1 ELSE 0 END) AS escalated_count
    FROM tickets
    $whereClause
";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();
$mysqli->close();

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=ticket_status_summary.xls");

echo "Open\tIn Progress\tClosed\tEscalated\n";
echo "{$data['open_count']}\t{$data['in_progress_count']}\t{$data['closed_count']}\t{$data['escalated_count']}\n";
