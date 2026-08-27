<?php
// Set up comprehensive error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error: ' . $errstr
    ]);
    exit;
});

set_exception_handler(function($exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Exception: ' . $exception->getMessage()
    ]);
    exit;
});

try {
    session_start();
    
    header('Content-Type: application/json; charset=utf-8');
    
    // Check authentication
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access. Please log in.'
        ]);
        exit;
    }
    
    // Validate ticket ID parameter
    if (!isset($_GET['ticket_id']) || !is_numeric($_GET['ticket_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing ticket ID.'
        ]);
        exit;
    }
    
    $ticketId = (int)$_GET['ticket_id'];
    
    // Include database connection
    require_once '../db.php';
    
    // Verify database connection
    if (!isset($conn) || $conn === null || !$conn) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed.'
        ]);
        exit;
    }
    
    // First, try a simple query to make sure we can connect
    if (!$conn->ping()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection lost.'
        ]);
        exit;
    }
    
    // Fetch ticket details - using LEFT JOIN so we don't lose tickets if user is missing
    $sql = "
        SELECT 
            t.id, t.title, t.priority, t.member_type, t.query_type, t.description, 
            t.region, t.phone_number, t.attachment_path, t.status, t.created_by, 
            t.query_date, t.assigned_to, t.updated_at, t.source, t.department_reason, t.division_id,
            u.department
        FROM tickets t
        LEFT JOIN users u ON t.created_by = u.username
        WHERE t.id = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Query preparation failed: ' . $conn->error
        ]);
        exit;
    }
    
    if (!$stmt->bind_param("i", $ticketId)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Parameter binding failed: ' . $stmt->error
        ]);
        $stmt->close();
        exit;
    }
    
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Query execution failed: ' . $stmt->error
        ]);
        $stmt->close();
        exit;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Ticket not found.'
        ]);
        $stmt->close();
        exit;
    }
    
    $ticket = $result->fetch_assoc();
    $stmt->close();
    
    // Process attachment URL
    if (!empty($ticket['attachment_path'])) {
        $configPath = __DIR__ . '/../includes/confi.ini';
        if (file_exists($configPath)) {
            $config = parse_ini_file($configPath, true);
            if ($config && isset($config['application']['base_url'])) {
                $baseUrl = $config['application']['base_url'];
            } else {
                $baseUrl = 'http://localhost/pspf_helpdesk/';
            }
        } else {
            $baseUrl = 'http://localhost/pspf_helpdesk/';
        }
	
        $ticket['attachment_url'] = rtrim($baseUrl, '/') . '/api/tickets/' . $ticket['attachment_path'];
    } else {
        $ticket['attachment_url'] = null;
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'ticket' => $ticket
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
    exit;
}
?>