<?php
session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// ---------------------------------------------------------------------------
// AUTHORIZATION — the Deploy dashboard is superadmin-only.
// The page AND every mutating action are gated (held-role check). Hiding the UI
// is never sufficient: each POST re-checks so a crafted request from a lower-
// privilege account cannot queue or approve a deploy.
//
// SECURITY MODEL: this page only ever reads/writes the deploy_requests and
// deploy_state tables. It NEVER runs git, PowerShell, or any shell command.
// The privileged PowerShell runner (a scheduled task under a service account)
// is the sole actor that touches git and live files. See deploy/PIPELINE_DESIGN.md.
// ---------------------------------------------------------------------------
if (!isLoggedIn() || !hasRole('superadmin')) {
    http_response_code(403);
    echo "<h3>403 - Forbidden</h3><p>The deployment dashboard is restricted to superadministrators.</p>";
    exit;
}

$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'] ?? '';
$UserDept     = $_SESSION['user']['department'] ?? '';

$activeRole   = getActiveRole();
// Role flags consumed by the shared topnav (all must be defined).
$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');
$role         = $_SESSION['active_role'] ?? 'user';
$roleIcons    = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill',
];
$iconClass    = $roleIcons[$role] ?? 'bi-person-fill';

// ---------------------------------------------------------------------------
// Optional separation-of-duties: when enabled, the superadmin who requested a
// check cannot be the one who approves it. Off by default (single-operator
// teams); flip to true once the team has >=2 superadmins. See PIPELINE_DESIGN Q2.
// ---------------------------------------------------------------------------
$ENFORCE_SEPARATION_OF_DUTIES = false;

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/** Flash helper: store a message, then redirect (POST-Redirect-GET). */
function flash_redirect(string $kind, string $msg): void {
    $_SESSION['deploy_flash'] = ['kind' => $kind, 'msg' => $msg];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ---------------------------------------------------------------------------
// POST HANDLING — superadmin already enforced. Every branch is CSRF-checked.
// The dashboard writes INTENT only; it never executes anything.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($CSRF, $token)) {
        flash_redirect('error', 'Security check failed (invalid CSRF token). Please try again.');
    }

    // ---- Check for updates: queue a 'check' request for the runner ----
    if (isset($_POST['check_updates'])) {
        // Refuse to stack checks: if one is already pending/checking, do nothing.
        $busy = $conn->query("SELECT COUNT(*) AS c FROM deploy_requests WHERE type='check' AND status IN ('pending','checking')")->fetch_assoc();
        if ((int)$busy['c'] > 0) {
            flash_redirect('info', 'A check is already in progress. Please wait for the runner to finish.');
        }
        $stmt = $conn->prepare("INSERT INTO deploy_requests (type, status, requested_by, created_at, updated_at) VALUES ('check','pending',?,NOW(),NOW())");
        $stmt->bind_param("i", $UserId);
        $stmt->execute();
        $stmt->close();
        flash_redirect('success', 'Check queued. The runner will fetch the repo and report what would change (usually within a minute).');
    }

    // ---- Approve a ready check: convert it into an approved deploy ----
    if (isset($_POST['approve'])) {
        $rid = (int)($_POST['request_id'] ?? 0);
        $req = null;
        $stmt = $conn->prepare("SELECT id, type, status, commit_sha, requested_by, drift_report FROM deploy_requests WHERE id = ?");
        $stmt->bind_param("i", $rid);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req || $req['status'] !== 'ready') {
            flash_redirect('error', 'That request is no longer ready to approve (it may have changed). Re-check for updates.');
        }
        if (empty($req['commit_sha'])) {
            flash_redirect('error', 'This request has no target commit; cannot approve.');
        }
        // Never approve over drift — the runner would refuse anyway, but block early.
        $drift = json_decode($req['drift_report'] ?? '[]', true);
        if (is_array($drift) && count($drift) > 0) {
            flash_redirect('error', 'Cannot approve: live has ' . count($drift) . ' file(s) edited outside the pipeline (drift). Reconcile live into the repo, then re-check.');
        }
        if ($ENFORCE_SEPARATION_OF_DUTIES && (int)$req['requested_by'] === $UserId) {
            flash_redirect('error', 'Separation of duties: the person who requested this check cannot approve it. Ask another superadmin to approve.');
        }
        // Convert the reviewed check into an approved deploy. The runner picks up
        // type='deploy' status='approved' and applies deploy.ps1 -AutoApprove.
        $stmt = $conn->prepare("UPDATE deploy_requests SET type='deploy', status='approved', decided_by=?, decided_at=NOW() WHERE id=? AND status='ready'");
        $stmt->bind_param("ii", $UserId, $rid);
        $stmt->execute();
        $ok = $stmt->affected_rows;
        $stmt->close();
        flash_redirect($ok ? 'success' : 'error',
            $ok ? 'Approved. The runner will deploy this commit on its next cycle.' : 'Could not approve (state changed). Re-check.');
    }

    // ---- Decline a ready check (reason required) ----
    if (isset($_POST['decline'])) {
        $rid    = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['decision_reason'] ?? '');
        if ($reason === '') {
            flash_redirect('error', 'A reason is required to decline.');
        }
        $stmt = $conn->prepare("UPDATE deploy_requests SET status='declined', decided_by=?, decided_at=NOW(), decision_reason=? WHERE id=? AND status='ready'");
        $stmt->bind_param("isi", $UserId, $reason, $rid);
        $stmt->execute();
        $ok = $stmt->affected_rows;
        $stmt->close();
        flash_redirect($ok ? 'success' : 'error',
            $ok ? 'Declined and recorded.' : 'Could not decline (state changed). Re-check.');
    }
}

// ---------------------------------------------------------------------------
// READ — flash, runner health, current actionable request, history.
// ---------------------------------------------------------------------------
$flash = $_SESSION['deploy_flash'] ?? null;
unset($_SESSION['deploy_flash']);

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// deploy_state: last deployed commit + runner heartbeat (health).
$state = $conn->query("SELECT last_deployed_sha, last_deployed_at, runner_heartbeat FROM deploy_state WHERE id=1")->fetch_assoc();
$lastSha    = $state['last_deployed_sha'] ?? null;
$lastAt     = $state['last_deployed_at'] ?? null;
$heartbeat  = $state['runner_heartbeat'] ?? null;

// Runner health: healthy if it beat within the last 5 minutes.
$runnerHealthy = false;
$heartbeatAge  = null;
if ($heartbeat) {
    $heartbeatAge = time() - strtotime($heartbeat);
    $runnerHealthy = ($heartbeatAge >= 0 && $heartbeatAge < 300);
}

// The one request currently needing attention or in flight (most recent open one).
$current = $conn->query(
    "SELECT * FROM deploy_requests
     WHERE status IN ('pending','checking','ready','approved','deploying')
     ORDER BY id DESC LIMIT 1"
)->fetch_assoc();

// Decode diff/drift JSON for display.
$diffFiles  = [];
$driftFiles = [];
if ($current) {
    $diffFiles  = json_decode($current['diff_summary']  ?? '[]', true) ?: [];
    $driftFiles = json_decode($current['drift_report']  ?? '[]', true) ?: [];
}

// History: the last 20 decided/finished requests.
$history = $conn->query(
    "SELECT dr.*, u1.username AS requested_name, u2.username AS decided_name
     FROM deploy_requests dr
     LEFT JOIN users u1 ON u1.id = dr.requested_by
     LEFT JOIN users u2 ON u2.id = dr.decided_by
     ORDER BY dr.id DESC LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);

/** Bootstrap badge class for a status. */
function statusBadge(string $s): string {
    $map = [
        'pending'   => 'bg-secondary',
        'checking'  => 'bg-info text-dark',
        'ready'     => 'bg-primary',
        'no_change' => 'bg-secondary',
        'approved'  => 'bg-warning text-dark',
        'declined'  => 'bg-dark',
        'deploying' => 'bg-info text-dark',
        'deployed'  => 'bg-success',
        'failed'    => 'bg-danger',
    ];
    $cls = $map[$s] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Deployments - PSPF CRM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
<link rel="stylesheet" href="../style5.css">
<link rel="stylesheet" href="../agent/agent_style.css">
<link rel="icon" type="image/png" href="../uploads/pspflogo.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    .file-list { max-height: 320px; overflow-y: auto; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
    .file-list .list-group-item { padding: .35rem .75rem; }
    .st-NEW { color: #198754; } .st-CHANGED { color: #b8860b; }
    .commit-sha { font-family: ui-monospace, monospace; }
    .runner-dot { width:.7rem; height:.7rem; border-radius:50%; display:inline-block; }
    .drift-panel { border-left: 4px solid #dc3545; }
</style>
</head>
<body>
    <?php include '../agent/topnav.php'; ?>

<main id="main-content">
  <div class="container-xl mt-4 mb-5">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-rocket-takeoff me-2"></i>Deployments</h1>
            <div class="text-muted small">Reviewed, audited delivery of the GitHub repo to the live CRM.</div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Runner health -->
            <span class="d-inline-flex align-items-center gap-2 small"
                  title="<?= $heartbeat ? 'Last runner heartbeat: ' . e($heartbeat) : 'No heartbeat recorded yet' ?>">
                <span class="runner-dot" style="background: <?= $runnerHealthy ? '#198754' : '#dc3545' ?>;"></span>
                Runner: <?= $runnerHealthy ? 'online' : 'offline / stale' ?>
            </span>
            <!-- Check for updates -->
            <form method="POST" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                <button type="submit" name="check_updates" value="1" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1"></i> Check for updates
                </button>
            </form>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['kind'] === 'error' ? 'danger' : ($flash['kind'] === 'success' ? 'success' : 'info') ?> alert-dismissible fade show">
            <?= e($flash['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$runnerHealthy): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                The deploy runner has not reported in recently<?= $heartbeatAge !== null ? ' (' . (int)floor($heartbeatAge/60) . ' min ago)' : '' ?>.
                Requests will queue but nothing will deploy until the runner is back online.
            </div>
        </div>
    <?php endif; ?>

    <!-- Current deployed state -->
    <div class="card shadow-sm mb-4">
      <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="text-muted small text-uppercase">Currently live</div>
            <?php if ($lastSha): ?>
                <div>Commit <span class="commit-sha"><?= e(substr($lastSha, 0, 10)) ?></span>
                     <span class="text-muted small">deployed <?= e($lastAt) ?></span></div>
            <?php else: ?>
                <div class="text-muted">No deploy recorded yet (baseline unknown). The first deploy establishes the baseline.</div>
            <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Current actionable request -->
    <?php if ($current): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hourglass-split me-1"></i>Pending deployment &mdash; request #<?= (int)$current['id'] ?></span>
            <?= statusBadge($current['status']) ?>
        </div>
        <div class="card-body">
          <?php if (in_array($current['status'], ['pending','checking'], true)): ?>
              <p class="mb-0 text-muted">
                <i class="bi bi-arrow-repeat me-1"></i>
                The runner is fetching the repo and computing the change set. This page updates when it finishes &mdash; refresh in a moment.
              </p>
          <?php elseif ($current['status'] === 'approved' || $current['status'] === 'deploying'): ?>
              <p class="mb-0 text-muted">
                <i class="bi bi-gear-wide-connected me-1"></i>
                Approved &mdash; the runner is applying this deploy. Refresh shortly for the outcome.
              </p>
          <?php else: /* ready */ ?>
              <div class="row g-3">
                <div class="col-lg-6">
                    <dl class="row mb-0 small">
                        <dt class="col-4">Commit</dt>
                        <dd class="col-8 commit-sha"><?= e(substr($current['commit_sha'] ?? '', 0, 10)) ?></dd>
                        <dt class="col-4">Message</dt>
                        <dd class="col-8"><?= e($current['commit_msg'] ?? '') ?></dd>
                        <dt class="col-4">Author</dt>
                        <dd class="col-8"><?= e($current['commit_author'] ?? '') ?></dd>
                        <dt class="col-4">Requested by</dt>
                        <dd class="col-8"><?= e($current['requested_by'] ? ('user #' . $current['requested_by']) : '—') ?></dd>
                    </dl>
                </div>
                <div class="col-lg-6">
                    <div class="text-muted small text-uppercase mb-1">
                        Files to change (<?= count($diffFiles) ?>)
                    </div>
                    <div class="list-group file-list">
                        <?php if (!$diffFiles): ?>
                            <div class="list-group-item text-muted">No file changes.</div>
                        <?php else: foreach ($diffFiles as $f): ?>
                            <div class="list-group-item d-flex justify-content-between">
                                <span><?= e($f['path'] ?? '') ?></span>
                                <span class="st-<?= e($f['status'] ?? '') ?> fw-semibold"><?= e($f['status'] ?? '') ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
              </div>

              <?php if ($driftFiles): ?>
                <div class="alert alert-danger drift-panel mt-3">
                    <div class="fw-semibold"><i class="bi bi-exclamation-octagon-fill me-1"></i>
                        Drift detected &mdash; <?= count($driftFiles) ?> live file(s) edited outside the pipeline
                    </div>
                    <div class="small mb-2">A deploy would overwrite these direct-on-live edits. Approval is blocked until live is reconciled into the repo.</div>
                    <ul class="mb-0 small">
                        <?php foreach (array_slice($driftFiles, 0, 30) as $d): ?>
                            <li class="commit-sha"><?= e(is_array($d) ? ($d['path'] ?? json_encode($d)) : $d) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
              <?php endif; ?>

              <div class="d-flex gap-2 mt-3">
                <form method="POST" class="m-0" onsubmit="return confirm('Approve and deploy commit <?= e(substr($current['commit_sha'] ?? '', 0, 8)) ?> to LIVE?');">
                    <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$current['id'] ?>">
                    <button type="submit" name="approve" value="1" class="btn btn-success"
                        <?= $driftFiles ? 'disabled title="Blocked by drift"' : '' ?>>
                        <i class="bi bi-check2-circle me-1"></i> Approve &amp; deploy
                    </button>
                </form>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#declineModal">
                    <i class="bi bi-x-circle me-1"></i> Decline
                </button>
              </div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-secondary">
        No pending deployment. Click <strong>Check for updates</strong> to see whether the repo is ahead of live.
      </div>
    <?php endif; ?>

    <!-- History -->
    <div class="card shadow-sm">
      <div class="card-header"><i class="bi bi-clock-history me-1"></i>Recent activity</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Type</th><th>Status</th><th>Commit</th><th>Message</th>
              <th>Requested</th><th>Decided</th><th>When</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$history): ?>
              <tr><td colspan="8" class="text-muted text-center py-3">No activity yet.</td></tr>
            <?php else: foreach ($history as $h): ?>
              <tr>
                <td><?= (int)$h['id'] ?></td>
                <td><?= e($h['type']) ?></td>
                <td><?= statusBadge($h['status']) ?></td>
                <td class="commit-sha"><?= e($h['commit_sha'] ? substr($h['commit_sha'],0,8) : '—') ?></td>
                <td class="text-truncate" style="max-width:260px;" title="<?= e($h['commit_msg'] ?? '') ?>"><?= e($h['commit_msg'] ?? '—') ?></td>
                <td><?= e($h['requested_name'] ?? '—') ?></td>
                <td>
                    <?= e($h['decided_name'] ?? '—') ?>
                    <?php if (!empty($h['decision_reason'])): ?>
                        <i class="bi bi-info-circle text-muted" title="<?= e($h['decision_reason']) ?>"></i>
                    <?php endif; ?>
                </td>
                <td class="small text-muted"><?= e($h['updated_at']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>

<!-- Decline modal -->
<div class="modal fade" id="declineModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Decline deployment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
        <input type="hidden" name="request_id" value="<?= $current ? (int)$current['id'] : 0 ?>">
        <label class="form-label">Reason (recorded in the audit trail)</label>
        <textarea name="decision_reason" class="form-control" rows="3" required
                  placeholder="Why is this deployment being declined?"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="decline" value="1" class="btn btn-danger">Decline</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
