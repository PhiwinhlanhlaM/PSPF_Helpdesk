<?php
include '../db.php';
//get_tickets.php

$result = $conn->query("SELECT * FROM tickets ORDER BY query_date DESC");

$tickets = [];
while ($row = $result->fetch_assoc()) {
    $tickets[] = $row;
}

echo json_encode($tickets);
?>

