<?php
// request_details.php?id=123
//
// Returns an HTML fragment with the full details of one vehicle request:
// trip info, allocation, who actioned it at each stage, trip completion
// figures and the activity trail from request_logs. Loaded into the shared
// details popup by assets/js/app.js whenever a request row is clicked.

session_start();
require_once __DIR__ . '/session_timeout.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/superuser.php';
require_once __DIR__ . '/trip_helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('<div class="alert alert-warning mb-0">Your session has expired. Please log in again.</div>');
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("
    SELECT vr.*,
           u.name  AS requester_name, u.email AS requester_email,
           v.registration, v.make AS vehicle_make, v.model AS vehicle_model,
           d.name  AS driver_name,
           s.name  AS supervisor_name,
           ss.name AS selected_supervisor_name,
           h.name  AS hrm_name
    FROM vehicle_requests vr
    LEFT JOIN users u    ON u.user_id  = vr.requester_id
    LEFT JOIN vehicles v ON v.vehicle_id = vr.vehicle_id
    LEFT JOIN users d    ON d.user_id  = vr.driver_id
    LEFT JOIN users s    ON s.user_id  = vr.supervisor_id
    LEFT JOIN users ss   ON ss.user_id = vr.selected_supervisor
    LEFT JOIN users h    ON h.user_id  = vr.hrm_id
    WHERE vr.request_id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

// Staff only see their own trips; supervisors see their department.
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$allowed = $r && (
    vbIsSuperuser()
    || in_array($role, ['driver', 'hrm', 'admin', 'viewer'], true)
    || ($role === 'supervisor' && (
            ($r['department'] ?? '') === ($_SESSION['department'] ?? null)
            || (int)$r['supervisor_id'] === $uid
            || (int)$r['selected_supervisor'] === $uid))
    || (int)$r['requester_id'] === $uid
    || (int)$r['driver_id'] === $uid
);

if (!$allowed) {
    http_response_code(404);
    exit('<div class="alert alert-warning mb-0">Request not found.</div>');
}

$logs = $conn->prepare("
    SELECT rl.action, rl.created_at, ab.name AS action_by_name, ab.role AS action_by_role
    FROM request_logs rl
    LEFT JOIN users ab ON ab.user_id = rl.action_by
    WHERE rl.request_id = ?
    ORDER BY rl.created_at ASC, rl.log_id ASC
");
$logs->execute([$id]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

$e = static fn($v, string $empty = '—') => ($v === null || $v === '') ? $empty : htmlspecialchars((string)$v);

$vehicle = trim(($r['vehicle_make'] ?? '') . ' ' . ($r['vehicle_model'] ?? ''));
if (!empty($r['registration'])) {
    $vehicle = $vehicle !== '' ? "$vehicle ({$r['registration']})" : $r['registration'];
}

$distance = (is_numeric($r['mileage_in']) && is_numeric($r['mileage_out']))
    ? ((int)$r['mileage_in'] - (int)$r['mileage_out']) . ' km'
    : null;

$roleNames = ['user' => 'Staff', 'hrm' => 'HRM', 'superuser' => 'IT Superuser'];

$field = static function (string $label, string $valueHtml): string {
    return '<div class="col-sm-6 col-lg-4"><small class="text-muted d-block">' . $label
         . '</small><div class="fw-semibold text-break">' . $valueHtml . '</div></div>';
};

// Who actioned each approval stage. Driver approval stores driver_id;
// supervisor and HRM approvals store supervisor_id / hrm_id.
$stages = [
    ['Driver (vehicle assigned)', $r['driver_name'],     !empty($r['driver_id'])],
    ['Supervisor',                $r['supervisor_name'], !empty($r['supervisor_id'])],
    ['HRM (final approval)',      $r['hrm_name'],        !empty($r['hrm_id'])],
];
?>
<div class="vb-request-details">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h5 class="mb-0">Request #<?= (int)$r['request_id'] ?></h5>
    <?= vbStatusBadge($r) ?>
  </div>

  <h6 class="text-muted text-uppercase small mb-2"><i class="fa fa-route me-1"></i>Trip</h6>
  <div class="row g-3 mb-3">
    <?= $field('Requester', $e($r['requester_name']) . (!empty($r['requester_email']) ? '<div class="small text-muted fw-normal">' . $e($r['requester_email']) . '</div>' : '')) ?>
    <?= $field('Department', $e($r['department'])) ?>
    <?= $field('Destination', $e($r['destination'])) ?>
    <?= $field('Date Required', $e($r['date_required'])) ?>
    <?= $field('Time Required', $e(vbTime($r['time_required']))) ?>
    <?= $field('Expected Return', $e($r['expected_return_date'])) ?>
    <?= $field('Passengers', $e($r['passengers'])) ?>
    <?= $field('Date Requested', $e($r['created_at'])) ?>
    <?= $field('Assigned Vehicle', $e($vehicle, 'Not yet assigned')) ?>
    <div class="col-12"><small class="text-muted d-block">Purpose</small><div class="fw-semibold text-break"><?= nl2br($e($r['purpose'])) ?></div></div>
  </div>

  <h6 class="text-muted text-uppercase small mb-2"><i class="fa fa-user-check me-1"></i>Actioned By</h6>
  <div class="row g-3 mb-3">
    <?php foreach ($stages as [$label, $name, $done]): ?>
      <?= $field($label, $done ? $e($name, 'Unknown user') : '<span class="text-muted fw-normal">Not yet actioned</span>') ?>
    <?php endforeach; ?>
    <?php if (!empty($r['selected_supervisor_name'])): ?>
      <?= $field('Supervisor Chosen by Requester', $e($r['selected_supervisor_name'])) ?>
    <?php endif; ?>
  </div>

  <?php if ($r['status'] === 'rejected' && !empty($r['rejection_reason'])): ?>
  <div class="alert alert-danger"><strong>Rejection Reason:</strong> <?= $e($r['rejection_reason']) ?></div>
  <?php endif; ?>

  <?php if ($r['status'] === 'closed' || !empty($r['actual_return_date']) || $r['mileage_out'] !== null): ?>
  <h6 class="text-muted text-uppercase small mb-2"><i class="fa fa-flag-checkered me-1"></i>Trip Completion</h6>
  <div class="row g-3 mb-3">
    <?= $field('Time Out', $e(vbTime($r['time_out']))) ?>
    <?= $field('Time In', $e(vbTime($r['time_in']))) ?>
    <?= $field('Actual Return', $e($r['actual_return_date'])) ?>
    <?= $field('Mileage Out', is_numeric($r['mileage_out']) ? $e($r['mileage_out']) . ' km' : '—') ?>
    <?= $field('Mileage In', is_numeric($r['mileage_in']) ? $e($r['mileage_in']) . ' km' : '—') ?>
    <?= $field('Distance Travelled', $e($distance)) ?>
  </div>
  <?php endif; ?>

  <h6 class="text-muted text-uppercase small mb-2"><i class="fa fa-clock-rotate-left me-1"></i>Activity Trail</h6>
  <?php if (!$logs): ?>
    <p class="text-muted small mb-0">No actions recorded yet.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>When</th><th>Action</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td class="text-nowrap"><?= $e($log['created_at']) ?></td>
          <td><?= nl2br($e($log['action'])) ?></td>
          <td><?= $e($log['action_by_name'], 'System') ?><?php if (!empty($log['action_by_role'])): ?> <span class="text-muted small">(<?= $e($roleNames[$log['action_by_role']] ?? ucfirst($log['action_by_role'])) ?>)</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
