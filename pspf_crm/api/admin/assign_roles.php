<?php
// assign_roles.php - Temporary script to assign multiple roles to a user
session_start();
require_once '../db.php';

// Only allow admins to run this
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    die("Access denied");
}

echo "<h1>Assign Multiple Roles to User</h1>";

// Get all users
$users = $conn->query("SELECT id, username FROM users LIMIT 10");
echo "<h3>Select User:</h3>";
echo "<form method='POST'>";
echo "<select name='user_id'>";
while ($user = $users->fetch_assoc()) {
    echo "<option value='{$user['id']}'>{$user['username']} (ID: {$user['id']})</option>";
}
echo "</select>";

// Get all available roles
$roles = $conn->query("SELECT id, name FROM roles");
echo "<h3>Select Roles:</h3>";
while ($role = $roles->fetch_assoc()) {
    echo "<div><input type='checkbox' name='roles[]' value='{$role['id']}'> {$role['name']}</div>";
}

echo "<br><input type='submit' name='assign' value='Assign Roles'>";
echo "</form>";

if (isset($_POST['assign'])) {
    $userId = $_POST['user_id'];
    $selectedRoles = $_POST['roles'] ?? [];
    
    // Clear existing roles
    $conn->query("DELETE FROM user_roles WHERE user_id = $userId");
    
    // Assign new roles
    foreach ($selectedRoles as $roleId) {
        $conn->query("INSERT INTO user_roles (user_id, role_id) VALUES ($userId, $roleId)");
    }
    
    echo "<p style='color: green;'>Roles assigned successfully!</p>";
    
    // Show current roles
    $currentRoles = $conn->query("
        SELECT r.name 
        FROM roles r 
        JOIN user_roles ur ON r.id = ur.role_id 
        WHERE ur.user_id = $userId
    ");
    
    echo "<p>Current roles: ";
    $roleNames = [];
    while ($role = $currentRoles->fetch_assoc()) {
        $roleNames[] = $role['name'];
    }
    echo implode(', ', $roleNames) . "</p>";
}

$conn->close();
?>