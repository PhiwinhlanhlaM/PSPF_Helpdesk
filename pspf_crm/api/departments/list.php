<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

// Return departments with their divisions nested
$result = $conn->query("
    SELECT d.id AS dept_id, d.department_name,
           v.id AS div_id, v.division_name
    FROM departments d
    LEFT JOIN divisions v ON v.department_id = d.id
    ORDER BY d.department_name ASC, v.division_name ASC
");

$departments = [];
$index = [];
while ($row = $result->fetch_assoc()) {
    $deptId   = (int)$row['dept_id'];
    $deptName = $row['department_name'];
    if (!isset($index[$deptId])) {
        $index[$deptId] = count($departments);
        $departments[]  = ['id' => $deptId, 'name' => $deptName, 'divisions' => []];
    }
    if ($row['div_id']) {
        $departments[$index[$deptId]]['divisions'][] = [
            'id'   => (int)$row['div_id'],
            'name' => $row['division_name'],
        ];
    }
}

echo json_encode(['departments' => array_values($departments)]);
