<?php
require '../vehicle_booking/db.php';

$dept = $_GET['department'] ?? '';

$stmt = $conn->prepare("SELECT user_id, name FROM users WHERE role='supervisor' AND department=?");
$stmt->execute([$dept]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
