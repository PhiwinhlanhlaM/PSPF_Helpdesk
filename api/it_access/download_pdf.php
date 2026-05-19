<?php
require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

if (!isLoggedIn()) { http_response_code(401); echo 'Not authenticated'; exit; }
enforceActiveUser($conn);

$requestDbId = (int)($_GET['id'] ?? 0);
if (!$requestDbId) { http_response_code(400); echo 'Missing id'; exit; }

$userId     = (int)$_SESSION['user']['id'];
$isSuper    = in_array(getActiveRole(), ['admin', 'superadmin']);
$isOfficer  = hasRole('it_officer');
$isDirector = hasRole('it_director');

$stmt = $conn->prepare("SELECT id, ref_number, submitted_by, claimed_by, status, pdf_filename FROM it_access_requests WHERE id = ?");
$stmt->bind_param("i", $requestDbId);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) { http_response_code(404); echo 'Request not found'; exit; }

$canAccess = $isSuper || $isOfficer || $isDirector
    || (int)$req['submitted_by'] === $userId
    || (int)$req['claimed_by']   === $userId;

if (!$canAccess) { http_response_code(403); echo 'Access denied'; exit; }

$pdfDir  = __DIR__ . '/../../uploads/it_access_pdfs/';
$filename = $req['pdf_filename'] ?? null;

if ($filename && file_exists($pdfDir . $filename)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdfDir . $filename));
    readfile($pdfDir . $filename);
    exit;
}

if ($req['status'] !== 'provisioned') {
    http_response_code(404);
    echo 'PDF is only available for provisioned requests';
    exit;
}

require_once __DIR__ . '/generate_pdf.php';
generateAndUploadPdf($conn, $requestDbId);

$stmt2 = $conn->prepare("SELECT pdf_filename FROM it_access_requests WHERE id = ?");
$stmt2->bind_param("i", $requestDbId);
$stmt2->execute();
$filename = $stmt2->get_result()->fetch_assoc()['pdf_filename'] ?? null;
$stmt2->close();

if ($filename && file_exists($pdfDir . $filename)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdfDir . $filename));
    readfile($pdfDir . $filename);
    exit;
}

http_response_code(500);
echo 'PDF generation failed';
