<?php
// includes/log_activity.php

/**
 * Log user activity to the database
 * 
 * @param string $username The username performing the action
 * @param string $action The action performed
 * @param string $description Description of the action
 * @return bool True if logged successfully, false otherwise
 */
function logActivity($username, $action, $description = '') {
    // Get database connection
    require_once '../db.php';
    
    // Ensure we have a valid database connection
    if (!$conn || $conn->connect_error) {
        error_log("Database connection failed for activity logging");
        return false;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $page_url = $_SERVER['REQUEST_URI'] ?? 'Unknown';
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO system_logs (username, action, description, ip_address, user_agent, page_url) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            error_log("Failed to prepare log statement: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("ssssss", $username, $action, $description, $ip_address, $user_agent, $page_url);
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("Failed to execute log statement: " . $stmt->error);
            return false;
        }
        
        $stmt->close();
        return true;
        
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Alternative function that accepts database connection as first parameter
 * for backward compatibility
 */
function logActivityWithConn($conn, $username, $action, $description = '') {
    return logActivity($username, $action, $description);
}
?>