<?php
// ---------------------------
// Include dependencies
// ---------------------------
require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
//require_once '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

// ---------------------------
// Ensure user is logged in
// ---------------------------
if (!isset($_SESSION['user']['username'])) {
    header("Location: ../signin/index.php");
    exit;
}

$username = $_SESSION['user']['username'];
$dateNow  = date("Y-m-d H:i:s");

// ---------------------------
// SANITIZE INPUT
// ---------------------------
$title        = trim($_POST['queryTitle'] ?? '');
$memberType   = trim($_POST['queryMembertype'] ?? '');
$region       = trim($_POST['queryRegion'] ?? '');
$source       = trim($_POST['querySource'] ?? '');
$queryType    = trim($_POST['queryType'] ?? '');
$description  = trim($_POST['queryDescription'] ?? '');
$priority     = trim($_POST['queryPriority'] ?? '');
$phoneNumber  = trim($_POST['queryPhonenumber'] ?? '');

// ---------------------------
// SERVER-SIDE VALIDATION
// ---------------------------
$errors = [];

if ($title === '')       $errors[] = 'Title is required.';
if ($memberType === '')  $errors[] = 'Member Type is required.';
if ($region === '')      $errors[] = 'Branch is required.';
if ($source === '')      $errors[] = 'Source is required.';
if ($queryType === '')   $errors[] = 'Department (To) is required.';
if ($priority === '')    $errors[] = 'Priority is required.';
if ($description === '') $errors[] = 'Description is required.';
if ($source === 'Phone' && $phoneNumber === '') $errors[] = 'Phone number is required when source is Phone.';

if (!empty($errors)) {
    $errorParam = urlencode(implode('|', $errors));
    header("Location: query.php?errors=$errorParam");
    exit;
}

// ---------------------------
// SET division_id
// ---------------------------
// queryType contains the division ID from the form select
$divisionId = null;
if (!empty($queryType) && is_numeric($queryType)) {
    $divisionId = (int)$queryType;
}


// ---------------------------
// HANDLE FILE UPLOAD
// ---------------------------
$attachmentPath = null;

if (!empty($_FILES['attachment']['name'])) {
    $uploadDir = "../uploads/tickets/";
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0777, true);
    }

    $fileTmp  = $_FILES['attachment']['tmp_name'];
    $fileName = time() . "_" . basename($_FILES['attachment']['name']);
    $destPath = $uploadDir . $fileName;

    if (move_uploaded_file($fileTmp, $destPath)) {
        $attachmentPath = $destPath;
	 
    }
}

$attachmentPath = trim($attachmentPath, "/uploads");

// ---------------------------
// GET USERS IN SELECTED DIVISION
// ---------------------------
$assignedEmails = [];

if ($divisionId) {
    $userStmt = $conn->prepare("
        SELECT email
        FROM users
        WHERE division_id = ?
    ");
    $userStmt->bind_param("i", $divisionId);
    $userStmt->execute();
    $res = $userStmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $assignedEmails[] = $row['email'];
    }
}

$assignedTo = implode(', ', $assignedEmails);

// ---------------------------
// INSERT TICKET
// ---------------------------
$sql = "
    INSERT INTO tickets
    (
        title, member_type, region, source, query_type,
        description, priority, phone_number, query_date,
        created_by, status, attachment_path, assigned_to, division_id
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?, ?, ?)
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssssssssssssi",
    $title,
    $memberType,
    $region,
    $source,
    $queryType,
    $description,
    $priority,
    $phoneNumber,
    $dateNow,
    $username,
    $attachmentPath,
    $assignedTo,
    $divisionId
);

if (!$stmt->execute()) {
    die("Database error: " . $stmt->error);
}

$ticketId = $stmt->insert_id;

// ---------------------------
// LOG INITIAL STATUS
// ---------------------------
$log = $conn->prepare("
    INSERT INTO ticket_status_logs
    (ticket_id, old_status, new_status, changed_by)
    VALUES (?, NULL, 'Open', ?)
");
$log->bind_param("is", $ticketId, $username);
$log->execute();
$stmt->close();
$log->close();
$conn->close();

header("Location: ticket_success2.php?ticket_id={$ticketId}");
exit;
