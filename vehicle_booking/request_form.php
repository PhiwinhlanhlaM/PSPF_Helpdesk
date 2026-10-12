<?php
session_start();
require_once __DIR__ . '/session_timeout.php';
require '../vehicle_booking/db.php';
require '../vehicle_booking/notification_engine.php';
require_once __DIR__ . '/superuser.php';
require_once __DIR__ . '/trip_helpers.php';

// Any signed-in staff role may raise a vehicle request, as may an IT superuser
// in any view.
if (!isset($_SESSION['user_id']) || (!vbIsSuperuser() && !in_array($_SESSION['role'], ['user', 'driver', 'supervisor', 'hrm', 'admin'], true))) {
    header("Location: ../vehicle_booking/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$date_requested = date('Y-m-d H:i:s');

// Fetch all distinct departments
$departments = $conn->query("SELECT DISTINCT department FROM users ORDER BY department ASC")
                    ->fetchAll(PDO::FETCH_COLUMN);

// Active users for the Driver's Name dropdown, grouped by department.
$driverOptions = [];
foreach ($conn->query("SELECT user_id, name, department FROM users WHERE active = 1 ORDER BY department ASC, name ASC") as $u) {
    $driverOptions[$u['department'] ?: 'Other'][(int)$u['user_id']] = vbName($u['name']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $department        = $_POST['department'];
    $purpose           = $_POST['purpose'];
    $destination       = $_POST['destination'];
    $passengers        = $_POST['passengers'];
    // Driver: an active user from the dropdown, or a typed name for "Other".
    $trip_driver_id    = null;
    $trip_driver_name  = '';
    if (($_POST['trip_driver_id'] ?? '') === 'other') {
        $trip_driver_name = trim($_POST['trip_driver_other'] ?? '');
    } else {
        foreach ($driverOptions as $users) {
            if (isset($users[(int)($_POST['trip_driver_id'] ?? 0)])) {
                $trip_driver_id   = (int)$_POST['trip_driver_id'];
                $trip_driver_name = $users[$trip_driver_id];
            }
        }
    }
    if ($trip_driver_name === '') {
        echo "<script>alert('Please choose the driver, or select Other and type their name.'); history.back();</script>";
        exit();
    }
    $date_required     = $_POST['date_required'];
    $time_required     = $_POST['time_required'];
    $expected_return_date = $_POST['expected_return_date'];

    // Insert request — selected_supervisor left NULL, all supervisors will see it
    $stmt = $conn->prepare("
        INSERT INTO vehicle_requests
        (requester_id, department, purpose, destination, passengers, date_requested,
         date_required, time_required, expected_return_date, selected_supervisor, status,
         trip_driver_id, trip_driver_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'pending_driver', ?, ?)
    ");

    $stmt->execute([
        $user_id, $department, $purpose, $destination, $passengers,
        $date_requested, $date_required, $time_required, $expected_return_date,
        $trip_driver_id, $trip_driver_name
    ]);

    $request_id = $conn->lastInsertId();

    // Log action
    $conn->prepare("
        INSERT INTO request_logs (request_id, action_by, action, created_at)
        VALUES (?, ?, 'Request submitted', NOW())
    ")->execute([$request_id, $user_id]);

    // Send notification to driver, all supervisors, and requester
    sendRequestEmail($conn, $request_id, 'request_submitted');

    $dashboards = [
        'user'       => 'user_dashboard.php',
        'driver'     => 'driver_dashboard.php',
        'supervisor' => 'supervisor_dashboard.php',
        'hrm'        => 'hrm_dashboard.php',
        'admin'      => 'admin_dashboard.php',
        'viewer'     => 'view.php',
    ];
    $redirect = $dashboards[$_SESSION['role']] ?? 'user_dashboard.php';
    echo "<script>alert('Vehicle request submitted successfully!'); window.location='$redirect';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Vehicle Request Form'; require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="bg-light">

<?php include '../vehicle_booking/navbar.php'; ?>

<div class="container mt-5">
    <div class="card shadow p-4 mx-auto" style="max-width: 800px;">

        <h3 class="card-title mb-4">Vehicle Request Form</h3>

        <form method="POST">

            <div class="mb-3">
                <label>Date Requested</label>
                <input type="text" class="form-control" value="<?= $date_requested ?>" readonly>
            </div>

            <div class="mb-3">
                <label>Department</label>
                <select name="department" class="form-select" required>
                    <option value="">-- Select Department --</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="trip_driver_id">Driver's Name</label>
                <select id="trip_driver_id" name="trip_driver_id" class="form-select" required>
                    <option value="">-- Select Driver --</option>
                    <?php foreach ($driverOptions as $dept => $users): ?>
                        <optgroup label="<?= htmlspecialchars($dept) ?>">
                            <?php foreach ($users as $uid => $uname): ?>
                                <option value="<?= $uid ?>"<?= $uid === (int)$user_id ? ' selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                    <option value="other">Other (not on the list)</option>
                </select>
                <input type="text" id="trip_driver_other" name="trip_driver_other" class="form-control mt-2 d-none"
                       maxlength="255" placeholder="Type the driver's full name">
                <div class="form-text">
                    <i class="fa fa-circle-info me-1"></i>This should be the name of the driver: the person who will be driving the vehicle on this trip. If they are not on the list, choose <em>Other</em> and type their name.
                </div>
            </div>

            <div class="mb-3">
                <label>Passengers</label>
                <input type="text" name="passengers" class="form-control" placeholder="Comma-separated names" required>
            </div>

            <div class="mb-3">
                <label>Destination</label>
                <input type="text" name="destination" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Purpose of Trip</label>
                <textarea name="purpose" class="form-control" required></textarea>
            </div>

            <div class="row mb-3">
                <div class="col">
                    <label>Date Required</label>
                    <input type="date" name="date_required" class="form-control" required>
                </div>
                <div class="col">
                    <label>Time Required</label>
                    <input type="time" name="time_required" class="form-control" required>
                </div>
            </div>

            <div class="mb-3">
                <label>Expected Return Date</label>
                <input type="date" name="expected_return_date" class="form-control" required>
            </div>

            <div class="text-center">
                <button class="btn btn-primary px-5">Submit Request</button>
            </div>

        </form>

    </div>
</div>

<script>
// Show the free-text box only when "Other" is chosen as the driver.
(function () {
    const select = document.getElementById('trip_driver_id');
    const other  = document.getElementById('trip_driver_other');
    const sync = () => {
        const isOther = select.value === 'other';
        other.classList.toggle('d-none', !isOther);
        other.required = isOther;
        if (isOther) other.focus();
    };
    select.addEventListener('change', sync);
    sync();
})();
</script>

<?php include '../vehicle_booking/footer.php'; ?>

</body>
</html>
