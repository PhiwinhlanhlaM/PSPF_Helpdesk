<?php
session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/division_helpers.php';
require_once '../includes/role_switcher.php';
require_once '../includes/ticket_gauge.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDept      = $_SESSION['user']['division_name'] ?? '';
$UserDivisionId= (int)($_SESSION['user']['division_id'] ?? 0);

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill'
];

$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

requireAnyRole(['user','agent','admin','superadmin']);

$agentId      = $_SESSION['user']['id'];
$divisionId   = $_SESSION['user']['division_id'];
$userUsername = $_SESSION['user']['username'];
$agentEmail   = $_SESSION['user']['email'];
$userDept     = $_SESSION['user']['division_name'];

// ---------------------------
// PAGINATION + SEARCH - FIXED
// ---------------------------
$filter = trim($_GET['filter'] ?? '');
$searchParam = '%' . $filter . '%';

$itemsPerPage = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $itemsPerPage;

// ---------------------------
// CORRECTED: Main Query with Search
// ---------------------------
$sql = "
    SELECT *
    FROM tickets
    WHERE division_id = ?
      AND FIND_IN_SET(?, assigned_to)
      AND (
          CAST(id AS CHAR) LIKE ? OR
          title LIKE ? OR
          status LIKE ? OR
          created_by LIKE ? OR
          query_date LIKE ? OR
          priority LIKE ?
      )
    ORDER BY query_date DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "isssssssii",           // i: division_id, s: email, ssssss: 6 search params, ii: limit, offset
    $divisionId,
    $agentEmail,
    $searchParam,           // id search
    $searchParam,           // title search
    $searchParam,           // status search
    $searchParam,           // created_by search
    $searchParam,           // query_date search
    $searchParam,           // priority search
    $itemsPerPage,
    $offset
);

$stmt->execute();
$result = $stmt->get_result();

// ---------------------------
// CORRECTED: Count Query for Pagination
// ---------------------------
$countSql = "
    SELECT COUNT(*) AS total
    FROM tickets
    WHERE division_id = ? 
      AND FIND_IN_SET(?, assigned_to)
      AND (
          CAST(id AS CHAR) LIKE ? OR
          title LIKE ? OR
          status LIKE ? OR
          created_by LIKE ? OR
          query_date LIKE ? OR
          priority LIKE ?
      )
";

$countStmt = $conn->prepare($countSql);
$countStmt->bind_param(
    "isssssss",              // i: division_id, s: email, ssssss: 6 search params
    $divisionId,
    $agentEmail,
    $searchParam,            // id search
    $searchParam,            // title search
    $searchParam,            // status search
    $searchParam,            // created_by search
    $searchParam,            // query_date search
    $searchParam             // priority search
);

$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRow = $countResult->fetch_assoc();
$totalTickets = (int)($totalRow['total'] ?? 0);
$totalPages = max(1, ceil($totalTickets / $itemsPerPage));
$countStmt->close();

// Clamp page safely
$page = max(1, min($page, $totalPages));
$offset = ($page - 1) * $itemsPerPage;

// If page changed due to clamping, redirect to correct page
if ($page != (int)($_GET['page'] ?? 1)) {
    header("Location: ?page={$page}&filter=" . urlencode($filter));
    exit;
}

// ---------------------------
// Badge Helper
// ---------------------------
function getBadgeClass($status) {
    return match (strtolower($status)) {
        'open' => 'bg-warning text-dark',
        'in progress' => 'bg-info text-dark',
        'closed' => 'bg-success',
        'escalate', 'escalated' => 'bg-danger',
        default => 'bg-secondary',
    };
}

// Get filter display text
$filterDisplay = !empty($filter) ? "Search results for: '" . htmlspecialchars($filter) . "'" : "All tickets";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>My Tickets - PSPF CRM</title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    
    <!-- CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="./agent_style.css">
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- SheetJS (XLSX) for client-side Excel export -->
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    
    <!-- Google Fonts -->
    <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <style>
        .view-ticket-btn {
            cursor: pointer;
        }
        .status-select {
            min-width: 150px;
        }
        .table th {
            vertical-align: middle;
        }
    </style>
    <?php ticket_gauge_assets(); ?>
</head>

<body data-bs-theme="light">
<?php include './topnav.php'; ?>


<div class="container mt-4">
    <div class="settings-header">
        <h1 class="settings-title">
            <i class="bi bi-person-circle me-2"></i>My Assigned Tickets
        </h1>
        <div class="settings-actions">
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
    </div>

    <div class="tab-content mt-3">
        <div class="container mt-5">
            <div class="settings-card">
                <div class="card border-0 shadow-sm">
                    <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
                        <span>Tickets Assigned to Me</span>
                        
                        <!-- Export buttons -->
                        <div class="mb-0">
                            <a href="export_agent_excel.php?filter=<?= urlencode($filter) ?>"
                               class="btn btn-sm btn-light btn-outline-primary">
                                Export to Excel
                            </a>
                        </div>
                    </div>
                    
                    <!-- Search Form with Clear Button -->
<form method="GET" class="mb-3 p-3 bg-light rounded">
    <div class="row g-3">
        <div class="col-md-10">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="filter" class="form-control" 
                       placeholder="Search by ID, title, status, created by, date, or priority..." 
                       value="<?= htmlspecialchars($filter) ?>">
                <?php if (!empty($filter)): ?>
                <a href="?" class="btn btn-outline-secondary" title="Clear search">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-search"></i> Search
            </button>
        </div>
    </div>
</form>

<!-- Results Summary -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <div>
        <span class="text-muted">
            <?= $filterDisplay ?> 
            <span class="badge bg-secondary"><?= $totalTickets ?> tickets found</span>
        </span>
    </div>
    
   
</div>

                    <!-- Agent ID display removed to avoid stray number showing before table -->

                    
                    <?php if ($result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th>Created Date</th>
                                    <th>Priority</th>
                                    <th class="no-export">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($ticket = $result->fetch_assoc()): ?>
                                    <tr <?= ticket_row_attrs($ticket) ?> >
                                        <td><?= 'TCK-' . str_pad($ticket['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                        <td><?= htmlspecialchars($ticket['title']) ?></td>
                                        <td>
                                            <span class="badge <?= getBadgeClass($ticket['status']) ?>">
                                                <?= htmlspecialchars($ticket['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($ticket['created_by']) ?></td>
                                        <td><?= htmlspecialchars($ticket['query_date']) ?></td>
                                        <td>
                                            <span class="badge <?= $ticket['priority'] === 'High' ? 'bg-danger' : ($ticket['priority'] === 'Medium' ? 'bg-warning' : 'bg-success') ?>">
                                                <?= htmlspecialchars($ticket['priority']) ?>
                                            </span>
                                        </td>
                                        <td>
                                                <div class="d-flex gap-2">
                                                    <!-- View Button -->
                                                    <button type="button" 
                                                            class="btn btn-sm btn-info view-ticket-btn" 
                                                            data-ticket-id="<?= $ticket['id'] ?>"
                                                            title="View Ticket">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Status Form -->
                                                    <form class="status-form" method="POST">
    <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
    <input type="hidden" name="current_status" value="<?= htmlspecialchars($ticket['status']) ?>">

                                                        <div class="input-group input-group-sm">
                                                            <select name="status" class="form-select form-select-sm status-select">
                                                                <option value="">Change Status</option>
                                                                <option value="Open" <?= $ticket['status'] == 'Open' ? 'selected' : '' ?>>Open</option>
                                                                <option value="In Progress" <?= $ticket['status'] == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                                                <option value="Closed" <?= $ticket['status'] == 'Closed' ? 'selected' : '' ?>>Closed</option>
                                                                <option value="Escalate" <?= $ticket['status'] == 'Escalate' ? 'selected' : '' ?>>Escalate</option>
                                                            </select>
                                                            <button type="submit" class="btn btn-outline-primary" title="Update Status">
                                                                <i class="bi bi-send"></i>
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </td>
                                    </tr>
                                    
                                    
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        No tickets assigned to you.
                    </div>
                <?php endif; ?>
                                          

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" 
                                       href="?page=<?= $i ?>&filter=<?= urlencode($filter) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ticket View Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-labelledby="ticketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header card-color text-white rounded-top">
                <h5 class="modal-title d-flex align-items-center" id="ticketModalLabel">
                    <i class="bi bi-ticket-perforated me-2"></i> Ticket Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" id="modalBodyContent">
                Loading...
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reason Modal (for escalated/closed tickets) -->
<div class="modal fade" id="reasonModal" tabindex="-1" aria-labelledby="reasonModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="reasonModalForm" method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reasonModalLabel">Provide a Reason</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="ticket_id" id="modalTicketId">
                <input type="hidden" name="status" id="modalNewStatus">
                <div class="mb-3">
                    <label for="modalReason" class="form-label">Reason for status change</label>
                    <textarea class="form-control" name="reason" id="modalReason" rows="3" required></textarea>
                    <div class="form-text">This information will be recorded with the status change.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Update Status</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Back function
    function goBack() {
        if (document.referrer && document.referrer.includes(window.location.hostname)) {
            window.history.back();
        } else {
            window.location.href = './agent_dashboard.php';
        }
    }
document.addEventListener('DOMContentLoaded', function () {

    const reasonModalEl = document.getElementById('reasonModal');
    const reasonModal = new bootstrap.Modal(reasonModalEl);
    const reasonForm = document.getElementById('reasonModalForm');

    let pendingFormData = null;

    // ==============================
    // STATUS FORM SUBMISSION
    // ==============================
    document.querySelectorAll('.status-form').forEach(form => {

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const ticketId = this.querySelector('input[name="ticket_id"]').value;
            const currentStatus = this.querySelector('input[name="current_status"]').value.toLowerCase();
            const selectElement = this.querySelector('.status-select');
            const newStatus = selectElement.value;

            if (!newStatus) {
                alert("Please select a status");
                return;
            }

            const newStatusLower = newStatus.toLowerCase();

            const requiresReason =
                (currentStatus === 'open' && newStatusLower === 'in progress') ||
                newStatusLower === 'closed' ||
                newStatusLower === 'escalate';

            if (requiresReason) {

                document.getElementById('modalTicketId').value = ticketId;
                document.getElementById('modalNewStatus').value = newStatus;
                document.getElementById('reasonModalLabel').textContent =
                    `Provide a Reason for marking as ${newStatus}`;

                pendingFormData = new FormData();
                pendingFormData.append('ticket_id', ticketId);
                pendingFormData.append('status', newStatus);

                reasonForm.reset();
                reasonModal.show();

            } else {
                await submitStatusUpdate(new FormData(this));
            }
        });
    });

    // ==============================
    // REASON MODAL SUBMISSION
    // ==============================
    reasonForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        const reason = document.getElementById('modalReason').value.trim();

        if (!reason) {
            alert('Please provide a reason before submitting.');
            return;
        }

        pendingFormData.append('reason', reason);

        await submitStatusUpdate(pendingFormData);

        reasonModal.hide();
        pendingFormData = null;
    });

    // ==============================
    // AJAX SUBMIT FUNCTION
    // ==============================
    async function submitStatusUpdate(formData) {
        try {
            const response = await fetch('../ticket/update_ticket_status_ajax.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message || 'Status updated successfully.');
                setTimeout(() => location.reload(), 1500);
            } else {
                alert(result.message || 'An error occurred.');
            }

        } catch (error) {
            console.error("AJAX error:", error);
            alert("Failed to update status. Please try again.");
        }
    }

});
    // View Ticket Button
    document.querySelectorAll('.view-ticket-btn').forEach(button => {
        button.addEventListener('click', async function () {
            const ticketId = this.getAttribute('data-ticket-id');
            
            try {
                const modal = new bootstrap.Modal(document.getElementById('ticketModal'));
                modal.show();
                
                // Fetch ticket details
                const response = await fetch(`../ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
                const data = await response.json();
                
                if (data.success) {
                    const t = data.ticket;
                    
                    let modalContent = `
                        <div class="container-fluid">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2"><i class="bi bi-info-circle me-1"></i> Ticket Info</h6>
                                            <ul class="list-group list-group-flush small">
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>ID:</strong> <span>TCK-${String(t.id).padStart(6, '0')}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Title:</strong> <span>${escapeHtml(t.title)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Status:</strong> 
                                                    <span class="badge ${getBadgeClass(t.status)}">${escapeHtml(t.status)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Priority:</strong> 
                                                    <span class="badge ${t.priority === 'High' ? 'bg-danger' : (t.priority === 'Medium' ? 'bg-warning' : 'bg-success')}">
                                                        ${escapeHtml(t.priority)}
                                                    </span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Created:</strong> <span>${escapeHtml(t.query_date)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Source:</strong> <span>${escapeHtml(t.source || 'N/A')}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2"><i class="bi bi-person-lines-fill me-1"></i> Requester Info</h6>
                                            <ul class="list-group list-group-flush small">
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Query Type:</strong> <span>${escapeHtml(t.query_type)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Member Type:</strong> <span>${escapeHtml(t.member_type)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Phone:</strong> <span>${escapeHtml(t.phone_number)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Region:</strong> <span>${escapeHtml(t.region)}</span>
                                                </li>
                                                <li class="list-group-item px-0 d-flex justify-content-between">
                                                    <strong>Created By:</strong> <span>${escapeHtml(t.created_by)}</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="mt-4">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <h6 class="text-muted mb-2"><i class="bi bi-file-text me-1"></i> Description</h6>
                                        <div class="p-3 bg-white rounded border text-dark" style="min-height: 80px;">
                                            ${escapeHtml(t.description || 'No description')}
                                        </div>
                                    </div>
                                </div>
                            </div>
                    `;
                    
                    // Add attachment section if exists
                    if (t.attachment_url) {
                        const ext = t.attachment_url.split('.').pop().toLowerCase();
                        let content = '';
                        if (['jpg', 'jpeg', 'png', 'gif', 'bmp'].includes(ext)) {
                            content = `<img src="${t.attachment_url}" class="img-fluid border rounded" style="max-height: 300px;">`;
                        } else if (ext === 'pdf') {
                            content = `<iframe src="${t.attachment_url}" class="w-100" style="height: 400px; border: none;"></iframe>`;
                        } else {
                            content = `<div class="text-center py-4">
                                        <i class="bi bi-file-earmark-text display-4 text-muted"></i>
                                        <p class="mt-2">${escapeHtml(t.attachment_url.split('/').pop())}</p>
                                      </div>`;
                        }
                        
                        modalContent += `
                            <!-- Attachments -->
                            <div class="mt-4">
                                <h6 class="text-muted mb-2"><i class="bi bi-paperclip me-1"></i> Attachment</h6>
                                <div class="border rounded p-2 bg-white text-center mb-2">
                                    ${content}
                                </div>
                                <a href="${t.attachment_url}" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-download me-1"></i> Download Attachment
                                </a>
                            </div>
                        `;
                    } else {
                        modalContent += `
                            <!-- Attachments -->
                            <div class="mt-4">
                                <h6 class="text-muted mb-2"><i class="bi bi-paperclip me-1"></i> Attachment</h6>
                                <div class="border rounded p-2 bg-white text-center">
                                    <p class="text-muted">No attachment</p>
                                </div>
                            </div>
                        `;
                    }
                    
                    modalContent += `</div>`;
                    document.getElementById('modalBodyContent').innerHTML = modalContent;
                } else {
                    document.getElementById('modalBodyContent').innerHTML = `
                        <div class="alert alert-danger">
                            ${escapeHtml(data.message || 'Failed to load ticket details.')}
                        </div>
                    `;
                }
            } catch (error) {
                console.error("Error loading ticket:", error);
                document.getElementById('modalBodyContent').innerHTML = `
                    <div class="alert alert-danger">
                        Failed to load ticket details. Please try again.
                    </div>
                `;
            }
        });
    });

    function getBadgeClass(status) {
        status = status.toLowerCase();
        switch(status) {
            case 'open': return 'bg-warning text-dark';
            case 'in progress': return 'bg-info text-dark';
            case 'closed': return 'bg-success';
            case 'escalate': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'position-fixed bottom-0 end-0 p-3';
        toast.innerHTML = `
            <div class="toast bg-success text-white" role="alert" data-bs-delay="3000">
                <div class="toast-body">${escapeHtml(message)}</div>
            </div>
        `;
        document.body.appendChild(toast);
        const toastInstance = new bootstrap.Toast(toast.querySelector('.toast'));
        toastInstance.show();
        setTimeout(() => toast.remove(), 4000);
    }

    // Prepare table clone for export: removes columns with class 'no-export'
    function prepareTableForExport() {
        const table = document.querySelector('.table');
        if (!table) return null;
        const clone = table.cloneNode(true);

        const ths = clone.querySelectorAll('thead th');
        const removeIndexes = [];
        ths.forEach((th, index) => {
            if (th.classList.contains('no-export')) removeIndexes.push(index);
        });

        clone.querySelectorAll('tr').forEach(row => {
            removeIndexes.slice().reverse().forEach(i => {
                if (row.children[i]) row.children[i].remove();
            });
        });

        return clone;
    }

    function exportFileName(ext) {
        const d = new Date();
        const date = d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, '0') + "-" + String(d.getDate()).padStart(2, '0');
        return `tickets_${date}.${ext}`;
    }

    // Export visible table to Excel using SheetJS
    function exportExcel() {
        const cleanTable = prepareTableForExport();
        if (!cleanTable) {
            alert('No table to export.');
            return;
        }

        const wb = XLSX.utils.table_to_book(cleanTable, { sheet: 'Tickets' });
        XLSX.writeFile(wb, exportFileName('xlsx'));
    }

    // Auto-refresh page every 2 minutes
    setInterval(() => {
        if (document.visibilityState === 'visible') {
            location.reload();
        }
    }, 120000);
</script>

<?php include '../footer.php'; ?>

</body>
</html>

<?php $conn->close(); ?>