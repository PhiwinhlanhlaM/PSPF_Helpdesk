<?php
require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn()) {
    header('Location: /pspf_crm/api/signin/index.php');
    exit;
}

$activeRole = getActiveRole();

// Admins/superadmins submit; ICT-dept agents action as officers; it_director gives final sign-off.
if (!in_array($activeRole, ['admin', 'superadmin', 'it_director']) && !isITOfficer()) {
    http_response_code(403);
    echo "<h3>403 – Forbidden</h3><p>Access denied.</p>";
    exit;
}
$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'];
$UserDept     = $_SESSION['user']['department'] ?? '';

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');

$roleIcons = [
    'superadmin'  => 'bi-person-gear',
    'admin'       => 'bi-shield-fill-check',
    'it_officer'  => 'bi-person-badge',
    'it_director' => 'bi-person-check-fill',
];
$iconClass = $roleIcons[$activeRole] ?? 'bi-person-fill';

// Map CRM context → React view role based on the ACTIVE role, not all roles.
// This ensures a user who logged in as admin sees the manager view, not the director view.
$reactRole = 'manager';
if ($activeRole === 'it_director')                                   $reactRole = 'director';
elseif (isITOfficer() && in_array($activeRole, ['agent', 'admin'])) $reactRole = 'officer';

$allCrmRoles  = getUserRoles();
$crmRolesJson = json_encode($allCrmRoles);
$csrfToken    = $_SESSION['csrf_token'];

$initials = strtoupper(substr($UserUsername, 0, 1) . substr($UserUsername, -1));

// Deep-link: ?request=REQ-2026-0001 navigates straight to that request on load.
$deepLinkRef  = preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($_GET['request'] ?? '')));
$deepLinkRole = $reactRole; // director lands on director-sign, officer on officer-sign
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>IT Access Request — PSPF CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="/pspf_crm/api/uploads/pspflogo2.png">

    <!-- CRM shared styles -->
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">

    <!-- IT Access Form design system -->
    <link rel="stylesheet" href="/pspf_crm/it_access_form/styles/tokens.css">
    <link rel="stylesheet" href="/pspf_crm/it_access_form/styles/shell.css">
    <link rel="stylesheet" href="/pspf_crm/it_access_form/styles/form.css">
    <link rel="stylesheet" href="/pspf_crm/it_access_form/styles/dashboard.css">
    <link rel="stylesheet" href="/pspf_crm/it_access_form/styles/sigpad.css">
    <link rel="stylesheet" href="/pspf_crm/it_access_form/styles/screens.css">

    <style>
        /* Hide the IT Access Form's own brandmark — CRM topnav already provides it */
        body.crm-embedded .topbar .brandmark { display: none !important; }
    </style>
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<script>
window.__CRM_EMBEDDED__ = true;
document.body.classList.add('crm-embedded');
window.__CRM_USER__ = {
    id:            "<?= htmlspecialchars($UserUsername,  ENT_QUOTES) ?>",
    name:          "<?= htmlspecialchars($UserUsername,  ENT_QUOTES) ?>",
    email:         "<?= htmlspecialchars($UserEmail,     ENT_QUOTES) ?>",
    department:    "<?= htmlspecialchars($UserDept,      ENT_QUOTES) ?>",
    initials:      "<?= htmlspecialchars($initials,      ENT_QUOTES) ?>",
    title:         "",
    role:          "<?= htmlspecialchars($reactRole,     ENT_QUOTES) ?>",
    crmRoles:      <?= $crmRolesJson ?>,
    crmActiveRole: "<?= htmlspecialchars($activeRole,    ENT_QUOTES) ?>",
};
window.__REACT_INITIAL_ROLE__ = "<?= htmlspecialchars($reactRole, ENT_QUOTES) ?>";
<?php if ($deepLinkRef): ?>
window.__DEEP_LINK__ = { role: "<?= htmlspecialchars($deepLinkRole, ENT_QUOTES) ?>", refNumber: "<?= htmlspecialchars($deepLinkRef, ENT_QUOTES) ?>" };
<?php endif; ?>
</script>

<div id="root"></div>

<script src="https://unpkg.com/react@18.3.1/umd/react.development.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" crossorigin="anonymous"></script>

<script type="text/babel" src="/pspf_crm/it_access_form/app/crm-client.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/data.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/Icon.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/SignaturePad.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/AppShell.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/ManagerForm.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/OfficerDashboard.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/OfficerSign.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/Director.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/ManagerHistory.jsx?v=14"></script>
<script type="text/babel" src="/pspf_crm/it_access_form/app/main.jsx?v=14"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
