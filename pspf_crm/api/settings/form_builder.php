<?php
/**
 * IT Access - Form Builder. Superadmin only.
 *
 * Lets a superadmin add custom fields to the IT Access request form's
 * "Additional information" section: label, type, options, required flag.
 * Fields are request-level and additive - the core fields (employee, systems,
 * justification) are fixed and not editable here.
 *
 * All reads/writes go through the JSON API (it_access/form_fields.php and
 * form_fields_admin.php), which re-checks the superadmin role on every request.
 * This page only renders the UI; hiding controls is never the access control.
 */

session_start();

require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once '../includes/role_switcher.php';

enforceActiveUser($conn);
enforcePasswordPolicy($conn);

if (!isLoggedIn() || !hasRole('superadmin')) {
    http_response_code(403);
    echo "<h3>403 - Forbidden</h3><p>Form Builder is restricted to superadministrators.</p>";
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

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Form Builder - PSPF CRM</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/pspf_crm/api/style5.css">
    <link rel="stylesheet" href="/pspf_crm/api/agent/agent_style.css">

    <style>
        .settings-title { font-weight: 600; }
        .field-card { transition: box-shadow .12s ease; }
        .field-card:hover { box-shadow: 0 .25rem .75rem rgba(0,0,0,.08) !important; }
        .field-card.retired { opacity: .62; }
        .field-card.retired .field-name::after {
            content: "Retired";
            font-size: .68rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .04em; margin-left: .5rem;
            background: #6c757d; color: #fff; padding: .1rem .4rem; border-radius: .2rem;
            vertical-align: middle;
        }
        .field-name { font-weight: 600; }
        .kind-pill {
            display: inline-block; font-size: .7rem; padding: .12rem .5rem;
            border-radius: .25rem; background: #eef2f6; color: #47546a; text-transform: uppercase;
            letter-spacing: .03em;
        }
        .req-pill { background: #fde6e6; color: #a01b1b; }
        .opt-chip {
            display: inline-block; font-size: .72rem; padding: .1rem .45rem;
            border-radius: .25rem; background: #e7f1fb; color: #2b5f95;
            margin: 0 .25rem .25rem 0;
        }
        .move-btns .btn { padding: .1rem .4rem; line-height: 1; }
        #fieldList { min-height: 60px; }
        .spinner-inline { width: 1rem; height: 1rem; border-width: .15em; }
        #optionsWrap .opt-row { display: flex; gap: .5rem; margin-bottom: .4rem; }
    </style>
</head>
<body>

<?php
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';
if (!$embed) include '../agent/topnav.php';
?>

<div class="container-xl <?= $embed ? 'mt-2' : 'mt-4' ?> mb-5">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
        <div>
            <?php if (!$embed): ?>
            <h1 class="settings-title mb-1">Form Builder</h1>
            <?php endif ?>
            <p class="text-muted mb-0">
                Custom fields shown in the request form's "Additional information" section.
                Changes take effect on new requests; requests already submitted keep what they
                were made with. Core fields (employee, systems, justification) are fixed.
            </p>
        </div>
        <button class="btn btn-primary" id="btnNew">
            <i class="bi bi-plus-lg me-1"></i> Add field
        </button>
    </div>

    <div id="alertBox" class="mt-3"></div>

    <div class="d-flex align-items-center gap-2 mt-3 mb-2">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="showRetired">
            <label class="form-check-label small text-muted" for="showRetired">Show retired fields</label>
        </div>
        <span class="text-muted small ms-auto" id="countNote"></span>
    </div>

    <div id="fieldList"></div>
</div>

<!-- Add / edit modal -->
<div class="modal fade" id="fieldModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="fieldModalTitle">Add field</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fFieldKey">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Label <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="fLabel" maxlength="150" placeholder="e.g. Cost centre">
          </div>
          <div class="col-md-4">
            <label class="form-label">Type</label>
            <select class="form-select" id="fKind">
              <option value="text">Text (single line)</option>
              <option value="textarea">Text (multi-line)</option>
              <option value="number">Number</option>
              <option value="date">Date</option>
              <option value="select">Dropdown (choose one)</option>
              <option value="multiselect">Checkboxes (choose many)</option>
              <option value="checkbox">Single checkbox (yes/no)</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Placeholder <span class="text-muted small">(optional)</span></label>
            <input type="text" class="form-control" id="fPlaceholder" maxlength="255">
          </div>
          <div class="col-md-6">
            <label class="form-label">Help text <span class="text-muted small">(optional)</span></label>
            <input type="text" class="form-control" id="fHelp" maxlength="500">
          </div>
          <div class="col-12" id="optionsBlock" style="display:none;">
            <label class="form-label">Options</label>
            <div id="optionsWrap"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddOption">
              <i class="bi bi-plus"></i> Add option
            </button>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="fRequired">
              <label class="form-check-label" for="fRequired">Required - block submission until answered</label>
            </div>
          </div>
        </div>
        <div id="modalAlert" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSave">Save field</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const READ_URL  = '/pspf_crm/api/it_access/form_fields.php';
const WRITE_URL = '/pspf_crm/api/it_access/form_fields_admin.php';
const KIND_LABELS = {
  text: 'Text', textarea: 'Text area', number: 'Number', date: 'Date',
  select: 'Dropdown', multiselect: 'Checkboxes', checkbox: 'Checkbox',
};
const HAS_OPTIONS = k => k === 'select' || k === 'multiselect';

let fields = [];
const modalEl = document.getElementById('fieldModal');
const modal = new bootstrap.Modal(modalEl);

function alertBox(msg, kind = 'danger', where = 'alertBox') {
  document.getElementById(where).innerHTML =
    `<div class="alert alert-${kind} alert-dismissible fade show py-2 px-3 mb-0" role="alert">
       ${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}
function clearAlert(where = 'alertBox') { document.getElementById(where).innerHTML = ''; }

async function api(action, extra = {}) {
  const res = await fetch(WRITE_URL, {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ action, ...extra }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || ('Request failed (' + res.status + ')'));
  return data;
}

async function load() {
  const showRetired = document.getElementById('showRetired').checked;
  const url = READ_URL + (showRetired ? '?all=1' : '');
  const res = await fetch(url, { credentials: 'include' });
  const data = await res.json().catch(() => ({ fields: [] }));
  fields = data.fields || [];
  render();
}

function render() {
  const list = document.getElementById('fieldList');
  const active = fields.filter(f => f.isActive).length;
  document.getElementById('countNote').textContent =
    `${active} active field${active === 1 ? '' : 's'}` +
    (fields.length > active ? ` · ${fields.length - active} retired` : '');

  if (!fields.length) {
    list.innerHTML = `<div class="text-center text-muted py-5">
      <i class="bi bi-input-cursor-text fs-1 d-block mb-2"></i>
      No custom fields yet. Click <strong>Add field</strong> to create one.</div>`;
    return;
  }

  list.innerHTML = fields.map((f, i) => {
    const opts = (f.options || []).map(o => `<span class="opt-chip">${escapeHtml(o)}</span>`).join('');
    const retiredCls = f.isActive ? '' : ' retired';
    return `<div class="card field-card mb-2${retiredCls}">
      <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
        <div class="move-btns d-flex flex-column">
          <button class="btn btn-sm btn-light" ${i === 0 ? 'disabled' : ''} onclick="move('${f.fieldKey}',-1)" title="Move up"><i class="bi bi-chevron-up"></i></button>
          <button class="btn btn-sm btn-light" ${i === fields.length - 1 ? 'disabled' : ''} onclick="move('${f.fieldKey}',1)" title="Move down"><i class="bi bi-chevron-down"></i></button>
        </div>
        <div class="flex-grow-1 min-w-0">
          <div class="field-name">${escapeHtml(f.label)}
            <span class="kind-pill ms-1">${KIND_LABELS[f.kind] || f.kind}</span>
            ${f.required ? '<span class="kind-pill req-pill ms-1">Required</span>' : ''}
          </div>
          ${opts ? `<div class="mt-1">${opts}</div>` : ''}
          ${f.helpText ? `<div class="text-muted small mt-1">${escapeHtml(f.helpText)}</div>` : ''}
        </div>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-secondary" onclick="edit('${f.fieldKey}')"><i class="bi bi-pencil"></i></button>
          ${f.isActive
            ? `<button class="btn btn-sm btn-outline-warning" onclick="setActive('${f.fieldKey}',false)" title="Retire">
                 <i class="bi bi-eye-slash"></i></button>`
            : `<button class="btn btn-sm btn-outline-success" onclick="setActive('${f.fieldKey}',true)" title="Restore">
                 <i class="bi bi-eye"></i></button>`}
          <button class="btn btn-sm btn-outline-danger" onclick="del('${f.fieldKey}')" title="Delete"><i class="bi bi-trash"></i></button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

// ---- Options editor ----
function addOptionRow(val = '') {
  const wrap = document.getElementById('optionsWrap');
  const row = document.createElement('div');
  row.className = 'opt-row';
  row.innerHTML = `<input type="text" class="form-control form-control-sm opt-input" value="${escapeHtml(val)}" placeholder="Option text">
    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentNode.remove()"><i class="bi bi-x"></i></button>`;
  wrap.appendChild(row);
}
function syncOptionsVisibility() {
  const kind = document.getElementById('fKind').value;
  document.getElementById('optionsBlock').style.display = HAS_OPTIONS(kind) ? '' : 'none';
}

// ---- Modal open (new / edit) ----
function openModal(field) {
  clearAlert('modalAlert');
  document.getElementById('fFieldKey').value = field ? field.fieldKey : '';
  document.getElementById('fLabel').value = field ? field.label : '';
  document.getElementById('fKind').value = field ? field.kind : 'text';
  document.getElementById('fPlaceholder').value = field ? (field.placeholder || '') : '';
  document.getElementById('fHelp').value = field ? (field.helpText || '') : '';
  document.getElementById('fRequired').checked = field ? !!field.required : false;
  document.getElementById('optionsWrap').innerHTML = '';
  (field && field.options ? field.options : []).forEach(o => addOptionRow(o));
  syncOptionsVisibility();
  document.getElementById('fieldModalTitle').textContent = field ? 'Edit field' : 'Add field';
  modal.show();
}
function edit(key) { openModal(fields.find(f => f.fieldKey === key)); }

async function save() {
  clearAlert('modalAlert');
  const kind = document.getElementById('fKind').value;
  const field = {
    fieldKey:    document.getElementById('fFieldKey').value || undefined,
    label:       document.getElementById('fLabel').value.trim(),
    kind,
    placeholder: document.getElementById('fPlaceholder').value.trim(),
    helpText:    document.getElementById('fHelp').value.trim(),
    required:    document.getElementById('fRequired').checked,
  };
  if (HAS_OPTIONS(kind)) {
    field.options = [...document.querySelectorAll('#optionsWrap .opt-input')]
      .map(i => i.value.trim()).filter(Boolean);
  }
  if (!field.label) { alertBox('Label is required.', 'danger', 'modalAlert'); return; }
  if (HAS_OPTIONS(kind) && (!field.options || !field.options.length)) {
    alertBox('This field type needs at least one option.', 'danger', 'modalAlert'); return;
  }
  try {
    const data = await api('save', { field });
    fields = data.fields || [];
    render();
    modal.hide();
    alertBox('Field saved.', 'success');
  } catch (e) { alertBox(e.message, 'danger', 'modalAlert'); }
}

async function setActive(key, active) {
  try {
    const data = await api(active ? 'activate' : 'deactivate', { key });
    fields = data.fields || []; render();
  } catch (e) { alertBox(e.message); }
}
async function del(key) {
  const f = fields.find(x => x.fieldKey === key);
  if (!confirm(`Delete "${f ? f.label : key}"? If any request used it, delete is blocked - retire it instead.`)) return;
  try {
    const data = await api('delete', { key });
    fields = data.fields || []; render();
    alertBox('Field deleted.', 'success');
  } catch (e) { alertBox(e.message); }
}
async function move(key, dir) {
  const idx = fields.findIndex(f => f.fieldKey === key);
  const swap = idx + dir;
  if (swap < 0 || swap >= fields.length) return;
  const order = fields.map(f => f.fieldKey);
  [order[idx], order[swap]] = [order[swap], order[idx]];
  try {
    const data = await api('reorder', { order });
    fields = data.fields || []; render();
  } catch (e) { alertBox(e.message); }
}

// ---- wire up ----
document.getElementById('btnNew').addEventListener('click', () => openModal(null));
document.getElementById('btnSave').addEventListener('click', save);
document.getElementById('btnAddOption').addEventListener('click', () => addOptionRow());
document.getElementById('fKind').addEventListener('change', syncOptionsVisibility);
document.getElementById('showRetired').addEventListener('change', load);
load();
</script>
</body>
</html>
