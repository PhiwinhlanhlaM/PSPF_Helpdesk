<?php
session_start();

// Restrict access to IT department only
if (!isset($_SESSION['department']) || $_SESSION['department'] !== 'IT') {
    header("Location: login.html");
    exit();
}

// DB connection
$conn = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Fetch general ticket stats
$total = $conn->query("SELECT COUNT(*) AS count FROM queries WHERE team = 'IT'")->fetch_assoc()['count'];
$closed = $conn->query("SELECT COUNT(*) AS count FROM queries WHERE team = 'IT' AND status = 'Closed'")->fetch_assoc()['count'];
$open = $conn->query("SELECT COUNT(*) AS count FROM queries WHERE team = 'IT' AND status IN ('Open', 'In Progress')")->fetch_assoc()['count'];

// Tickets closed today
$today = $conn->query("SELECT COUNT(*) AS count FROM query_closures WHERE DATE(closed_at) = CURDATE()")->fetch_assoc()['count'];

// Tickets closed this week
$this_week = $conn->query("SELECT COUNT(*) AS count FROM query_closures WHERE YEARWEEK(closed_at, 1) = YEARWEEK(NOW(), 1)")->fetch_assoc()['count'];

// Tickets closed this month
$this_month = $conn->query("SELECT COUNT(*) AS count FROM query_closures WHERE MONTH(closed_at) = MONTH(CURDATE()) AND YEAR(closed_at) = YEAR(CURDATE())")->fetch_assoc()['count'];

// Average resolution time in hours
$resolution_query = $conn->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, q.query_date, qc.closed_at)) AS avg_hours
    FROM queries q
    JOIN query_closures qc ON q.id = qc.ticket_id
    WHERE q.team = 'IT'
");
$avg_hours = round($resolution_query->fetch_assoc()['avg_hours'], 2);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Helpdesk Statistics - IT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark" style="background-color: #003366;">
    <div class="container-fluid">
        <a class="navbar-brand" href="it_dashboard.php">PSPF</a>
        <span class="navbar-text mx-auto text-white fs-4">IT Statistics Report</span>
        <a class="btn btn-light" href="login.html">Logout</a>
    </div>
</nav>

<div class="container mt-4">
    <h3 class="mb-4">Helpdesk Ticket Statistics Summary</h3>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card text-white bg-primary h-100">
                <div class="card-body">
                    <h5 class="card-title">Total Tickets</h5>
                    <p class="display-6"><?= $total ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-success h-100">
                <div class="card-body">
                    <h5 class="card-title">Closed Tickets</h5>
                    <p class="display-6"><?= $closed ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-warning h-100">
                <div class="card-body">
                    <h5 class="card-title">Open/In Progress</h5>
                    <p class="display-6"><?= $open ?></p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card bg-light h-100">
                <div class="card-body">
                    <h5 class="card-title">Closed Today</h5>
                    <p class="display-6"><?= $today ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light h-100">
                <div class="card-body">
                    <h5 class="card-title">Closed This Week</h5>
                    <p class="display-6"><?= $this_week ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-light h-100">
                <div class="card-body">
                    <h5 class="card-title">Closed This Month</h5>
                    <p class="display-6"><?= $this_month ?></p>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card text-white bg-dark">
                <div class="card-body">
                    <h5 class="card-title">Average Resolution Time</h5>
                    <p class="display-6"><?= $avg_hours ?> Hours</p>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="text-center text-muted py-4 border-top mt-5">
    <p>&copy; <?= date('Y') ?> PSPF Helpdesk. All rights reserved.</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
