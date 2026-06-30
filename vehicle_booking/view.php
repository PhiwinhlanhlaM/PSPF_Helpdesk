<?php
session_start();
// Only users with role 'viewer' may access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'viewer') {
    header('HTTP/1.1 403 Forbidden');
    echo '<h3>Access denied. You do not have permission to view this page.</h3>';
    echo '<p><a href="login.php">Login</a></p>';
    exit;
}

require_once 'db.php';
// Optional site navbar if present
if (file_exists(__DIR__ . '/navbar.php')) {
    include 'navbar.php';
}

// Fetch requests and their logs (include user names and vehicle registration)
$sql = "SELECT vr.*, 
               u.name AS requester_name, 
               d.name AS driver_name,
               v.registration AS vehicle_registration,
               rl.log_id, rl.action_by, ab.name AS action_by_name, rl.action AS log_action, rl.created_at AS log_created_at
        FROM vehicle_requests vr
        LEFT JOIN users u ON vr.requester_id = u.user_id
        LEFT JOIN users d ON vr.driver_id = d.user_id
        LEFT JOIN vehicles v ON vr.vehicle_id = v.vehicle_id
        LEFT JOIN request_logs rl ON vr.request_id = rl.request_id
        LEFT JOIN users ab ON rl.action_by = ab.user_id
        ORDER BY vr.created_at DESC, rl.created_at DESC";

try {
    $stmt = $conn->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo '<h3>Failed to load data: ' . htmlspecialchars($e->getMessage()) . '</h3>';
    exit;
}

$requests = [];
foreach ($rows as $row) {
    $rid = $row['request_id'];
    if (!isset($requests[$rid])) {
        $requests[$rid] = [
            'meta' => [
                'request_id' => $row['request_id'],
                'requester_name' => $row['requester_name'] ?? $row['requester_id'],
                'department' => $row['department'],
                'purpose' => $row['purpose'],
                'destination' => $row['destination'],
                'date_required' => $row['date_required'],
                'status' => $row['status'],
                'vehicle_registration' => $row['vehicle_registration'] ?? $row['vehicle_id'],
                'driver_name' => $row['driver_name'] ?? $row['driver_id'],
                'created_at' => $row['created_at']
            ],
            'logs' => []
        ];
    }

    if (!empty($row['log_id'])) {
        $requests[$rid]['logs'][] = [
            'log_id' => $row['log_id'],
            'action_by' => $row['action_by'],
            'action_by_name' => $row['action_by_name'] ?? $row['action_by'],
            'action' => $row['log_action'],
            'created_at' => $row['log_created_at']
        ];
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Logs - Viewer</title>
    <link rel="stylesheet" href="style5.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .status-badge { padding: 4px 8px; border-radius: 3px; font-size: 0.9em; }
        .status-pending { background: #ffc107; color: black; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        .status-closed { background: #6c757d; color: white; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h2>Vehicle Request History</h2>
    <p class="small">Showing all requests. Click the 📋 icon to view full details and status trail.</p>

    <?php if (empty($requests)): ?>
        <p>No requests found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Request ID</th>
                    <th>Requester</th>
                    <th>Department</th>
                    <th>Destination</th>
                    <th>Date Required</th>
                    <th>Status</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="requestsTableBody">
            <?php foreach ($requests as $r): $m = $r['meta']; ?>
                <tr class="request-row" data-request-id="<?= $m['request_id'] ?>">
                    <td><?= htmlspecialchars($m['request_id']) ?></td>
                    <td><?= htmlspecialchars($m['requester_name']) ?></td>
                    <td><?= htmlspecialchars($m['department']) ?></td>
                    <td><?= htmlspecialchars($m['destination']) ?></td>
                    <td><?= htmlspecialchars($m['date_required']) ?></td>
                    <td>
                        <span class="status-badge status-<?= strtolower(str_replace('_', '-', $m['status'])) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst($m['status']))) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($m['vehicle_registration']) ?></td>
                    <td><?= htmlspecialchars($m['driver_name']) ?></td>
                    <td style="text-align: center;">
                        <span class="details-icon" onclick="showDetails(<?= htmlspecialchars(json_encode($r)) ?>)" title="View full details and history">
                            📋
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <div class="pagination-container">
            <button onclick="previousPage()">← Previous</button>
            <span id="pageInfo" style="margin: 0 15px;"></span>
            <button onclick="nextPage()">Next →</button>
        </div>
    <?php endif; ?>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Request Details & Status Trail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="modalContent"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

    function showDetails(requestData) {
        const m = requestData.meta;
        const logs = requestData.logs;

        let html = `
            <div class="row mb-3">
                <div class="col-md-6">
                    <p><strong>Request ID:</strong> ${m.request_id}</p>
                    <p><strong>Requester:</strong> ${m.requester_name}</p>
                    <p><strong>Department:</strong> ${m.department}</p>
                    <p><strong>Destination:</strong> ${m.destination}</p>
                </div>
                <div class="col-md-6">
                    <p><strong>Date Required:</strong> ${m.date_required}</p>
                    <p><strong>Vehicle:</strong> ${m.vehicle_registration}</p>
                    <p><strong>Driver:</strong> ${m.driver_name}</p>
                    <p><strong>Created At:</strong> ${m.created_at}</p>
                </div>
            </div>
            <hr>
            <p><strong>Purpose:</strong></p>
            <p>${m.purpose.replace(/\n/g, '<br>')}</p>
            <hr>
            <h6>Status Trail</h6>
            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px;">
        `;

        if (logs.length === 0) {
            html += '<p class="small text-muted">No actions recorded yet.</p>';
        } else {
            html += '<ul style="list-style: none; padding: 0;">';
            logs.forEach(log => {
                html += `
                    <li style="padding: 8px; border-bottom: 1px solid #eee;">
                        <strong>${log.created_at}</strong><br>
                        <small>By: ${log.action_by_name}</small><br>
                        <p style="margin: 5px 0; font-size: 0.95em;">${log.action.replace(/\n/g, '<br>')}</p>
                    </li>
                `;
            });
            html += '</ul>';
        }

        html += '</div>';
        document.getElementById('modalContent').innerHTML = html;
        const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
        modal.show();
    }
</script>
</body>
<?php include '../vehicle_booking/footer.php'; ?>
</html>
