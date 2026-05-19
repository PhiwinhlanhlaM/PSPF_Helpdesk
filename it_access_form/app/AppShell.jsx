// AppShell — top bar, role switcher, route view container, toast system.

const { createContext, useContext, useMemo, useReducer, useCallback, useState } = React;

// ---------- App state ----------
const AppCtx = createContext(null);
const useApp = () => useContext(AppCtx);

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
      const requests = state.requests.map(r => r.id === action.id
        ? { ...r, claimedBy: action.personId, actionedSystems: action.actionedSystems || r.systems.map(s => s.id), status: r.status === "new" ? "claimed" : r.status }
        : r);
      return { ...state, requests };
    }
    case "approve-request": {
      const { id, personId, role: stepRole, signature } = action;
      const requests = state.requests.map(r => {
        if (r.id !== id) return r;
        const approvals = [...r.approvals, { role: stepRole, personId, at: new Date().toISOString(), action: "approved", signature }];
        // Compute next status
        let status = r.status;
        if (stepRole === "officer-1") status = "awaiting-director";
        else if (stepRole === "officer-2") status = "awaiting-director";
        else if (stepRole === "director") status = "provisioned";
        const next = { ...r, approvals, status };
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
  };
}

function AppProvider({ children, initialRole }) {
  const [state, dispatch] = useReducer(appReducer, null, () => {
    const s = makeInitialState();
    if (initialRole) {
      s.role = initialRole;
      s.route = defaultRouteForRole(initialRole);
    }
    return s;
  });

  const [toasts, setToasts] = useState([]);
  const toast = useCallback((opts) => {
    const id = Math.random().toString(36).slice(2, 9);
    setToasts(t => [...t, { id, kind: "success", ...opts }]);
    setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), opts.duration || 4000);
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
        // API returns a plain array: [{ name, divisions: [{id, name}] }]
        const list = Array.isArray(data) ? data : (Array.isArray(data.departments) ? data.departments : []);
        if (list.length > 0) {
          dispatch({ type: "load-departments", departments: list });
        }
      })
      .catch(() => {}); // fallback to DEPARTMENTS constant when running standalone
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
  }), [state, toast, setRole]);

  return (
    <AppCtx.Provider value={value}>
      {children}
      <ToastStack toasts={toasts} onDismiss={(id) => setToasts(t => t.filter(x => x.id !== id))}/>
    </AppCtx.Provider>
  );
}

function defaultRouteForRole(role) {
  if (role === "manager")  return { name: "manager-form" };
  if (role === "officer")  return { name: "officer-dashboard" };
  if (role === "director") return { name: "director-dashboard" };
  return { name: "manager-form" };
}

function meForRole(role) {
  if (window.__CRM_USER__) return window.__CRM_USER__;
  if (role === "manager")  return PEOPLE.managers[0];
  if (role === "officer")  return PEOPLE.officers[1];
  if (role === "director") return PEOPLE.director;
  return PEOPLE.managers[0];
}

// Compute which roles the current CRM user can switch between.
// Rules:
//   - Everyone can be "manager" (Admin on the form)
//   - ICT department users also get "officer"
//   - it_director role also gets "director"
// The __CRM_USER__.crmRoles array is injected by index.php.
function getAvailableRoles() {
  const u = window.__CRM_USER__;
  if (!u) return [{ value: "manager", label: "Admin" }, { value: "officer", label: "IT Officer" }, { value: "director", label: "Director" }]; // standalone demo
  // Build the list from what the server says this user can do.
  // crmActiveRole drives the DEFAULT view (set via __REACT_INITIAL_ROLE__),
  // but the user can switch freely within the form without a page reload.
  const roles = [{ value: "manager", label: "Admin" }];
  const crmRoles = u.crmRoles || [];
  if ((u.department || "").toUpperCase() === "ICT") {
    roles.push({ value: "officer", label: "IT Officer" });
  }
  if (crmRoles.includes("it_director")) {
    roles.push({ value: "director", label: "Director" });
  }
  return roles;
}

// ---------- Notification panel ----------
function NotificationPanel({ onClose }) {
  const { state, me, dispatch } = useApp();
  const role = state.role;

  // Gather relevant notifications based on role
  const notifications = React.useMemo(() => {
    const items = [];
    for (const r of state.requests) {
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
      if (role === "director" && r.status === "awaiting-director")
        items.push({ id: r.id + "-ddir", kind: "amber", title: "Awaiting your sign-off", body: `${r.employee.name} · ${r.id}`, at: r.submittedAt, requestId: r.id, route: "director-sign" });
    }
    // Sort newest first, cap at 15
    return items.sort((a, b) => new Date(b.at) - new Date(a.at)).slice(0, 15);
  }, [state.requests, role, me.id]);

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
          <button className="btn btn-ghost btn-sm" onClick={onClose}><Icon name="x" size={14}/></button>
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
    : role === "officer"
    ? [
        { name: "officer-dashboard", label: "Dashboard" },
      ]
    : [
        { name: "director-dashboard", label: "Pending review" },
      ];

  // Unread count: anything actionable for the current role
  const unreadCount = React.useMemo(() => {
    let n = 0;
    for (const r of state.requests) {
      if (role === "officer" && r.status === "new") n++;
      if (role === "officer" && r.status === "claimed" && String(r.claimedBy) === String(me.id)) n++;
      if (role === "director" && r.status === "awaiting-director") n++;
      if (role === "manager" && String(r.submittedBy) === String(me.id) && (r.status === "provisioned" || r.status === "rejected")) n++;
    }
    return n;
  }, [state.requests, role, me.id]);

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
        <button className="bell" title="Notifications" onClick={() => setShowNotifs(v => !v)}
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
  // availableRoles is [{value, label}] from getAvailableRoles() — only roles the user actually has.
  // Don't render if there's only one role (no point switching).
  if (!availableRoles || availableRoles.length <= 1) return null;
  const hints = { manager: "Submit & track", officer: "Provision & sign", director: "Review & sign off" };
  return (
    <div className="role-switch" role="tablist" aria-label="Acting as">
      <span className="role-switch-label">Acting as</span>
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
