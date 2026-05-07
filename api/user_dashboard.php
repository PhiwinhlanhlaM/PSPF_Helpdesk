<?php
// user_dashboard.php

require_once './includes/auth_helpers.php';
require_once './includes/role_switcher.php';
require_once './db.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn()) {
    header('Location: signin/index.php');
    exit;
}

// allow any system role here
requireAnyRole(['user','agent','admin','superadmin']);

$activeRole = getActiveRole();

$UserId        = (int)$_SESSION['user']['id'];
$UserUsername  = $_SESSION['user']['username'];
$UserEmail     = $_SESSION['user']['email'];
$UserDept      = $_SESSION['user']['department'] ?? '';
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



// 🔹 fallback reload department if missing from session
if (empty($UserDept)) {
    $stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
    $stmt->bind_param("i", $UserId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        $_SESSION['user']['department'] = $res['department'];
        $UserDept = $res['department'];
    }
}



// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// -----------------------------
// Back button functionality - Store page history
// -----------------------------
if (!isset($_SESSION['page_history'])) {
    $_SESSION['page_history'] = [];
}

$current_url = $_SERVER['REQUEST_URI'];

// Add current page to history if it's different from the last one
if (empty($_SESSION['page_history']) || end($_SESSION['page_history']) !== $current_url) {
    $_SESSION['page_history'][] = $current_url;
}

// Keep only last 5 pages to prevent array from growing too large
if (count($_SESSION['page_history']) > 5) {
    array_shift($_SESSION['page_history']);
}

// Get previous page for back button
$previous_page = '';
if (count($_SESSION['page_history']) > 1) {
    $previous_page = $_SESSION['page_history'][count($_SESSION['page_history']) - 2];
}

// Ensure user exists
if (!isset($_SESSION['user'])) {
    die("Not logged in.");
}

$username = $_SESSION['user']['username'];  // USE USERNAME, NOT ID
$itemsPerPage = 10;

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $itemsPerPage;

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'query_date';
$sortOrder = isset($_GET['order']) ? strtoupper($_GET['order']) : 'DESC';

$allowedSortColumns = ['id', 'title', 'priority', 'status', 'query_date'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'query_date';
}

$sortOrder = ($sortOrder === 'ASC') ? 'ASC' : 'DESC';
$searchLike = '%' . $conn->real_escape_string($search) . '%';

// COUNT QUERY
$sqlCount = "
    SELECT COUNT(*) AS cnt
    FROM tickets
    WHERE created_by = ?
      AND (CAST(id AS CHAR) LIKE ?
        OR title LIKE ?
        OR description LIKE ?)
";

$params = [$username, $searchLike, $searchLike, $searchLike];
$types  = "ssss";

if ($statusFilter !== 'all') {
    $sqlCount .= " AND status = ?";
    $params[] = $statusFilter;
    $types   .= "s";
}

$stmt = $conn->prepare($sqlCount);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalTickets = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$totalPages = max(1, ceil($totalTickets / $itemsPerPage));


// MAIN QUERY
$sql = "
    SELECT *
    FROM tickets
    WHERE created_by = ?
      AND (CAST(id AS CHAR) LIKE ?
        OR title LIKE ?
        OR description LIKE ?)
";

$params = [$username, $searchLike, $searchLike, $searchLike];
$types  = "ssss";

if ($statusFilter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
    $types   .= "s";
}

$sql .= " ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
$params[] = $itemsPerPage;
$params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();


/* -----------------------------
   HELPER FUNCTIONS
----------------------------- */
function statusBadge(string $s): string {
    return match (strtolower($s)) {
        'open' => 'badge bg-primary',
        'in progress' => 'badge bg-warning text-dark',
        'closed' => 'badge bg-success',
        'escalated' => 'badge bg-danger',
        default => 'badge bg-secondary',
    };
}

function priorityBadge(string $priority): string {
    return match (strtolower($priority)) {
        'high' => 'badge bg-danger',
        'medium' => 'badge bg-warning text-dark',
        'low' => 'badge bg-info',
        default => 'badge bg-secondary',
    };
}
function statusColor(string $status): string {
    return match (strtolower($status)) {
        'open' => 'status-open',
        'in progress' => 'status-in-progress',
        'closed' => 'status-closed',
        'escalated' => 'status-escalated',
        default => 'status-unknown',
    };
}

function formatDateTime($dateTime): string {
    if (empty($dateTime)) return 'N/A';
    return date('d M Y, H:i', strtotime($dateTime));
}

function truncateText($text, $length = 100): string {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>' . $_SESSION['success'] . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>' . $_SESSION['error'] . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['error']);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Help Center - PSPF CRM</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="./style5.css">
  <link rel="stylesheet" href="./agent/agent_style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="icon" type="image/png" href="./uploads/pspflogo2.png">
  <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

</head>
<body>

<!-- Top Navigation Bar -->
<?php include './agent/topnav.php'; ?>

<!-- Loading indicator -->
<div id="loading" class="loading-indicator" style="display: none;">
  <div class="spinner-border text-primary" role="status">
    <span class="visually-hidden">Loading...</span>
  </div>
</div>

<!-- Main Content -->
<main class="main-content">
  <!-- Help Desk Categories -->
  <div class="container mt-4">

      <div class="settings-header">   
        <h1 class="settings-title">What we offer?</h1>
        <div class="settings-actions">
          <!-- Back Button -->
            <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </button>
        </div>
      </div>

   <div class="card-grid">

  <div class="card-1">
    <img src="./uploads/it_repository.jpg" alt="Self Service">
    <h3>Self-Service</h3>
    <p>Access detailed guides and solutions to common IT questions.</p>
    <a href="./Knowledge_base.php">IT Repository</a>
  </div>

  <div class="card-1">
    <img src="./uploads/ticketlogging.png" alt="Get Started">
    <h3>Getting Started</h3>
    <p>Submit a new request or report an issue through the ticketing system.</p>
    <a href="./ticket/query.php">Log Ticket</a>
  </div>

  <!-- <div class="card-1">
    <img src="./uploads/istockphoto.jpg" alt="Friday Lunch">
    <h3>Friday Lunch</h3>
    <p>Place your complimentary Friday lunch order from approved partner outlets.</p>
    <a href="./order/food_order.php">Order</a>
  </div> -->

  <div class="card-1">
    <img src="./uploads/booking1.png" alt="Vehicles">
    <h3>Vehicles</h3>
    <p>Need to run a Fund-related errand? Reserve a vehicle for official use.</p>
    <a href="../vehicle_booking/login.php">Book</a>
  </div>

</div>

  </div>

  <!-- PREVIOUS TICKETS -->
<div class="container mt-5">
    <div class="card border-0 shadow-sm">
        <div class="card-header card-color text-white d-flex justify-content-between align-items-center">
            <span>Your Previous Tickets</span>
            <small class="text-light">Total: <?= $totalTickets ?> tickets</small>
        </div>
        
        <div class="card-body">
            <form method="GET" class="row gy-2 gx-3 mb-4" onsubmit="return validateSearchForm()">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
                <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
                
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Search tickets by ID, title, or description..." 
                           value="<?= htmlspecialchars($search) ?>" maxlength="100">
                </div>
                
                <div class="col-md-3">
                    <select name="status" class="form-select form-select-sm">
                        <option value="all" <?= empty($statusFilter) || $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="open" <?= $statusFilter == 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="in progress" <?= $statusFilter == 'in progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="closed" <?= $statusFilter == 'closed' ? 'selected' : '' ?>>Closed</option>
                        <option value="escalated" <?= $statusFilter == 'escalated' ? 'selected' : '' ?>>Escalated</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
                
                <div class="col-md-3 text-md-end">
                    <a href="./ticket/query.php" class="btn btn-success btn-sm w-100">
                        <i class="bi bi-plus-circle"></i> New Ticket
                    </a>
                </div>
                
                <?php if (!empty($search) || !empty($statusFilter)): ?>
                <div class="col-12">
                    <a href="user_dashboard.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </form>
            
            <?php if (!empty($tickets)): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="card-color text-white">
                            <tr>
                                <th>
                                    <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=id&order=<?= $sortBy == 'id' && $sortOrder == 'DESC' ? 'ASC' : 'DESC' ?>">
                                        Ticket ID <?= $sortBy == 'id' ? ($sortOrder == 'DESC' ? '↓' : '↑') : '' ?>
                                    </a>
                                </th>
                                <th>Title</th>
                                <th>
                                    <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=priority&order=<?= $sortBy == 'priority' && $sortOrder == 'DESC' ? 'ASC' : 'DESC' ?>">
                                        Priority <?= $sortBy == 'priority' ? ($sortOrder == 'DESC' ? '↓' : '↑') : '' ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=status&order=<?= $sortBy == 'status' && $sortOrder == 'DESC' ? 'ASC' : 'DESC' ?>">
                                        Status <?= $sortBy == 'status' ? ($sortOrder == 'DESC' ? '↓' : '↑') : '' ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&sort=query_date&order=<?= $sortBy == 'query_date' && $sortOrder == 'DESC' ? 'ASC' : 'DESC' ?>">
                                        Date Created <?= $sortBy == 'query_date' ? ($sortOrder == 'DESC' ? '↓' : '↑') : '' ?>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold">TCK-<?= str_pad((int)$ticket['id'], 6, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars(truncateText($ticket['title'], 50)) ?></div>
                                        <?php if (!empty($ticket['description'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars(truncateText($ticket['description'], 60)) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <span class="<?= priorityBadge($ticket['priority']) ?>">
                                            <i class="bi bi-<?= $ticket['priority'] == 'high' ? 'exclamation-triangle' : ($ticket['priority'] == 'medium' ? 'exclamation-circle' : 'info-circle') ?>"></i>
                                            <?= htmlspecialchars(ucfirst($ticket['priority'])) ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <span class="status-button <?= htmlspecialchars(statusColor($ticket['status'])) ?>">
                                            <i class="bi bi-<?= 
                                                $ticket['status'] == 'open' ? 'clock' : 
                                                ($ticket['status'] == 'in progress' ? 'gear' : 
                                                ($ticket['status'] == 'escalated' ? 'exclamation-octagon' :
                                                ($ticket['status'] == 'closed' ? 'check-circle' : 'arrow-up-circle'))) ?>"></i>
                                            <?= htmlspecialchars(ucfirst($ticket['status'])) ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <div><?= formatDateTime($ticket['query_date']) ?></div>
                                        
                                    </td>
                                    
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button 
                                                class="btn btn-info view-ticket-btn"
                                                data-id="<?= (int)$ticket['id'] ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#ticketModal"
                                                title="View Details"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            
                                            <?php if ($ticket['status'] === 'Open' && $ticket['created_by'] === $_SESSION['user']['username']): ?>
                                                
                                            <?php endif; ?>

                                            
                                            <?php if (!empty($ticket['attachment_path'])): ?>
                                                <a href="<?= htmlspecialchars($ticket['attachment_path']) ?>" 
                                                   class="btn btn-outline-primary"
                                                   target="_blank"
                                                   title="View Attachment">
                                                    <i class="bi bi-paperclip"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                               <!-- PAGINATION -->
                <?php 
                
                    // Build base query for pagination links
                    $baseQuery = "?search=" . urlencode($search)
                                . "&status=" . urlencode($statusFilter)
                                . "&sort=" . urlencode($sortBy)
                                . "&order=" . urlencode($sortOrder)
                                . "&page=";

                ?>

                

                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mt-3">

                        <!-- Previous Button -->
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" 
                               href="<?= $page > 1 ? $baseQuery . ($page - 1) : '#' ?>">
                                Previous
                            </a>
                        </li>

                        <!-- Page Numbers -->
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $baseQuery . $i ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <!-- Next Button -->
                        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <a class="page-link" 
                               href="<?= $page < $totalPages ? $baseQuery . ($page + 1) : '#' ?>">
                                Next
                            </a>
                        </li>

                    </ul>
                </nav>


            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h4 class="text-muted mt-3">No tickets found</h4>
                    <p class="text-muted">
                        <?php if (!empty($search) || (!empty($statusFilter) && $statusFilter !== 'all')): ?>
                            No tickets match your search criteria.
                        <?php else: ?>
                            You haven't created any tickets yet.
                        <?php endif; ?>
                    </p>
                    <a href="./ticket/query.php" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-circle"></i> Create Your First Ticket
                    </a>
                    <?php if (!empty($search) || (!empty($statusFilter) && $statusFilter !== 'all')): ?>
                        <a href="user_dashboard.php" class="btn btn-outline-secondary mt-2">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</main>

<!-- Ticket View Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-labelledby="ticketModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content shadow-lg border-0">
      <!-- Header -->
      <div class="modal-header card-color text-white rounded-top">
        <h5 class="modal-title d-flex align-items-center" id="ticketModalLabel">
          <i class="bi bi-ticket-perforated me-2"></i> Ticket Details
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Body -->
      <div class="modal-body bg-light">
        <div class="container-fluid">
          <!-- Ticket Summary -->
          <div class="row g-3">
            <div class="col-md-6">
              <div class="card border-0 shadow-sm">
                <div class="card-body">
                  <h6 class="text-muted mb-2"><i class="bi bi-info-circle me-1"></i> Ticket Info</h6>
                  <ul class="list-group list-group-flush small">
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>ID:</strong> <span id="modalTicketID"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Title:</strong> <span id="modalTitle"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Status:</strong> 
                      <span id="modalStatus" class="badge bg-info text-dark"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Priority:</strong> 
                      <span id="modalPriority" class="badge bg-danger"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Created:</strong> <span id="modalCreatedDate"></span>
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
                      <strong>Department:</strong> <span id="modalDepartment"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Query Type:</strong> <span id="modalQueryType"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Member Type:</strong> <span id="modalMemberType"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Phone:</strong> <span id="modalPhone"></span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                      <strong>Region:</strong> <span id="modalRegion"></span>
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
                <div id="modalDescription" class="p-3 bg-white rounded border text-dark" style="min-height: 80px;"></div>
              </div>
            </div>
          </div>

          <!-- Escalation -->
          <div class="mt-3">
            <div class="alert alert-warning border-start border-4 border-danger py-2">
              <strong>Escalation Reason:</strong> 
              <span id="modalEscalationReason" class="text-danger fw-semibold"></span>
            </div>
          </div>

          <!-- Attachments -->
          <div class="mt-4">
            <h6 class="text-muted mb-2"><i class="bi bi-paperclip me-1"></i> Attachment</h6>
            <div id="attachmentPreview" class="border rounded p-2 bg-white text-center mb-2"></div>
            <a id="attachmentDownload" href="#" class="btn btn-sm btn-outline-primary d-none" target="_blank">
              <i class="bi bi-download me-1"></i> Download Attachment
            </a>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle me-1"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>



<?php include './footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Loading functions
function showLoading() {
    document.getElementById('loading').style.display = 'block';
}

function hideLoading() {
    document.getElementById('loading').style.display = 'none';
}

// Back function
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
            window.location.href = 'user_dashboard.php';
        }
    }
}

// Keyboard shortcut for back button (Alt + Left Arrow)
document.addEventListener('keydown', function(event) {
    if (event.altKey && event.key === 'ArrowLeft') {
        goBack();
        event.preventDefault();
    }
});

// Form validation
function validateSearchForm() {
    const search = document.querySelector('input[name="search"]').value;
    if (search.length > 100) {
        alert('Search term too long. Maximum 100 characters allowed.');
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', () => {
    const ticketModalEl = document.getElementById('ticketModal');
    const ticketModal = new bootstrap.Modal(ticketModalEl);

    // Ticket view functionality
    document.querySelectorAll('.view-ticket-btn').forEach(button => {
        button.addEventListener('click', async function () {
            showLoading();
            const ticketId = this.getAttribute('data-id');

            try {
                const response = await fetch(`./ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`);
                const data = await response.json();

                if (data.success) {
                    const t = data.ticket;
                    document.getElementById('modalTicketID').textContent = 'TCK-' + String(t.id).padStart(6, '0');
                    document.getElementById('modalTitle').textContent = t.title;
                    document.getElementById('modalPriority').textContent = t.priority;
                    document.getElementById('modalStatus').textContent = t.status;
                    document.getElementById('modalDepartment').textContent = t.department;
                    document.getElementById('modalQueryType').textContent = t.query_type;
                    document.getElementById('modalMemberType').textContent = t.member_type;
                    document.getElementById('modalPhone').textContent = t.phone_number;
                    document.getElementById('modalDescription').textContent = t.description;
                    document.getElementById('modalRegion').textContent = t.region;
                    document.getElementById('modalCreatedDate').textContent = t.query_date;
                    document.getElementById('modalEscalationReason').textContent = t.escalation_reason || 'N/A';

                    const preview = document.getElementById('attachmentPreview');
                    const downloadBtn = document.getElementById('attachmentDownload');

                    if (t.attachment_path) {
                        const ext = t.attachment_path.split('.').pop().toLowerCase();
                        let content = '';
                        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                            content = `<img src="${t.attachment_path}" class="img-fluid border" alt="Ticket attachment">`;
                        } else if (ext === 'pdf') {
                            content = `<iframe src="${t.attachment_path}" style="width:100%;height:400px;" title="PDF preview"></iframe>`;
                        } else {
                            content = `<p class="text-muted">Preview not supported.</p>`;
                        }
                        preview.innerHTML = content;
                        downloadBtn.href = t.attachment_path;
                        downloadBtn.style.display = 'inline-block';
                    } else {
                        preview.innerHTML = '<p class="text-muted">No attachment.</p>';
                        downloadBtn.style.display = 'none';
                    }

                    ticketModal.show();
                } else {
                    alert('Failed to load ticket.');
                }
            } catch (e) {
                console.error('Error fetching ticket:', e);
                alert('Error fetching ticket details.');
            } finally {
                hideLoading();
            }
        });
    });

    // Modal cleanup
    ticketModalEl.addEventListener('hidden.bs.modal', () => {
        document.querySelectorAll('.modal-backdrop').forEach(e => e.remove());
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
    });

    // Notifications functionality
    const notifBtn = document.getElementById('notifBtn');
    const notifCount = document.getElementById('notifCount');
    const notifList = document.getElementById('notifList');
    const notifDropdown = document.getElementById('notifDropdown');

    // Toggle dropdown
    notifBtn.addEventListener('click', () => {
        notifDropdown.classList.toggle('show');
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
            notifDropdown.classList.remove('show');
        }
    });

    // Fetch notifications
    async function fetchNotifications() {
        try {
            showLoading();
            const response = await fetch('./ticket/fetch_notifications.php');
            const data = await response.json();

            // Update badge
            notifCount.textContent = data.count;
            notifCount.style.display = data.count > 0 ? 'inline-block' : 'none';

            // Update dropdown
            if (data.count > 0) {
                notifList.innerHTML = '';
                data.notifications.forEach(n => {
                    const div = document.createElement('div');
                    div.classList.add('dropdown-item');
                    div.innerHTML = `<small class="text-muted">${n.created_at}</small><br>${n.message}`;
                    notifList.appendChild(div);
                });
            } else {
                notifList.innerHTML = `<p class="text-muted text-center mb-0">No new notifications</p>`;
            }
        } catch (err) {
            console.error('Error fetching notifications:', err);
        } finally {
            hideLoading();
        }
    }

    // Initial fetch
    fetchNotifications();

    // Fetch every 15 seconds
    setInterval(fetchNotifications, 15000);

    // Theme toggle
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    themeToggleBtn.addEventListener('click', () => {
        document.body.classList.toggle('dark-theme');
        // Save theme preference to localStorage
        const isDark = document.body.classList.contains('dark-theme');
        localStorage.setItem('darkTheme', isDark);
    });

    // Load saved theme
    if (localStorage.getItem('darkTheme') === 'true') {
        document.body.classList.add('dark-theme');
    }
});

// Accessibility: Skip link focus
document.addEventListener('DOMContentLoaded', function() {
    const skipLink = document.querySelector('.skip-link');
    if (skipLink) {
        skipLink.addEventListener('click', function(e) {
            e.preventDefault();
            const mainContent = document.getElementById('main-content');
            if (mainContent) {
                mainContent.setAttribute('tabindex', '-1');
                mainContent.focus();
            }
        });
    }
});
</script>
<script>
function openEditTicketModal(ticketId) {

  fetch(`./ticket/get_ticket_details_ajax.php?ticket_id=${ticketId}`)
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        alert(data.message);
        return;
      }

      const t = data.ticket;

      document.getElementById('edit_ticket_id').value = t.id;
      document.getElementById('edit_title').value = t.title;
      document.getElementById('edit_priority').value = t.priority;
      document.getElementById('edit_query_type').value = t.query_type;
      document.getElementById('edit_region').value = t.region;
      document.getElementById('edit_phone').value = t.phone_number;
      document.getElementById('edit_description').value = t.description;

      new bootstrap.Modal(document.getElementById('editTicketModal')).show();
    });
}
</script>
<script>
// Check for ticket creation
if (window.location.search.includes('ticket_created=1')) {
    // Show a toast notification
    showToast('Ticket created successfully!', 'success');
}

function showToast(message, type = 'success') {
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <i class="bi bi-check-circle-fill"></i>
            <span>${message}</span>
        </div>
        <button class="toast-close">&times;</button>
    `;
    
    document.body.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => toast.remove(), 5000);
    
    // Close button
    toast.querySelector('.toast-close').addEventListener('click', () => toast.remove());
}
</script>

</body>
</html>
