<?php
/**
 * IT Access — system catalog (read).
 *
 * Serves the catalog from the database in the exact shape the React app's
 * SYSTEM_CATALOG constant used to have, so getSystem() and every consumer
 * (ManagerForm, OfficerSign, Director, and later the ticket Title dropdown)
 * keep working unchanged.
 *
 * Readable by any authenticated user: the request form needs it, and the
 * ticket module will too. Writes live in catalog_admin.php (superadmin only).
 *
 *   GET catalog.php          active systems only (what a new request shows)
 *   GET catalog.php?all=1    include retired systems + usage counts
 *                            (superadmin only — for the management UI)
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
require_once __DIR__ . '/catalog_shared.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

// Retired systems and usage counts are only for the management UI, so only a
// superadmin may ask for them. Everyone else gets the active catalog.
$wantAll = isset($_GET['all']) && $_GET['all'] === '1' && hasRole('superadmin');

echo json_encode(['systems' => itaBuildCatalog($conn, $wantAll, $wantAll)]);
