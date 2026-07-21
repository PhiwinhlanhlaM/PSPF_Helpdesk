<?php
/**
 * Org Structure — division supervisors and delegates. Superadmin only.
 *
 * Sets who approves IT access requests for each division, and who covers when
 * that person is away. A requester's approver is resolved as:
 *
 *     1. their own supervisor override (set per user, for divisions with
 *        internal tiers such as Benefits)
 *     2. otherwise their division's supervisor (set here)
 *     3. otherwise nobody — the request skips the supervisor step and goes
 *        straight to the ICT queue, so it never stalls
 *
 * Only users holding the `supervisor` role can be chosen. Grant that role in
 * Settings -> User Management first.
 */

session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// ---------------------------------------------------------------------------
// AUTHORIZATION — superadmin only. Checked on held roles, and re-checked on
// every POST below: hiding the UI is never the access control.
// ---------------------------------------------------------------------------
if (!isLoggedIn() || !hasRole('superadmin')) {
    http_response_code(403);
    echo "<h3>403 - Forbidden</h3><p>Org Structure is restricted to superadministrators.</p>";
    exit;
}

$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'];
$UserDept     = $_SESSION['user']['department'] ?? '';

$activeRole   = getActiveRole();
$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');
$role         = $_SESSION['active_role'] ?? 'user';
$roleIcons    = [
    'superadmin' => 'bi-person-gear', 'admin' => 'bi-shield-fill-check',
    'agent' => 'bi-headset', 'user' => 'bi-person-fill',
    'it_officer' => 'bi-person-badge', 'it_director' => 'bi-person-check',
];
$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

$CSRF = $_SESSION['csrf_token'] ?? '';
if ($CSRF === '') {
    $CSRF = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $CSRF;
}

function org_flash_redirect(string $kind, string $msg): void {
    $_SESSION['org_flash'] = ['kind' => $kind, 'msg' => $msg];
    header('Location: org_structure.php');
    exit;
}

/** Record a change in audit_logs, matching settings/profile.php's shape. */
function org_audit(mysqli $conn, int $userId, string $action, string $details): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) return;
    $stmt->bind_param("issss", $userId, $action, $details, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

// ---------------------------------------------------------------------------
// POST — assign a division's supervisor / delegate.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($CSRF, $_POST['csrf_token'] ?? '')) {
        org_flash_redirect('danger', 'Security check failed. Please try again.');
    }

    $divisionId  = (int)($_POST['division_id'] ?? 0);
    // Empty string means "clear it" — stored as NULL.
    $supervisorId = ($_POST['supervisor_id'] ?? '') === '' ? null : (int)$_POST['supervisor_id'];
    $delegateId   = ($_POST['delegate_id']   ?? '') === '' ? null : (int)$_POST['delegate_id'];

    if ($divisionId <= 0) {
        org_flash_redirect('danger', 'Unknown division.');
    }
    if ($supervisorId !== null && $supervisorId === $delegateId) {
        org_flash_redirect('danger', 'The delegate must be someone other than the supervisor — otherwise there is no cover when they are away.');
    }

    // Only users who actually hold the supervisor role may be assigned. This is
    // re-validated server-side because the dropdown is not the access control.
    $validate = function (?int $uid) use ($conn): bool {
        if ($uid === null) return true;
        $st = $conn->prepare(
            "SELECT 1 FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ? AND r.name = 'supervisor' AND u.is_active = 1 LIMIT 1"
        );
        $st->bind_param("i", $uid);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_assoc();
        $st->close();
        return $ok;
    };
    if (!$validate($supervisorId) || !$validate($delegateId)) {
        org_flash_redirect('danger', 'That person does not hold the Supervisor role. Grant it in User Management first.');
    }

    $stmt = $conn->prepare("UPDATE divisions SET supervisor_id = ?, delegate_id = ? WHERE id = ?");
    $stmt->bind_param("iii", $supervisorId, $delegateId, $divisionId);
    $stmt->execute();
    $stmt->close();

    $nameStmt = $conn->prepare("SELECT division_name FROM divisions WHERE id = ?");
    $nameStmt->bind_param("i", $divisionId);
    $nameStmt->execute();
    $divName = $nameStmt->get_result()->fetch_assoc()['division_name'] ?? "#$divisionId";
    $nameStmt->close();

    org_audit($conn, $UserId, 'org_supervisor_set',
        "Set supervisor/delegate for division '{$divName}'");
    org_flash_redirect('success', "Saved approvers for {$divName}.");
}

// ---------------------------------------------------------------------------
// Data for rendering
// ---------------------------------------------------------------------------
$flash = $_SESSION['org_flash'] ?? null;
unset($_SESSION['org_flash']);

// Everyone holding the supervisor role — the only valid choices.
$supervisors = $conn->query(
    "SELECT u.id, COALESCE(NULLIF(TRIM(u.full_name), ''), u.Username) AS display
     FROM users u
     JOIN user_roles ur ON ur.user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     WHERE r.name = 'supervisor' AND u.is_active = 1
     ORDER BY display"
)->fetch_all(MYSQLI_ASSOC);

// Divisions with their department, current approvers, and headcount.
$divisions = $conn->query(
    "SELECT d.id, d.division_name, d.supervisor_id, d.delegate_id,
            dep.department_name,
            (SELECT COUNT(*) FROM users u WHERE u.division_id = d.id) AS headcount,
            sup.Username AS sup_username, sup.full_name AS sup_full,
            del.Username AS del_username, del.full_name AS del_full,
            (SELECT COUNT(*) FROM users u2
             WHERE u2.division_id = d.id AND u2.supervisor_id IS NOT NULL) AS overrides
     FROM divisions d
     JOIN departments dep ON dep.id = d.department_id
     LEFT JOIN users sup ON sup.id = d.supervisor_id
     LEFT JOIN users del ON del.id = d.delegate_id
     ORDER BY dep.department_name, d.division_name"
)->fetch_all(MYSQLI_ASSOC);

$assigned = 0;
foreach ($divisions as $d) { if ($d['supervisor_id']) $assigned++; }

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function disp(?string $full, ?string $uname): string {
    $full = trim((string)$full);
    return $full !== '' ? $full : (string)$uname;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Org Structure - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">
    <style>
        .settings-title { font-weight: 600; }
        .dept-head {
            font-size: .72rem; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: #6c757d;
            padding: .9rem .25rem .35rem;
        }
        .div-card { border: 1px solid #e3e6ea; border-radius: .5rem; background: #fff; }
        .div-card + .div-card { margin-top: .5rem; }
        .div-name { font-weight: 600; }
        .muted-sm { font-size: .8rem; color: #6c757d; }
        .unset { color: #b02a37; font-style: italic; }
        .chip-count {
            font-size: .72rem; background: #eef2f6; color: #47546a;
            padding: .1rem .4rem; border-radius: .25rem;
        }
    </style>
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<div class="container-xl mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="settings-title mb-1">Org Structure</h1>
            <p class="text-muted mb-0">
                Who approves IT access requests for each division, and who covers when they're away.
            </p>
        </div>
        <span class="badge bg-light text-dark border align-self-center">
            <?= (int)$assigned ?> of <?= count($divisions) ?> divisions assigned
        </span>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['kind']) ?> alert-dismissible fade show mt-3" role="alert">
            <?= e($flash['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$supervisors): ?>
        <div class="alert alert-warning mt-3">
            <strong>No one holds the Supervisor role yet.</strong>
            Assign it to the relevant people in
            <a href="/pspf_crm/api/settings/user_management.php" class="alert-link">User Management</a>,
            then come back here to put them in charge of their divisions.
        </div>
    <?php else: ?>
        <div class="alert alert-light border mt-3 mb-2">
            <i class="bi bi-info-circle me-1"></i>
            A request goes to the requester's own supervisor if one is set for them individually,
            otherwise to their division's supervisor below. If neither is set, it skips straight to
            the ICT team rather than waiting.
        </div>
    <?php endif; ?>

    <?php
    $currentDept = null;
    foreach ($divisions as $d):
        if ($d['department_name'] !== $currentDept):
            $currentDept = $d['department_name'];
    ?>
        <div class="dept-head"><?= e($currentDept) ?></div>
    <?php endif; ?>

        <div class="div-card p-3">
            <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                <input type="hidden" name="division_id" value="<?= (int)$d['id'] ?>">

                <div class="col-lg-3">
                    <div class="div-name"><?= e($d['division_name']) ?></div>
                    <div class="muted-sm">
                        <span class="chip-count"><?= (int)$d['headcount'] ?> <?= (int)$d['headcount'] === 1 ? 'person' : 'people' ?></span>
                        <?php if ((int)$d['overrides'] > 0): ?>
                            <span class="chip-count" title="These people have their own supervisor set individually">
                                <?= (int)$d['overrides'] ?> with own supervisor
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <label class="form-label muted-sm mb-1">Supervisor</label>
                    <select name="supervisor_id" class="form-select form-select-sm" <?= $supervisors ? '' : 'disabled' ?>>
                        <option value="">— not set (goes straight to ICT) —</option>
                        <?php foreach ($supervisors as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$d['supervisor_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= e($s['display']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-4">
                    <label class="form-label muted-sm mb-1">Delegate <span class="text-muted">(covers absence)</span></label>
                    <select name="delegate_id" class="form-select form-select-sm" <?= $supervisors ? '' : 'disabled' ?>>
                        <option value="">— none —</option>
                        <?php foreach ($supervisors as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)$d['delegate_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= e($s['display']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-1 d-grid">
                    <button class="btn btn-sm btn-primary" <?= $supervisors ? '' : 'disabled' ?>>Save</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
