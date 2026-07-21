<?php
/**
 * IT Access — supervisor picker data.
 *
 * Feeds the request form: who this user's request will go to by default, and
 * the full list of people they may pick instead. The default is resolved by
 * the same helper submit.php uses, so what the requester is shown is exactly
 * where the request will actually be routed.
 *
 *   GET supervisors.php
 *     -> { "defaultId": 12|null, "choices": [ {id, name}, ... ] }
 *
 * defaultId is null when nobody is assigned — the form then explains that the
 * request will go straight to the ICT team.
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
require_once __DIR__ . '/supervisor_helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

$userId = (int)$_SESSION['user']['id'];

echo json_encode([
    'defaultId' => itaResolveSupervisor($conn, $userId),
    'choices'   => itaSupervisorChoices($conn, $userId),
]);
