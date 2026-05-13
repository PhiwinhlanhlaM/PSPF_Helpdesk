<?php
// all_orders.php
// Production-ready, secure, optimized list & print view for food orders

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../includes/role_switcher.php';
require_once __DIR__ . '/../../vendor/autoload.php';


// Include TCPDF library
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);


// Ensure user is logged in
if (empty($_SESSION['user_id']) && empty($_SESSION['user']['id'])) {
    header('Location: ../signin/index.php');
    exit;
}

// Normalize user info
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

// Permission check
requireAnyRole(['user', 'agent', 'admin', 'superadmin']);



// Generate CSRF token for inline links/forms if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Input filters (sanitize)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_outlet = trim((string)($_GET['outlet'] ?? ''));
$selected_type = trim((string)($_GET['type'] ?? ''));

// Pagination
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

try {
    // Fetch outlets for filter dropdown (simple query)
    $outletsStmt = $conn->prepare("SELECT id, name FROM outlets WHERE is_active = 1 ORDER BY name");
    $outletsStmt->execute();
    $outletsRes = $outletsStmt->get_result();
    $outlets = $outletsRes ? $outletsRes->fetch_all(MYSQLI_ASSOC) : [];
    $outletsStmt->close();

    // Build base query (select only needed columns)
    $baseSql = "FROM orders o
                JOIN users u ON o.user_id = u.id
                LEFT JOIN outlets ou ON o.outlet_id = ou.id
                WHERE DATE(o.order_date) = ?";

    $params = [$selected_date];
    $types = "s";

    // Outlet filter
    if ($selected_outlet !== '') {
        if ($selected_outlet === 'cash_request') {
            $baseSql .= " AND o.order_type = 'cash_request'";
        } else {
            // match by outlet name (case-insensitive)
            $baseSql .= " AND ou.name LIKE ?";
            $params[] = '%' . $selected_outlet . '%';
            $types .= "s";
        }
    }

    // Type filter
    if ($selected_type !== '') {
        $baseSql .= " AND o.order_type = ?";
        $params[] = $selected_type;
        $types .= "s";
    }

    // Count total matching rows for pagination and summary
       $countSql = "SELECT COUNT(*) as cnt, 
                        COALESCE(SUM(o.total_amount),0) AS total_amount_sum, 
                        COALESCE(SUM(o.overfee_amount),0) AS total_overfees,
                        COALESCE(SUM(o.total_amount + o.overfee_amount),0) AS grand_total " . $baseSql;
    $countStmt = $conn->prepare($countSql);
    if ($types !== '') {
        // bind dynamically
        $bind_names = [];
        $bind_names[] = $types;
        foreach ($params as $i => $param) {
            $bind_names[] = &$params[$i];
        }
        call_user_func_array([$countStmt, 'bind_param'], $bind_names);
    }
    $countStmt->execute();
    $countRes = $countStmt->get_result()->fetch_assoc();
    $totalRows = (int)$countRes['cnt'];
    $totalAmountSum = (float)$countRes['total_amount_sum'];
    $totalOverfees = (float)$countRes['total_overfees'];
    $grandTotal = (float)$countRes['grand_total']; // Add this
    $countStmt->close();

    // Fetch paginated rows
    $query = "SELECT o.id, o.order_date, o.order_type, o.order_items, o.total_amount, o.overfee_amount, o.notes,
                     u.username as user_name, u.email, u.department,
                     ou.name as outlet_name
              " . $baseSql . " 
              ORDER BY o.created_at DESC
              LIMIT ? OFFSET ?";

    // Add pagination params
    $paramsWithPage = $params;
    $typesWithPage = $types . "ii";
    $paramsWithPage[] = $perPage;
    $paramsWithPage[] = $offset;

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare orders query: ' . $conn->error);
    }

    // bind params dynamically (mysqli requires references)
    $bindParams = [];
    $bindParams[] = $typesWithPage;
    foreach ($paramsWithPage as $i => $p) {
        $bindParams[] = &$paramsWithPage[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    $stmt->execute();
    $ordersResult = $stmt->get_result();
    $stmt->close();

} catch (Throwable $e) {
    echo "<pre>Debug Error: " . $e->getMessage() . "</pre>";
    error_log('all_orders.php error: ' . $e->getMessage());
    exit;
}


// Helper: escape
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Build URL for pagination while preserving filters
function build_url(array $overrides = []): string {
    $qs = array_merge($_GET, $overrides);
    return strtok($_SERVER['PHP_SELF'], '?') . '?' . http_build_query($qs);
}

// Calculate page count
$totalPages = (int)ceil($totalRows / $perPage);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Food Orders - Delivery Receipt</title>
<link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style5.css">
    <linik rel="stylesheet" href="../agent/agent_style.css">
    <link rel="stylesheet" href="./foodstyle.css">
    <link rel="stylesheet" href="../style4.css">
    <linik rel="stylesheet" href="../agent/agent_style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

<style>
    /* General table */
.print-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12pt;
}

.print-table th, .print-table td {
  border: 1px solid #444;
  padding: 6px 8px;
  text-align: left;
  vertical-align: top;
  word-wrap: break-word;
}

/* Header style */
.print-table thead th {
  background-color: #f2f2f2;
  font-weight: bold;
  text-align: center;
}

/* Alternate row colors */
.print-table tbody tr:nth-child(even) {
  background-color: #fafafa;
}

/* Cash request highlight */
.cash-request {
  background-color: #d4edda !important;
}

/* Order type badge */
.order-type-badge {
  font-size: 0.75em;
  padding: 2px 5px;
  border-radius: 4px;
  display: inline-block;
  margin-top: 2px;
}

.badge-food { background: #007bff; color: #fff; }
.badge-cash { background: #28a745; color: #fff; }

/* Signature space */
.signature-space {
  min-height: 40px;
  border-bottom: 1px dashed #000;
  margin-top: 4px;
}

/* Print-specific */
@media print {
  body {
    margin: 0.5in;
    font-size: 11pt;
  }

  .no-print {
    display: none !important;
  }

  .print-table {
    page-break-inside: auto;
  }

  .print-table tr {
    page-break-inside: avoid;
    page-break-after: auto;
  }
}
/* Scoped styles (kept compact) */
.my-table { width:100%; table-layout:fixed; border-collapse:collapse; }
.my-table th,.my-table td { padding:10px; border:1px solid #ddd; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cash-request { background-color:#e8f5e8; }
.signature-space { height:40px; border-bottom:1px dashed #000; margin-top:6px; }
.filter-section { background:#f8f9fa; padding:16px; border-radius:8px; margin-bottom:20px; }
.order-type-badge{font-size:.75em;padding:2px 6px;border-radius:4px;} .badge-food{background:#007bff;color:#fff}.badge-cash{background:#28a745;color:#fff}
@media print { .no-print{display:none!important;} .print-only{display:block!important;} /* rest of print CSS can be kept as earlier */ }
</style>
</head>
<body>
  <?php include './topnav.php'; ?>
 

<main class="container my-4">
  <div class="settings-header">   
                <h1 class="settings-title"> Food order List</h1>
                <div class="settings-actions">
                <!-- Back Button -->
                    <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                        <i class="bi bi-arrow-left"></i> Back
                    </button>
                </div>
            </div>
      <a href="export_orders_pdf.php?date=<?= urlencode($selected_date) ?>&outlet=<?= urlencode($selected_outlet) ?>&type=<?= urlencode($selected_type) ?>"
         class="btn btn-primary">
          <i class="bi bi-file-earmark-pdf"></i> Generate PDF
      </a>
    </div>
  </div>


  <section class="filter-section no-print">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label"><strong>Select Date</strong></label>
        <input type="date" name="date" id="date" class="form-control" value="<?= e($selected_date) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label"><strong>Outlet</strong></label>
        <select name="outlet" id="outlet" class="form-select">
          <option value="">All Outlets</option>
          <option value="cash_request" <?= $selected_outlet === 'cash_request' ? 'selected' : '' ?>>Cash Requests</option>
          <?php foreach ($outlets as $o): ?>
            <option value="<?= e($o['name']) ?>" <?= $selected_outlet === $o['name'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label"><strong>Order Type</strong></label>
        <select name="type" id="type" class="form-select">
          <option value="">All Types</option>
          <option value="food_order" <?= $selected_type === 'food_order' ? 'selected' : '' ?>>Food Orders</option>
          <option value="cash_request" <?= $selected_type === 'cash_request' ? 'selected' : '' ?>>Cash Requests</option>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary w-100" type="submit">Apply Filters</button>
        <a href="<?= e(strtok($_SERVER['PHP_SELF'], '?')) ?>" class="btn btn-outline-secondary w-100 mt-2">Clear Filters</a>
      </div>
    </form>
  </section>

  <section class="print-section">
    <?php if ($ordersResult && $ordersResult->num_rows > 0): ?>
    <div class="table-responsive">
      <table class="print-table">
  <thead>
    <tr>
      <th style="width:7%">Order ID</th>
      <th style="width:18%">User</th>
      <th style="width:12%">Outlet</th>
      <th style="width:35%">Order Details</th>
      <th style="width:7%">Total Amount</th>
      <th style="width:7%">Extra Fee</th>
      <th style="width:14%">Signature</th>
    </tr>
  </thead>
  <tbody>
    <?php while($row = $ordersResult->fetch_assoc()):
      $isCash = ($row['order_type'] === 'cash_request');
      $rowClass = $isCash ? 'cash-request' : '';
    ?>
    <tr class="<?= e($rowClass) ?>">
      <td>
        <?= e($row['id']) ?>
        <br>
        <?php if ($isCash): ?>
          <span class="order-type-badge badge-cash">CASH</span>
        <?php else: ?>
          <span class="order-type-badge badge-food">FOOD</span>
        <?php endif; ?>
      </td>
      <td>
        <strong><?= e($row['user_name'] ?? '') ?></strong><br>
        <small><?= e($row['email']) ?></small><br>
        <small><?= e($row['department']) ?></small>
      </td>
      <td><?= $isCash ? '<strong>Cash Request</strong>' : e($row['outlet_name'] ?? 'Unknown') ?></td>
     <td style="white-space:pre-wrap;">
      <?php
          $items = nl2br(e($row['order_items']));
          $notes = trim($row['notes'] ?? '');

          echo $items;

          if ($notes !== '') {
              echo ' <span class="text-muted">(' . nl2br(e($notes)) . ')</span>';
          }
      ?>
      </td>

      <td style="text-align:right">E <?= number_format((float)$row['total_amount'],2) ?></td>
      <td style="text-align:right"><?= (float)$row['overfee_amount']>0 ? '<span class="text-danger">E '.number_format((float)$row['overfee_amount'],2).'</span>' : 'E 0.00' ?></td>
      <td><div class="signature-space"></div></td>
    </tr>
    <?php endwhile; ?>
  </tbody>
</table>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-between align-items-center mt-3 no-print">
      <div>
        Showing <?= min($perPage, $totalRows - $offset) ?> of <?= $totalRows ?> orders
      </div>
      <nav aria-label="Orders pagination">
        <ul class="pagination mb-0">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e(build_url(['page' => $page - 1])) ?>">Previous</a>
          </li>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(build_url(['page' => $p])) ?>"><?= $p ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e(build_url(['page' => $page + 1])) ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>

    <!-- Summary -->
    <div class="card mt-4">
      <div class="card-body">
        <div class="row">
          <div class="col-md-3"><strong>Total Orders:</strong> <?= $totalRows ?></div>
          <div class="col-md-3"><strong>Order Total:</strong> E <?= number_format($totalAmountSum, 2) ?></div>
          <div class="col-md-3"><strong>Extra Fees:</strong> E <?= number_format($totalOverfees, 2) ?></div>
          <div class="col-md-3"><strong>Grand Total:</strong> <span class="text-success">E <?= number_format($grandTotal, 2) ?></span></div>
        </div>
      </div>
    </div>

    <?php else: ?>
      <div class="alert alert-info">No orders found for <?= e(date('F j, Y', strtotime($selected_date))) ?> with the selected filters.</div>
    <?php endif; ?>
  </section>

  <div class="alert alert-info mt-3 no-print">
    <strong>Instructions:</strong>
    <ul class="mb-0">
      <li>Click "Print Orders" for standard printing or "Large Print" for enlarged text</li>
      <li>Recipients should sign in the "Received Signature" column upon delivery</li>
      <li>Cash requests are highlighted in green</li>
      <li>Use filters to view specific outlet orders or cash requests only</li>
    </ul>
  </div>
</main>

<?php include '../footer.php'; ?>

<script src="../fonts/titilliumweb-normal.js"></script>
<script src="../fonts/titilliumweb-bold.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Inline JS for small UX niceties
document.addEventListener('click', function(e) {
  const sig = e.target.closest('.signature-space');
  if (!sig) return;
  const name = prompt('Enter recipient name for signature:');
  if (name) {
    sig.innerHTML = '<strong>' + name.replace(/</g,'&lt;') + '</strong><br><small>' + new Date().toLocaleDateString() + '</small>';
    sig.style.borderBottom = 'none';
  }
});
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

// Auto-submit date change
document.getElementById('date')?.addEventListener('change', function(){ this.form.submit(); });

// Keep filters consistent (UX helper)
const outletSelect = document.getElementById('outlet');
const typeSelect = document.getElementById('type');
if (outletSelect && typeSelect) {
  outletSelect.addEventListener('change', function(){
    if (this.value === 'cash_request') typeSelect.value = 'cash_request';
    else if (typeSelect.value === 'cash_request') typeSelect.value = '';
  });
  typeSelect.addEventListener('change', function(){
    if (this.value === 'cash_request') outletSelect.value = 'cash_request';
    else if (outletSelect.value === 'cash_request') outletSelect.value = '';
  });
}

function generatePDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'pt', 'a4');

    // Prepare table data
    const headers = [["Order ID", "User", "Type / Outlet", "Order Details", "Total Amount", "Extra Fee", "Signature"]];
    const body = [];

    // Variables to calculate totals
    let orderTotal = 0;
    let extraFeeTotal = 0;
    let grandTotal = 0;
    let orderCount = 0;

    const rows = document.querySelectorAll('.print-table tbody tr');
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        const rowData = [];
        
        cols.forEach((col, index) => {
            let text = col.innerText.trim();
            rowData.push(text);
            
            // Calculate totals from the table
            if (index === 4) { // Total Amount column (0-based index)
                const amount = parseFloat(text.replace('E ', '').replace(/,/g, '')) || 0;
                orderTotal += amount;
            }
            if (index === 5) { // Extra Fee column
                const fee = parseFloat(text.replace('E ', '').replace(/,/g, '')) || 0;
                extraFeeTotal += fee;
            }
        });
        
        body.push(rowData);
        orderCount++;
    });

    // Calculate grand total
    grandTotal = orderTotal + extraFeeTotal;

    // Company logo
    const logoImg = new Image();
    logoImg.src = '../uploads/pspflogo1.png';
    
    logoImg.onload = function() {
        // Add logo
        doc.addImage(logoImg, 'PNG', 40, 20, 60, 60);

        // Header
        doc.setFont('TitilliumWeb', 'bold');
        doc.setFontSize(16);
        doc.text('PSPF Food Orders - Delivery Receipt', 120, 40);

        // Date and filter info
        doc.setFont('TitilliumWeb', 'normal');
        doc.setFontSize(10);
        doc.text('Generated on: ' + new Date().toLocaleString(), 120, 55);
        
        // Show filters if applicable
        const dateFilter = document.getElementById('date')?.value || '';
        const outletFilter = document.getElementById('outlet')?.value || 'All';
        const typeFilter = document.getElementById('type')?.value || 'All';
        
        if (dateFilter) {
            doc.text('Date: ' + dateFilter, 120, 65);
        }
        if (outletFilter !== 'All') {
            doc.text('Outlet: ' + outletFilter, 120, 75);
        }
        if (typeFilter !== 'All') {
            doc.text('Type: ' + typeFilter, 120, 85);
        }

        // Add table
        doc.autoTable({
            head: headers,
            body: body,
            startY: 110,
            theme: 'grid',
            styles: { 
                font: 'TitilliumWeb', 
                fontStyle: 'normal', 
                fontSize: 8,
                cellPadding: 4
            },
            headStyles: { 
                fillColor: [41, 128, 185],
                textColor: 255,
                fontStyle: 'bold'
            },
            alternateRowStyles: {
                fillColor: [245, 245, 245]
            },
            margin: { left: 40, right: 40 },
            didDrawPage: function(data) {
                // This callback runs after each page is drawn
            }
        });

        // Get the final Y position after the table
        const finalY = doc.lastAutoTable.finalY || 400;
        
        // Add totals section at the bottom
        doc.setFont('TitilliumWeb', 'bold');
        doc.setFontSize(12);
        
        // Draw a line above totals
        doc.setDrawColor(100, 100, 100);
        doc.setLineWidth(0.5);
        doc.line(40, finalY + 20, 550, finalY + 20);
        
        // Add totals text
        doc.text('SUMMARY OF ORDERS', 40, finalY + 40);
        
        doc.setFont('TitilliumWeb', 'normal');
        doc.setFontSize(10);
        
        // Add totals in a table-like format
        const totalsData = [
            ['Total Orders:', orderCount.toString()],
            ['Order Total:', 'E ' + orderTotal.toFixed(2)],
            ['Extra Fees:', 'E ' + extraFeeTotal.toFixed(2)],
            ['GRAND TOTAL:', 'E ' + grandTotal.toFixed(2)]
        ];
        
        let yPos = finalY + 60;
        totalsData.forEach(([label, value], index) => {
            doc.setFont('TitilliumWeb', 'bold');
            doc.text(label, 40, yPos);
            
            doc.setFont('TitilliumWeb', 'normal');
            const textWidth = doc.getTextWidth(value);
            doc.text(value, 550 - textWidth, yPos);
            
            // Highlight the grand total
            if (index === 3) {
                doc.setFont('TitilliumWeb', 'bold');
                doc.setTextColor(39, 174, 96); // Green color
                doc.text(value, 550 - textWidth, yPos);
                doc.setTextColor(0, 0, 0); // Reset to black
            }
            
            yPos += 15;
        });

        // Add signature section
        yPos += 20;
        doc.setFont('TitilliumWeb', 'bold');
        doc.text('Acknowledgement:', 40, yPos);
        
        yPos += 30;
        doc.setFont('TitilliumWeb', 'normal');
        doc.text('Received by: _________________________', 40, yPos);
        doc.text('Signature: __________________________', 300, yPos);
        
        yPos += 30;
        doc.text('Date: _______________________________', 40, yPos);

        // Add page number if needed
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(8);
            doc.text(`Page ${i} of ${pageCount}`, 550 - doc.getTextWidth(`Page ${i} of ${pageCount}`), 800);
        }

        // Save PDF
        doc.save('PSPF_Food_Orders_' + new Date().toISOString().slice(0,10) + '.pdf');
    };

    logoImg.onerror = function() {
        alert("Logo not found, PDF generation may be incomplete.");
    };
}


</script>
</body>
</html>
