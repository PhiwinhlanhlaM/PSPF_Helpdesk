// AppShell - top bar, role switcher, route view container, toast system.

const { createContext, useContext, useMemo, useReducer, useCallback, useState } = React;

// ---------- App state ----------
const AppCtx = createContext(null);
const useApp = () => useContext(AppCtx);

// Persist "seen notification" ids across reloads, scoped per CRM user, so the
// unread badge stays cleared after a hard refresh.
const SEEN_NOTIFS_KEY = (() => {
  const uid = (window.__CRM_USER__ && window.__CRM_USER__.id) || "anon";
  return "ita_seen_notifs_" + uid;
})();
function loadSeenNotifs() {
  try {
    const raw = localStorage.getItem(SEEN_NOTIFS_KEY);
    const obj = raw ? JSON.parse(raw) : {};
    return (obj && typeof obj === "object") ? obj : {};
  } catch { return {}; }
}
function persistSeenNotifs(seen) {
  try { localStorage.setItem(SEEN_NOTIFS_KEY, JSON.stringify(seen)); } catch {}
}

function appReducer(state, action) {
  switch (action.type) {
    case "set-role":
      return { ...state, role: action.role };
    case "set-route":
      return { ...state, route: action.route, routeParams: action.params || {} };
    case "submit-request": {
      const requests = [action.request, ...state.requests];
      return { ...state, requests };
    }
    case "claim-request": {
      const claimedIds = action.actionedSystems || [];
      const requests = state.requests.map(r => {
        if (r.id !== action.id) return r;
        // Mark the officer's chosen systems as claimed by them; leave others as-is.
        const systems = r.systems.map(s =>
          claimedIds.includes(s.id) && (s.status || "pending") === "pending"
            ? { ...s, status: "claimed", claimedBy: action.personId }
            : s
        );
        return { ...r, claimedBy: action.personId, status: "claimed", systems };
      });
      return { ...state, requests };
    }
    case "approve-request": {
      const { id, personId, role: stepRole, signature, actionedSystems } = action;
      const requests = state.requests.map(r => {
        if (r.id !== id) return r;
        const approvals = [...r.approvals, { role: stepRole, personId, at: new Date().toISOString(), action: "approved", signature }];
        let systems = r.systems;
        let status = r.status;

        if (stepRole === "officer-1") {
          // Mark this officer's actioned systems; advance to director only when
          // every system on the request has been actioned.
          const ids = actionedSystems || systems.filter(s => s.claimedBy === personId).map(s => s.id);
          systems = systems.map(s =>
            ids.includes(s.id) ? { ...s, status: "actioned", actionedBy: personId } : s
          );
          const allActioned = systems.every(s => (s.status || "pending") === "actioned");
          status = allActioned ? "awaiting-director" : "claimed";
        } else if (stepRole === "director") {
          status = "provisioned";
        }

        const next = { ...r, approvals, status, systems };
        if (status === "provisioned") next.provisionedAt = new Date().toISOString();
        return next;
      });
      return { ...state, requests };
    }
    case "reject-request": {
      const { id, personId, role: stepRole, reason } = action;
      const requests = state.requests.map(r => {
        if (r.id !== id) return r;
        return {
          ...r,
          approvals: [...r.approvals, { role: stepRole, personId, at: new Date().toISOString(), action: "rejected", reason }],
          status: "rejected",
          rejectedBy: personId,
        };
      });
      return { ...state, requests };
    }
    case "load-requests":
      return { ...state, requests: action.requests };
    case "load-departments":
      return { ...state, departments: action.departments };
    // A rejected request the user chose to appeal. The form reads it on mount to
    // prefill, and clears it once consumed so it does not leak into a later new
    // request.
    case "set-appeal-draft":
      return { ...state, appealDraft: action.request };
    case "clear-appeal-draft":
      return { ...state, appealDraft: null };
    // The catalog itself lives in a module binding (see data.jsx), not in
    // state — this counter exists purely to trigger a re-render once the
    // server copy has replaced the fallback.
    case "catalog-loaded":
      return { ...state, catalogVersion: state.catalogVersion + 1 };
    case "mark-notifs-seen": {
      // Mark the given notification ids as seen so the unread badge clears,
      // and persist so it survives a page reload.
      const seenNotifs = { ...state.seenNotifs };
      for (const id of action.ids) seenNotifs[id] = true;
      persistSeenNotifs(seenNotifs);
      return { ...state, seenNotifs };
    }
    case "reset":
      return makeInitialState();
    default:
      return state;
  }
}

function makeInitialState() {
  return {
    role: "manager",       // manager | officer | director
    route: { name: "manager-form" },
    routeParams: {},
    requests: buildSeedRequests(),
    departments: DEPARTMENTS.map(name => ({ id: null, name, divisions: [] })),  // fallback until API responds
    catalogVersion: 0,             // bumped when the server catalog replaces the fallback
    appealDraft: null,             // a rejected request being appealed, prefilled into the form
    seenNotifs: loadSeenNotifs(),  // restored from localStorage so reads persist across reloads
  };
}

function AppProvider({ children, initialRole }) {
  const [state, dispatch] = useReducer(appReducer, null, () => {
    const s = makeInitialState();
    // Pick a starting area the user is actually allowed into. If the injected
    // initial role isn't available to them (e.g. an IT-only user with no
    // Requests area), fall back to their first available area.
    const available = getAvailableRoles();
    const allowedValues = available.map(a => a.value);
    let role = initialRole && allowedValues.includes(initialRole)
      ? initialRole
      : (available[0] && available[0].value);
    if (role) {
      s.role = role;
      s.route = defaultRouteForRole(role);
    }
    return s;
  });

  const [toasts, setToasts] = useState([]);
  const toast = useCallback((opts) => {
    const id = Math.random().toString(36).slice(2, 9);
    setToasts(t => [...t, { id, kind: "success", ...opts }]);
    setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), opts.duration || 4000);
  }, []);

  // One-time full-name capture: prompt when the CRM user has no saved full name.
  const [needName, setNeedName] = useState(() => !!(window.__CRM_USER__ && window.__CRM_USER__.needsName));
  const [nameVersion, bumpName] = useReducer(x => x + 1, 0); // re-derive `me` after __CRM_USER__.name changes
  const onNameSaved = useCallback((fullName) => {
    if (window.__CRM_USER__) {
      window.__CRM_USER__.name = fullName;
      window.__CRM_USER__.needsName = false;
      const parts = fullName.trim().split(/\s+/);
      window.__CRM_USER__.initials = ((parts[0]?.[0] || "") + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase();
    }
    setNeedName(false);
    bumpName();
  }, []);

  // Seed requests and departments from the CRM backend on mount
  const { useEffect } = React;
  useEffect(() => {
    fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" })
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(data => {
        if (Array.isArray(data.requests) && data.requests.length > 0) {
          dispatch({ type: "load-requests", requests: data.requests });
        }
      })
      .catch(() => {}); // fallback to demo seed data when running standalone

    fetch("/pspf_crm/api/departments/list.php", { credentials: "include" })
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(data => {
        if (Array.isArray(data.departments) && data.departments.length > 0) {
          dispatch({ type: "load-departments", departments: data.departments });
        }
      })
      .catch(() => {}); // fallback to DEPARTMENTS constant when running standalone

    // The system catalog is superadmin-managed and served from the DB. Replace
    // the fallback constant with the live copy, then bump catalogVersion so
    // views re-render — setSystemCatalog mutates a module binding, which React
    // cannot see on its own.
    fetch("/pspf_crm/api/it_access/catalog.php", { credentials: "include" })
      .then(r => r.ok ? r.json() : Promise.reject())
      .then(data => {
        if (Array.isArray(data.systems) && data.systems.length > 0) {
          setSystemCatalog(data.systems);
          dispatch({ type: "catalog-loaded" });
        }
      })
      .catch(() => {}); // fallback to the SYSTEM_CATALOG constant when standalone
  }, []);

  // When role changes, jump to that role's default landing route
  const setRole = useCallback((role) => {
    dispatch({ type: "set-role", role });
    dispatch({ type: "set-route", route: defaultRouteForRole(role) });
  }, []);

  const value = useMemo(() => ({
    state, dispatch, toast, setRole,
    me: meForRole(state.role),
    availableRoles: getAvailableRoles(),
    departments: state.departments,
    needName, onNameSaved,   // full-name capture, consumed on the request page only
  }), [state, toast, setRole, nameVersion, needName, onNameSaved]);

  return (
    <AppCtx.Provider value={value}>
      {children}
      <ToastStack toasts={toasts} onDismiss={(id) => setToasts(t => t.filter(x => x.id !== id))}/>
    </AppCtx.Provider>
  );
}

function defaultRouteForRole(role) {
  if (role === "manager")    return { name: "manager-form" };
  if (role === "supervisor") return { name: "supervisor-dashboard" };
  if (role === "officer")    return { name: "officer-dashboard" };
  if (role === "director")   return { name: "director-dashboard" };
  return { name: "manager-form" };
}

function meForRole(role) {
  if (window.__CRM_USER__) return window.__CRM_USER__;
  if (role === "officer")  return PEOPLE.officers[0];
  if (role === "director") return PEOPLE.director;
  return PEOPLE.managers[0];
}

// Compute which WORK AREAS the current user can access within IT Access.
// These are functional areas unlocked by permissions, not selectable personas:
// it_officer / it_director are never shown as roles to pick; they simply unlock
// the "IT Review" and "Director" areas. Submitting requests ("Requests") is
// limited to CRM administrators (admin / superadmin).
// The __CRM_USER__.crmRoles array is injected by index.php.
function getAvailableRoles() {
  const u = window.__CRM_USER__;
  if (!u) return [ // standalone demo: show all areas
    { value: "manager",  label: "Requests" },
    { value: "officer",  label: "IT Review" },
    { value: "director", label: "Director" },
  ];
  const areas = [];
  const crmRoles = u.crmRoles || [];
  // Requesting access is open to every signed-in user — the approval chain
  // gates the request, not who is allowed to ask.
  areas.push({ value: "manager", label: "Requests" });
  if (crmRoles.includes("supervisor")) {
    areas.push({ value: "supervisor", label: "Approvals" });
  }
  if (crmRoles.includes("it_officer")) {
    areas.push({ value: "officer", label: "IT Review" });
  }
  if (crmRoles.includes("it_director")) {
    areas.push({ value: "director", label: "Director" });
  }
  return areas;
}

// Build the role-relevant notification list. Shared by the panel (display) and
// the top bar (unread badge) so both stay in sync and use identical ids.
function buildNotifications(requests, role, me) {
  const items = [];
  for (const r of requests) {
    if (role === "manager" && String(r.submittedBy) === String(me.id)) {
      if (r.status === "provisioned")
        items.push({ id: r.id + "-prov", kind: "success", title: "Access provisioned", body: `${r.employee.name} · ${r.id}`, at: r.provisionedAt || r.submittedAt, requestId: r.id, route: "manager-history" });
      else if (r.status === "rejected")
        items.push({ id: r.id + "-rej", kind: "error", title: "Request rejected", body: `${r.employee.name} · ${r.id}`, at: r.approvals.slice(-1)[0]?.at || r.submittedAt, requestId: r.id, route: "manager-history" });
      else if (r.status === "awaiting-director")
        items.push({ id: r.id + "-dir", kind: "info", title: "Awaiting director", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "manager-history" });
      else if (r.status === "new" || r.status === "claimed")
        items.push({ id: r.id + "-new", kind: "info", title: "Under IT review", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "manager-history" });
    }
    if (role === "officer") {
      if (r.status === "new")
        items.push({ id: r.id + "-claim", kind: "info", title: "New request awaiting claim", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "officer-dashboard" });
      else if (r.status === "claimed" && String(r.claimedBy) === String(me.id))
        items.push({ id: r.id + "-sign", kind: "amber", title: "Awaiting your signature", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "officer-sign" });
    }
    if (role === "supervisor" && r.status === "awaiting-supervisor")
      items.push({ id: r.id + "-sup", kind: "amber", title: "Awaiting your approval", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "supervisor-sign" });
    if (role === "director" && r.status === "awaiting-director")
      items.push({ id: r.id + "-ddir", kind: "amber", title: "Awaiting your review", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "director-sign" });
  }
  // Sort newest first, cap at 15
  return items.sort((a, b) => new Date(b.at) - new Date(a.at)).slice(0, 15);
}

// ---------- Notification panel ----------
function NotificationPanel({ onClose }) {
  const { state, me, dispatch } = useApp();
  const role = state.role;

  const notifications = React.useMemo(
    () => buildNotifications(state.requests, role, me),
    [state.requests, role, me.id]
  );

  function go(n) {
    if (n.route === "officer-sign")
      dispatch({ type: "set-route", route: { name: n.route }, params: { requestId: n.requestId } });
    else if (n.route === "director-sign")
      dispatch({ type: "set-route", route: { name: n.route }, params: { requestId: n.requestId } });
    else
      dispatch({ type: "set-route", route: { name: n.route } });
    onClose();
  }

  const kindIcon = { success: "check-circle", error: "alert", info: "info", amber: "clock" };
  const kindColor = { success: "var(--green-700)", error: "var(--red-600)", info: "var(--blue-600)", amber: "var(--amber-700,#b45309)" };

  const unreadIds = notifications.filter(n => !state.seenNotifs[n.id]).map(n => n.id);
  function markAllRead() {
    if (unreadIds.length) dispatch({ type: "mark-notifs-seen", ids: unreadIds });
  }

  return (
    <>
      <div style={{ position: "fixed", inset: 0, zIndex: 1100 }} onClick={onClose}/>
      <div className="card slide-up" style={{
        position: "fixed", top: 56, right: 12, width: 360, maxWidth: "95vw",
        maxHeight: "80vh", overflowY: "auto", zIndex: 1101,
        boxShadow: "0 8px 32px rgba(0,0,0,0.18)", borderRadius: 12,
      }}>
        <div className="row" style={{ justifyContent: "space-between", alignItems: "center", padding: "14px 16px 10px", borderBottom: "1px solid var(--ink-100)" }}>
          <strong style={{ fontSize: 14 }}>Notifications</strong>
          <div className="row gap-1" style={{ alignItems: "center" }}>
            <button className="btn btn-ghost btn-sm" onClick={markAllRead} disabled={unreadIds.length === 0}>
              Mark all as read
            </button>
            <button className="btn btn-ghost btn-sm" onClick={onClose}><Icon name="x" size={14}/></button>
          </div>
        </div>
        {notifications.length === 0 ? (
          <div style={{ padding: "28px 16px", textAlign: "center", color: "var(--ink-400)" }}>
            <Icon name="check-circle" size={24}/>
            <p style={{ marginTop: 8, fontSize: 13 }}>You're all caught up</p>
          </div>
        ) : (
          <div>
            {notifications.map(n => (
              <button key={n.id} onClick={() => go(n)} style={{
                display: "flex", gap: 10, alignItems: "flex-start",
                width: "100%", padding: "10px 16px", textAlign: "left",
                background: "none", border: "none", borderBottom: "1px solid var(--ink-50)",
                cursor: "pointer",
              }}
                onMouseEnter={e => e.currentTarget.style.background = "var(--ink-50)"}
                onMouseLeave={e => e.currentTarget.style.background = "none"}
              >
                <Icon name={kindIcon[n.kind] || "info"} size={16} style={{ color: kindColor[n.kind], flexShrink: 0, marginTop: 1 }}/>
                <div className="col" style={{ flex: 1, minWidth: 0 }}>
                  <strong style={{ fontSize: 12.5, display: "block" }}>{n.title}</strong>
                  <span style={{ fontSize: 12, color: "var(--ink-500)" }}>{n.body}</span>
                  <span style={{ fontSize: 11, color: "var(--ink-400)", marginTop: 2 }}>{fmtDateTime(n.at)}</span>
                </div>
                <Icon name="chevron-right" size={12} style={{ color: "var(--ink-300)", flexShrink: 0, marginTop: 4 }}/>
              </button>
            ))}
          </div>
        )}
      </div>
    </>
  );
}

// ---------- Top bar ----------
function TopBar() {
  const { state, setRole, dispatch, me } = useApp();
  const role = state.role;
  const [showNotifs, setShowNotifs] = useState(false);

  // Role-specific nav tabs
  const tabs = role === "manager"
    ? [
        { name: "manager-form",    label: "New request" },
        { name: "manager-history", label: "My requests" },
      ]
    : role === "supervisor"
    ? [
        { name: "supervisor-dashboard", label: "Approvals" },
      ]
    : role === "officer"
    ? [
        { name: "officer-dashboard", label: "Dashboard" },
      ]
    : [
        { name: "director-dashboard", label: "Pending review" },
      ];

  // Current role-relevant notifications, and the unread badge = those not yet seen.
  const notifications = React.useMemo(
    () => buildNotifications(state.requests, role, me),
    [state.requests, role, me.id]
  );
  const unreadCount = React.useMemo(
    () => notifications.filter(n => !state.seenNotifs[n.id]).length,
    [notifications, state.seenNotifs]
  );

  // The badge is cleared only by the explicit "Mark all as read" button inside
  // the panel, not merely by opening it.
  function toggleNotifs() {
    setShowNotifs(v => !v);
  }

  const embedded = !!window.__CRM_EMBEDDED__;

  return (
    <header className="topbar">
      <div className="topbar-left">
        {!embedded && (
          <div className="brandmark">
            <img src="assets/pspf-logo.png" alt="" />
            <div className="brandtext">
              <span className="brandtext-1">PSPF</span>
              <span className="brandtext-2">IT Access</span>
            </div>
          </div>
        )}

        <nav className="topnav">
          {tabs.map(t => (
            <button
              key={t.name}
              className={"topnav-tab " + (state.route.name === t.name ? "active" : "")}
              onClick={() => dispatch({ type: "set-route", route: { name: t.name } })}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      <div className="topbar-right">
        <RoleSwitcher role={role} onChange={setRole} />
        <button className="bell" title="Notifications" onClick={toggleNotifs}
          style={{ position: "relative", background: "none", border: "none", cursor: "pointer", padding: 4 }}>
          <Icon name="bell" size={16}/>
          {unreadCount > 0 && (
            <span className="bell-dot" style={{ position: "absolute", top: 0, right: 0, background: "var(--red-500,#ef4444)", color: "white", borderRadius: "50%", fontSize: 9, minWidth: 14, height: 14, display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700 }}>
              {unreadCount > 9 ? "9+" : unreadCount}
            </span>
          )}
        </button>
        {showNotifs && <NotificationPanel onClose={() => setShowNotifs(false)}/>}
        {!embedded && (
          <div className="me-chip" title={me.email}>
            <span className="me-avatar">{me.initials}</span>
            <div className="col" style={{ alignItems: "flex-start" }}>
              <span className="me-name">{me.name}</span>
              <span className="me-title">{me.title}</span>
            </div>
          </div>
        )}
      </div>
    </header>
  );
}

function RoleSwitcher({ role, onChange }) {
  const { availableRoles } = useApp();
  // availableRoles is [{value, label}] of WORK AREAS the user's permissions unlock.
  // it_officer / it_director are not shown as selectable roles; they simply unlock
  // the IT Review / Director areas. Hide the switcher when there's only one area.
  if (!availableRoles || availableRoles.length <= 1) return null;
  const hints = { manager: "Submit & track requests", supervisor: "Approve your team's requests", officer: "Review & sign requests", director: "Final sign-off" };
  return (
    <div className="role-switch" role="tablist" aria-label="Work area">
      <span className="role-switch-label">View</span>
      <div className="role-switch-pills">
        {availableRoles.map(item => (
          <button
            key={item.value}
            role="tab"
            aria-selected={role === item.value}
            className={"role-pill " + (role === item.value ? "active" : "")}
            onClick={() => onChange(item.value)}
            title={hints[item.value] || ""}
          >
            {item.label}
          </button>
        ))}
      </div>
    </div>
  );
}

// ---------- Toasts ----------
function ToastStack({ toasts, onDismiss }) {
  return (
    <div className="toast-stack">
      {toasts.map(t => (
        <div key={t.id} className={"toast toast-" + t.kind}>
          <Icon name={t.kind === "success" ? "check-circle" : t.kind === "error" ? "alert" : "info"} size={16}/>
          <div className="col" style={{ flex: 1 }}>
            <strong className="toast-title">{t.title}</strong>
            {t.body && <span className="toast-body">{t.body}</span>}
          </div>
          <button className="toast-close" onClick={() => onDismiss(t.id)} aria-label="Dismiss">
            <Icon name="x" size={14}/>
          </button>
        </div>
      ))}
    </div>
  );
}

// ---------- Route view ----------
function RouteView() {
  const { state } = useApp();
  switch (state.route.name) {
    case "manager-form":      return <ManagerForm key="form"/>;
    case "manager-history":   return <ManagerHistory />;
    case "supervisor-dashboard": return <SupervisorDashboard />;
    case "supervisor-sign":     return <SupervisorSign requestId={state.routeParams.requestId}/>;
    case "officer-dashboard": return <OfficerDashboard />;
    case "officer-sign":      return <OfficerSign requestId={state.routeParams.requestId}/>;
    case "director-dashboard":return <DirectorDashboard />;
    case "director-sign":     return <DirectorSign requestId={state.routeParams.requestId}/>;
    default:                  return <ManagerForm/>;
  }
}

window.AppProvider = AppProvider;
window.useApp = useApp;
window.TopBar = TopBar;
window.RouteView = RouteView;
