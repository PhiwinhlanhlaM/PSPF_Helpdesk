<?php
session_start();
$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$agent = $conn->real_escape_string($_SESSION['user']['username']);

// Pie Chart: Count per Status
$status_sql = "SELECT status, COUNT(*) AS count FROM tickets 
               WHERE assigned_to = '$agent' 
               GROUP BY status";
$status_result = $conn->query($status_sql);
$status_data = [];
while ($row = $status_result->fetch_assoc()) {
    $status_data[] = $row;
}

// Line Chart: Tickets per Day (last 7 days)
$date_sql = "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM tickets 
             WHERE assigned_to = '$agent' 
               AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
             GROUP BY DATE(created_at)";
$date_result = $conn->query($date_sql);
$date_data = [];
while ($row = $date_result->fetch_assoc()) {
    $date_data[] = $row;
}

echo json_encode([
    'status_data' => $status_data,
    'date_data' => $date_data
]);
?>
