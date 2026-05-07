<?php
require_once '../db.php';
//get_ticket_data.php

// Get filters
$agent = isset($_GET['agent']) ? $_GET['agent'] : '';
$start_date = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_GET['end'] ?? date('Y-m-d');

// Build base WHERE clause
$logged_where = "WHERE query_date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'";
$closed_where = "WHERE query_date BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59' AND status = 'Closed'";

if (!empty($agent)) {
    $logged_where .= " AND assigned_to = '$agent'";
    $closed_where .= " AND assigned_to = '$agent'";
}

// Logged tickets
$sql_logged = "
    SELECT DATE(query_date) AS ticket_date, COUNT(*) AS count
    FROM tickets
    $logged_where
    GROUP BY ticket_date
    ORDER BY ticket_date ASC
";

// Closed tickets
$sql_closed = "
    SELECT DATE(query_date) AS ticket_date, COUNT(*) AS count
    FROM tickets
    $closed_where
    GROUP BY ticket_date
    ORDER BY ticket_date ASC
";

$logged_result = $conn->query($sql_logged);
$closed_result = $conn->query($sql_closed);

// Prepare date range
$period = new DatePeriod(
    new DateTime($start_date),
    new DateInterval('P1D'),
    (new DateTime($end_date))->modify('+1 day')
);

$labels = [];
$logged = [];
$closed = [];

$date_map_logged = [];
$date_map_closed = [];

while ($row = $logged_result->fetch_assoc()) {
    $date_map_logged[$row['ticket_date']] = (int)$row['count'];
}
while ($row = $closed_result->fetch_assoc()) {
    $date_map_closed[$row['ticket_date']] = (int)$row['count'];
}

// Assemble final arrays
foreach ($period as $date) {
    $formatted = $date->format("Y-m-d");
    $labels[] = $formatted;
    $logged[] = $date_map_logged[$formatted] ?? 0;
    $closed[] = $date_map_closed[$formatted] ?? 0;
}

echo json_encode([
    'labels' => $labels,
    'logged' => $logged,
    'closed' => $closed
]);

$conn->close();
?>
