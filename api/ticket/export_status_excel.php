<?php
ob_start();
require_once '../../vendor/autoload.php';
require_once '../includes/xlsx_styles.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$mysqli = new mysqli("localhost", "root", "", "pspf_helpdesk");

$assigned_to = $_GET['assigned_to'] ?? '';
$start_date  = $_GET['start_date']  ?? '';
$end_date    = $_GET['end_date']    ?? '';

$conditions = [];
$params     = [];
$types      = '';

if (!empty($assigned_to)) {
    $conditions[] = "assigned_to = ?";
    $params[]      = $assigned_to;
    $types        .= 's';
}
if (!empty($start_date)) {
    $conditions[] = "query_date >= ?";
    $params[]      = $start_date;
    $types        .= 's';
}
if (!empty($end_date)) {
    $conditions[] = "query_date <= ?";
    $params[]      = $end_date;
    $types        .= 's';
}

$whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$sql = "
    SELECT
        SUM(CASE WHEN status = 'Open'        THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'Closed'      THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN status = 'Escalated'   THEN 1 ELSE 0 END) AS escalated_count
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

$headers = ['Open', 'In Progress', 'Closed', 'Escalated'];
$dataRow = [
    (int)$data['open_count'],
    (int)$data['in_progress_count'],
    (int)$data['closed_count'],
    (int)$data['escalated_count'],
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Status Summary');

$sheet->fromArray([$headers], null, 'A1');
$sheet->fromArray([$dataRow],  null, 'A2');

applyXlsxStyles($sheet, $headers, 1, 'Ticket Status Summary');

ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="ticket_status_summary.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
