<?php
/**
 * IT Access — System Catalog management. Superadmin only.
 *
 * Lets a superadmin manage the systems offered on the IT Access request form:
 * name, description, icon, roles, and sub-options (the extra questions asked
 * per system). The catalog is shared, so the same list will drive the ticket
 * Title dropdown — there is no second hardcoded copy to keep in step.
 *
 * All reads/writes go through the JSON API (it_access/catalog.php and
 * catalog_admin.php), which re-checks the superadmin role on every request.
 * This page only renders the UI: hiding controls is never the access control.
 */

session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

// ---------------------------------------------------------------------------
// AUTHORIZATION — superadmin only, checked on held roles (it_officer /
// it_director are permission roles that are never the active persona).
// The API enforces this again on every write.
// ---------------------------------------------------------------------------
if (!isLoggedIn() || !hasRole('superadmin')) {
    http_response_code(403);
    echo "<h3>403 - Forbidden</h3><p>System Catalog management is restricted to superadministrators.</p>";
    exit;
}

$UserId       = (int)$_SESSION['user']['id'];
$UserUsername = $_SESSION['user']['username'];
$UserEmail    = $_SESSION['user']['email'];
$UserDept     = $_SESSION['user']['department'] ?? '';

$activeRole   = getActiveRole();
// Role flags consumed by the shared topnav (all must be defined).
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

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>System Catalog - PSPF CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">

    <style>
        .settings-title { font-weight: 600; }
        .sys-card { transition: box-shadow .12s ease; }
        .sys-card:hover { box-shadow: 0 .25rem .75rem rgba(0,0,0,.08) !important; }
        .sys-card.retired { opacity: .62; }
        .sys-card.retired .sys-name::after {
            content: "Retired";
            font-size: .68rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .04em; margin-left: .5rem;
            background: #6c757d; color: #fff; padding: .1rem .4rem; border-radius: .2rem;
            vertical-align: middle;
        }
        .sys-name { font-weight: 600; }
        .chip {
            display: inline-block; font-size: .72rem; padding: .12rem .45rem;
            border-radius: .25rem; background: #eef2f6; color: #47546a;
            margin: 0 .25rem .25rem 0; white-space: nowrap;
        }
        .chip-sub { background: #e7f1fb; color: #2b5f95; }
        .sub-row { border: 1px solid #dee2e6; border-radius: .375rem; padding: .75rem; margin-bottom: .5rem; }
        .drag-handle { cursor: grab; color: #adb5bd; }
        .usage-note { font-size: .78rem; }
        #catalogList { min-height: 60px; }
        .spinner-inline { width: 1rem; height: 1rem; border-width: .15em; }
    </style>
</head>
<body>

<?php include '../agent/topnav.php'; ?>

<div class="container-xl mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
        <div>
            <h1 class="settings-title mb-1">System Catalog</h1>
            <p class="text-muted mb-0">
                The systems people can request on the IT Access form. Changes take effect immediately
                on new requests; requests already submitted keep the details they were made with.
            </p>
        </div>
        <button class="btn btn-primary" id="btnNew">
            <i class="bi bi-plus-lg me-1"></i> Add system
        </button>
    </div>

    <div id="alertBox" class="mt-3"></div>

    <div class="d-flex align-items-center gap-2 mt-3 mb-2">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="showRetired">
            <label class="form-check-label small text-muted" for="showRetired">Show retired systems</label>
        </div>
        <span class="text-muted small ms-auto" id="countNote"></span>
    </div>

    <div id="catalogList" class="d-flex flex-column gap-2">
        <div class="text-center text-muted py-5">
            <div class="spinner-border spinner-border-sm me-2"></div> Loading catalog…
        </div>
    </div>
</div>

<!-- ---------------------------------------------------------------- -->
<!-- Editor modal                                                      -->
<!-- ---------------------------------------------------------------- -->
<div class="modal fade" id="editorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editorTitle">Edit system</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="editorError" class="alert alert-danger d-none" role="alert"></div>

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="fName">Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="fName" maxlength="255" autocomplete="off">
            <div class="form-text" id="idNote"></div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="fIcon">Icon</label>
            <select class="form-select" id="fIcon">
              <option value="shield">Shield</option>
              <option value="shield-check">Shield (check)</option>
              <option value="bank">Bank</option>
              <option value="key">Key</option>
              <option value="door">Door</option>
              <option value="phone">Phone</option>
              <option value="archive">Archive</option>
              <option value="scale">Scale</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" for="fDesc">Description</label>
            <input type="text" class="form-control" id="fDesc" maxlength="500"
                   placeholder="What this system is for — shown under the name on the request form">
          </div>
        </div>

        <hr class="my-4">

        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <h6 class="mb-0">Access levels</h6>
            <small class="text-muted">The roles a requester can ask for. Leave empty if this system has none.</small>
          </div>
          <button class="btn btn-sm btn-outline-secondary" id="btnAddRole" type="button">
            <i class="bi bi-plus-lg"></i> Add
          </button>
        </div>
        <div id="rolesWrap" class="d-flex flex-column gap-2 mb-2"></div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="fMultiRole">
          <label class="form-check-label small" for="fMultiRole">
            Allow selecting several access levels at once
          </label>
        </div>

        <hr class="my-4">

        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <h6 class="mb-0">Extra questions</h6>
            <small class="text-muted">Asked when someone selects this system.</small>
          </div>
          <button class="btn btn-sm btn-outline-secondary" id="btnAddSub" type="button">
            <i class="bi bi-plus-lg"></i> Add
          </button>
        </div>
        <div id="subsWrap"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSave">
          <span class="spinner-border spinner-inline me-1 d-none" id="saveSpin"></span>
          Save
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const READ  = '/pspf_crm/api/it_access/catalog.php?all=1';
  const WRITE = '/pspf_crm/api/it_access/catalog_admin.php';

  let catalog = [];
  let editing = null;   // the system being edited, or null when adding

  const $  = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const modal = new bootstrap.Modal($('editorModal'));

  function alertMsg(kind, text) {
    $('alertBox').innerHTML =
      `<div class="alert alert-${kind} alert-dismissible fade show" role="alert">
         ${esc(text)}
         <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
       </div>`;
    if (kind === 'success') setTimeout(() => {
      const a = $('alertBox').querySelector('.alert');
      if (a) bootstrap.Alert.getOrCreateInstance(a).close();
    }, 4000);
  }

  async function api(body) {
    const res = await fetch(WRITE, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'The change could not be saved.');
    return data;
  }

  async function load() {
    try {
      const res  = await fetch(READ, { credentials: 'include' });
      const data = await res.json();
      catalog = data.systems || [];
      render();
    } catch (e) {
      $('catalogList').innerHTML =
        '<div class="alert alert-danger">Could not load the catalog. Refresh to try again.</div>';
    }
  }

  function render() {
    const showRetired = $('showRetired').checked;
    const list = catalog.filter(s => showRetired || s.isActive);
    const activeCount = catalog.filter(s => s.isActive).length;
    $('countNote').textContent =
      `${activeCount} active${catalog.length - activeCount ? ` · ${catalog.length - activeCount} retired` : ''}`;

    if (!list.length) {
      $('catalogList').innerHTML =
        '<div class="text-center text-muted py-5">No systems to show.</div>';
      return;
    }

    $('catalogList').innerHTML = list.map(s => {
      const roles = (s.roles || []).map(r => `<span class="chip">${esc(r)}</span>`).join('');
      const subs  = (s.subOptions || []).map(o =>
        `<span class="chip chip-sub">${esc(o.label)}${o.text ? ' (text)' : (o.multi ? ' (multi)' : '')}</span>`).join('');
      const used  = s.usageCount || 0;
      return `
      <div class="card sys-card border-0 shadow-sm ${s.isActive ? '' : 'retired'}">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div class="flex-grow-1" style="min-width:16rem">
              <div class="sys-name">${esc(s.name)}</div>
              <div class="text-muted small mb-2">${esc(s.desc || '—')}</div>
              <div>${roles}${subs}</div>
              ${used ? `<div class="text-muted usage-note mt-2">
                          <i class="bi bi-link-45deg"></i> used by ${used} request${used === 1 ? '' : 's'}
                        </div>` : ''}
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
              <button class="btn btn-sm btn-outline-secondary" data-edit="${esc(s.id)}">
                <i class="bi bi-pencil"></i> Edit
              </button>
              ${s.isActive
                ? `<button class="btn btn-sm btn-outline-secondary" data-retire="${esc(s.id)}">Retire</button>`
                : `<button class="btn btn-sm btn-outline-success" data-restore="${esc(s.id)}">Restore</button>`}
              <button class="btn btn-sm btn-outline-danger" data-delete="${esc(s.id)}"
                      ${used ? 'disabled title="Used by existing requests — retire it instead"' : ''}>
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');
  }

  // ---- editor ----------------------------------------------------------
  function roleRow(value = '') {
    const d = document.createElement('div');
    d.className = 'input-group input-group-sm';
    d.innerHTML = `
      <input type="text" class="form-control role-input" maxlength="100"
             value="${esc(value)}" placeholder="e.g. Viewer">
      <button class="btn btn-outline-secondary" type="button" data-rm-role>
        <i class="bi bi-x-lg"></i>
      </button>`;
    d.querySelector('[data-rm-role]').onclick = () => d.remove();
    return d;
  }

  function subRow(sub = null) {
    const d = document.createElement('div');
    d.className = 'sub-row';
    // The stable key rides along on the element so a save can update the
    // existing sub-option in place rather than minting a new one, which is
    // what keeps answers on past requests pointing at the right question.
    d.dataset.key = sub?.key || '';
    const kind = sub ? (sub.text ? 'text' : (sub.multi ? 'multi' : 'single')) : 'single';
    d.innerHTML = `
      <div class="row g-2 align-items-start">
        <div class="col-md-5">
          <input type="text" class="form-control form-control-sm sub-label" maxlength="150"
                 value="${esc(sub?.label || '')}" placeholder="Question, e.g. Duration">
        </div>
        <div class="col-md-3">
          <select class="form-select form-select-sm sub-kind">
            <option value="single"${kind === 'single' ? ' selected' : ''}>Pick one</option>
            <option value="multi"${kind === 'multi' ? ' selected' : ''}>Pick several</option>
            <option value="text"${kind === 'text' ? ' selected' : ''}>Free text</option>
          </select>
        </div>
        <div class="col-md-4 d-flex gap-1">
          <input type="text" class="form-control form-control-sm sub-opts"
                 value="${esc((sub?.options || []).join(', '))}"
                 placeholder="Choices, comma separated"${kind === 'text' ? ' disabled' : ''}>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-rm-sub>
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </div>
      ${sub?.key ? `<div class="form-text mt-1"><i class="bi bi-lock-fill"></i>
                      Existing question — past requests reference it, so its answers stay linked.</div>` : ''}`;
    const kindSel = d.querySelector('.sub-kind');
    const optsIn  = d.querySelector('.sub-opts');
    kindSel.onchange = () => {
      optsIn.disabled = kindSel.value === 'text';
      if (optsIn.disabled) optsIn.value = '';
    };
    d.querySelector('[data-rm-sub]').onclick = () => d.remove();
    return d;
  }

  function openEditor(sys) {
    editing = sys;
    $('editorError').classList.add('d-none');
    $('editorTitle').textContent = sys ? 'Edit system' : 'Add system';
    $('fName').value = sys?.name || '';
    $('fDesc').value = sys?.desc || '';
    $('fIcon').value = sys?.icon || 'archive';
    $('fMultiRole').checked = !!sys?.multiRole;
    $('idNote').textContent = sys
      ? `Identifier: ${sys.id} — fixed, because past requests reference it.`
      : 'An identifier is generated from the name and cannot change later.';

    $('rolesWrap').innerHTML = '';
    (sys?.roles || []).forEach(r => $('rolesWrap').appendChild(roleRow(r)));

    $('subsWrap').innerHTML = '';
    (sys?.subOptions || []).forEach(o => $('subsWrap').appendChild(subRow(o)));

    modal.show();
  }

  function collect() {
    const roles = [...document.querySelectorAll('.role-input')]
      .map(i => i.value.trim()).filter(Boolean);

    const subOptions = [...document.querySelectorAll('.sub-row')].map(row => {
      const label = row.querySelector('.sub-label').value.trim();
      const kind  = row.querySelector('.sub-kind').value;
      const opts  = row.querySelector('.sub-opts').value
        .split(',').map(s => s.trim()).filter(Boolean);
      const o = { label };
      if (row.dataset.key) o.key = row.dataset.key;   // preserve the stable key
      if (kind === 'text') { o.text = true; }
      else { o.multi = kind === 'multi'; o.options = opts; }
      return o;
    }).filter(o => o.label);

    const sys = {
      name: $('fName').value.trim(),
      desc: $('fDesc').value.trim(),
      icon: $('fIcon').value,
      multiRole: $('fMultiRole').checked,
      roles, subOptions,
    };
    if (editing) sys.id = editing.id;
    return sys;
  }

  // ---- events ----------------------------------------------------------
  $('btnNew').onclick      = () => openEditor(null);
  $('showRetired').onchange = render;
  $('btnAddRole').onclick  = () => $('rolesWrap').appendChild(roleRow());
  $('btnAddSub').onclick   = () => $('subsWrap').appendChild(subRow());

  $('btnSave').onclick = async () => {
    const sys = collect();
    if (!sys.name) {
      $('editorError').textContent = 'Give the system a name.';
      $('editorError').classList.remove('d-none');
      return;
    }
    $('saveSpin').classList.remove('d-none');
    $('btnSave').disabled = true;
    try {
      const data = await api({ action: 'save', system: sys });
      catalog = data.systems;
      render();
      modal.hide();
      alertMsg('success', `Saved “${sys.name}”.`);
    } catch (e) {
      $('editorError').textContent = e.message;
      $('editorError').classList.remove('d-none');
    } finally {
      $('saveSpin').classList.add('d-none');
      $('btnSave').disabled = false;
    }
  };

  $('catalogList').addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-edit],button[data-retire],button[data-restore],button[data-delete]');
    if (!btn) return;

    const id  = btn.dataset.edit || btn.dataset.retire || btn.dataset.restore || btn.dataset.delete;
    const sys = catalog.find(s => s.id === id);
    if (!sys) return;

    if (btn.dataset.edit) { openEditor(sys); return; }

    let action, confirmText;
    if (btn.dataset.retire) {
      action = 'deactivate';
      confirmText = `Retire “${sys.name}”?\n\nIt will no longer appear on new requests. Past requests keep showing it.`;
    } else if (btn.dataset.restore) {
      action = 'activate';
      confirmText = null;
    } else {
      action = 'delete';
      confirmText = `Delete “${sys.name}” permanently?\n\nNo request uses it, so nothing will be lost. This cannot be undone.`;
    }
    if (confirmText && !confirm(confirmText)) return;

    btn.disabled = true;
    try {
      const data = await api({ action, id });
      catalog = data.systems;
      render();
      alertMsg('success',
        action === 'delete'   ? `Deleted “${sys.name}”.` :
        action === 'activate' ? `Restored “${sys.name}”.`
                              : `Retired “${sys.name}”.`);
    } catch (e) {
      alertMsg('danger', e.message);
      btn.disabled = false;
    }
  });

  load();
})();
</script>
</body>
</html>
