<?php
ob_start();
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';
require_once '../../vendor/autoload.php';
require_once '../includes/xlsx_styles.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

enforceActiveUser($conn);
requireAnyRole(['user', 'agent', 'admin', 'superadmin']);

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

$filename = 'ticket_status_logs';
if ($ticketId)             $filename .= '_ticket_' . $ticketId;
if (!empty($statusFilter)) $filename .= '_' . preg_replace('/\s+/', '_', strtolower($statusFilter));
if (!empty($dateFrom))     $filename .= '_from_' . $dateFrom;
if (!empty($dateTo))       $filename .= '_to_' . $dateTo;
$filename .= '.xlsx';

$headers = [
    'Ticket ID', 'Title', 'Division / Query Type', 'Priority',
    'Previous Status', 'New Status',
    'Changed By', 'Department',
    'Reason', 'Date & Time',
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Status Logs');

$sheet->fromArray([$headers], null, 'A1');

$rowNum = 2;
foreach ($rows as $row) {
    $sheet->fromArray([[
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
    ]], null, 'A' . $rowNum);
    $rowNum++;
}

applyXlsxStyles($sheet, $headers, count($rows), 'Ticket Status Logs');

ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
