<?php
/**
 * IT Access Form — Integration Test Runner
 *
 * Covers every API scenario end-to-end using real HTTP + real DB.
 * Run via: http://localhost/pspf_crm/api/it_access/test_runner.php
 *          or CLI: php test_runner.php
 *
 * The script creates its own isolated test users (prefixed "test_ita_"),
 * runs all scenarios, then tears them down — leaving the DB clean.
 *
 * REQUIREMENTS
 *   - XAMPP running (Apache + MySQL)
 *   - mPDF installed (composer vendor/)
 *   - php_curl enabled in php.ini
 */

declare(strict_types=1);

// ─── Output buffering for clean HTML/CLI report ──────────────────────────────
ob_start();
$isCli = (php_sapi_name() === 'cli');

// ─── Config ──────────────────────────────────────────────────────────────────
define('BASE_URL',  'http://localhost/pspf_crm/api/it_access');
define('CSRF_META', 'http://localhost/pspf_crm/api/it_access/index.php');

// Test user passwords (plaintext — only exist during test run)
define('TEST_PASSWORD', 'TestPass@2026!');
define('TEST_PASSWORD_HASH', password_hash(TEST_PASSWORD, PASSWORD_DEFAULT));

// ─── Minimal DB bootstrap (no session, no auth) ───────────────────────────────
require_once __DIR__ . '/../db.php';   // provides $conn

// ─── Test state ──────────────────────────────────────────────────────────────
$results   = [];   // array of test result records
$createdUserIds   = [];
$createdRequestIds = [];

// ─── Helpers ─────────────────────────────────────────────────────────────────

function pass(string $name, string $detail = ''): void {
    global $results;
    $results[] = ['status' => 'PASS', 'name' => $name, 'detail' => $detail];
}

function fail(string $name, string $detail = ''): void {
    global $results;
    $results[] = ['status' => 'FAIL', 'name' => $name, 'detail' => $detail];
}

function skip(string $name, string $detail = ''): void {
    global $results;
    $results[] = ['status' => 'SKIP', 'name' => $name, 'detail' => $detail];
}

function assert_eq(mixed $expected, mixed $actual, string $testName): bool {
    if ($expected === $actual) {
        pass($testName, "Expected: " . json_encode($expected));
        return true;
    }
    fail($testName, "Expected: " . json_encode($expected) . " | Got: " . json_encode($actual));
    return false;
}

function assert_contains(string $needle, string $haystack, string $testName): bool {
    if (str_contains($haystack, $needle)) {
        pass($testName, "Found: $needle");
        return true;
    }
    fail($testName, "Expected to find '$needle' in: " . substr($haystack, 0, 200));
    return false;
}

/**
 * HTTP client that maintains cookies across calls (one jar per "session").
 * Returns [http_code, decoded_body_or_raw, headers_string].
 */
function http(string $method, string $url, array $payload = [], string $csrfToken = '', string $cookieJarFile = ''): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,   // handle redirects manually
        CURLOPT_TIMEOUT        => 15,
    ]);

    if ($cookieJarFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookieJarFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
    }

    $headers = ['Accept: application/json'];
    if ($csrfToken) {
        $headers[] = 'X-CSRF-Token: ' . $csrfToken;
    }

    if (strtoupper($method) === 'POST') {
        if (!empty($payload)) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        curl_setopt($ch, CURLOPT_POST, true);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw      = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $hdrSize);
    $body       = substr($raw, $hdrSize);
    // Strip UTF-8 BOM if present (some PHP configs emit it)
    if (str_starts_with($body, "\xEF\xBB\xBF")) {
        $body = substr($body, 3);
    }
    $decoded    = json_decode($body, true);

    return [$code, $decoded ?? $body, $rawHeaders];
}

/**
 * Log in as a test user and return [cookieJarFile, csrfToken].
 * Uses a PHP-CLI-style direct session bootstrap to avoid the HTML select-role page.
 */
function loginAs(int $userId, string $activeRole): array {
    // We create a dedicated "fast-login" endpoint inline via a temp file.
    // Simpler: write a tiny bootstrap PHP file, call it over HTTP.
    // Even simpler: use the DB directly to grab a real session via the test-login helper.
    $jar = tempnam(sys_get_temp_dir(), 'ita_test_cookie_');

    // POST to our test-login helper (created below)
    [$code, $body] = http('POST',
        'http://localhost/pspf_crm/api/it_access/test_login_helper.php',
        ['user_id' => $userId, 'active_role' => $activeRole, 'secret' => 'ita_test_secret_2026'],
        '',
        $jar
    );

    $csrf = is_array($body) ? ($body['csrf_token'] ?? '') : '';
    return [$jar, $csrf];
}

/**
 * Clean up: remove all test artefacts.
 */
function teardown(mysqli $conn, array $userIds, array $requestIds): void {
    // Also catch any requests submitted_by our test users that weren't tracked
    if ($userIds) {
        $uIds = implode(',', array_map('intval', $userIds));
        $extraRows = $conn->query("SELECT id FROM it_access_requests WHERE submitted_by IN ($uIds)");
        if ($extraRows) {
            while ($row = $extraRows->fetch_assoc()) {
                $requestIds[] = (int)$row['id'];
            }
        }
        $requestIds = array_unique(array_filter($requestIds));
    }

    // Delete requests (and children) first, then users
    if ($requestIds) {
        $rIds = implode(',', array_map('intval', $requestIds));
        $conn->query("DELETE FROM it_request_approvals WHERE request_id IN ($rIds)");
        $conn->query("DELETE FROM it_request_systems   WHERE request_id IN ($rIds)");
        // Delete any generated PDFs from disk
        $pdfs = $conn->query("SELECT pdf_filename FROM it_access_requests WHERE id IN ($rIds) AND pdf_filename IS NOT NULL");
        if ($pdfs) {
            while ($pRow = $pdfs->fetch_assoc()) {
                $path = __DIR__ . '/../../uploads/it_access_pdfs/' . $pRow['pdf_filename'];
                if (file_exists($path)) @unlink($path);
            }
        }
        $conn->query("DELETE FROM it_access_requests WHERE id IN ($rIds)");
    }
    if ($userIds) {
        $uIds = implode(',', array_map('intval', $userIds));
        $conn->query("DELETE FROM user_roles WHERE user_id IN ($uIds)");
        $conn->query("DELETE FROM users      WHERE id      IN ($uIds)");
    }
}

// ─── Signature fixture (minimal drawn stroke, normalised 0..1 coords) ─────────
$SIG_DRAWN = [
    'kind'    => 'drawn',
    'strokes' => [
        [[0.10, 0.30], [0.20, 0.20], [0.35, 0.50], [0.50, 0.20], [0.65, 0.50]],
        [[0.70, 0.30], [0.80, 0.30]],
    ],
];

// Small 1×1 transparent PNG as base64 for upload fixture
$SIG_UPLOADED = [
    'kind'    => 'uploaded',
    'dataUrl' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
];


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 0 — Pre-flight checks
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 0] Pre-flight checks\n";

// 0-A: DB connection
if ($conn instanceof mysqli && !$conn->connect_errno) {
    pass('DB connection', 'Connected to pspf_helpdesk');
} else {
    fail('DB connection', 'Cannot connect to DB — aborting');
    goto report;
}

// 0-B: cURL available
if (function_exists('curl_init')) {
    pass('cURL extension', 'Available');
} else {
    fail('cURL extension', 'curl_init() not found — aborting');
    goto report;
}

// 0-C: Apache serving the app
[$pingCode] = http('GET', 'http://localhost/pspf_crm/api/it_access/list.php');
if (in_array($pingCode, [200, 401, 403])) {
    pass('Apache reachable', "HTTP $pingCode from list.php");
} else {
    fail('Apache reachable', "Unexpected HTTP $pingCode — is XAMPP running?");
    goto report;
}

// 0-D: mPDF available
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    if (class_exists('\Mpdf\Mpdf')) {
        pass('mPDF library', 'Available');
    } else {
        fail('mPDF library', 'autoload.php found but \\Mpdf\\Mpdf class missing');
    }
} else {
    skip('mPDF library', 'vendor/autoload.php not found — PDF tests will be limited');
}

// 0-E: test-login helper accessible
[$hlpCode] = http('GET', 'http://localhost/pspf_crm/api/it_access/test_login_helper.php');
if ($hlpCode === 405) {
    pass('Test-login helper', 'Present (GET returns 405 as expected)');
} elseif ($hlpCode === 200 || $hlpCode === 400) {
    pass('Test-login helper', "Present (HTTP $hlpCode)");
} else {
    fail('Test-login helper', "HTTP $hlpCode — create test_login_helper.php first");
}


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 1 — Create isolated test users
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 1] Creating test users\n";

$hash = TEST_PASSWORD_HASH;

// Manager (regular user — just needs to submit)
$conn->query("INSERT INTO users (username, email, password, department, is_active)
              VALUES ('test_ita_manager','test_ita_manager@test.local','$hash','Finance',1)");
$managerId = (int)$conn->insert_id;
if ($managerId) {
    // Assign base 'user' role
    $rRes = $conn->query("SELECT id FROM roles WHERE name='user' LIMIT 1");
    $rRow = $rRes ? $rRes->fetch_assoc() : null;
    if ($rRow) {
        $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($managerId, {$rRow['id']})");
    }
    $createdUserIds[] = $managerId;
    pass('Create test manager', "user_id=$managerId");
} else {
    fail('Create test manager', $conn->error);
    goto report;
}

// IT Officer
$conn->query("INSERT INTO users (username, email, password, department, is_active)
              VALUES ('test_ita_officer','test_ita_officer@test.local','$hash','ICT',1)");
$officerId = (int)$conn->insert_id;
if ($officerId) {
    $rRes = $conn->query("SELECT id FROM roles WHERE name='it_officer' LIMIT 1");
    $rRow = $rRes ? $rRes->fetch_assoc() : null;
    if ($rRow) {
        $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($officerId, {$rRow['id']})");
    }
    // Also give base user role
    $rRes2 = $conn->query("SELECT id FROM roles WHERE name='user' LIMIT 1");
    $rRow2 = $rRes2 ? $rRes2->fetch_assoc() : null;
    if ($rRow2) {
        $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($officerId, {$rRow2['id']})");
    }
    $createdUserIds[] = $officerId;
    pass('Create test officer', "user_id=$officerId");
} else {
    fail('Create test officer', $conn->error);
    goto teardown_early;
}

// IT Director
$conn->query("INSERT INTO users (username, email, password, department, is_active)
              VALUES ('test_ita_director','test_ita_director@test.local','$hash','ICT',1)");
$directorId = (int)$conn->insert_id;
if ($directorId) {
    $rRes = $conn->query("SELECT id FROM roles WHERE name='it_director' LIMIT 1");
    $rRow = $rRes ? $rRes->fetch_assoc() : null;
    if ($rRow) {
        $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($directorId, {$rRow['id']})");
    }
    $rRes2 = $conn->query("SELECT id FROM roles WHERE name='user' LIMIT 1");
    $rRow2 = $rRes2 ? $rRes2->fetch_assoc() : null;
    if ($rRow2) {
        $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ($directorId, {$rRow2['id']})");
    }
    $createdUserIds[] = $directorId;
    pass('Create test director', "user_id=$directorId");
} else {
    fail('Create test director', $conn->error);
    goto teardown_early;
}


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 2 — Authentication
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 2] Authentication\n";

[$mgJar, $mgCsrf] = loginAs($managerId, 'user');
if ($mgCsrf) {
    pass('Manager login', "CSRF token obtained");
} else {
    fail('Manager login', 'No CSRF token returned — check test_login_helper.php');
    goto teardown_early;
}

[$ofJar, $ofCsrf] = loginAs($officerId, 'it_officer');
if ($ofCsrf) {
    pass('Officer login', "CSRF token obtained");
} else {
    fail('Officer login', 'No CSRF token returned');
    goto teardown_early;
}

[$drJar, $drCsrf] = loginAs($directorId, 'it_director');
if ($drCsrf) {
    pass('Director login', "CSRF token obtained");
} else {
    fail('Director login', 'No CSRF token returned');
    goto teardown_early;
}

// 2-A: Unauthenticated list should return 401
[$code] = http('GET', BASE_URL . '/list.php');
assert_eq(401, $code, 'list.php: unauthenticated → 401');

// 2-B: Unauthenticated submit should return 401
[$code] = http('POST', BASE_URL . '/submit.php', ['foo' => 'bar']);
assert_eq(401, $code, 'submit.php: unauthenticated → 401');

// 2-C: Wrong CSRF on approve should return 403
[$code] = http('POST', BASE_URL . '/approve.php', ['request_db_id' => 1, 'action' => 'approved', 'step_role' => 'officer-1'], 'bad_token', $ofJar);
assert_eq(403, $code, 'approve.php: bad CSRF → 403');


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 3 — Submit: Validation rejections
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 3] submit.php validation\n";

// 3-A: Empty body
[$code, $body] = http('POST', BASE_URL . '/submit.php', [], $mgCsrf, $mgJar);
assert_eq(400, $code, 'submit: empty body → 400');

// 3-B: Missing employee name
[$code, $body] = http('POST', BASE_URL . '/submit.php', [
    'employee'      => ['name' => '', 'department' => 'Finance', 'title' => 'Analyst'],
    'systems'       => [['id' => 'inpensions', 'role' => 'Capturer']],
    'justification' => 'Testing submission flow end to end',
    'startDate'     => '2026-06-01',
    'approvals'     => [['role' => 'manager', 'signature' => $SIG_DRAWN]],
], $mgCsrf, $mgJar);
assert_eq(422, $code, 'submit: missing employee name → 422');

// 3-C: No systems selected
[$code, $body] = http('POST', BASE_URL . '/submit.php', [
    'employee'      => ['name' => 'Test Employee', 'department' => 'Finance', 'title' => 'Analyst'],
    'systems'       => [],
    'justification' => 'Testing submission flow end to end',
    'startDate'     => '2026-06-01',
    'approvals'     => [['role' => 'manager', 'signature' => $SIG_DRAWN]],
], $mgCsrf, $mgJar);
assert_eq(422, $code, 'submit: no systems → 422');

// 3-D: Justification too short
[$code, $body] = http('POST', BASE_URL . '/submit.php', [
    'employee'      => ['name' => 'Test Employee', 'department' => 'Finance', 'title' => 'Analyst'],
    'systems'       => [['id' => 'inpensions', 'role' => 'Capturer']],
    'justification' => 'Short',
    'startDate'     => '2026-06-01',
    'approvals'     => [['role' => 'manager', 'signature' => $SIG_DRAWN]],
], $mgCsrf, $mgJar);
assert_eq(422, $code, 'submit: short justification → 422');

// 3-E: Missing manager signature
[$code, $body] = http('POST', BASE_URL . '/submit.php', [
    'employee'      => ['name' => 'Test Employee', 'department' => 'Finance', 'title' => 'Analyst'],
    'systems'       => [['id' => 'inpensions', 'role' => 'Capturer']],
    'justification' => 'Testing submission flow end to end',
    'startDate'     => '2026-06-01',
    'approvals'     => [],
], $mgCsrf, $mgJar);
assert_eq(422, $code, 'submit: no manager signature → 422');

// 3-F: Bad start date format
[$code, $body] = http('POST', BASE_URL . '/submit.php', [
    'employee'      => ['name' => 'Test Employee', 'department' => 'Finance', 'title' => 'Analyst'],
    'systems'       => [['id' => 'inpensions', 'role' => 'Capturer']],
    'justification' => 'Testing submission flow end to end',
    'startDate'     => '01/06/2026',   // wrong format
    'approvals'     => [['role' => 'manager', 'signature' => $SIG_DRAWN]],
], $mgCsrf, $mgJar);
assert_eq(422, $code, 'submit: bad startDate format → 422');


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 4 — Submit: Happy path (drawn signature)
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 4] submit.php happy path\n";

$submitPayload = [
    'requestType'   => 'new',
    'employee'      => [
        'name'       => 'Alice Testington',
        'id'         => 'EMP-TEST-001',
        'department' => 'Finance',
        'title'      => 'Senior Accountant',
    ],
    'systems'       => [
        ['id' => 'inpensions',   'role' => 'Capturer',  'subValues' => null],
        ['id' => 'smartstream',  'role' => 'Viewer',    'subValues' => null],
        ['id' => 'helpdesk',     'role' => ['User', 'Agent'], 'subValues' => null],  // multi-role
    ],
    'justification' => 'New hire requires immediate access to pensions and accounting systems for month-end close.',
    'startDate'     => '2026-06-01',
    'approvals'     => [['role' => 'manager', 'personId' => $managerId, 'at' => date('c'), 'action' => 'approved', 'signature' => $SIG_DRAWN]],
];

[$code, $body] = http('POST', BASE_URL . '/submit.php', $submitPayload, $mgCsrf, $mgJar);
assert_eq(200, $code, 'submit: valid request → 200');

$req1DbId  = null;
$req1Ref   = null;
if (is_array($body) && ($body['ok'] ?? false)) {
    $req1DbId = (int)($body['id'] ?? 0);
    $req1Ref  = $body['ref'] ?? '';
    $createdRequestIds[] = $req1DbId;
    pass('submit: response has ok=true', "ref=$req1Ref db_id=$req1DbId");
} else {
    fail('submit: response body', 'Missing ok=true — body: ' . json_encode($body));
}

// 4-A: Verify DB row
if ($req1DbId) {
    $row = $conn->query("SELECT status, employee_name, employee_id FROM it_access_requests WHERE id=$req1DbId")->fetch_assoc();
    assert_eq('new',                $row['status'],        'submit: DB status = new');
    assert_eq('Alice Testington',   $row['employee_name'], 'submit: DB employee_name correct');

    $sysCnt = (int)$conn->query("SELECT COUNT(*) AS c FROM it_request_systems WHERE request_id=$req1DbId")->fetch_assoc()['c'];
    assert_eq(3, $sysCnt, 'submit: DB has 3 system rows');

    $apprCnt = (int)$conn->query("SELECT COUNT(*) AS c FROM it_request_approvals WHERE request_id=$req1DbId AND step_role='manager'")->fetch_assoc()['c'];
    assert_eq(1, $apprCnt, 'submit: DB has manager approval row');

    // Multi-role system stored as comma-separated string
    $sysRow = $conn->query("SELECT role FROM it_request_systems WHERE request_id=$req1DbId AND system_id='helpdesk'")->fetch_assoc();
    if ($sysRow) {
        assert_contains('User', $sysRow['role'], 'submit: helpdesk multi-role stored correctly');
    } else {
        fail('submit: helpdesk system row', 'Row not found in DB');
    }
}

// 4-B: Ref number format
if ($req1Ref) {
    if (preg_match('/^REQ-\d{4}-\d{4}$/', $req1Ref)) {
        pass('submit: ref number format', "Format REQ-YYYY-NNNN ✓ ($req1Ref)");
    } else {
        fail('submit: ref number format', "Got: $req1Ref");
    }
}

// 4-C: Submit with uploaded signature
$submitPayload2 = $submitPayload;
$submitPayload2['employee']['name'] = 'Bob Uploadington';
$submitPayload2['approvals'][0]['signature'] = $SIG_UPLOADED;
[$code2, $body2] = http('POST', BASE_URL . '/submit.php', $submitPayload2, $mgCsrf, $mgJar);
assert_eq(200, $code2, 'submit: uploaded signature → 200');
if (is_array($body2) && ($body2['ok'] ?? false)) {
    $req2DbId = (int)($body2['id'] ?? 0);
    $createdRequestIds[] = $req2DbId;
    pass('submit: uploaded sig request created', "db_id=$req2DbId");
}


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 5 — list.php: role-based visibility
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 5] list.php visibility\n";

// 5-A: Manager sees own requests
[$code, $body] = http('GET', BASE_URL . '/list.php', [], '', $mgJar);
assert_eq(200, $code, 'list: manager → 200');
$mgRequests = is_array($body) ? ($body['requests'] ?? []) : [];
$mgIds = array_column($mgRequests, 'db_id');
if ($req1DbId && in_array($req1DbId, $mgIds)) {
    pass('list: manager sees own submitted request', "db_id=$req1DbId in list");
} else {
    fail('list: manager sees own submitted request', "db_id=$req1DbId not in: " . implode(',', $mgIds));
}

// 5-B: Manager does NOT see non-own requests (spot-check: officer's user has no own requests)
$foreignIds = array_filter($mgIds, fn($id) => !in_array($id, $createdRequestIds));
// We can't guarantee none, but at minimum our own should be there

// 5-C: Officer sees the new request (status=new, non-terminal)
[$code, $body] = http('GET', BASE_URL . '/list.php', [], '', $ofJar);
assert_eq(200, $code, 'list: officer → 200');
$ofRequests = is_array($body) ? ($body['requests'] ?? []) : [];
$ofIds = array_column($ofRequests, 'db_id');
if ($req1DbId && in_array($req1DbId, $ofIds)) {
    pass('list: officer sees new request', "db_id=$req1DbId visible to officer");
} else {
    fail('list: officer sees new request', "db_id=$req1DbId not in officer list: " . implode(',', $ofIds));
}

// 5-D: Director does NOT see 'new' status requests
[$code, $body] = http('GET', BASE_URL . '/list.php', [], '', $drJar);
assert_eq(200, $code, 'list: director → 200');
$drRequests = is_array($body) ? ($body['requests'] ?? []) : [];
$drIds = array_column($drRequests, 'db_id');
if ($req1DbId && in_array($req1DbId, $drIds)) {
    fail('list: director should NOT see new-status request', "db_id=$req1DbId incorrectly visible to director");
} else {
    pass('list: director does not see new-status request', "Correct — director only sees awaiting-director+terminal");
}

// 5-E: Response shape check
if (!empty($mgRequests)) {
    $r = $mgRequests[0];
    $requiredKeys = ['id', 'db_id', 'employee', 'systems', 'approvals', 'status', 'justification', 'startDate'];
    $missingKeys = array_diff($requiredKeys, array_keys($r));
    if (empty($missingKeys)) {
        pass('list: response shape has all required keys', implode(', ', $requiredKeys));
    } else {
        fail('list: response shape missing keys', implode(', ', $missingKeys));
    }
}


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 6 — approve.php: validation
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 6] approve.php validation\n";

// 6-A: Missing fields
[$code, $body] = http('POST', BASE_URL . '/approve.php', ['action' => 'approved'], $ofCsrf, $ofJar);
assert_eq(422, $code, 'approve: missing request_db_id → 422');

// 6-B: Invalid action
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $req1DbId,
    'action'        => 'maybe',
    'step_role'     => 'officer-1',
], $ofCsrf, $ofJar);
assert_eq(422, $code, 'approve: invalid action value → 422');

// 6-C: Approve without signature
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $req1DbId,
    'action'        => 'approved',
    'step_role'     => 'officer-1',
], $ofCsrf, $ofJar);
assert_eq(422, $code, 'approve: no signature on approval → 422');

// 6-D: Reject without reason
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $req1DbId,
    'action'        => 'rejected',
    'step_role'     => 'officer-1',
    'reason'        => 'AB',  // too short
], $ofCsrf, $ofJar);
assert_eq(422, $code, 'approve: rejection reason too short → 422');

// 6-E: Wrong role — manager tries to act as officer (use any valid-looking id; 403 fires before DB lookup)
$anyId = $req1DbId ?: 1;
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $anyId,
    'action'        => 'approved',
    'step_role'     => 'officer-1',
    'signature'     => $SIG_DRAWN,
], $mgCsrf, $mgJar);
assert_eq(403, $code, 'approve: manager acting as officer → 403');

// 6-F: Director tries to approve a 'new' request (wrong status transition → 409)
if ($req1DbId) {
    [$code, $body] = http('POST', BASE_URL . '/approve.php', [
        'request_db_id' => $req1DbId,
        'action'        => 'approved',
        'step_role'     => 'director',
        'signature'     => $SIG_DRAWN,
    ], $drCsrf, $drJar);
    assert_eq(409, $code, 'approve: director on new-status request → 409 (invalid transition)');
} else {
    skip('approve: director on wrong-status request', 'No req1DbId available');
}


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 7 — Full happy-path workflow: new → awaiting-director → provisioned
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 7] Full approval workflow\n";

if (!$req1DbId) {
    skip('Phase 7', 'No request ID from Phase 4 — skipping');
    goto phase8;
}

// 7-A: Officer approves (officer-1)
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id'    => $req1DbId,
    'action'           => 'approved',
    'step_role'        => 'officer-1',
    'signature'        => $SIG_DRAWN,
    'actioned_systems' => ['inpensions', 'smartstream', 'helpdesk'],
], $ofCsrf, $ofJar);
assert_eq(200, $code, 'workflow: officer approve → 200');
if (is_array($body) && ($body['ok'] ?? false)) {
    assert_eq('awaiting-director', $body['new_status'], 'workflow: status after officer = awaiting-director');
} else {
    fail('workflow: officer approve body', json_encode($body));
}

// 7-B: DB status check
$row = $conn->query("SELECT status, claimed_by FROM it_access_requests WHERE id=$req1DbId")->fetch_assoc();
assert_eq('awaiting-director', $row['status'],   'workflow: DB status = awaiting-director');
assert_eq($officerId,          (int)$row['claimed_by'], 'workflow: DB claimed_by = officer id');

// 7-C: Director now sees request
[$code, $listBody] = http('GET', BASE_URL . '/list.php', [], '', $drJar);
$drIds2 = array_column($listBody['requests'] ?? [], 'db_id');
if (in_array($req1DbId, $drIds2)) {
    pass('workflow: director sees awaiting-director request', "db_id=$req1DbId");
} else {
    fail('workflow: director sees awaiting-director request', "Not in director list: " . implode(',', $drIds2));
}

// 7-D: Officer can't double-approve same request
[$code] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $req1DbId,
    'action'        => 'approved',
    'step_role'     => 'officer-1',
    'signature'     => $SIG_DRAWN,
], $ofCsrf, $ofJar);
assert_eq(409, $code, 'workflow: officer double-approve → 409 conflict');

// 7-E: Director approves (final)
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $req1DbId,
    'action'        => 'approved',
    'step_role'     => 'director',
    'signature'     => $SIG_UPLOADED,   // uploaded this time
], $drCsrf, $drJar);
assert_eq(200, $code, 'workflow: director approve → 200');
if (is_array($body) && ($body['ok'] ?? false)) {
    assert_eq('provisioned', $body['new_status'], 'workflow: status after director = provisioned');
} else {
    fail('workflow: director approve body', json_encode($body));
}

// 7-F: DB final state
$row = $conn->query("SELECT status, provisioned_at, pdf_filename FROM it_access_requests WHERE id=$req1DbId")->fetch_assoc();
assert_eq('provisioned', $row['status'], 'workflow: DB final status = provisioned');
if ($row['provisioned_at']) {
    pass('workflow: DB provisioned_at set', $row['provisioned_at']);
} else {
    fail('workflow: DB provisioned_at', 'NULL');
}

// 7-G: Approval chain in DB (manager + officer + director = 3 rows)
$apprCount = (int)$conn->query("SELECT COUNT(*) AS c FROM it_request_approvals WHERE request_id=$req1DbId")->fetch_assoc()['c'];
assert_eq(3, $apprCount, 'workflow: DB has 3 approval rows (manager + officer + director)');

// 7-H: PDF generated
if ($row['pdf_filename']) {
    pass('workflow: pdf_filename set in DB', $row['pdf_filename']);
    $pdfPath = __DIR__ . '/../../uploads/it_access_pdfs/' . $row['pdf_filename'];
    if (file_exists($pdfPath) && filesize($pdfPath) > 1024) {
        pass('workflow: PDF file exists on disk', round(filesize($pdfPath)/1024, 1) . ' KB');
    } else {
        fail('workflow: PDF file on disk', file_exists($pdfPath) ? 'File too small (' . filesize($pdfPath) . ' bytes)' : 'File not found at ' . $pdfPath);
    }
} else {
    fail('workflow: pdf_filename', 'NULL — PDF generation may have failed (check error.log)');
}

// 7-I: Manager now sees provisioned request in list
[$code, $body] = http('GET', BASE_URL . '/list.php', [], '', $mgJar);
$mgIds2 = array_column($body['requests'] ?? [], 'db_id');
if (in_array($req1DbId, $mgIds2)) {
    pass('workflow: manager sees provisioned request', "db_id=$req1DbId");
} else {
    fail('workflow: manager sees provisioned request', "db_id=$req1DbId not in: " . implode(',', $mgIds2));
}

// 7-J: pdfFilename returned in list response
$provReq = array_values(array_filter($body['requests'] ?? [], fn($r) => $r['db_id'] === $req1DbId))[0] ?? null;
if ($provReq && !empty($provReq['pdfFilename'])) {
    pass('workflow: pdfFilename in list response', $provReq['pdfFilename']);
} else {
    fail('workflow: pdfFilename in list response', 'pdfFilename missing or null in list.php response');
}

phase8:

// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 8 — download_pdf.php
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 8] download_pdf.php\n";

// 8-A: Unauthenticated
[$code] = http('GET', BASE_URL . '/download_pdf.php?id=' . ($req1DbId ?? 1));
assert_eq(401, $code, 'download_pdf: unauthenticated → 401');

// 8-B: Missing id param
[$code] = http('GET', BASE_URL . '/download_pdf.php', [], '', $mgJar);
assert_eq(400, $code, 'download_pdf: missing id → 400');

// 8-C: Non-existent id
[$code] = http('GET', BASE_URL . '/download_pdf.php?id=9999999', [], '', $mgJar);
assert_eq(404, $code, 'download_pdf: unknown id → 404');

// 8-D: Manager downloads own provisioned PDF
if ($req1DbId) {
    [$code, $body, $headers] = http('GET', BASE_URL . '/download_pdf.php?id=' . $req1DbId, [], '', $mgJar);
    assert_eq(200, $code, 'download_pdf: manager downloads own PDF → 200');
    if ($code === 200) {
        if (str_contains($headers, 'Content-Type: application/pdf')) {
            pass('download_pdf: Content-Type is application/pdf', '');
        } else {
            fail('download_pdf: Content-Type', 'Expected application/pdf in: ' . substr($headers, 0, 400));
        }
        $pdfBody = is_string($body) ? $body : '';
        if (str_starts_with($pdfBody, '%PDF')) {
            pass('download_pdf: response starts with %PDF', 'Valid PDF binary');
        } else {
            fail('download_pdf: response is PDF', 'Body starts with: ' . substr($pdfBody, 0, 20));
        }
    }
}

// 8-E: A random user cannot download someone else's un-owned PDF
//       We use req2DbId (submitted by manager, not by director)
if (!empty($createdRequestIds[1])) {
    $req2DbId2 = $createdRequestIds[1];
    // First provision req2 quickly so the endpoint doesn't 404 for "not provisioned"
    // (skip — just test the 403 on a provisioned one they didn't submit)
    // For now, test that director can download (has access to all)
    [$code] = http('GET', BASE_URL . '/download_pdf.php?id=' . $req1DbId, [], '', $drJar);
    assert_eq(200, $code, 'download_pdf: director can download any provisioned PDF → 200');
}


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 9 — Rejection workflow
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 9] Rejection workflow\n";

// Submit a fresh request to reject
$rejectPayload = $submitPayload;
$rejectPayload['employee']['name'] = 'Reject TestUser';
[$code, $body] = http('POST', BASE_URL . '/submit.php', $rejectPayload, $mgCsrf, $mgJar);
$rejDbId = is_array($body) ? ((int)($body['id'] ?? 0)) : 0;
if ($rejDbId) {
    $createdRequestIds[] = $rejDbId;
    pass('rejection workflow: request created', "db_id=$rejDbId");
} else {
    skip('rejection workflow', 'Could not create request for rejection test');
    goto phase10;
}

// Officer rejects
[$code, $body] = http('POST', BASE_URL . '/approve.php', [
    'request_db_id' => $rejDbId,
    'action'        => 'rejected',
    'step_role'     => 'officer-1',
    'reason'        => 'System access scope not approved by security team — additional clearance required.',
], $ofCsrf, $ofJar);
assert_eq(200, $code, 'rejection: officer reject → 200');
assert_eq('rejected', $body['new_status'] ?? '', 'rejection: new_status = rejected');

$row = $conn->query("SELECT status FROM it_access_requests WHERE id=$rejDbId")->fetch_assoc();
assert_eq('rejected', $row['status'], 'rejection: DB status = rejected');

$apprRow = $conn->query("SELECT reason, action FROM it_request_approvals WHERE request_id=$rejDbId AND step_role='officer-1'")->fetch_assoc();
assert_eq('rejected', $apprRow['action'] ?? '', 'rejection: approval action = rejected');
if (strlen($apprRow['reason'] ?? '') > 0) {
    pass('rejection: reason stored in DB', substr($apprRow['reason'], 0, 60));
} else {
    fail('rejection: reason stored in DB', 'reason is empty');
}

// Manager can still see the rejected request
[$code, $listBody] = http('GET', BASE_URL . '/list.php', [], '', $mgJar);
$mgRejIds = array_column($listBody['requests'] ?? [], 'db_id');
if (in_array($rejDbId, $mgRejIds)) {
    pass('rejection: manager sees rejected request in list', "db_id=$rejDbId");
} else {
    fail('rejection: manager sees rejected request', "db_id=$rejDbId not in list");
}

// No PDF for rejected request
[$code] = http('GET', BASE_URL . '/download_pdf.php?id=' . $rejDbId, [], '', $mgJar);
assert_eq(404, $code, 'rejection: download_pdf on rejected request → 404');

// Director rejection path — submit, officer approves, director rejects
$dirRejectPayload = $submitPayload;
$dirRejectPayload['employee']['name'] = 'DirectorReject TestUser';
[$code, $body] = http('POST', BASE_URL . '/submit.php', $dirRejectPayload, $mgCsrf, $mgJar);
$dirRejDbId = is_array($body) ? ((int)($body['id'] ?? 0)) : 0;
if ($dirRejDbId) {
    $createdRequestIds[] = $dirRejDbId;
    // Officer approve
    http('POST', BASE_URL . '/approve.php', [
        'request_db_id' => $dirRejDbId, 'action' => 'approved',
        'step_role' => 'officer-1', 'signature' => $SIG_DRAWN,
    ], $ofCsrf, $ofJar);
    // Director rejects
    [$code, $body] = http('POST', BASE_URL . '/approve.php', [
        'request_db_id' => $dirRejDbId,
        'action'        => 'rejected',
        'step_role'     => 'director',
        'reason'        => 'Board policy change — access level not compliant with Q2 governance update.',
    ], $drCsrf, $drJar);
    assert_eq(200, $code, 'rejection: director reject → 200');
    assert_eq('rejected', $body['new_status'] ?? '', 'rejection: director reject new_status = rejected');
    $row = $conn->query("SELECT status FROM it_access_requests WHERE id=$dirRejDbId")->fetch_assoc();
    assert_eq('rejected', $row['status'], 'rejection: DB status after director reject = rejected');
}

phase10:

// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 10 — Edge cases
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 10] Edge cases\n";

// 10-A: Duplicate ref number safety — submit two requests rapidly and check refs differ
[$cA, $bA] = http('POST', BASE_URL . '/submit.php', $submitPayload, $mgCsrf, $mgJar);
[$cB, $bB] = http('POST', BASE_URL . '/submit.php', $submitPayload, $mgCsrf, $mgJar);
if ($cA === 200 && $cB === 200 && is_array($bA) && is_array($bB)) {
    if ($bA['ref'] !== $bB['ref']) {
        pass('edge: concurrent submits get unique ref numbers', $bA['ref'] . ' vs ' . $bB['ref']);
    } else {
        fail('edge: concurrent submits get unique ref numbers', 'Both got ' . $bA['ref']);
    }
    if (isset($bA['id'])) $createdRequestIds[] = (int)$bA['id'];
    if (isset($bB['id'])) $createdRequestIds[] = (int)$bB['id'];
}

// 10-B: "change" request type accepted
$changePayload = $submitPayload;
$changePayload['requestType'] = 'change';
[$code, $body] = http('POST', BASE_URL . '/submit.php', $changePayload, $mgCsrf, $mgJar);
assert_eq(200, $code, 'edge: requestType=change accepted → 200');
if (isset($body['id'])) $createdRequestIds[] = (int)$body['id'];

// 10-C: System with sub_values (physical access — multi select)
$subValPayload = $submitPayload;
$subValPayload['employee']['name'] = 'SubValues Tester';
$subValPayload['systems'] = [
    ['id' => 'physical', 'role' => null, 'subValues' => ['sub_0' => ['Server room', 'Board room'], 'sub_1' => 'Normal hours']],
    ['id' => 'banking',  'role' => 'Capturer', 'subValues' => ['sub_0' => ['FNB', 'STD', 'MTN MoMo']]],
];
[$code, $body] = http('POST', BASE_URL . '/submit.php', $subValPayload, $mgCsrf, $mgJar);
assert_eq(200, $code, 'edge: systems with subValues (physical+banking) → 200');
if (isset($body['id'])) {
    $createdRequestIds[] = (int)$body['id'];
    $subValReqId = (int)$body['id'];
    $sRow = $conn->query("SELECT sub_values FROM it_request_systems WHERE request_id=$subValReqId AND system_id='physical'")->fetch_assoc();
    $decoded = json_decode($sRow['sub_values'] ?? '{}', true);
    if (is_array($decoded)) {
        pass('edge: physical sub_values stored as JSON', json_encode($decoded));
    } else {
        fail('edge: physical sub_values JSON', 'Got: ' . ($sRow['sub_values'] ?? 'null'));
    }
}

// 10-D: Inactive user cannot log in (enforceActiveUser check)
$conn->query("UPDATE users SET is_active=0 WHERE id=$managerId");
[$code2, $body2] = http('GET', BASE_URL . '/list.php', [], '', $mgJar);
// Session is still alive so cookie still works, but enforceActiveUser should kill it
// Result depends on implementation — 401 or 403 expected
// enforceActiveUser does session_destroy + Location redirect (302) for HTML-mixed endpoints
if (in_array($code2, [401, 403, 302])) {
    pass('edge: disabled user session rejected', "HTTP $code2 (session invalidated)");
} else {
    fail('edge: disabled user session rejected', "Expected 401/403/302, got $code2");
}
$conn->query("UPDATE users SET is_active=1 WHERE id=$managerId");  // re-enable


// ═══════════════════════════════════════════════════════════════════════════════
// PHASE 11 — Teardown
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n[Phase 11] Teardown\n";

// Clean up temp cookie jars
foreach ([$mgJar, $ofJar, $drJar] as $jar) {
    if ($jar && file_exists($jar)) @unlink($jar);
}

teardown($conn, $createdUserIds, $createdRequestIds);

$verify = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE username LIKE 'test_ita_%'")->fetch_assoc()['c'];
if ($verify === 0) {
    pass('Teardown: test users removed', '');
} else {
    fail('Teardown: test users removed', "$verify test_ita_ users still in DB");
}

$verifyR = (int)$conn->query("SELECT COUNT(*) AS c FROM it_access_requests WHERE employee_name LIKE '%TestUser%' OR employee_name LIKE '%Testington%' OR employee_name LIKE '%Uploadington%' OR employee_name LIKE '%Tester%'")->fetch_assoc()['c'];
if ($verifyR === 0) {
    pass('Teardown: test requests removed', '');
} else {
    fail('Teardown: test requests removed', "$verifyR test requests still in DB");
}

goto report;

teardown_early:
echo "\n[Teardown Early] Cleaning up after early abort\n";
teardown($conn, $createdUserIds, $createdRequestIds);

report:

// ═══════════════════════════════════════════════════════════════════════════════
// REPORT
// ═══════════════════════════════════════════════════════════════════════════════
$total  = count($results);
$passed = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$failed = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
$skipped= count(array_filter($results, fn($r) => $r['status'] === 'SKIP'));

ob_end_clean();

if ($isCli) {
    // ── CLI output ────────────────────────────────────────────────────────────
    $width = 80;
    echo str_repeat('═', $width) . "\n";
    echo " IT ACCESS FORM — INTEGRATION TEST REPORT\n";
    echo str_repeat('═', $width) . "\n";
    echo " Date : " . date('Y-m-d H:i:s') . "\n";
    echo " Total: $total   PASS: $passed   FAIL: $failed   SKIP: $skipped\n";
    echo str_repeat('─', $width) . "\n";
    foreach ($results as $r) {
        $icon = match($r['status']) { 'PASS' => '✓', 'FAIL' => '✗', default => '○' };
        $line = " $icon [{$r['status']}] {$r['name']}";
        if ($r['detail']) $line .= "\n        └─ {$r['detail']}";
        echo $line . "\n";
    }
    echo str_repeat('═', $width) . "\n";
    $emoji = $failed === 0 ? '✓ ALL PASSED' : "✗ $failed FAILED";
    echo " $emoji\n";
    echo str_repeat('═', $width) . "\n";
    exit($failed > 0 ? 1 : 0);
} else {
    // ── HTML output ───────────────────────────────────────────────────────────
    $pct = $total ? round($passed / $total * 100) : 0;
    $barColor = $failed === 0 ? '#16a34a' : ($failed < 3 ? '#d97706' : '#dc2626');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IT Access — Test Report</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; color: #1e293b; margin: 0; padding: 24px; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  .meta { font-size: .85rem; color: #64748b; margin-bottom: 20px; }
  .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
  .stat { background: white; border-radius: 10px; padding: 14px 20px; min-width: 110px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .stat .n { font-size: 2rem; font-weight: 700; line-height: 1; }
  .stat .l { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin-top: 4px; }
  .pass .n { color: #16a34a; }
  .fail .n { color: #dc2626; }
  .skip .n { color: #d97706; }
  .bar-wrap { background: #e2e8f0; border-radius: 6px; height: 10px; margin-bottom: 24px; }
  .bar-fill { height: 10px; border-radius: 6px; background: <?= $barColor ?>; width: <?= $pct ?>%; transition: width .4s; }
  table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); font-size: .875rem; }
  th { background: #1e293b; color: white; padding: 10px 14px; text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; }
  td { padding: 9px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #f8fafc; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: .75rem; font-weight: 600; }
  .badge-pass { background: #dcfce7; color: #166534; }
  .badge-fail { background: #fee2e2; color: #991b1b; }
  .badge-skip { background: #fef3c7; color: #92400e; }
  .detail { font-size: .8rem; color: #64748b; margin-top: 2px; font-family: monospace; word-break: break-all; }
</style>
</head>
<body>
<h1>IT Access Form — Integration Test Report</h1>
<p class="meta">Run at <?= date('Y-m-d H:i:s') ?> &nbsp;·&nbsp; <?= $total ?> tests &nbsp;·&nbsp; <?= $pct ?>% passed</p>
<div class="summary">
  <div class="stat pass"><div class="n"><?= $passed ?></div><div class="l">Passed</div></div>
  <div class="stat fail"><div class="n"><?= $failed ?></div><div class="l">Failed</div></div>
  <div class="stat skip"><div class="n"><?= $skipped ?></div><div class="l">Skipped</div></div>
  <div class="stat"><div class="n"><?= $total ?></div><div class="l">Total</div></div>
</div>
<div class="bar-wrap"><div class="bar-fill"></div></div>
<table>
  <thead><tr><th>#</th><th>Status</th><th>Test</th><th>Detail</th></tr></thead>
  <tbody>
<?php foreach ($results as $i => $r):
  $cls = match($r['status']) { 'PASS' => 'pass', 'FAIL' => 'fail', default => 'skip' };
?>
    <tr>
      <td style="color:#94a3b8;width:36px"><?= $i+1 ?></td>
      <td><span class="badge badge-<?= $cls ?>"><?= $r['status'] ?></span></td>
      <td><?= htmlspecialchars($r['name']) ?></td>
      <td><span class="detail"><?= htmlspecialchars($r['detail']) ?></span></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
<?php
}
