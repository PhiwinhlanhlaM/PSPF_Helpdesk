<?php
// export_admin_excel.php — server-side Excel export of all tickets (no pagination)
session_start();
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../db.php';

enforceActiveUser($conn);
requireAnyRole(['admin', 'superadmin']);

$activeRole     = getActiveRole();
$UserEmail      = $_SESSION['user']['email'];
$UserDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);

// ---------------------------
// Filters (mirrors admin_view.php)
// ---------------------------
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

// ---------------------------
// Fetch all rows (no LIMIT)
// ---------------------------
$sql = "
    SELECT t.id, t.title, t.status, t.priority, t.source, t.region,
           t.member_type, t.phone_number, t.query_date, t.created_by,
           t.assigned_to, u.department, d.division_name
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

// ---------------------------
// Build XLS (HTML table trick — works in Excel 2003+)
// ---------------------------
$filename = 'tickets_export_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<table border="1">';
echo '<thead><tr>
    <th>Ticket ID</th>
    <th>Title</th>
    <th>Status</th>
    <th>Priority</th>
    <th>Source</th>
    <th>Branch</th>
    <th>Member Type</th>
    <th>Phone</th>
    <th>Division</th>
    <th>Department</th>
    <th>Created By</th>
    <th>Assigned To</th>
    <th>Date Submitted</th>
</tr></thead><tbody>';

foreach ($rows as $row) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars('TCK-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT)) . '</td>';
    echo '<td>' . htmlspecialchars($row['title'])        . '</td>';
    echo '<td>' . htmlspecialchars($row['status'])       . '</td>';
    echo '<td>' . htmlspecialchars($row['priority'])     . '</td>';
    echo '<td>' . htmlspecialchars($row['source'])       . '</td>';
    echo '<td>' . htmlspecialchars($row['region'])       . '</td>';
    echo '<td>' . htmlspecialchars($row['member_type'])  . '</td>';
    echo '<td>' . htmlspecialchars($row['phone_number'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($row['division_name'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($row['department']    ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($row['created_by'])   . '</td>';
    echo '<td>' . htmlspecialchars($row['assigned_to']   ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($row['query_date'])   . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
