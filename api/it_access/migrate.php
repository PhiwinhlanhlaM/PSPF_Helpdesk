<?php
/**
 * Migration runner — superadmin only.
 * Reads every *.sql file in /database/, tracks which have been applied in a
 * `migrations` table, and runs pending ones in filename order.
 *
 * GET  → shows status of all migrations (HTML page)
 * POST → runs all pending migrations and redirects back to GET
 */
require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn() || getActiveRole() !== 'superadmin') {
    http_response_code(403);
    echo '<h3>403 – Forbidden</h3><p>Superadmin access required.</p>';
    exit;
}

// CSRF guard on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo '<h3>403 – CSRF mismatch</h3>';
        exit;
    }
}

// ── Ensure migrations tracking table exists ──────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`         int(11)      NOT NULL AUTO_INCREMENT,
        `filename`   varchar(255) NOT NULL,
        `applied_at` datetime     NOT NULL DEFAULT current_timestamp(),
        `applied_by` int(11)      DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `filename` (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Discover SQL files ────────────────────────────────────────────────────────
$dbDir = realpath(__DIR__ . '/../../database');
$files = glob($dbDir . DIRECTORY_SEPARATOR . '*.sql');
if (!$files) $files = [];
sort($files); // alphabetical = deterministic order

// ── Load already-applied migrations ──────────────────────────────────────────
$applied = [];
$res = $conn->query("SELECT filename FROM migrations");
while ($row = $res->fetch_assoc()) {
    $applied[$row['filename']] = true;
}

// ── POST: run pending migrations ──────────────────────────────────────────────
$runLog = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)$_SESSION['user']['id'];

    foreach ($files as $path) {
        $name = basename($path);
        if (isset($applied[$name])) continue; // already done

        $sql = file_get_contents($path);
        if ($sql === false) {
            $runLog[] = ['file' => $name, 'ok' => false, 'msg' => 'Could not read file'];
            continue;
        }

        // Split on semicolons to run statement-by-statement
        // (mysqli::query only handles one statement at a time without multi_query)
        $stmts = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== '' && !preg_match('/^--/', $s) && !preg_match('/^\s*$/', $s)
        );

        $ok  = true;
        $err = '';
        foreach ($stmts as $stmt) {
            if (trim($stmt) === '') continue;
            if (!$conn->query($stmt)) {
                // Ignore "already exists" / "duplicate entry" — migrations must be idempotent
                $errno = $conn->errno;
                if (!in_array($errno, [
                    1050, // Table already exists
                    1060, // Duplicate column
                    1061, // Duplicate key
                    1062, // Duplicate entry (INSERT IGNORE doesn't suppress at execute level for some drivers)
                    1091, // Can't DROP non-existent key
                    1146, // Table doesn't exist (for DROP IF NOT EXISTS fallback)
                ])) {
                    $ok  = false;
                    $err = "errno {$conn->errno}: {$conn->error}";
                    error_log("Migration $name failed: $err — stmt: " . substr($stmt, 0, 200));
                    break;
                }
            }
        }

        if ($ok) {
            $insStmt = $conn->prepare("INSERT IGNORE INTO migrations (filename, applied_by) VALUES (?, ?)");
            $insStmt->bind_param("si", $name, $userId);
            $insStmt->execute();
            $insStmt->close();
            $applied[$name] = true;
        }

        $runLog[] = ['file' => $name, 'ok' => $ok, 'msg' => $err];
    }

    // Redirect to GET so a page refresh doesn't re-submit
    $qs = http_build_query(['ran' => count(array_filter($runLog, fn($r) => $r['ok'])), 'failed' => count(array_filter($runLog, fn($r) => !$r['ok']))]);
    header("Location: /pspf_crm/api/it_access/migrate.php?$qs");
    exit;
}

// ── GET: render status page ───────────────────────────────────────────────────
$pendingCount = 0;
foreach ($files as $path) {
    if (!isset($applied[basename($path)])) $pendingCount++;
}

$csrf       = $_SESSION['csrf_token'];
$ran        = (int)($_GET['ran']    ?? -1);
$failed     = (int)($_GET['failed'] ?? 0);
$UserUsername = $_SESSION['user']['username'];
$iconClass  = 'bi-person-gear';
$UserDept   = $_SESSION['user']['department'] ?? '';
$activeRole = getActiveRole();
$isSuperAdmin = true;
$isAdmin      = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migrations — PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">
    <link rel="icon" type="image/png" href="/pspf_crm/api/uploads/pspflogo2.png">
</head>
<body>
<?php include '../agent/topnav.php'; ?>

<div class="container mt-5 mb-4" style="max-width:860px;">
    <div class="settings-header mb-4">
        <h1 class="settings-title"><i class="bi bi-database-gear me-2"></i>Database Migrations</h1>
        <div class="settings-actions">
            <a href="<?= getRoleHomePage() ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <?php if ($ran >= 0): ?>
    <div class="alert alert-<?= $failed > 0 ? 'warning' : 'success' ?> d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-<?= $failed > 0 ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"></i>
        <div>
            <?php if ($ran > 0): ?>
                <strong><?= $ran ?> migration<?= $ran !== 1 ? 's' : '' ?> applied successfully.</strong>
            <?php else: ?>
                <strong>Nothing to run — all migrations were already applied.</strong>
            <?php endif; ?>
            <?php if ($failed > 0): ?>
                <strong class="text-danger"> <?= $failed ?> failed</strong> — check the PHP error log for details.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($pendingCount > 0): ?>
    <div class="alert alert-info d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-hourglass-split"></i>
        <div><strong><?= $pendingCount ?> pending migration<?= $pendingCount !== 1 ? 's' : '' ?></strong> — click <em>Run pending</em> to apply them.</div>
    </div>
    <?php else: ?>
    <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-check2-all"></i>
        <div><strong>Database is up to date.</strong> All migrations have been applied.</div>
    </div>
    <?php endif; ?>

    <form method="POST" action="/pspf_crm/api/it_access/migrate.php" class="mb-4"
          onsubmit="return confirm('Run <?= $pendingCount ?> pending migration(s)? This cannot be undone.')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn btn-primary" <?= $pendingCount === 0 ? 'disabled' : '' ?>>
            <i class="bi bi-play-fill me-1"></i> Run pending (<?= $pendingCount ?>)
        </button>
    </form>

    <div class="card">
        <div class="card-header fw-semibold py-2 px-3" style="background:#f6f8fb;">
            <i class="bi bi-list-check me-1"></i> Migration files in <code>/database/</code>
        </div>
        <table class="table table-hover mb-0" style="font-size:13.5px;">
            <thead style="background:#f6f8fb;">
                <tr>
                    <th class="ps-3">File</th>
                    <th style="width:130px;">Status</th>
                    <th style="width:180px;">Applied at</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Fetch applied_at timestamps
            $times = [];
            $tres = $conn->query("SELECT filename, applied_at FROM migrations");
            while ($tr = $tres->fetch_assoc()) {
                $times[$tr['filename']] = $tr['applied_at'];
            }

            foreach ($files as $path):
                $name      = basename($path);
                $isApplied = isset($applied[$name]);
                $appliedAt = $times[$name] ?? null;
            ?>
            <tr>
                <td class="ps-3">
                    <i class="bi bi-file-earmark-code me-1 text-muted"></i>
                    <code><?= htmlspecialchars($name) ?></code>
                </td>
                <td>
                    <?php if ($isApplied): ?>
                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Applied</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:12px;">
                    <?= $appliedAt ? date('d M Y H:i', strtotime($appliedAt)) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($files)): ?>
            <tr><td colspan="3" class="text-center py-4 text-muted">No SQL files found in <code>/database/</code>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="text-muted mt-3" style="font-size:12px;">
        <i class="bi bi-info-circle me-1"></i>
        Migration files are run in alphabetical order. Each file is applied exactly once.
        <code>schema.sql</code> is typically already applied on a running installation — if it's shown as Pending, it is safe to run (all statements use <code>CREATE TABLE IF NOT EXISTS</code> / <code>INSERT IGNORE</code>).
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
