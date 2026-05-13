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
requireAnyRole(['admin', 'superadmin']);

$activeRole     = getActiveRole();
$UserEmail      = $_SESSION['user']['email'];
$UserDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);

$filter         = $_GET['filter']     ?? '';
$deptFilter     = isset($_GET['department']) && $_GET['department'] !== '' ? $_GET['department'] : '';
$statusFilter   = isset($_GET['status'])     && $_GET['status']     !== '' ? $_GET['status']     : '';
$priorityFilter = isset($_GET['priority'])   && $_GET['priority']   !== '' ? $_GET['priority']   : '';
$assignedFilter = isset($_GET['assigned'])   && $_GET['assigned']   === 'me';

$searchParam  = '%' . $filter . '%';
$whereClauses = [];
$params       = [];
$types        = '';

if (!empty($filter)) {
    $whereClauses[] = "(t.title LIKE ? OR t.description LIKE ? OR t.created_by LIKE ? OR t.assigned_to LIKE ? OR CAST(t.id AS CHAR) LIKE ?)";
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= 'sssss';
}
if ($activeRole === 'superadmin' && !empty($deptFilter)) {
    $whereClauses[] = 't.division_id = ?';
    $params[]        = (int)$deptFilter;
    $types          .= 'i';
}
if (!empty($statusFilter)) {
    $whereClauses[] = 't.status = ?';
    $params[]        = $statusFilter;
    $types          .= 's';
}
if (!empty($priorityFilter)) {
    $whereClauses[] = 't.priority = ?';
    $params[]        = $priorityFilter;
    $types          .= 's';
}
if ($activeRole === 'admin') {
    if ($UserDivisionId > 0) {
        $whereClauses[] = 't.division_id = ?';
        $params[]        = $UserDivisionId;
        $types          .= 'i';
    }
    if ($assignedFilter) {
        $whereClauses[] = 't.assigned_to LIKE ?';
        $params[]        = '%' . $UserEmail . '%';
        $types          .= 's';
    }
}

$whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$sql = "
    SELECT t.id, t.title, t.status, t.priority, t.source, t.region,
           t.member_type, t.phone_number, t.query_date, t.created_by,
           t.assigned_to, t.description, u.department, d.division_name
    FROM tickets t
    LEFT JOIN users u  ON t.created_by  = u.username
    LEFT JOIN divisions d ON t.division_id = d.id
    $whereClause
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

$headers = ['Ticket ID', 'Title', 'Status', 'Priority', 'Source', 'Branch', 'Member Type', 'Phone', 'Division', 'Department', 'Created By', 'Assigned To', 'Date Submitted', 'Description'];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Tickets');

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
        $row['division_name'] ?? '',
        $row['department']    ?? '',
        $row['created_by'],
        $row['assigned_to']   ?? '',
        $row['query_date'],
        $row['description']   ?? '',
    ]], null, 'A' . $rowNum);
    $rowNum++;
}

applyXlsxStyles($sheet, $headers, count($rows), 'All Tickets');

$filename = 'tickets_export_' . date('Ymd_His') . '.xlsx';
ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
