<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
// Only users with role 'viewer' may access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'viewer') {
    header('HTTP/1.1 403 Forbidden');
    echo '<h3>Access denied. You do not have permission to view this page.</h3>';
    echo '<p><a href="login.php">Login</a></p>';
    exit;
}

require_once 'db.php';
require_once __DIR__ . '/trip_helpers.php';

// Full details and the status trail load on demand (request_details.php)
// when a row is clicked.
$sql = "SELECT vr.*,
               u.name AS requester_name,
               d.name AS driver_name,
               v.registration AS vehicle_registration
        FROM vehicle_requests vr
        LEFT JOIN users u ON vr.requester_id = u.user_id
        LEFT JOIN users d ON vr.driver_id = d.user_id
        LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
        ORDER BY vr.created_at DESC";

try {
    $requests = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo '<h3>Failed to load data: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
    <?php $pageTitle = 'Request Logs'; require __DIR__ . '/partials/head.php'; ?>
    <style>
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        tr:hover { background-color: #f9f9f9; }
        .details-icon { cursor: pointer; font-size: 1.2em; color: #007bff; }
        .details-icon:hover { color: #0056b3; }
        .small { font-size: 0.85em; color: #666; }
        .pagination-container { margin-top: 20px; text-align: center; }
        .pagination-container button { 
            margin: 0 3px; padding: 8px 12px; border: 1px solid #ddd; 
            background: white; cursor: pointer; border-radius: 4px; 
        }
        .pagination-container button.active { 
            background: #007bff; color: white; border-color: #007bff; 
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container mt-5">
    <h2>Vehicle Request History</h2>
    <p class="small">Showing all requests. Click a request to view full details, who actioned it and the status trail.</p>

    <?php if (empty($requests)): ?>
        <p>No requests found.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Requester</th>
                    <th>Department</th>
                    <th>Destination</th>
                    <th>Date Required</th>
                    <th>Time Required</th>
                    <th>Status</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="requestsTableBody">
            <?php foreach ($requests as $m): ?>
                <tr class="request-row" data-request-id="<?= (int)$m['request_id'] ?>">
                    <td><?= htmlspecialchars($m['request_id']) ?></td>
                    <td><?= htmlspecialchars($m['requester_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($m['department'] ?? '') ?></td>
                    <td class="cell-truncate" title="<?= htmlspecialchars($m['destination']) ?>"><?= htmlspecialchars($m['destination']) ?></td>
                    <td class="col-nowrap"><?= htmlspecialchars($m['date_required']) ?></td>
                    <td class="col-nowrap"><?= htmlspecialchars(vbTime($m['time_required'])) ?></td>
                    <td class="col-nowrap"><?= vbStatusBadge($m) ?></td>
                    <td><?= htmlspecialchars($m['vehicle_registration'] ?? '') ?></td>
                    <td><?= htmlspecialchars($m['driver_name'] ?? '') ?></td>
                    <td style="text-align: center;">
                        <button type="button" class="btn btn-link p-0 details-icon text-decoration-none"
                                onclick="vbShowRequestDetails(<?= (int)$m['request_id'] ?>)" title="View full details and history">
                            📋
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination Controls -->
        <div class="pagination-container">
            <button onclick="previousPage()">← Previous</button>
            <span id="pageInfo" style="margin: 0 15px;"></span>
            <button onclick="nextPage()">Next →</button>
        </div>
    <?php endif; ?>
</div>

<script>
    const rowsPerPage = 8;
    let currentPage = 1;
    let allRows = [];

    document.addEventListener('DOMContentLoaded', function() {
        allRows = Array.from(document.querySelectorAll('.request-row'));
        updatePagination();
    });

    function updatePagination() {
        const totalRows = allRows.length;
        const totalPages = Math.ceil(totalRows / rowsPerPage);
        currentPage = Math.min(currentPage, totalPages || 1);

        // Show/hide rows
        allRows.forEach((row, index) => {
            const pageNum = Math.floor(index / rowsPerPage) + 1;
            row.style.display = (pageNum === currentPage) ? '' : 'none';
        });

        document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
    }

    function nextPage() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updatePagination();
        }
    }

    function previousPage() {
        if (currentPage > 1) {
            currentPage--;
            updatePagination();
        }
    }
</script>
<?php include '../vehicle_booking/footer.php'; ?>
</body>
</html>
