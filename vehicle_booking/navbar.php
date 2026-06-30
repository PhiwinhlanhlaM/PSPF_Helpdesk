<?php
if (!isset($_SESSION)) { session_start(); }
$role = $_SESSION['role'] ?? '';
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style5.css">
<nav class="navbar navbar-expand-lg navbar-dark main-navbar">
    <div class="container-fluid">

       <a class="navbar-brand d-flex align-items-center" href="" style="padding:0;">
    <img src="pspf.png" alt="Logo"
         style="height:28px; width:auto; margin-right:8px;">
    <span style="font-size:15px; line-height:1;">TRANSPORT BOOKING</span>
</a>


        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse justify-content-end" id="mainNavbar">
            <ul class="navbar-nav mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link" href="user_dashboard.php"><i class="fa fa-tachometer-alt nav-icon"></i>Dashboard</a>
                </li>

                <?php if ($role === 'user'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="request_form.php"><i class="fa fa-plus-circle nav-icon"></i>New Request</a>
                    </li>
                <?php endif; ?>

                <?php if ($role === 'driver' || $role === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="report_page.php"><i class="fa fa-chart-line nav-icon"></i>Report</a>
                    </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a class="nav-link" href="logout.php"><i class="fa fa-sign-out-alt nav-icon"></i>Logout</a>
                </li>

            </ul>
        </div>
    </div>
</nav>


