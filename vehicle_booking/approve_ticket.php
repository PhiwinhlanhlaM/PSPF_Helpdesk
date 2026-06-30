<?php
require "../vehicle_booking/db.php";

$id = $_POST['request_id'];

$sql = "UPDATE vehicle_requests SET status='Approved' WHERE id='$id'";
$conn->query($sql);

echo json_encode(["message" => "Ticket Approved"]);
