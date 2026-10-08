<?php
session_start();
require '../vehicle_booking/db.php';
require_once __DIR__ . '/trip_helpers.php';
require_once __DIR__ . '/admin_stats.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

/**
 * ⚠️ Primary key column of the `vehicle_requests` table.
 * If your table uses `id` (or anything else) instead of `request_id`,
 * change ONLY this line and everything else keeps working.
 */
$PK = 'request_id';

/**
 * AJAX handler: save a single edited row into the database.
 * Handled early so it exits before any HTML is rendered.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_row'])) {
    header('Content-Type: application/json');

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid record ID']);
        exit;
    }

    /**
     * Whitelist of columns a driver is allowed to edit.
     * NOTE: "Vehicle" is shown via a JOIN, so we edit the underlying
     * foreign key (vehicle_id), not the display text.
     */
    $editable   = ['department', 'destination', 'vehicle_id', 'status', 'mileage_in', 'mileage_out'];
    $numeric    = ['mileage_in', 'mileage_out'];   // must be a number, blank -> NULL
    $intForeign = ['vehicle_id'];                  // must be an integer id, blank -> NULL

    $set  = [];
    $vals = [];

    foreach ($editable as $col) {
        if (!array_key_exists($col, $_POST)) {
            continue;
        }
        $val = $_POST[$col];

        if (in_array($col, $numeric, true)) {
            if ($val === '') {
                $val = null;
            } elseif (!is_numeric($val)) {
                echo json_encode([
                    'success' => false,
                    'error'   => ucwords(str_replace('_', ' ', $col)) . ' must be a number'
                ]);
                exit;
            }
        } elseif ($col === 'status') {
            // Only real stored statuses; "In Progress" is derived, not saved.
            if (!array_key_exists($val, VB_STATUS_LABELS) || $val === 'in_progress') {
                echo json_encode(['success' => false, 'error' => 'Invalid status']);
                exit;
            }
        } elseif (in_array($col, $intForeign, true)) {
            if ($val === '') {
                $val = null;
            } elseif (!ctype_digit((string)$val)) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Invalid vehicle selection'
                ]);
                exit;
            } else {
                $val = (int)$val;
            }
        }

        $set[]  = "$col = ?";
        $vals[] = $val;
    }

    if (!$set) {
        echo json_encode(['success' => false, 'error' => 'Nothing to update']);
        exit;
    }

    $vals[] = $id;
    $sql = "UPDATE vehicle_requests SET " . implode(', ', $set) . " WHERE $PK = ?";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($vals);

        // Recalculate trip mileage so the UI can update the row instantly
        $tm = $conn->prepare("SELECT mileage_in, mileage_out, status, date_required, time_required FROM vehicle_requests WHERE $PK = ?");
        $tm->execute([$id]);
        $row = $tm->fetch(PDO::FETCH_ASSOC);

        $trip = (isset($row['mileage_in'], $row['mileage_out'])
                 && is_numeric($row['mileage_in']) && is_numeric($row['mileage_out']))
              ? ($row['mileage_in'] - $row['mileage_out'])
              : 0;

        echo json_encode([
            'success'      => true,
            'trip_mileage' => $trip,
            'status_badge' => vbStatusBadge($row),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

/**
 * Fetch vehicles for dropdown
 */
$vehicles = $conn->query(
    "SELECT vehicle_id, registration FROM vehicles ORDER BY registration ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/**
 * Status filter options (includes the derived "In Progress" status for
 * approved trips whose departure time has passed).
 */
$statuses = VB_STATUS_LABELS;

/**
 * Canonical department list (must match the booking form).
 * Single source of truth — edit here to add/remove a department.
 */
$departmentOptions = [
    'Accounting',
    'Audit',
    'Benefits',
    'CEO Office',
    'Company Secretary',
    'Facilities',
    'HR',
    'ICT',
    'Investment Monitoring',
    'Investments',
    'Legal',
    'Marketing',
];

/**
 * AJAX handler: build the report table
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {

    $params = [];
    $where = vbReportFilters($_POST, $params);
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT vr.*, u.name AS requester, v.registration
        FROM vehicle_requests vr
        LEFT JOIN users u ON u.user_id = vr.requester_id
        LEFT JOIN vehicles v ON v.vehicle_id = vr.vehicle_id
        $whereSQL
        ORDER BY vr.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $table = '<table class="table table-bordered table-striped">
        <thead>
             <tr>
                <th>Request #</th>
                <th>Date Required</th>
                <th>Time Required</th>
                <th>Requester (Driver)</th>
                <th>Department</th>
                <th>Destination</th>
                <th>Vehicle</th>
                <th>Status</th>
                <th>Mileage In</th>
                <th>Mileage Out</th>
                <th>Trip Mileage</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>';

    $totalMileage = 0;
    $perVehicle = [];
    $inProgress = 0;

    // small helper to keep output safe (prevents XSS + broken edit inputs)
    $esc = function ($v) {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    };

    foreach ($rows as $r) {
        $tripMileage = (
            is_numeric($r['mileage_in']) && is_numeric($r['mileage_out'])
        ) ? ($r['mileage_in'] - $r['mileage_out']) : 0;

        $totalMileage += $tripMileage;

        if (!empty($r['vehicle_id'])) {
            if (!isset($perVehicle[$r['vehicle_id']])) {
                $perVehicle[$r['vehicle_id']] = [
                    'registration' => $r['registration'],
                    'mileage' => 0
                ];
            }
            $perVehicle[$r['vehicle_id']]['mileage'] += $tripMileage;
        }

        $id     = (int)($r[$PK] ?? 0);
        $vehId  = !empty($r['vehicle_id']) ? (int)$r['vehicle_id'] : '';
        $dateReq = $esc($r['date_required']);
        $timeReq = $esc(vbTime($r['time_required']));
        $req    = $esc(vbName($r['requester']));
        $dep    = $esc($r['department']);
        $des    = $esc($r['destination']);
        $reg    = $esc($r['registration']);
        $staVal = $esc($r['status']);
        $staBadge = vbStatusBadge($r);
        if (vbTripStatus($r) === 'in_progress') {
            $inProgress++;
        }
        $min    = $esc($r['mileage_in']);
        $mout   = $esc($r['mileage_out']);

        $table .= "<tr data-id=\"{$id}\" data-request-id=\"{$id}\" title=\"Click to view full request details\">
            <td>#{$id}</td>
            <td class=\"text-nowrap\">{$dateReq}</td>
            <td class=\"text-nowrap\">{$timeReq}</td>
            <td>{$req}</td>
            <td data-field=\"department\">{$dep}</td>
            <td data-field=\"destination\">{$des}</td>
            <td data-field=\"vehicle_id\" data-value=\"{$vehId}\">{$reg}</td>
            <td data-field=\"status\" data-value=\"{$staVal}\">{$staBadge}</td>
            <td data-field=\"mileage_in\">{$min}</td>
            <td data-field=\"mileage_out\">{$mout}</td>
            <td class=\"trip-cell\">{$tripMileage}</td>
            <td class=\"text-nowrap\">
                <button class=\"btn btn-sm btn-outline-primary view-btn\" onclick=\"vbShowRequestDetails({$id})\">View</button>
                <button class=\"btn btn-sm btn-primary edit-btn\" onclick=\"editRow(this)\">Edit</button>
                <button class=\"btn btn-sm btn-success save-btn d-none\" onclick=\"saveRow(this)\">Save</button>
                <button class=\"btn btn-sm btn-secondary cancel-btn d-none\" onclick=\"cancelRow(this)\">Cancel</button>
            </td>
        </tr>";
    }

    $table .= '</tbody></table>';

    $totals = "<h5>Trips In Progress: <strong>{$inProgress}</strong></h5>
               <h5>Total Trip Mileage: <strong id=\"totalTripValue\">{$totalMileage} km</strong></h5>
               <h6>Mileage per Vehicle</h6><ul>";

    foreach ($perVehicle as $v) {
        $totals .= "<li>" . $esc($v['registration']) . " — {$v['mileage']} km</li>";
    }
    $totals .= '</ul>';

    echo json_encode([
        'table_html' => $table,
        'totals_html' => $totals
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Transport Report'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="bg-light">

<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-4">

<div class="settings-header">
          <h1 class="settings-title">Transport Report</h1>
          <div class="settings-actions">
            <!-- Back Button -->
              <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                  <i class="bi bi-arrow-left"></i> Back
              </button>
          </div>
        </div>

    <?php $statsView = vbStatsViewFor($_SESSION['role'] ?? '', 'driver'); ?>
    <?php if ($statsView !== null): ?>
    <ul class="nav nav-tabs mb-4 no-print" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-records" data-bs-toggle="tab" data-bs-target="#pane-records" type="button" role="tab">
                <i class="fa fa-table-list me-1"></i>Trip Records
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-stats" data-bs-toggle="tab" data-bs-target="#pane-stats" type="button" role="tab">
                <i class="fa fa-chart-column me-1"></i>Statistics
            </button>
        </li>
    </ul>
    <?php endif; ?>

    <div class="tab-content">
    <div class="tab-pane fade show active" id="pane-records" role="tabpanel">

    <div class="card p-3 mb-4">
        <h5>Filters</h5>
        <form id="filterForm" class="row g-3">
            <div class="col-md-3"><label>From</label><input type="date" name="from_date" class="form-control"></div>
            <div class="col-md-3"><label>To</label><input type="date" name="to_date" class="form-control"></div>
            <div class="col-md-3"><label>Requester</label><input type="text" name="requester" class="form-control"></div>
            <div class="col-md-3"><label>Department</label><input type="text" name="department" class="form-control"></div>
            <div class="col-md-3"><label>Destination</label><input type="text" name="destination" class="form-control"></div>

            <div class="col-md-3">
                <label>Vehicle</label>
                <select name="vehicle_id" class="form-control">
                    <option value="">All Vehicles</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= htmlspecialchars($v['vehicle_id'], ENT_QUOTES) ?>"><?= htmlspecialchars($v['registration'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach ($statuses as $value => [, $label]): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3"><label>Mileage Out (Min)</label><input type="number" name="mileage_min" class="form-control"></div>
            <div class="col-md-3"><label>Mileage In (Max)</label><input type="number" name="mileage_max" class="form-control"></div>

            <div class="col-md-12 text-end">
                <button type="button" onclick="loadReport()" class="btn btn-primary">Apply Filters</button>
            </div>
        </form>
    </div>

    <div class="mb-3">
        <button class="btn btn-success" onclick="exportExcel()">Export to Excel</button>
        <button class="btn btn-danger" onclick="exportPDF()">Export to PDF</button>
    </div>

    <div id="reportTable" class="table-responsive"></div>
    <div id="totalsSection" class="mt-4"></div>
    </div><!-- /#pane-records -->

    <?php if ($statsView !== null): ?>
    <div class="tab-pane fade" id="pane-stats" role="tabpanel">
        <?php
        $statsTitle = 'Fleet Statistics';
        require __DIR__ . '/partials/stats_section.php';
        ?>
    </div>
    <?php endif; ?>
    </div><!-- /.tab-content -->
</div>

<script>
// Open the Statistics tab when the URL asks for it (period links and the
// period form return to #statistics).
if (location.hash === '#statistics' && document.getElementById('tab-stats')) {
    bootstrap.Tab.getOrCreateInstance(document.getElementById('tab-stats')).show();
}
</script>

<script>
/**
 * Option list for the Vehicle foreign-key dropdown.
 */
const VEHICLES = <?= json_encode(
    array_map(fn($v) => ['value' => (int)$v['vehicle_id'], 'label' => $v['registration']], $vehicles),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

/**
 * Stored request statuses for the Status dropdown ("In Progress" is derived
 * from Approved + departure time, so it is not offered here).
 */
const STATUSES = <?= json_encode(
    array_map(
        fn($value) => ['value' => $value, 'label' => vbStatusLabel($value)],
        array_keys(array_diff_key(VB_STATUS_LABELS, ['in_progress' => true]))
    ),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

/**
 * Fixed Department options (mirrors the booking form).
 */
const DEPARTMENTS = <?= json_encode(
    $departmentOptions,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

/**
 * Which cells are editable and how to render their input.
 *  - text/number : free text
 *  - select      : fixed dropdown (its own labels are the stored value)
 *  - fk          : dropdown of {value,label}; stores the id, displays the label
 * Add entries here (plus a matching data-field <td> + PHP whitelist entry) to edit more columns.
 */
const EDITABLE = {
    department:   { type: 'select', options: DEPARTMENTS },
    destination:  { type: 'text' },
    vehicle_id:   { type: 'fk', options: VEHICLES },
    status:       { type: 'fk', options: STATUSES },
    mileage_in:   { type: 'number' },
    mileage_out:  { type: 'number' }
};

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function goBack() {
    const previousPages = <?= json_encode($_SESSION['page_history'] ?? []) ?>;

    if (previousPages.length > 1) {
        previousPages.pop();
        const previousPage = previousPages[previousPages.length - 1];
        window.location.href = previousPage;
    } else {
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            window.location.href = 'driver_dashboard.php';
        }
    }
}

function loadReport() {
    const fd = new FormData(document.getElementById('filterForm'));
    fd.append('ajax', '1');

    fetch('', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            document.getElementById('reportTable').innerHTML = data.table_html;
            document.getElementById('totalsSection').innerHTML = data.totals_html;
        });
}

window.onload = loadReport;

/* ---------- Inline row editing ---------- */

function editRow(btn) {
    const tr = btn.closest('tr');
    tr.querySelectorAll('td[data-field]').forEach(td => {
        const field = td.dataset.field;
        const val = td.textContent.trim();
        td.dataset.original = val;
        td.dataset.originalHtml = td.innerHTML;

        const cfg = EDITABLE[field] || { type: 'text' };

        if (cfg.type === 'fk') {
            const curVal = td.dataset.value || '';
            const curLabel = val;
            let opts = [{ value: '', label: '—' }].concat(cfg.options);
            // preserve current selection even if it's no longer in the list
            if (curVal && !opts.some(o => String(o.value) === String(curVal))) {
                opts.splice(1, 0, { value: curVal, label: curLabel });
            }
            td.innerHTML = '<select class="form-select form-select-sm">' +
                opts.map(o =>
                    `<option value="${escapeHtml(o.value)}"${String(o.value) === String(curVal) ? ' selected' : ''}>${escapeHtml(o.label)}</option>`
                ).join('') +
                '</select>';
        } else if (cfg.type === 'select') {
            const opts = cfg.options.slice();
            // keep the current value even if it's not in our default list
            if (val && !opts.includes(val)) opts.unshift(val);
            td.innerHTML = '<select class="form-select form-select-sm">' +
                opts.map(o => `<option${o === val ? ' selected' : ''}>${escapeHtml(o)}</option>`).join('') +
                '</select>';
        } else {
            td.innerHTML = `<input type="${cfg.type}" class="form-control form-control-sm" value="${escapeHtml(val)}">`;
        }
    });
    tr.classList.add('editing');
    toggleButtons(tr, true);
}

function cancelRow(btn) {
    const tr = btn.closest('tr');
    tr.querySelectorAll('td[data-field]').forEach(td => {
        if (td.dataset.originalHtml !== undefined) {
            td.innerHTML = td.dataset.originalHtml;
            delete td.dataset.original;
            delete td.dataset.originalHtml;
        }
    });
    tr.classList.remove('editing');
    toggleButtons(tr, false);
}

function saveRow(btn) {
    const tr = btn.closest('tr');
    const id = tr.dataset.id;

    const fd = new FormData();
    fd.append('update_row', '1');
    fd.append('id', id);
    tr.querySelectorAll('td[data-field]').forEach(td => {
        const input = td.querySelector('input, select');
        fd.append(td.dataset.field, input ? input.value : '');
    });

    btn.disabled = true;
    fetch('', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            if (!data.success) {
                alert(data.error || 'Update failed');
                return;
            }
            // commit inputs back to plain text
            tr.querySelectorAll('td[data-field]').forEach(td => {
                const input = td.querySelector('input, select');
                const cfg = EDITABLE[td.dataset.field] || {};
                if (cfg.type === 'fk' && input) {
                    const opt = input.options[input.selectedIndex];
                    td.dataset.value = input.value;                 // store the new id
                    td.textContent = opt ? opt.textContent : '';    // display the label
                } else {
                    td.textContent = input ? input.value : (td.dataset.original ?? '');
                }
                delete td.dataset.original;
                delete td.dataset.originalHtml;
            });
            // status shows as a badge (may be "In Progress" for approved trips)
            const statusCell = tr.querySelector('td[data-field="status"]');
            if (statusCell && data.status_badge) statusCell.innerHTML = data.status_badge;
            // update trip mileage + grand total
            if (data.trip_mileage !== undefined) {
                const tripCell = tr.querySelector('.trip-cell');
                if (tripCell) tripCell.textContent = data.trip_mileage;
            }
            recalcTotal();
            tr.classList.remove('editing');
            toggleButtons(tr, false);
        })
        .catch(() => {
            btn.disabled = false;
            alert('Network error while saving');
        });
}

function toggleButtons(tr, editing) {
    tr.querySelector('.edit-btn').classList.toggle('d-none', editing);
    tr.querySelector('.view-btn').classList.toggle('d-none', editing);
    tr.querySelector('.save-btn').classList.toggle('d-none', !editing);
    tr.querySelector('.cancel-btn').classList.toggle('d-none', !editing);
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('#reportTable .trip-cell').forEach(c => {
        const n = parseFloat(c.textContent);
        if (!isNaN(n)) total += n;
    });
    const el = document.getElementById('totalTripValue');
    if (el) el.textContent = total + ' km';
    // Note: the per-vehicle breakdown fully refreshes when you click "Apply Filters".
}

/* ---------- Exports (unchanged) ---------- */

function exportExcel() {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form)).toString();
    window.location.href = 'report_excel.php?' + params;
}

function exportPDF() {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form)).toString();
    window.location.href = 'report_pdf.php?' + params;
}
</script>

<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>