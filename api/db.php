<?php
$host = "127.0.0.1";
$user = "root";
$password = "";
$db = "pspf_helpdesk";

$conn = new mysqli($host, $user, $password, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
