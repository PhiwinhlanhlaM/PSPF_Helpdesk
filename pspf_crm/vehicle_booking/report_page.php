<?php
session_start();
require '../vehicle_booking/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

/**
 * Build WHERE clause from filters
 */
function buildFilters(array $source, array &$params): array {
    $where = [];

    if (!empty($source['from_date'])) {
        $where[] = "vr.created_at >= ?";
        $params[] = $source['from_date'] . " 00:00:00";
    }

    if (!empty($source['to_date'])) {
        $where[] = "vr.created_at <= ?";
        $params[] = $source['to_date'] . " 23:59:59";
    }

    if (!empty($source['department'])) {
        $where[] = "vr.department LIKE ?";
        $params[] = "%" . $source['department'] . "%";
    }

    if (!empty($source['destination'])) {
        $where[] = "vr.destination LIKE ?";
        $params[] = "%" . $source['destination'] . "%";
    }

    if (!empty($source['requester'])) {
        $where[] = "u.name LIKE ?";
        $params[] = "%" . $source['requester'] . "%";
    }

    if (!empty($source['vehicle_id'])) {
        $where[] = "vr.vehicle_id = ?";
        $params[] = $source['vehicle_id'];
    }

    if ($source['mileage_min'] !== '') {
        $where[] = "vr.mileage_out >= ?";
        $params[] = $source['mileage_min'];
    }

    if ($source['mileage_max'] !== '') {
        $where[] = "vr.mileage_in <= ?";
        $params[] = $source['mileage_max'];
    }

    return $where;
}

/**
 * Fetch vehicles for dropdown
 */
$vehicles = $conn->query(
    "SELECT vehicle_id, registration FROM vehicles ORDER BY registration ASC"
)->fetchAll(PDO::FETCH_ASSOC);

/**
 * AJAX handler
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {

    $params = [];
    $where = buildFilters($_POST, $params);
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
                <th>Date</th>
                <th>Requester</th>
                <th>Department</th>
                <th>Destination</th>
                <th>Vehicle</th>
                <th>Mileage In</th>
                <th>Mileage Out</th>
                <th>Trip Mileage</th>
            </tr>
        </thead>
        <tbody>';

    $totalMileage = 0;
    $perVehicle = [];

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

        $table .= "<tr>
            <td>{$r['created_at']}</td>
            <td>{$r['requester']}</td>
            <td>{$r['department']}</td>
            <td>{$r['destination']}</td>
            <td>{$r['registration']}</td>
            <td>{$r['mileage_in']}</td>
            <td>{$r['mileage_out']}</td>
            <td>{$tripMileage}</td>
        </tr>";
    }

    $table .= '</tbody></table>';

    $totals = "<h5>Total Trip Mileage: <strong>{$totalMileage} km</strong></h5>
               <h6>Mileage per Vehicle</h6><ul>";

    foreach ($perVehicle as $v) {
        $totals .= "<li>{$v['registration']} — {$v['mileage']} km</li>";
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
<meta charset="UTF-8">
<title>Transport Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../vehicle_booking/style5.css" rel="stylesheet">
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
                        <option value="<?= $v['vehicle_id'] ?>"><?= $v['registration'] ?></option>
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

    <div id="reportTable"></div>
    <div id="totalsSection" class="mt-4"></div>
</div>

<script>
function goBack() {
    const previousPages = <?= json_encode($_SESSION['page_history'] ?? []) ?>;
    
    if (previousPages.length > 1) {
        // Remove current page from history
        previousPages.pop();
        // Get the previous page
        const previousPage = previousPages[previousPages.length - 1];
        window.location.href = previousPage;
    } else {
        // Fallback to browser history or default page
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            // If no referrer or from different domain, go to home
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

function exportExcel() {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form)).toString();

    // 🔴 CHANGE THIS PATH to your excel file
    window.location.href = 'report_excel.php?' + params;
}


function exportPDF() {
    const form = document.getElementById('filterForm');
    const params = new URLSearchParams(new FormData(form)).toString();

    // 🔴 CHANGE THIS PATH to your excel file
    window.location.href = 'report_pdf.php?' + params;
}


</script>

<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
