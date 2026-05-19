<?php
/**
 * Superadmin-only: regenerate the PDF for an already-provisioned IT access request.
 * POST { request_db_id: N }  or  GET ?id=N  (for browser testing)
 */
header('Content-Type: application/json');
require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

enforceActiveUser($conn);
if (!isLoggedIn() || getActiveRole() !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Superadmin access required']);
    exit;
}

$id = (int)(($_POST['request_db_id'] ?? $_GET['id'] ?? 0));
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'request_db_id required']);
    exit;
}

// Verify request exists and is provisioned
$chk = $conn->prepare("SELECT id, status FROM it_access_requests WHERE id = ?");
$chk->bind_param("i", $id);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Request not found']);
    exit;
}

require_once __DIR__ . '/generate_pdf.php';
$filename = generateAndUploadPdf($conn, $id);

if ($filename) {
    echo json_encode(['ok' => true, 'filename' => $filename]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'PDF generation failed — check PHP error log']);
}
