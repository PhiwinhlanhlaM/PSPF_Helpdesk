<?php
/**
 * IT Access - Form Management. Superadmin only.
 *
 * One home for everything added to or removed from the IT Access request form:
 *   - Systems      (the system catalog: systems, roles, per-system sub-options)
 *   - Custom fields (request-level custom form fields)
 *
 * Both editors keep their own full UI. They are embedded here in isolated
 * iframes (?embed=1) so their scripts and element IDs never collide, and each
 * page still works standalone. This page renders only the tab shell + topnav.
 */

session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn() || !hasRole('superadmin')) {
    http_response_code(403);
    echo "<h3>403 - Forbidden</h3><p>Form Management is restricted to superadministrators.</p>";
    exit;
}

$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'];
$UserDept     = $_SESSION['user']['department'] ?? '';

$activeRole   = getActiveRole();
$isSuperAdmin = ($activeRole === 'superadmin');
$isAdmin      = ($activeRole === 'admin');
$isAgent      = ($activeRole === 'agent');
$isUser       = ($activeRole === 'user');
$role         = $_SESSION['active_role'] ?? 'user';
$roleIcons    = [
    'superadmin' => 'bi-person-gear',
    'admin'      => 'bi-shield-fill-check',
    'agent'      => 'bi-headset',
    'user'       => 'bi-person-fill',
    'it_officer' => 'bi-person-badge',
    'it_director'=> 'bi-person-check',
];
$iconClass = $roleIcons[$role] ?? 'bi-person-fill';

// Which tab to open first: ?tab=fields opens Custom fields, else Systems.
$tab = ($_GET['tab'] ?? '') === 'fields' ? 'fields' : 'systems';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Management - PSPF CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">

    <style>
        .settings-title { font-weight: 600; }
        .fm-frame { width: 100%; border: 0; display: block; }
        .nav-tabs .nav-link { font-weight: 500; }
    </style>
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<div class="container-xl mt-4 mb-5">
    <h1 class="settings-title mb-1">Form Management</h1>
    <p class="text-muted mb-3">
        Everything on the IT Access request form: the systems people can request, and any custom
        fields you add. Changes take effect on new requests; submitted requests keep what they were made with.
    </p>

    <ul class="nav nav-tabs" id="fmTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tab === 'systems' ? 'active' : '' ?>" id="tab-systems"
                    data-bs-toggle="tab" data-bs-target="#pane-systems" type="button" role="tab">
                <i class="bi bi-hdd-stack me-1"></i> Systems
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tab === 'fields' ? 'active' : '' ?>" id="tab-fields"
                    data-bs-toggle="tab" data-bs-target="#pane-fields" type="button" role="tab">
                <i class="bi bi-input-cursor-text me-1"></i> Custom fields
            </button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom bg-white">
        <div class="tab-pane fade <?= $tab === 'systems' ? 'show active' : '' ?>" id="pane-systems" role="tabpanel">
            <iframe class="fm-frame" data-src="/pspf_crm/api/settings/system_catalog.php?embed=1"
                    <?= $tab === 'systems' ? 'src="/pspf_crm/api/settings/system_catalog.php?embed=1"' : '' ?>
                    title="System catalog"></iframe>
        </div>
        <div class="tab-pane fade <?= $tab === 'fields' ? 'show active' : '' ?>" id="pane-fields" role="tabpanel">
            <iframe class="fm-frame" data-src="/pspf_crm/api/settings/form_builder.php?embed=1"
                    <?= $tab === 'fields' ? 'src="/pspf_crm/api/settings/form_builder.php?embed=1"' : '' ?>
                    title="Custom fields"></iframe>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Lazy-load an iframe the first time its tab is shown, and auto-size it to its
// content so there is no inner scrollbar.
function sizeFrame(fr) {
  try {
    const doc = fr.contentWindow.document;
    const h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
    fr.style.height = (h + 20) + 'px';
  } catch (e) { fr.style.height = '800px'; }
}
document.querySelectorAll('.fm-frame').forEach(fr => {
  fr.addEventListener('load', () => {
    sizeFrame(fr);
    // Re-measure a few times: modals/lists change height after XHR loads.
    let n = 0;
    const t = setInterval(() => { sizeFrame(fr); if (++n > 8) clearInterval(t); }, 400);
  });
});
document.querySelectorAll('#fmTabs button').forEach(btn => {
  btn.addEventListener('shown.bs.tab', () => {
    const pane = document.querySelector(btn.dataset.bsTarget);
    const fr = pane.querySelector('.fm-frame');
    if (fr && !fr.src) fr.src = fr.dataset.src; // lazy-load on first reveal
    else if (fr) sizeFrame(fr);
  });
});
</script>
</body>
</html>
