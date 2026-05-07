<?php
/**
 * CLI script to create a user and assign roles in pspf_helpdesk.
 *
 * Usage:
 *   php database/create_user.php
 *
 * Run from the pspf_crm/ directory. Requires PHP CLI and a running MySQL server.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// ── Config ────────────────────────────────────────────────────────────────────
$dbHost = '127.0.0.1';
$dbName = 'pspf_helpdesk';
$dbUser = 'root';
$dbPass = '';
// ─────────────────────────────────────────────────────────────────────────────

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_errno) {
    die("Database connection failed: " . $conn->connect_error . "\n");
}

// Fetch available roles and divisions for display
$roles     = $conn->query("SELECT id, name FROM roles ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$divisions = $conn->query("
    SELECT d.id, d.division_name, dep.department_name
    FROM divisions d
    JOIN departments dep ON dep.id = d.department_id
    ORDER BY dep.department_name, d.division_name
")->fetch_all(MYSQLI_ASSOC);

function prompt(string $label, bool $secret = false): string {
    echo $label;
    if ($secret && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $value = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $value = trim(fgets(STDIN));
    }
    return $value;
}

echo "\n";
echo "╔══════════════════════════════════════╗\n";
echo "║     PSPF CRM — Create New User       ║\n";
echo "╚══════════════════════════════════════╝\n\n";

// ── Collect user details ──────────────────────────────────────────────────────

$username = '';
while (empty($username)) {
    $username = prompt("Username: ");
    $check = $conn->prepare("SELECT id FROM users WHERE Username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo "  ✖ Username already exists. Choose another.\n";
        $username = '';
    }
}

$email = '';
while (empty($email)) {
    $email = prompt("Email: ");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "  ✖ Invalid email address.\n";
        $email = '';
        continue;
    }
    $check = $conn->prepare("SELECT id FROM users WHERE Email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo "  ✖ Email already registered.\n";
        $email = '';
    }
}

$password = '';
while (strlen($password) < 8) {
    $password = prompt("Password (min 8 chars): ", secret: true);
    if (strlen($password) < 8) {
        echo "  ✖ Password too short.\n";
    }
}
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// ── Division picker ───────────────────────────────────────────────────────────

echo "\nAvailable Divisions:\n";
foreach ($divisions as $div) {
    printf("  [%2d] %-30s (%s)\n", $div['id'], $div['division_name'], $div['department_name']);
}

$divisionId = 0;
$validDivIds = array_column($divisions, 'id');
while (!in_array($divisionId, $validDivIds)) {
    $divisionId = (int) prompt("\nEnter Division ID: ");
    if (!in_array($divisionId, $validDivIds)) {
        echo "  ✖ Invalid division ID.\n";
    }
}

// Derive department name from chosen division
$divRow = array_filter($divisions, fn($d) => $d['id'] === $divisionId);
$department = array_values($divRow)[0]['department_name'];

// ── Role picker ───────────────────────────────────────────────────────────────

echo "\nAvailable Roles:\n";
foreach ($roles as $role) {
    printf("  [%d] %s\n", $role['id'], ucfirst($role['name']));
}

$selectedRoleIds = [];
echo "\nEnter role ID(s) to assign, separated by commas (e.g. 1,3): ";
$roleInput = trim(fgets(STDIN));
$inputIds  = array_map('intval', explode(',', $roleInput));
$validRoleIds = array_column($roles, 'id');

foreach ($inputIds as $rid) {
    if (in_array($rid, $validRoleIds)) {
        $selectedRoleIds[] = $rid;
    } else {
        echo "  ⚠ Role ID $rid not found — skipped.\n";
    }
}

if (empty($selectedRoleIds)) {
    echo "  No valid roles selected. Defaulting to 'user' (id=1).\n";
    $selectedRoleIds = [1];
}

// ── Confirm ───────────────────────────────────────────────────────────────────

$roleNames = array_map(function($rid) use ($roles) {
    $r = array_filter($roles, fn($r) => $r['id'] === $rid);
    return ucfirst(array_values($r)[0]['name']);
}, $selectedRoleIds);

echo "\n────────────────────────────────────────\n";
echo "  Username   : $username\n";
echo "  Email      : $email\n";
echo "  Department : $department\n";
echo "  Division ID: $divisionId\n";
echo "  Roles      : " . implode(', ', $roleNames) . "\n";
echo "────────────────────────────────────────\n";

$confirm = prompt("Create this user? [y/N]: ");
if (strtolower($confirm) !== 'y') {
    echo "Aborted.\n\n";
    exit(0);
}

// ── Insert ────────────────────────────────────────────────────────────────────

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        INSERT INTO users (Username, department, division_id, Email, Password)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssiss", $username, $department, $divisionId, $email, $passwordHash);
    $stmt->execute();
    $userId = $conn->insert_id;

    $roleStmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
    foreach ($selectedRoleIds as $rid) {
        $roleStmt->bind_param("ii", $userId, $rid);
        $roleStmt->execute();
    }

    $conn->commit();
    echo "\n✔ User '$username' created successfully (ID: $userId).\n\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "\n✖ Failed to create user: " . $e->getMessage() . "\n\n";
    exit(1);
}

$conn->close();
