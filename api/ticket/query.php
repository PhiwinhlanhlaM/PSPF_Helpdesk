<?php
//query.php
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn()) {
    header('Location: /pspf_crm/api/signin/index.php');
    exit;
}

//require '../session_timeout.php';
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

// ---------------------------------------------------------------------------
// Anti-duplicate + CSRF tokens
// ---------------------------------------------------------------------------
// One-time submission token (synchronizer token): embedded in the form and
// consumed exactly once by the handler. A double-click, back-button re-POST, or
// refresh-before-redirect replays the SAME token, which the handler then
// recognises as already-used and treats as a duplicate (no second ticket).
$formToken = bin2hex(random_bytes(16));
$_SESSION['ticket_form_tokens'][$formToken] = time();
// Bound the store so it can't grow unbounded across a long session: keep the
// 20 most recent tokens (also drop any older than 2 hours).
if (!empty($_SESSION['ticket_form_tokens'])) {
    $cutoff = time() - 7200;
    $_SESSION['ticket_form_tokens'] = array_filter(
        $_SESSION['ticket_form_tokens'],
        static fn($ts) => $ts >= $cutoff
    );
    if (count($_SESSION['ticket_form_tokens']) > 20) {
        $_SESSION['ticket_form_tokens'] =
            array_slice($_SESSION['ticket_form_tokens'], -20, null, true);
    }
}
// CSRF token (reuse the CRM-wide session token created by auth_helpers.php).
$CSRF = $_SESSION['csrf_token'] ?? '';

// Fetch all departments dynamically
$departments = [];
$sql = "SELECT  division_name FROM divisions";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row['division_name'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Helpdesk Query - PSPF CRM</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../style5.css">
  <link rel="stylesheet" href="../style4.css">
  <link rel="stylesheet" href="../agent/agent_style.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="../uploads/pspflogo2.png">
</head>
<body>

<!-- Top Navigation Bar -->
 <?php include '../agent/topnav.php'; ?>

<main id="main-content">
  <div class="container my-5">

    <div class="settings-header">   
          <h1 class="settings-title">Log Your Ticket</h1>
          <div class="settings-actions">
            <!-- Back Button -->
              <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                  <i class="bi bi-arrow-left"></i> Back
              </button>
          </div>
        </div>

    <div class="card shadow p-4">
      <div class="card-header card-color text-center text-white"><h2>Submit Your Query</h2></div>

        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="alert alert-success text-center">Query successfully submitted!</div>
        <?php endif; ?>
        <?php if (!empty($_GET['errors'])): ?>
            <div class="alert alert-danger">
                <strong>Please fix the following:</strong>
                <ul class="mb-0 mt-1">
                    <?php foreach (explode('|', $_GET['errors']) as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <div class="card-body">
        <form action="submit_query2.php" method="POST" enctype="multipart/form-data" id="ticketForm">
            <input type="hidden" name="created_by" value="<?= htmlspecialchars($loggedInUser) ?>">
            <input type="hidden" name="department" value="<?= htmlspecialchars($userDept) ?>">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($formToken) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($CSRF) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Title</label>
                    <input type="text" name="queryTitle" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Member Type</label>
                    <select name="queryMembertype" class="form-select" required>
                        <option selected disabled>Select Member type</option>
                        <option>Active</option>
                        <option>Annuitant</option>
                        <option>Spouse</option>
                        <option>Dependent</option>
			<option>Supplier</option>
			<option>Stakeholder</option>
                        <option>Employee</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Branch</label>
                    <select name="queryRegion" class="form-select" required>
                        <option selected disabled>Select Branch</option>
                        <option>Manzini</option>
                        <option>Nhlangano</option>
                        <option>Siteki</option>
                        <option>Piggs Peak</option>
                        <option>Headquarters</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Source</label>
                    <select name="querySource" id="querySource" class="form-select" required>
                        <option selected disabled>Select Source</option>
                        <option>Phone</option>
                        <option>E-mail</option>
                        <option>Walk-in</option>
                        <option>Social Media</option>
                        <option>PSPF Staff</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">To</label>
                    <select name="queryType" class="form-select" required>
                        <option selected disabled>Select Department</option>
                        <?php 
                        // Fetch departments dynamically
                        $sqlDept = "SELECT id, division_name FROM divisions ORDER BY division_name ASC";
                        $resDept = $conn->query($sqlDept);
                        while ($dept = $resDept->fetch_assoc()): ?>
                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['division_name']) ?></option>
                        <?php endwhile; ?>
                    </select>

                </div>


                <div class="col-md-6">
                    <label class="form-label">Priority</label>
                    <select name="queryPriority" class="form-select" required>
                        <option selected disabled>Select Priority</option>
                        <option>Low</option>
                        <option>Medium</option>
                        <option>High</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number</label>
                    <input type="text"  inputmode="numeric" pattern="[0-9]*" name="queryPhonenumber" id="queryPhonenumber" class="form-control">
                    <div id="phoneErrorMessage" class="text-danger fw-bold mt-2" style="display: none;">
                        ⚠️ Phone number is required when source is Phone.
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Attachment</label>
                    <input type="file" name="attachment" class="form-control">
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="queryDescription" rows="4" class="form-control" required></textarea>
                </div>
            </div>

            <div class="text-end mt-4">
                <button type="reset" class="btn btn-secondary me-2"><i class="bi bi-x-circle"></i> Cancel</button>
                <button type="submit" class="btn card-color"><i class="bi bi-send-fill"></i> Submit</button>
            </div>
        </form>
    </div>
    </div>
</div>
</main>

<?php include '../footer.php'; ?>   

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            window.location.href = 'user_dashboard.php';
        }
    }
}
    document.addEventListener('DOMContentLoaded', function () {
        const querySource = document.getElementById('querySource');
        const phoneNumberField = document.getElementById('queryPhonenumber');
        const phoneErrorMessage = document.getElementById('phoneErrorMessage');
        const queryForm = document.querySelector('form');

        // Clear error when source changes
        querySource.addEventListener('change', function () {
            phoneErrorMessage.style.display = 'none';
        });

        // Clear error when user types in phone field
        phoneNumberField.addEventListener('input', function () {
            phoneErrorMessage.style.display = 'none';
        });

        // Form submission validation + double-submit guard
        if (queryForm) {
            const submitBtn = queryForm.querySelector('button[type="submit"]');
            const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';

            queryForm.addEventListener('submit', function (e) {
                const source = querySource.value;
                const phoneNumber = phoneNumberField.value.trim();

                // Check if Phone is selected but no phone number provided
                if (source === 'Phone' && !phoneNumber) {
                    e.preventDefault();
                    phoneErrorMessage.style.display = 'block';
                    phoneNumberField.focus();
                    return false;
                }
                phoneErrorMessage.style.display = 'none';

                // Double-submit guard: disable the Submit button immediately so a
                // rapid second click can't fire a second POST. (The server-side
                // one-time token is the real guard; this is instant UX feedback.)
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Submitting…';
                    // Safety net: if the browser blocks navigation for any reason,
                    // re-enable after a few seconds so the user isn't stuck.
                    setTimeout(function () {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHtml;
                    }, 8000);
                }
            });
        }
    });
</script>
</body>
</html>
