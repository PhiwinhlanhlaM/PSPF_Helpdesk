<?php 

session_start();
require_once './db.php';
require_once './includes/auth_helpers.php'; 
require_once './includes/role_switcher.php';  

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Knowledge base - PSPF CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="./style5.css">
    <link rel="stylesheet" href="./style4.css">
    <link rel="stylesheet" href="./agent/agent_style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href='https://fonts.googleapis.com/css?family=Titillium Web' rel='stylesheet'>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="./uploads/pspflogo2.png">
    

</head>
<body>

    <!-- Header -->
<!-- Top Navigation Bar -->
<?php include './agent/topnav.php'; ?>

<!-- Content Section -->
<main class="main-content">
    
    <div class="container mt-5">
        <div class="settings-header">   
            <h1 class="settings-title">Top Tasks</h1>
            <div class="settings-actions">
                <!-- Back Button -->
                <button onclick="goBack()" class="btn btn-outline-secondary back-btn">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
            </div>
        </div>

        <div class="card-grid">
            <div class="card-1">
               <a href="telephone.php"><i class="fas fa-phone fa-2x"></i></a>
                <h4>Telephone</h4>
                <p>Configure and personalize your office landline — from ringtones to feature settings.</p>
            </div>
            <div class="card-1">
               <a href="#"> <i class="fas fa-print fa-2x"></i></a>
                <h4>Printer</h4>
                <p>User manuals and guides for operating your office printers effectively.</p>
            </div>
            <div class="card-1">
                <a href="#">
                <i class="fas fa-book-reader fa-2x"></i>
                </a>
                <h4>Insight Tutorial</h4>
                <p>A step-by-step guide to navigating and using the In-Pension System.</p>
            </div>
            <div class="card-1">
                <a href="https://support.microsoft.com/en-us/office/outlook-training-8a5b816d-9052-4190-a5eb-494512343cca">
                    <i class="fas fa-envelope-open fa-2x"></i>
                </a>
                <h4>Outlook tutorial</h4>
                <p>Training resources for Microsoft Outlook, including email setup and productivity tips.</p>
            </div>

            <div class="card-1">
                <a href="#">
                <i class="fa-brands fa-windows fa-2x"></i>
                </a>
                <h4>Microsoft Office tutorial</h4>
                <p>Learn how to use Microsoft Office applications more efficiently.</p>
            </div>
            <div class="card-1">
                <a href="#">
                <i class="fas fa-lock fa-2x"></i>
                </a>
                <h4>Physical Access control</h4>
                <p>Information and procedures for requesting or managing physical access permissions.</p>
            </div>
            <div class="card-1">
                <a href="#">
                <i class="fa fa-list-alt fa-2x"></i>
                </a>
                <h4>General IT Forms</h4>
                <p>Download and submit IT service request forms for various support needs.</p>
            </div>
            <div class="card-1">
                <a href="#">
                <i class="fas fa-book-open fa-2x"></i>
                </a>
                <h4>Helpdesk tutorial</h4>
                <p>Learn how to use the Helpdesk system to submit, track, and manage your IT tickets.</p>
            </div>
        </div>
    </div>
</main>


<?php include './footer.php'; ?>

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
</script>

</body>
</html>