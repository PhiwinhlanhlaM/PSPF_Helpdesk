<?php
// export_status_logs_csv.php — server-side CSV export of all ticket status log entries
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

enforceActiveUser($conn);
requireAnyRole(['user', 'agent', 'admin', 'superadmin']);

// ---------------------------
// Filters (mirrors ticket_status_logs.php)
// ---------------------------
$ticketId     = isset($_GET['ticket_id']) && is_numeric($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : null;
$statusFilter = $_GET['status']     ?? '';
$dateFrom     = $_GET['date_from']  ?? '';
$dateTo       = $_GET['date_to']    ?? '';
$changedBy    = $_GET['changed_by'] ?? '';

$whereClauses = [];
$params       = [];
$types        = '';

if ($ticketId) {
    $whereClauses[] = 'tsl.ticket_id = ?';
    $params[]        = $ticketId;
    $types          .= 'i';
}
if (!empty($statusFilter)) {
    $whereClauses[] = 'tsl.new_status = ?';
    $params[]        = $statusFilter;
    $types          .= 's';
}
if (!empty($dateFrom)) {
    $whereClauses[] = 'DATE(tsl.change_date) >= ?';
    $params[]        = $dateFrom;
    $types          .= 's';
}
if (!empty($dateTo)) {
    $whereClauses[] = 'DATE(tsl.change_date) <= ?';
    $params[]        = $dateTo;
    $types          .= 's';
}
if (!empty($changedBy)) {
    $whereClauses[] = 'tsl.changed_by LIKE ?';
    $params[]        = '%' . $changedBy . '%';
    $types          .= 's';
}

$whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// ---------------------------
// Fetch all rows (no LIMIT)
// ---------------------------
$sql = "
    SELECT
        tsl.ticket_id,
        t.title         AS ticket_title,
        t.priority      AS ticket_priority,
        t.query_type,
        tsl.old_status,
        tsl.new_status,
        tsl.changed_by,
        u.department    AS changed_by_department,
        tsl.change_reason,
        tsl.change_date
    FROM ticket_status_logs tsl
    LEFT JOIN tickets t ON tsl.ticket_id = t.id
    LEFT JOIN users u   ON tsl.changed_by = u.username
    $whereClause
    ORDER BY tsl.change_date DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

// ---------------------------
// Build filename with active filters
// ---------------------------
$filename = 'ticket_status_logs';
if ($ticketId)          $filename .= '_ticket_' . $ticketId;
if (!empty($statusFilter)) $filename .= '_' . preg_replace('/\s+/', '_', strtolower($statusFilter));
if (!empty($dateFrom))  $filename .= '_from_' . $dateFrom;
if (!empty($dateTo))    $filename .= '_to_'   . $dateTo;
$filename .= '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Ticket ID', 'Title', 'Division / Query Type', 'Priority',
    'Previous Status', 'New Status',
    'Changed By', 'Department',
    'Reason', 'Date & Time',
]);

foreach ($rows as $row) {
    fputcsv($out, [
        'TCK-' . str_pad($row['ticket_id'], 6, '0', STR_PAD_LEFT),
        $row['ticket_title']           ?? '',
        $row['query_type']             ?? '',
        $row['ticket_priority']        ?? '',
        $row['old_status']             ?? '',
        $row['new_status']             ?? '',
        $row['changed_by']             ?? '',
        $row['changed_by_department']  ?? '',
        $row['change_reason']          ?? '',
        $row['change_date']            ?? '',
    ]);
}

fclose($out);
