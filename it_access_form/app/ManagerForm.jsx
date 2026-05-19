// Screen 1 — Admin: Access Request Form

function ManagerForm() {
  const { state, dispatch, toast, me, departments } = useApp();
  const [form, setForm] = useState(() => ({
    requestType: "new",        // "new" | "change"
    employeeName: "",
    employeeId: "",
    department: "",
    division: "",
    title: "",
    justification: "",
    startDate: "",
    startDateInput: "",
    systems: {}, // { [systemId]: { role?, subValues, subTexts } }
  }));
  const [errors, setErrors] = useState({});
  const [submitted, setSubmitted] = useState(null);
  const [managerSignature, setManagerSignature] = useState(null);
  const [confirmedAuth, setConfirmedAuth] = useState(false);
  const [isSending, setIsSending] = useState(false);

  function set(k, v) {
    // Changing department resets division
    const extra = k === "department" ? { division: "" } : {};
    setForm(f => ({ ...f, [k]: v, ...extra }));
    if (errors[k]) setErrors(e => ({ ...e, [k]: null }));
  }

  // Divisions available for the currently selected department
  const activeDivisions = React.useMemo(() => {
    if (!form.department) return [];
    const dept = departments.find(d => d.name === form.department);
    return dept ? dept.divisions : [];
  }, [departments, form.department]);

  function toggleSystem(sysId) {
    setForm(f => {
      const next = { ...f.systems };
      if (next[sysId]) {
        delete next[sysId];
      } else {
        const sys = getSystem(sysId);
        const subValues = {};
        const subTexts = {};
        // initialise sub-option defaults
        if (sys.subOptions) {
          const opts = Array.isArray(sys.subOptions) ? sys.subOptions : [sys.subOptions];
          opts.forEach((so, i) => {
            const key = `sub_${i}`;
            if (so.text) subTexts[key] = "";
            else if (so.multi) subValues[key] = [];
            else subValues[key] = so.options[0];
          });
        }
        next[sysId] = {
          role: sys.roles ? (sys.multiRole ? [] : sys.roles[0]) : null,
          subValues,
          subTexts,
        };
      }
      return { ...f, systems: next };
    });
  }

  function setSystemRole(sysId, role) {
    setForm(f => ({ ...f, systems: { ...f.systems, [sysId]: { ...f.systems[sysId], role } } }));
  }

  function toggleSystemRoleMulti(sysId, role) {
    setForm(f => {
      const cur = f.systems[sysId].role || [];
      const next = cur.includes(role) ? cur.filter(x => x !== role) : [...cur, role];
      return { ...f, systems: { ...f.systems, [sysId]: { ...f.systems[sysId], role: next } } };
    });
  }

  function setSubValue(sysId, key, val) {
    setForm(f => ({
      ...f,
      systems: {
        ...f.systems,
        [sysId]: {
          ...f.systems[sysId],
          subValues: { ...f.systems[sysId].subValues, [key]: val },
        },
      },
    }));
  }

  function toggleSubMulti(sysId, key, opt) {
    setForm(f => {
      const cur = f.systems[sysId].subValues[key] || [];
      const next = cur.includes(opt) ? cur.filter(x => x !== opt) : [...cur, opt];
      return {
        ...f,
        systems: {
          ...f.systems,
          [sysId]: {
            ...f.systems[sysId],
            subValues: { ...f.systems[sysId].subValues, [key]: next },
          },
        },
      };
    });
  }

  function setSubText(sysId, key, val) {
    setForm(f => ({
      ...f,
      systems: {
        ...f.systems,
        [sysId]: {
          ...f.systems[sysId],
          subTexts: { ...f.systems[sysId].subTexts, [key]: val },
        },
      },
    }));
  }

  function validate() {
    const e = {};
    if (!form.employeeName.trim()) e.employeeName = "Required";
    if (!form.department) e.department = "Required";
    if (!form.title.trim()) e.title = "Required";
    if (!form.startDate) e.startDate = "Required — use DD/MM/YYYY";
    if (Object.keys(form.systems).length === 0) e.systems = "Select at least one system";
    if (!form.justification.trim() || form.justification.trim().length < 10)
      e.justification = "Please provide a clear reason (10+ characters)";
    if (!managerSignature) e.signature = "Please sign before submitting";
    if (!confirmedAuth) e.confirmedAuth = "Please tick the declaration checkbox";
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  function submit(ev) {
    ev.preventDefault();
    if (!validate()) {
      if (!managerSignature || !confirmedAuth) {
        document.getElementById("manager-sign-card")?.scrollIntoView?.({ behavior: "smooth", block: "center" });
      }
      toast({ kind: "error", title: "Please review the form", body: "Some required fields are missing." });
      return;
    }
    setIsSending(true);
    (async () => {
      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
        const payload = {
          requestType: form.requestType,
          employee: {
            name:       form.employeeName.trim(),
            id:         form.employeeId.trim(),
            department: form.department,
            division:   form.division,
            title:      form.title.trim(),
          },
          systems: Object.entries(form.systems).map(([sysId, v]) => ({
            id: sysId, role: v.role, subValues: v.subValues, subTexts: v.subTexts,
          })),
          justification: form.justification.trim(),
          startDate:     form.startDate,
          approvals: [{
            role: "manager", personId: me.id,
            at: new Date().toISOString(), action: "approved",
            signature: managerSignature,
          }],
        };

        const res = await fetch("/pspf_crm/api/it_access/submit.php", {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
          body: JSON.stringify(payload),
        });

        if (!res.ok) {
          const err = await res.json().catch(() => ({}));
          throw new Error(err.error || "Server error");
        }
        const data = await res.json();

        const request = {
          id:            data.ref,
          db_id:         data.id,
          requestType:   form.requestType,
          employee:      payload.employee,
          systems:       payload.systems,
          justification: payload.justification,
          startDate:     payload.startDate,
          submittedBy:   me.id,
          submittedAt:   new Date().toISOString(),
          approvals:     payload.approvals,
          status:        "new",
          claimedBy:     null,
        };
        dispatch({ type: "submit-request", request });
        setSubmitted(request);
        toast({ kind: "success", title: "Request submitted", body: `Reference ${data.ref} · IT will review shortly.` });
      } catch (err) {
        toast({ kind: "error", title: "Submission failed", body: err.message });
      } finally {
        setIsSending(false);
      }
    })();
  }

  function startNew() {
    setSubmitted(null);
    setForm({ requestType: "new", employeeName: "", employeeId: "", department: "", division: "", title: "", justification: "", startDate: "", startDateInput: "", systems: {} });
    setErrors({});
    setManagerSignature(null);
    setConfirmedAuth(false);
  }

  if (submitted) return <SubmittedScreen request={submitted} onNew={startNew}/>;

  const selectedCount = Object.keys(form.systems).length;

  return (
    <div className="page slide-up">
      <div className="page-header">
        <div>
          <h1 className="page-title">IT Access Request</h1>
          <p className="page-subtitle">Submit on behalf of an employee. Approvals route automatically to IT and the Director.</p>
        </div>
        <span className="badge badge-gray"><span className="dot"/>Draft</span>
      </div>

      <form className="form-grid" onSubmit={submit} noValidate>
        <div className="form-main">

          {/* Request Type */}
          <section className="card card-pad">
            <h2 className="card-title">Request type</h2>
            <p className="card-subtitle">Is this for a new employee or an access change for an existing employee?</p>
            <div className="seg" style={{ maxWidth: 360 }}>
              <button type="button"
                className={"seg-opt " + (form.requestType === "new" ? "active" : "")}
                onClick={() => set("requestType", "new")}>
                New employee access
              </button>
              <button type="button"
                className={"seg-opt " + (form.requestType === "change" ? "active" : "")}
                onClick={() => set("requestType", "change")}>
                Change of access
              </button>
            </div>
            {form.requestType === "change" && (
              <p className="help" style={{ marginTop: 8 }}>
                For promotions or role changes — specify the new access level required below.
              </p>
            )}
          </section>

          {/* Employee details */}
          <section className="card card-pad">
            <h2 className="card-title">Employee details</h2>
            <p className="card-subtitle">Who is this access for?</p>
            <div className="grid-2">

              <Field label="Full name" required error={errors.employeeName}>
                <input className={"input" + (errors.employeeName ? " input-error" : "")}
                  placeholder="e.g. Jane Smith"
                  value={form.employeeName} onChange={e => set("employeeName", e.target.value)}/>
              </Field>

              <Field label="Employee ID" help="Staff number or payroll ID (optional)">
                <input className="input" placeholder="e.g. EMP-0042"
                  value={form.employeeId} onChange={e => set("employeeId", e.target.value)}/>
              </Field>

              <Field label="Department" required error={errors.department}>
                <select className={"select" + (errors.department ? " input-error" : "")}
                  value={form.department} onChange={e => set("department", e.target.value)}>
                  <option value="">Select department…</option>
                  {departments.map(d => <option key={d.name}>{d.name}</option>)}
                </select>
              </Field>

              <Field label="Division" help={activeDivisions.length === 0 && form.department ? "No divisions for this department" : ""}>
                <select className="select" value={form.division}
                  onChange={e => set("division", e.target.value)}
                  disabled={activeDivisions.length === 0}>
                  <option value="">{activeDivisions.length === 0 ? "—" : "Select division…"}</option>
                  {activeDivisions.map(v => <option key={v.id} value={v.name}>{v.name}</option>)}
                </select>
              </Field>

              <Field label="Job title" required error={errors.title}>
                <input className={"input" + (errors.title ? " input-error" : "")}
                  placeholder="e.g. Senior Accountant"
                  value={form.title} onChange={e => set("title", e.target.value)}/>
              </Field>

              <Field label="Access start date" required error={errors.startDate}
                help="When access should be active from">
                <input type="text" inputMode="numeric" maxLength={10}
                  className={"input" + (errors.startDate ? " input-error" : "")}
                  placeholder="DD/MM/YYYY"
                  value={form.startDateInput || ""}
                  onChange={e => {
                    const digits = e.target.value.replace(/\D/g, "").slice(0, 8);
                    let formatted = digits;
                    if (digits.length > 4)      formatted = `${digits.slice(0,2)}/${digits.slice(2,4)}/${digits.slice(4)}`;
                    else if (digits.length > 2) formatted = `${digits.slice(0,2)}/${digits.slice(2)}`;
                    setForm(f => {
                      const next = { ...f, startDateInput: formatted };
                      if (formatted.length === 10) {
                        const [dd, mm, yyyy] = formatted.split("/");
                        next.startDate = `${yyyy}-${mm}-${dd}`;
                      } else {
                        next.startDate = "";
                      }
                      return next;
                    });
                    if (errors.startDate) setErrors(er => ({ ...er, startDate: null }));
                  }}/>
              </Field>

              <div style={{ gridColumn: "span 2" }}>
                <Field label="Submitted by" help="Synced from your CRM login">
                  <div className="readonly-row hd-row">
                    <span className="me-avatar" style={{ width: 28, height: 28, fontSize: 11 }}>{me.initials}</span>
                    <div className="col" style={{ minWidth: 0, flex: 1 }}>
                      <span style={{ fontWeight: 600, fontSize: 13 }}>{me.name}</span>
                      <span className="muted" style={{ fontSize: 11.5 }}>{me.email}</span>
                    </div>
                    <span className="hd-badge"><Icon name="shield-check" size={11}/> Verified</span>
                  </div>
                </Field>
              </div>

            </div>
          </section>

          {/* Systems */}
          <section className="card card-pad">
            <div className="row" style={{ justifyContent: "space-between", alignItems: "flex-start" }}>
              <div>
                <h2 className="card-title">Systems & access</h2>
                <p className="card-subtitle">Select each system this employee requires. Configure the access level inline.</p>
              </div>
              <span className="badge badge-gray">{selectedCount} selected</span>
            </div>
            {errors.systems && <div className="error-text" style={{ marginTop: 6 }}>{errors.systems}</div>}

            <div className="systems-list">
              {SYSTEM_CATALOG.map(sys => {
                const sel = form.systems[sys.id];
                const subOptsList = sys.subOptions
                  ? (Array.isArray(sys.subOptions) ? sys.subOptions : [sys.subOptions])
                  : [];
                return (
                  <div key={sys.id} className={"system-row " + (sel ? "selected" : "")}>
                    <button type="button" className="system-row-head" onClick={() => toggleSystem(sys.id)}>
                      <div className={"system-check " + (sel ? "checked" : "")}>
                        {sel && <Icon name="check" size={12} stroke={2.4}/>}
                      </div>
                      <div className="system-icon"><Icon name={sys.icon} size={16}/></div>
                      <div className="system-meta">
                        <strong>{sys.name}</strong>
                        <span className="muted">{sys.desc}</span>
                      </div>
                      {sel && sel.role && (Array.isArray(sel.role) ? sel.role.length > 0 : sel.role) && (
                        <span className="badge badge-blue" style={{ marginLeft: "auto" }}>
                          {Array.isArray(sel.role) ? sel.role.join(", ") : sel.role}
                        </span>
                      )}
                    </button>

                    {sel && (
                      <div className="system-row-config slide-up">
                        <div className="config-grid">
                          {/* Role selector — only systems with roles[] */}
                          {sys.roles && (
                            <div className="field">
                              <label className="label">Role{sys.multiRole ? " (select all that apply)" : ""}</label>
                              {sys.multiRole ? (
                                <div className="chip-group">
                                  {sys.roles.map(r => {
                                    const on = Array.isArray(sel.role) && sel.role.includes(r);
                                    return (
                                      <button key={r} type="button"
                                        className={"chip " + (on ? "active" : "")}
                                        onClick={() => toggleSystemRoleMulti(sys.id, r)}>
                                        {on && <Icon name="check" size={11} stroke={2.4}/>}
                                        {r}
                                      </button>
                                    );
                                  })}
                                </div>
                              ) : (
                                <div className="seg">
                                  {sys.roles.map(r => (
                                    <button key={r} type="button"
                                      className={"seg-opt " + (sel.role === r ? "active" : "")}
                                      onClick={() => setSystemRole(sys.id, r)}>
                                      {r}
                                    </button>
                                  ))}
                                </div>
                              )}
                            </div>
                          )}

                          {/* Sub-options */}
                          {subOptsList.map((so, i) => {
                            const key = `sub_${i}`;
                            if (so.text) {
                              return (
                                <div className="field" key={key}>
                                  <label className="label">{so.label}</label>
                                  <input className="input"
                                    placeholder={`Enter ${so.label.toLowerCase()}…`}
                                    value={sel.subTexts[key] || ""}
                                    onChange={e => setSubText(sys.id, key, e.target.value)}/>
                                </div>
                              );
                            }
                            if (so.multi) {
                              return (
                                <div className="field" key={key}>
                                  <label className="label">{so.label}</label>
                                  <div className="chip-group">
                                    {so.options.map(opt => {
                                      const on = (sel.subValues[key] || []).includes(opt);
                                      return (
                                        <button key={opt} type="button"
                                          className={"chip " + (on ? "active" : "")}
                                          onClick={() => toggleSubMulti(sys.id, key, opt)}>
                                          {on && <Icon name="check" size={11} stroke={2.4}/>}
                                          {opt}
                                        </button>
                                      );
                                    })}
                                  </div>
                                </div>
                              );
                            }
                            return (
                              <div className="field" key={key}>
                                <label className="label">{so.label}</label>
                                <div className="seg">
                                  {so.options.map(opt => (
                                    <button key={opt} type="button"
                                      className={"seg-opt " + (sel.subValues[key] === opt ? "active" : "")}
                                      onClick={() => setSubValue(sys.id, key, opt)}>
                                      {opt}
                                    </button>
                                  ))}
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </section>

          {/* Justification */}
          <section className="card card-pad">
            <h2 className="card-title">Justification</h2>
            <p className="card-subtitle">
              {form.requestType === "change"
                ? "Explain why the access change is required (e.g. promotion, role transfer)."
                : "Briefly explain why this employee needs the requested access."}
            </p>
            <Field error={errors.justification}>
              <textarea className={"textarea" + (errors.justification ? " input-error" : "")}
                rows={4}
                placeholder={form.requestType === "change"
                  ? "e.g. Promoted to Senior Accountant — requires Authorizer level on INPENSIONS and Banking access…"
                  : "e.g. New hire replacing outgoing senior accountant; needs same access profile…"}
                value={form.justification}
                onChange={e => set("justification", e.target.value)}/>
              <div className="row" style={{ justifyContent: "space-between", marginTop: 4 }}>
                <span className="help">Visible to all approvers in the chain</span>
                <span className="help">{form.justification.length} chars</span>
              </div>
            </Field>
          </section>

          {/* Signature */}
          <section className="card card-pad sign-panel" id="manager-sign-card">
            <div className="row" style={{ justifyContent: "space-between", alignItems: "flex-start" }}>
              <div>
                <h2 className="card-title">Your signature</h2>
                <p className="card-subtitle">Sign to certify this access request is authorised and operationally needed.</p>
              </div>
              {managerSignature && <span className="badge badge-green"><Icon name="check" size={11} stroke={2.4}/> Signed</span>}
            </div>
            {errors.signature && <div className="error-text" style={{ marginBottom: 8 }}>{errors.signature}</div>}
            <SignaturePad onChange={sig => { setManagerSignature(sig); if (sig && errors.signature) setErrors(e => ({ ...e, signature: null })); }}/>
            <label className="confirm-row" style={errors.confirmedAuth ? { border: "1.5px solid var(--red-500,#ef4444)", borderRadius: 6, padding: "8px 10px", background: "var(--red-50,#fef2f2)" } : {}}>
              <input type="checkbox" checked={confirmedAuth} onChange={e => { setConfirmedAuth(e.target.checked); if (e.target.checked && errors.confirmedAuth) setErrors(er => ({ ...er, confirmedAuth: null })); }}/>
              <span>I certify the information above is accurate and that this employee requires the listed access to perform their duties.</span>
            </label>
            {errors.confirmedAuth && <div className="error-text" style={{ marginTop: 4 }}>{errors.confirmedAuth}</div>}
          </section>

          <div className="form-actions">
            <button type="button" className="btn btn-ghost" disabled={isSending}
              onClick={() => { setForm({ requestType: "new", employeeName: "", department: "", title: "", justification: "", startDate: "", startDateInput: "", systems: {} }); setErrors({}); setManagerSignature(null); setConfirmedAuth(false); }}>
              Clear form
            </button>
            <button type="submit" className="btn btn-primary btn-lg" disabled={isSending}>
              {isSending
                ? <><span className="spin"/> Submitting…</>
                : <><Icon name="lock" size={13}/> Sign &amp; submit <Icon name="chevron-right" size={14}/></>}
            </button>
          </div>
        </div>

        {/* Right column — review */}
        <aside className="form-aside">
          <div className="card card-pad" style={{ position: "sticky", top: 84 }}>
            <h2 className="card-title">Review</h2>
            <div className="review-row">
              <span className="review-key">Type</span>
              <span className="review-val">
                <span className={"badge " + (form.requestType === "change" ? "badge-amber" : "badge-blue")}>
                  {form.requestType === "change" ? "Change request" : "New access"}
                </span>
              </span>
            </div>
            <div className="review-row">
              <span className="review-key">Employee</span>
              <span className="review-val">{form.employeeName || <em className="faint">—</em>}</span>
            </div>
            <div className="review-row">
              <span className="review-key">Department</span>
              <span className="review-val">{form.department || <em className="faint">—</em>}</span>
            </div>
            <div className="review-row">
              <span className="review-key">Start date</span>
              <span className="review-val">{form.startDate ? fmtDate(form.startDate) : <em className="faint">—</em>}</span>
            </div>
            <div className="divider"/>
            <div className="review-row" style={{ alignItems: "flex-start" }}>
              <span className="review-key">Systems</span>
              <div className="col gap-1" style={{ alignItems: "flex-end", flex: 1 }}>
                {Object.keys(form.systems).length === 0 && <em className="faint">none selected</em>}
                {Object.entries(form.systems).map(([sysId, v]) => {
                  const sys = getSystem(sysId);
                  return (
                    <div key={sysId} className="review-sys">
                      <strong>{sys.name}</strong>
                      {v.role && <span className="muted">· {v.role}</span>}
                    </div>
                  );
                })}
              </div>
            </div>
            <div className="divider"/>
            <div className="review-row">
              <span className="review-key">Signature</span>
              <span className="review-val">
                {managerSignature
                  ? <span className="badge badge-green"><Icon name="check" size={11} stroke={2.4}/> Signed</span>
                  : <em className="faint">awaiting</em>}
              </span>
            </div>
          </div>
        </aside>
      </form>
    </div>
  );
}

function Field({ label, required, error, help, children }) {
  return (
    <div className="field">
      {label && <label className="label">{label}{required && <span className="req">*</span>}</label>}
      {children}
      {help && !error && <span className="help">{help}</span>}
      {error && <span className="error-text">{error}</span>}
    </div>
  );
}

function SubmittedScreen({ request, onNew }) {
  const { dispatch } = useApp();
  const isChange = request.requestType === "change";
  return (
    <div className="page slide-up">
      <div className="success-card scale-in">
        <div className="success-icon">
          <Icon name="check" size={28} stroke={2.4}/>
        </div>
        <h1 className="page-title" style={{ textAlign: "center" }}>
          {isChange ? "Change request submitted" : "Access request submitted"}
        </h1>
        <p className="page-subtitle" style={{ textAlign: "center", marginBottom: 20 }}>
          Your request has been sent to the IT team. They will provision the access and the Director will sign off the record.
        </p>

        <div className="success-meta">
          <div>
            <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Reference</span>
            <strong style={{ fontFamily: "var(--font-mono)", fontSize: 16, display: "block" }}>{request.id}</strong>
          </div>
          <div>
            <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Employee</span>
            <strong style={{ fontSize: 14, display: "block" }}>{request.employee.name}</strong>
          </div>
          <div>
            <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Submitted</span>
            <strong style={{ fontSize: 14, display: "block" }}>{fmtDateTime(request.submittedAt)}</strong>
          </div>
        </div>

        <div className="success-chain">
          <div className="chain-step done">
            <div className="chain-dot done"><Icon name="check" size={11} stroke={2.6}/></div>
            <div className="col"><strong>Admin approval</strong><span className="muted">Submitted just now</span></div>
          </div>
          <div className="chain-line"/>
          <div className="chain-step pending">
            <div className="chain-dot pending"><Icon name="clock" size={11}/></div>
            <div className="col"><strong>IT Officer provisions access</strong><span className="muted">Up next · typically within 4 hours</span></div>
          </div>
          <div className="chain-line"/>
          <div className="chain-step">
            <div className="chain-dot"><span>3</span></div>
            <div className="col"><strong>Director sign-off</strong><span className="muted">Authorisation record</span></div>
          </div>
        </div>

        <div className="row gap-3" style={{ justifyContent: "center", marginTop: 24 }}>
          <button className="btn btn-secondary" onClick={onNew}>Submit another request</button>
          <button className="btn btn-primary" onClick={() => dispatch({ type: "set-route", route: { name: "manager-history" } })}>
            View my requests <Icon name="chevron-right" size={14}/>
          </button>
        </div>
      </div>
    </div>
  );
}

window.ManagerForm = ManagerForm;
