<?php
// ---------------------------
// Include dependencies
// ---------------------------
require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/ticket_classifier.php';
//require_once '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

// ---------------------------
// Ensure user is logged in
// ---------------------------
if (!isset($_SESSION['user']['username'])) {
    header("Location: ../signin/index.php");
    exit;
}

// ---------------------------
// CSRF + duplicate-submit guard  (must run BEFORE any INSERT)
// ---------------------------
// 1. CSRF: reject forged cross-site POSTs.
$postedCsrf = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
    header("Location: query.php?errors=" . urlencode('Security check failed. Please try submitting again.'));
    exit;
}

// 2. One-time submission token: prevents duplicate tickets from a double-click,
//    back-button re-POST, or refresh-before-redirect. The form embeds a fresh
//    token; the FIRST POST consumes it. A replay of the same token means the
//    ticket was already created — so we DON'T insert again; we silently send the
//    user to the SAME success page as the original submission.
$formToken = $_POST['form_token'] ?? '';

// Already-consumed token? -> duplicate. Redirect to the original success page.
if ($formToken !== '' && isset($_SESSION['ticket_submitted'][$formToken])) {
    $origId = (int)$_SESSION['ticket_submitted'][$formToken];
    header("Location: ticket_success2.php?ticket_id={$origId}");
    exit;
}

// Unknown / missing token? -> not a valid live form submission (stale form,
// replay after session token pruning, or a crafted request). Send back safely.
if ($formToken === '' || !isset($_SESSION['ticket_form_tokens'][$formToken])) {
    header("Location: query.php?errors=" . urlencode('This form has expired or was already submitted. Please try again.'));
    exit;
}

// Valid, first-time token: consume it now so any concurrent/replayed POST that
// gets past the checks above will fail the "unknown token" test.
unset($_SESSION['ticket_form_tokens'][$formToken]);

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
if ($phoneNumber !== '' && (!ctype_digit($phoneNumber) || strlen($phoneNumber) > 20)) {
    $errors[] = 'Phone number must be digits only (max 20).';
}

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
// CLASSIFY TICKET
// ---------------------------
// Tag the ticket with a coarse subject-matter category (Access & Accounts,
// Hardware, Network, ...) from its title/description. Stored on the row so the
// daily department digest and any category reports don't re-classify later.
$category = classifyTicket($title, $description, $memberType . ' ' . $source);

// ---------------------------
// INSERT TICKET
// ---------------------------
$sql = "
    INSERT INTO tickets
    (
        title, member_type, region, source, query_type, category,
        description, priority, phone_number, query_date,
        created_by, status, attachment_path, assigned_to, division_id
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?, ?, ?)
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sssssssssssssi",
    $title,
    $memberType,
    $region,
    $source,
    $queryType,
    $category,
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

// Remember which ticket this one-time token produced, so a duplicate replay of
// the same token lands on the SAME success page instead of creating a new
// ticket. Keep this map small (last 20 submissions this session).
$_SESSION['ticket_submitted'][$formToken] = $ticketId;
if (count($_SESSION['ticket_submitted']) > 20) {
    $_SESSION['ticket_submitted'] =
        array_slice($_SESSION['ticket_submitted'], -20, null, true);
}

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
