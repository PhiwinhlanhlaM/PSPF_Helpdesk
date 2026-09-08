<?php
/**
 * IT Access - custom form fields (read).
 *
 * Serves the superadmin-defined custom request fields (migration 009) for the
 * request form to render in its "Additional information" section.
 *
 * Readable by any authenticated user (the form needs it). Writes live in
 * form_fields_admin.php (superadmin only).
 *
 *   GET form_fields.php          active fields only (what a new request shows)
 *   GET form_fields.php?all=1    include retired fields (superadmin only -
 *                                for the builder UI)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once __DIR__ . '/form_fields_shared.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

// Retired fields are only for the builder UI, so only a superadmin may ask for
// them. Everyone else gets the active fields.
$wantAll = isset($_GET['all']) && $_GET['all'] === '1' && hasRole('superadmin');

echo json_encode(['fields' => itaFormFields($conn, $wantAll)], JSON_UNESCAPED_UNICODE);
