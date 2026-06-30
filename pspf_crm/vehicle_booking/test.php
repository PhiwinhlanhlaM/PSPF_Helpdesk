<?php
require '../vehicle_booking/db.php';

$hashedPassword = password_hash('password123', PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (name, email, password, department, role) VALUES (?, ?, ?, ?, ?)");
$stmt->execute(['admin', 'admin@example.com', $hashedPassword, 'IT', 'admin']);

echo "User added successfully!";
