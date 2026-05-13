<?php
ob_start();
session_start();
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../../vendor/autoload.php';
require_once '../includes/xlsx_styles.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

enforceActiveUser($conn);
requireAnyRole(['agent', 'admin', 'superadmin']);

$agentEmail  = $_SESSION['user']['email'];
$divisionId  = (int)($_SESSION['user']['division_id'] ?? 0);

$filter      = trim($_GET['filter'] ?? '');
$searchParam = '%' . $filter . '%';

$sql = "
    SELECT id, title, status, priority, source, region, member_type,
           phone_number, query_date, created_by, assigned_to, description
    FROM tickets
    WHERE division_id = ?
      AND FIND_IN_SET(?, assigned_to)
      AND (
          CAST(id AS CHAR) LIKE ? OR
          title LIKE ?             OR
          status LIKE ?            OR
          created_by LIKE ?        OR
          query_date LIKE ?        OR
          priority LIKE ?
      )
    ORDER BY query_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "isssssss",
    $divisionId,
    $agentEmail,
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam,
    $searchParam
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$headers = ['Ticket ID', 'Title', 'Status', 'Priority', 'Source', 'Branch', 'Member Type', 'Phone', 'Created By', 'Assigned To', 'Date Submitted', 'Description'];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('My Tickets');

$sheet->fromArray([$headers], null, 'A1');

$rowNum = 2;
foreach ($rows as $row) {
    $sheet->fromArray([[
        'TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT),
        $row['title'],
        $row['status'],
        $row['priority'],
        $row['source'],
        $row['region'],
        $row['member_type'],
        $row['phone_number']  ?? '',
        $row['created_by'],
        $row['assigned_to']   ?? '',
        $row['query_date'],
        $row['description']   ?? '',
    ]], null, 'A' . $rowNum);
    $rowNum++;
}

applyXlsxStyles($sheet, $headers, count($rows), 'My Assigned Tickets');

$filename = 'my_tickets_' . date('Ymd_His') . '.xlsx';
ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
