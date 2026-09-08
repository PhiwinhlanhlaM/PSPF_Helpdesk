<?php
// includes/auth_functions.php

function authenticateUser($identifier, $password, $isEmail = false) {
    require '../db.php';
    
    $field = $isEmail ? 'email' : 'username';

    $stmt = $conn->prepare("
        SELECT id, username, email, password, department, is_active 
        FROM users 
        WHERE $field = ?
        LIMIT 1
    ");
    
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // ❌ User not found
    if (!$user) {
        return false;
    }

    // 🚫 BLOCK DISABLED USERS
    if ((int)$user['is_active'] === 0) {
        throw new Exception("ACCOUNT_DISABLED");
    }

    // ❌ Wrong password
    if (!password_verify($password, $user['password'])) {
        return false;
    }

    // ✅ Success
    return $user;
}


function createUserSession($user) {
    $defaultRole = 'user';
    
    $_SESSION['user'] = [
        'id'          => $user['id'],
        'username'    => $user['username'],
        'email'       => $user['email'] ?? '',
        'department'  => $user['department'] ?? '',
        'role'        => $defaultRole,
        'roles'       => [$defaultRole],
        'active_role' => $defaultRole
    ];
    
    return $_SESSION['user'];
}
?>