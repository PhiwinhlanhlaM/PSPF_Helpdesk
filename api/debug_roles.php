<?php
// debug_roles.php
session_start();
require_once './includes/auth_helpers.php';
require_once './includes/role_switcher.php';

echo "<h1>Role Debug Information</h1>";

// Check if user is logged in
if (!isLoggedIn()) {
    echo "<p style='color: red;'>User not logged in</p>";
    exit;
}

echo "<h3>Session Data:</h3>";
echo "<pre>";
print_r($_SESSION['user']);
echo "</pre>";

echo "<h3>Role Information:</h3>";
echo "<p>Available Roles: " . implode(', ', getAvailableRoles()) . "</p>";
echo "<p>Current Active Role: " . getActiveRole() . "</p>";
echo "<p>Has Multiple Roles: " . (count(getAvailableRoles()) > 1 ? 'YES' : 'NO') . "</p>";

// Test the role switcher rendering
echo "<h3>Role Switcher Output:</h3>";
echo renderRoleSwitcher();

// Check database for user roles
require_once './db.php';
$userId = $_SESSION['user']['id'];
$query = "
    SELECT r.name 
    FROM roles r 
    JOIN user_roles ur ON r.id = ur.role_id 
    WHERE ur.user_id = ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$dbRoles = [];
while ($row = $result->fetch_assoc()) {
    $dbRoles[] = $row['name'];
}

echo "<h3>Database Roles:</h3>";
echo "<p>" . implode(', ', $dbRoles) . "</p>";

echo "<h3>Session vs Database:</h3>";
echo "<p>Session Roles: " . implode(', ', getAvailableRoles()) . "</p>";
echo "<p>Database Roles: " . implode(', ', $dbRoles) . "</p>";
echo "<p>Match: " . (implode(',', getAvailableRoles()) === implode(',', $dbRoles) ? 'YES' : 'NO') . "</p>";

$stmt->close();
$conn->close();
?>