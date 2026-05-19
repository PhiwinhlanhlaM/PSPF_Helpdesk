<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Fetch all departments and their divisions from the DB (same tables used during registration).
$rows = $conn->query("
    SELECT d.department_name, v.id AS division_id, v.division_name
    FROM departments d
    LEFT JOIN divisions v ON v.department_id = d.id
    ORDER BY d.department_name, v.division_name
");

$map = [];
while ($r = $rows->fetch_assoc()) {
    $dept = $r['department_name'];
    if (!isset($map[$dept])) {
        $map[$dept] = ['name' => $dept, 'divisions' => []];
    }
    if ($r['division_id']) {
        $map[$dept]['divisions'][] = ['id' => (int)$r['division_id'], 'name' => $r['division_name']];
    }
}

echo json_encode(array_values($map));
