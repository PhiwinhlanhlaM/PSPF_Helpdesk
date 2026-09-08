<?php
// order/food_order.php

// Include session configuration at the very top
require_once '../db.php';
require_once '../includes/auth_helpers.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// Add security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
// Consider adding CSP if compatible with your CDN usage

// Enhanced session check with regeneration
if (!isLoggedIn()) {
    header('Location: ../signin/index.php');
    exit;
}

// Regenerate session ID periodically for security
if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

// Require that the active role is one of user roles for "user dashboard"
requireAnyRole(['user', 'agent', 'admin', 'superadmin']);

$user = getUserFromSession();
$activeRole = getActiveRole() ?? 'user';
$username = $user['username'] ?? $user['email'] ?? 'User';

// Date setup - allow immediate orders
$current_datetime = date('Y-m-d\TH:i');
$min_datetime = $current_datetime; // No buffer, current time
$max_datetime = date('Y-m-d\TH:i', strtotime('+30 days'));


// Fetch active outlets with error handling
$outlets = [];

// Fetch outlets data (your existing code)
try {
    $stmt = $conn->prepare("SELECT id, name, description, url, logo FROM outlets WHERE is_active = 1 ORDER BY name");
    if (!$stmt) {
        throw new Exception("Database query preparation failed");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    

    
    $outlets = []; // Initialize array
    while ($row = $result->fetch_assoc()) {
        // Sanitize outlet data
        $row['name'] = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
        $row['description'] = htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8');
        $row['url'] = filter_var($row['url'], FILTER_VALIDATE_URL) ? $row['url'] : '';
        
        // Handle logo URL - assuming it might be full URL or relative path
       $uploadDir = __DIR__ . '/../uploads/outlets/';
        $webDir    = '../uploads/outlets/';
        $defaultLogo = '../uploads/outlets/default.png';

        if (!empty($row['logo']) && file_exists($uploadDir . $row['logo'])) {
            $row['logo_url'] = $webDir . $row['logo'];
        } else {
            $row['logo_url'] = $defaultLogo;
        }
                $outlets[] = $row;  
            }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching outlets: " . $e->getMessage());
    $outlets = []; // Empty array on error
}

$logoBasePath = '../uploads/';
$defaultLogo  = '../uploads/default.png';

// Store variables for footer
$userName = $username;
$userRole = $activeRole;

// Fetch today's orders for the logged-in user
$todayOrders = [];
try {
    $stmt = $conn->prepare("
        SELECT o.*, out.name AS outlet_name
        FROM orders o
        JOIN outlets out ON out.id = o.outlet_id
        WHERE o.user_id = ?
          AND DATE(o.created_at) = CURDATE()
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param("i", $_SESSION['user']['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $todayOrders[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching today's orders: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Food - PSPF CRM</title>
    <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <link rel="stylesheet" href="../style4.css">
    <link rel="stylesheet" href="../agent/agent_style.css">
    <link rel="stylesheet" href="./foodstyle.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

    <style>
        .hidden { display: none !important; }
        .outlet-container { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; }
        .outlet-card-horizontal, .cash-card { 
            border: 1px solid #ddd; border-radius: 8px; padding: 15px; 
            width: 300px; cursor: pointer; transition: all 0.3s ease;
            background: white;
        }
        .outlet-card-horizontal:hover, .cash-card:hover { 
            transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); 
        }
        .outlet-card-horizontal.selected, .cash-card.selected {
            border-color: #3D5C80; background-color: #f8f9fa;
        }
        .card-content { margin-bottom: 10px; }
        .card-title { margin: 0 0 5px 0; font-size: 1.1em; }
        .card-description { color: #666; font-size: 0.9em; margin: 0; }
        .cash-icon { font-size: 2em; text-align: center; margin-bottom: 10px; }
        .cash-title { text-align: center; margin: 0 0 5px 0; }
        .cash-subtitle { text-align: center; color: #666; font-size: 0.9em; margin: 0; }
        .calculator-container, .order-summary { 
            background: #f8f9fa; border-radius: 8px; padding: 20px; 
            border: 1px solid #dee2e6;
        }
        .fee-warning { color: #dc3545; font-weight: bold; }
        .section-header { 
            background: #3D5C80; 
            color: white; padding: 10px 15px; border-radius: 8px; 
            margin-bottom: 15px;
        }
        .order-item { 
            background: white; border: 1px solid #dee2e6; border-radius: 5px; 
            padding: 10px; margin-bottom: 10px;
        }
        .active-order-card { margin-bottom: 15px; }
        .no-outlets { 
            background: #fff3cd; border: 1px solid #ffeaa7; 
            padding: 20px; border-radius: 8px; text-align: center;
            width: 100%;
        }
        .alert-message {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }
    </style>
</head>

<body>
<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show alert-message" role="alert">
    <?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php unset($_SESSION['error']); endif; ?>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show alert-message" role="alert">
    <?= htmlspecialchars($_SESSION['success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php unset($_SESSION['success']); endif; ?>

<?php include '../agent/topnav.php'; ?>
<main id="main-content">
  <!-- Help Desk Categories -->
  <div class="container mt-4">

            <div class="settings-header">   
                <h1 class="settings-title"> What's for lunch?</h1>
                <div class="settings-actions">
                <!-- Back Button -->
                    <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                </div>
            </div>

            <?php if (!empty($todayOrders)): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <strong> Your Order</strong>
    </div>
    <div class="card-body">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Outlet</th>
                    <th>Items</th>
                    <th>Total (E)</th>
                    <th>Ordered At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($todayOrders as $order): ?>
                <tr>
                    <td><?= htmlspecialchars($order['outlet_name']) ?></td>
                    <td><?= nl2br(htmlspecialchars($order['order_items'])) ?></td>
                    <td><?= number_format($order['total_amount'], 2) ?></td>
                    <td><?= date('H:i', strtotime($order['created_at'])) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary"
                                onclick="editOrder(<?= $order['id'] ?>)">
                            ✏️ Edit
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

        <div class="card-grid">
            
           <!-- Cash Request Card -->
            <div class="card-1" onclick="selectCashRequest(this)">
                <div class="card-content">
                    <div class="cash-icon">💰</div>
                    <h5 class="cash-title">Quick Cash Request</h5>
                    <p class="cash-subtitle">Get cash to order from any outlet</p>
                </div>
                <div class="card-actions">
                    <div class="mb-2">
                        <label for="cashAmount" class="form-label mb-1"><strong>Amount (E)</strong></label>
                        <input type="number" 
                            class="form-control" 
                            id="cashAmount" 
                            placeholder="0.00" 
                            min="0" 
                            max="70.00" 
                            step="0.01" 
                            onclick="event.stopPropagation();">
                    </div>
                    <button type="button" 
                            class="btn btn-success btn-sm w-100" 
                            onclick="event.stopPropagation(); addCashRequestFromCard()">
                             Add Cash Request
                    </button>
                    <p class="cash-subtitle">Enter amount (up to E70.00)</p>
                </div>
            </div>

           <!-- Outlet Cards -->
<?php if (!empty($outlets)): ?>
    <?php foreach($outlets as $outlet):
        $logoFile = $outlet['logo'];
        $logoPath = ($logoFile && file_exists(__DIR__ . '/../uploads/' . $logoFile))
            ? $logoBasePath . htmlspecialchars($logoFile)
            : $defaultLogo;
    ?>
        <div class="card-1" 
             onclick="selectOutlet(
                 <?= (int)$outlet['id'] ?>, 
                 '<?= htmlspecialchars($outlet['name'], ENT_QUOTES, 'UTF-8') ?>', 
                 this
             )">
            <div class="card-content">
                <img 
                    src="<?= $logoPath ?>"
                    alt="<?= htmlspecialchars($outlet['name']) ?> Logo"
                    class="outlet-logo"
                    loading="lazy"
                >
                <h5 class="card-title"><?= $outlet['name'] ?></h5>
                <p class="card-description"><?= $outlet['description'] ?></p>
            </div>
            
            <!-- Buttons side by side -->
            <div class="card-actions row g-1">
                <?php if(!empty($outlet['url'])): ?>
                    <div class="col-6">
                        <a href="<?= $outlet['url'] ?>" 
                           target="_blank" 
                           rel="noopener noreferrer"
                           onclick="event.stopPropagation();" 
                           class="btn btn-sm w-100 btn-outline-primary">
                           View Menu
                        </a>
                    </div>
                <?php else: ?>
                    <div class="col-6">
                        <button class="btn btn-sm w-100 btn-outline-secondary" disabled>No Menu</button>
                    </div>
                <?php endif; ?>
                
                <div class="col-6">
                    <button
                        type="button"
                        class="btn btn-sm w-100 btn-outline-secondary"
                        onclick="event.stopPropagation(); selectOutlet(
                            <?= (int)$outlet['id'] ?>,
                            '<?= htmlspecialchars($outlet['name'], ENT_QUOTES, 'UTF-8') ?>',
                            this
                        )"
                    >
                        Add Order
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="no-outlets">
        <h5>No Food Outlets Available</h5>
        <p>There are currently no active food outlets. Please check back later or contact support.</p>
        <p><small>You can still use the cash request option above.</small></p>
    </div>
<?php endif; ?>
        </div>
    
<div class="container mt-5">
    <div id="activeOrdersContainer" class="mt-4"></div>

    <div class="card hidden mt-4" id="orderFormCard">
        <div class="card-header card-color text-white"><h5>Add Items - <span id="selectedOutlet"></span></h5></div>
        <div class="card-body">
            <form id="foodOrderForm">
                <input type="hidden" id="csrfToken" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" id="outletId" name="outlet_id">
                <div class="row">
                    <div class="col-md-6">
                        <div class="calculator-container">
                            <h6>Add Items</h6>
                            <div class="mb-3">
                                <label class="form-label">Item Description *</label>
                                <input type="text" class="form-control" id="itemDescription" 
                                       placeholder="e.g., Chicken Burger" maxlength="255" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Item Price (E)</label>
                                <input type="number" class="form-control" id="itemPrice" 
                                       step="0.01" min="0" max="1000" oninput="validateAmount(this)">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Delivery Fee (E)</label>
                                <input type="number" class="form-control" id="deliveryFee" 
                                       step="0.01" min="0" max="100" oninput="validateAmount(this)">
                            </div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-success" onclick="addToCurrentOutlet()">Add to Order</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="cancelCurrentOutlet()">Cancel</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="order-summary">
                            <h6>Current Outlet Summary</h6>
                            <p><strong>Outlet:</strong> <span id="currentOutletName">-</span></p>
                            <p><strong>Items:</strong> <span id="currentItemCount">0</span></p>
                            <p><strong>Subtotal:</strong> E <span id="currentSubtotal">0.00</span></p>
                            <p class="fee-warning hidden" id="currentExtraFeeWarning">Extra Fee: E <span id="currentExtraFeeAmount">0.00</span></p>
                            <p><strong>Total:</strong> E <span id="currentTotalAmount">0.00</span></p>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4 hidden" id="completeOrderCard">
        <div class="card-header"><h5>Complete Your Order</h5></div>
        <div class="card-body">
            <form id="completeOrderForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="orderDateTime" class="form-label">Order Time</label>
                            <div class="input-group">
                                <input type="datetime-local" 
                                    class="form-control" 
                                    id="orderDateTime" 
                                    name="order_date"
                                    readonly  
                                    style="background-color: #f8f9fa; cursor: not-allowed;">
                                <button type="button" class="btn btn-outline-secondary" onclick="updateToCurrentTime()">
                                    <i class="bi bi-clock"></i> Now
                                </button>
                            </div>
                            <small class="form-text text-muted">Orders are submitted with current time automatically</small>
                        </div>
                    
                    <div class="col-md-6">
                        <label for="orderNotes" class="form-label">Additional Notes (Optional)</label>
                        <textarea class="form-control" id="orderNotes" name="order_notes" rows="3" 
                                maxlength="1000" placeholder="Any special instructions..."></textarea>
                    </div>
                </div>
                
                <div class="order-summary">
                    <h6>Final Order Summary</h6>
                    <div id="finalOrderSummary"></div>
                    <p class="mt-3"><strong>Grand Total:</strong> E <span id="grandTotal">0.00</span></p>
                    <p class="fee-warning hidden" id="finalExtraFeeWarning">Total Extra Fee: E <span id="finalExtraFeeAmount">0.00</span></p>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-success">Submit All Orders</button>
                    <button type="button" class="btn btn-secondary" onclick="resetAllOrders()">Cancel All Orders</button>
                </div>
            </form>
        </div>
    </div>
            </div>
</main>

<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="./food_order.js"></script>
<script>
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
            window.location.href = '../user_dashboard.php';
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
// Client-side validation functions
function validateAmount(input) {
    const value = parseFloat(input.value);
    const max = parseFloat(input.max) || 1000;
    
    if (isNaN(value) || value < 0) {
        input.value = '0.00';
    } else if (value > max) {
        input.value = max.toFixed(2);
    }
}

// Function to escape HTML for safe JavaScript usage
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// CSRF token for AJAX requests
const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>';

// Auto-dismiss alerts after 5 seconds
setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
/* -------------------------
   REAL-TIME FUNCTIONS
-------------------------- */
function updateToCurrentTime() {
    const now = new Date();
    const localDateTime = new Date(now.getTime() - (now.getTimezoneOffset() * 60000))
        .toISOString()
        .slice(0, 16);
    
    document.getElementById('orderDateTime').value = localDateTime;
    showAlert('Updated to current time', 'info');
}

// Update time display every minute
function updateTimeDisplay() {
    const datetimeInput = document.getElementById('orderDateTime');
    if (!datetimeInput) return;
    
    // Update every minute to keep it current
    setInterval(() => {
        if (!datetimeInput.readOnly) {
            updateToCurrentTime();
        }
    }, 60000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    updateToCurrentTime(); // Set to current time on load
    updateTimeDisplay(); // Keep it updated
    
    // Also update the current time display
    setInterval(updateCurrentTime, 60000);
    updateCurrentTime();
});
</script>
</body>
</html>