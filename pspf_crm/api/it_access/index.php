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

// The IT Access module is open to every signed-in user: anyone may request
// access for themselves or their team. The approval chain (supervisor -> ICT
// -> director) is what gates a request, not who is allowed to open the form.
// enforceActiveUser() above already blocks disabled accounts.

$activeRole     = getActiveRole();
$UserId         = (int)$_SESSION['user']['id'];
$UserUsername   = $_SESSION['user']['username'];
$UserEmail      = $_SESSION['user']['email'];
$UserDept       = $_SESSION['user']['department'] ?? '';
$UserDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);

// Prefer the user's saved full name. If none is stored yet, fall back to the
// email local-part and flag the React app to prompt for it once.
$UserFullName = null;
$fnStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
if ($fnStmt) {
    $fnStmt->bind_param("i", $UserId);
    $fnStmt->execute();
    $fnRow = $fnStmt->get_result()->fetch_assoc();
    $fnStmt->close();
    $UserFullName = $fnRow['full_name'] ?? null;
}
$UserNeedsName   = (trim((string)$UserFullName) === '');
$UserDisplayName = $UserNeedsName
    ? ((strpos($UserEmail, '@') !== false) ? substr($UserEmail, 0, strpos($UserEmail, '@')) : $UserUsername)
    : $UserFullName;

$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');

$role = $_SESSION['active_role'] ?? 'user';

$roleIcons = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill',
    'it_officer' => 'bi-person-badge',
    'it_director'=> 'bi-person-check',
];
$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// Map CRM role → React initial role
// Land the user in the area they are most likely to be here for. Ordered by
// how far along the chain the role sits, so someone who is both a supervisor
// and an officer opens on the ICT queue rather than their approvals.
$reactRole = 'manager';
if (hasRole('it_director'))     $reactRole = 'director';
elseif (hasRole('it_officer'))  $reactRole = 'officer';
elseif (hasRole('supervisor'))  $reactRole = 'supervisor';

// All CRM roles this user holds — passed to React so the Acting As panel is accurate
$allCrmRoles = getUserRoles(); // returns array like ['user','it_officer']
$crmRolesJson = json_encode($allCrmRoles);

$csrfToken = $_SESSION['csrf_token'];

// Initials: first char + last char of the display name
$initials = strtoupper(
    substr($UserDisplayName, 0, 1) . substr($UserDisplayName, -1)
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>IT Access Request - PSPF CRM</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- CRM shared styles -->
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">

    <!-- IT Access Form design system -->
    <link rel="stylesheet" href="/IT%20Access%20Form/styles/tokens.css?v=21">
    <link rel="stylesheet" href="/IT%20Access%20Form/styles/shell.css?v=21">
    <link rel="stylesheet" href="/IT%20Access%20Form/styles/form.css?v=21">
    <link rel="stylesheet" href="/IT%20Access%20Form/styles/dashboard.css?v=21">
    <link rel="stylesheet" href="/IT%20Access%20Form/styles/sigpad.css?v=21">
    <link rel="stylesheet" href="/IT%20Access%20Form/styles/screens.css?v=21">

    <style>
        /* Push React app content below the CRM topnav */
        #root {
            min-height: calc(100vh - 60px);
        }
        /* When embedded in CRM, hide the brandmark — CRM topnav already has it */
        body.crm-embedded .topbar .brandmark {
            display: none !important;
        }
    </style>
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<!-- Inject CRM user identity for React to consume -->
<script>
window.__CRM_EMBEDDED__ = true;
document.body.classList.add('crm-embedded');
window.__CRM_USER__ = {
    id:         <?= (int)$UserId ?>,
    username:   "<?= htmlspecialchars($UserUsername, ENT_QUOTES) ?>",
    name:       "<?= htmlspecialchars($UserDisplayName, ENT_QUOTES) ?>",
    email:      "<?= htmlspecialchars($UserEmail, ENT_QUOTES) ?>",
    department: "<?= htmlspecialchars($UserDept, ENT_QUOTES) ?>",
    initials:   "<?= htmlspecialchars($initials, ENT_QUOTES) ?>",
    title:      "",
    role:       "<?= htmlspecialchars($reactRole, ENT_QUOTES) ?>",
    crmRoles:   <?= $crmRolesJson ?>,
    needsName:  <?= $UserNeedsName ? 'true' : 'false' ?>,
};
window.__REACT_INITIAL_ROLE__ = "<?= htmlspecialchars($reactRole, ENT_QUOTES) ?>";
</script>

<div id="root"></div>

<!-- React 18 + Babel (same versions as standalone IT Access Form.html) -->
<script src="https://unpkg.com/react@18.3.1/umd/react.development.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" crossorigin="anonymous"></script>

<!-- IT Access Form JSX files (load order matches standalone HTML) -->
<script type="text/babel" src="/IT%20Access%20Form/app/crm-client.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/data.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/Icon.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/SignaturePad.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/AppShell.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/ManagerForm.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/OfficerDashboard.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/OfficerSign.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/SupervisorDashboard.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/Director.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/ManagerHistory.jsx?v=21"></script>
<script type="text/babel" src="/IT%20Access%20Form/app/main.jsx?v=21"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
