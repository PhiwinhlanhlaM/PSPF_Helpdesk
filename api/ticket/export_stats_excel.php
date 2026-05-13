<?php
ob_start();
session_start();

require '../db.php';
require_once '../../vendor/autoload.php';
require_once '../includes/xlsx_styles.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$filters = [
    'member_type' => $_GET['member_type'] ?? '',
    'region'      => $_GET['region']      ?? '',
    'source'      => $_GET['source']      ?? '',
    'query_type'  => $_GET['query_type']  ?? '',
    'priority'    => $_GET['priority']    ?? '',
    'status'      => $_GET['status']      ?? '',
    'start_date'  => $_GET['start_date']  ?? '',
    'end_date'    => $_GET['end_date']    ?? '',
];

$whereClauses = ["1=1"];
$params = [];
$types  = "";

foreach ($filters as $field => $value) {
    if (in_array($field, ['start_date', 'end_date'])) continue;
    if (!empty($value)) {
        $whereClauses[] = "t.$field = ?";
        $params[] = $value;
        $types   .= "s";
    }
}

if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
    $whereClauses[] = "t.query_date BETWEEN ? AND ?";
    $params[] = $filters['start_date'];
    $params[] = $filters['end_date'];
    $types   .= "ss";
} elseif (!empty($filters['start_date'])) {
    $whereClauses[] = "t.query_date >= ?";
    $params[] = $filters['start_date'];
    $types   .= "s";
} elseif (!empty($filters['end_date'])) {
    $whereClauses[] = "t.query_date <= ?";
    $params[] = $filters['end_date'];
    $types   .= "s";
}

$whereSql = implode(" AND ", $whereClauses);

$sql = "
    SELECT t.id, t.title, t.member_type, t.query_type, t.region, t.source,
           t.priority, t.status, t.phone_number, t.query_date,
           t.created_by, t.assigned_to, t.description,
           d.division_name
    FROM tickets t
    LEFT JOIN divisions d ON t.division_id = d.id
    WHERE $whereSql
    ORDER BY t.query_date DESC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$headers = [
    'Ticket ID', 'Title', 'Member Type', 'Division', 'Branch', 'Source',
    'Priority', 'Status', 'Phone', 'Created By', 'Assigned To', 'Date Submitted', 'Description',
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Helpdesk Report');

$sheet->fromArray([$headers], null, 'A1');

$rowNum = 2;
foreach ($rows as $row) {
    $sheet->fromArray([[
        'TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT),
        $row['title'],
        $row['member_type']   ?? '',
        $row['division_name'] ?? $row['query_type'],
        $row['region'],
        $row['source'],
        $row['priority'],
        $row['status'],
        $row['phone_number']  ?? '',
        $row['created_by'],
        $row['assigned_to']   ?? '',
        $row['query_date'],
        $row['description']   ?? '',
    ]], null, 'A' . $rowNum);
    $rowNum++;
}

applyXlsxStyles($sheet, $headers, count($rows), 'Helpdesk Report');

$filename = 'Helpdesk_Report_' . date('Y-m-d') . '.xlsx';
ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
