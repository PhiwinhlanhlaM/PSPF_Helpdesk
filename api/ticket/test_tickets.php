<?php
require_once '../db.php';

// Test query - get all tickets with latest first
$sql = "SELECT t.id, t.title, t.status, t.created_by, t.query_date, d.division_name 
        FROM tickets t
        LEFT JOIN divisions d ON t.division_id = d.id
        ORDER BY t.query_date DESC
        LIMIT 5";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "Found " . $result->num_rows . " recent tickets:\n\n";
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Title: " . $row['title'] . " | Status: " . $row['status'] . " | Division: " . ($row['division_name'] ?? 'N/A') . " | Date: " . $row['query_date'] . "\n";
    }
} else {
    echo "No tickets found in database.\n";
}

$conn->close();
?>
