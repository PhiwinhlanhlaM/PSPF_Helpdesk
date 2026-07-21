// Screen 5 - Supervisor approval
//
// The first step in the chain once the form is open to everyone: a supervisor
// confirms a request from their team is legitimate before the ICT team spends
// time on it. Approving releases it into the ICT queue; rejecting ends it with
// a recorded reason.

const { useState: useSupState } = React;

function SupervisorDashboard() {
  const { state, dispatch, me } = useApp();

  // Requests routed to me and still waiting. Anything already actioned moves
  // into "Recent decisions" below rather than vanishing.
  const pending = state.requests.filter(r => r.status === "awaiting-supervisor");
  const recent = state.requests
    .filter(r => r.approvals.some(a => a.role === "supervisor" && String(a.personId) === String(me.id)))
    .slice(0, 8);

  return (
    <div className="page slide-up">
      <div className="page-header">
        <div>
          <h1 className="page-title">Approvals</h1>
          <p className="page-subtitle">IT access requests from your team, awaiting your approval before they go to ICT.</p>
        </div>
      </div>

      <h2 className="section-title" style={{ marginBottom: 12 }}>Awaiting your approval · {pending.length}</h2>
      {pending.length === 0 ? (
        <div className="card empty">
          <Icon name="check-circle" size={28}/>
          <strong>Nothing pending</strong>
          <span>You're all caught up.</span>
        </div>
      ) : (
        <div className="dir-grid">
          {pending.map(r => (
            <SupervisorCard key={r.id} request={r}
              onOpen={() => dispatch({
                type: "set-route",
                route: { name: "supervisor-sign" },
                params: { requestId: r.id },
              })}/>
          ))}
        </div>
      )}

      {recent.length > 0 && (
        <>
          <h2 className="section-title" style={{ margin: "32px 0 12px" }}>Your recent decisions</h2>
          <div className="card" style={{ overflow: "hidden" }}>
            <table className="table">
              <thead><tr><th>Request</th><th>Employee</th><th>Status</th><th>Decided</th></tr></thead>
              <tbody>
                {recent.map(r => {
                  const meta = statusMeta(r.status);
                  const mine = r.approvals.filter(a => a.role === "supervisor").slice(-1)[0];
                  return (
                    <tr key={r.id}>
                      <td><span className="mono muted" style={{ fontSize: 12 }}>{r.id}</span></td>
                      <td><strong style={{ fontWeight: 550 }}>{r.employee.name}</strong> · <span className="muted">{r.employee.department}</span></td>
                      <td><span className={"badge " + meta.cls}>{meta.dot && <span className="dot"/>}{meta.label}</span></td>
                      <td className="muted" style={{ fontSize: 12.5 }}>{fmtDate(mine?.at)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}

function SupervisorCard({ request, onOpen }) {
  return (
    <div className="dir-card" onClick={onOpen}>
      <div className="row" style={{ justifyContent: "space-between", marginBottom: 12 }}>
        <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
        <span className="badge badge-amber"><span className="dot"/>Awaiting you</span>
      </div>
      <h3 style={{ fontFamily: "var(--font-display)", fontSize: 17, fontWeight: 600, margin: "0 0 4px" }}>
        {request.employee.name}
      </h3>
      <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>
        {request.employee.title} · {request.employee.department}
      </p>

      <div className="dir-card-systems">
        {request.systems.slice(0, 4).map(s => (
          <span key={s.id} className="badge badge-gray">
            {getSystem(s.id).name.split(" ")[0]}{s.role ? ` · ${s.role}` : ""}
          </span>
        ))}
        {request.systems.length > 4 && <span className="badge badge-gray">+{request.systems.length - 4}</span>}
      </div>

      <div className="dir-card-foot">
        <span className="muted" style={{ fontSize: 12 }}>from {submitterName(request)}</span>
        <button className="btn btn-primary btn-sm">Review <Icon name="chevron-right" size={12}/></button>
      </div>
    </div>
  );
}

function SupervisorSign({ requestId }) {
  const { state, dispatch, toast, me } = useApp();
  const request = state.requests.find(r => r.id === requestId);
  const [stage, setStage] = useSupState("review");   // review | sign | rejecting | submitting | done
  const [signature, setSignature] = useSupState(null);
  const [confirmed, setConfirmed] = useSupState(false);
  const [rejectReason, setRejectReason] = useSupState("");

  if (!request) {
    return (
      <div className="page"><div className="empty"><Icon name="alert" size={28}/><strong>Request not found</strong>
        <button className="btn btn-secondary" onClick={() => dispatch({ type: "set-route", route: { name: "supervisor-dashboard" } })}>Back</button></div></div>
    );
  }

  const back = () => dispatch({ type: "set-route", route: { name: "supervisor-dashboard" } });

  async function approve() {
    if (!confirmed) {
      toast({ kind: "error", title: "Confirmation required", body: "Tick the box to confirm this request is legitimate." });
      return;
    }
    if (!signature) {
      toast({ kind: "error", title: "Signature required", body: "Draw or upload your signature to approve." });
      return;
    }
    setStage("submitting");
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const res = await fetch("/pspf_crm/api/it_access/approve.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          request_db_id: request.db_id,
          action:        "approved",
          step_role:     "supervisor",
          signature:     signature,
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Could not record approval"); }
      // Re-fetch so the request moves out of the pending list with the real
      // server state rather than an optimistic guess.
      fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" })
        .then(r => r.ok ? r.json() : null)
        .then(data => { if (data && Array.isArray(data.requests)) dispatch({ type: "load-requests", requests: data.requests }); })
        .catch(() => {});
      toast({ title: "Approved", body: `${request.employee.name}'s request has gone to the ICT team.` });
      back();
    } catch (err) {
      toast({ kind: "error", title: "Could not approve", body: err.message });
      setStage("sign");
    }
  }

  async function reject() {
    if (rejectReason.trim().length < 10) return;
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const res = await fetch("/pspf_crm/api/it_access/approve.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          request_db_id: request.db_id,
          action:        "rejected",
          step_role:     "supervisor",
          reason:        rejectReason.trim(),
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Rejection failed"); }
      fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" })
        .then(r => r.ok ? r.json() : null)
        .then(data => { if (data && Array.isArray(data.requests)) dispatch({ type: "load-requests", requests: data.requests }); })
        .catch(() => {});
      toast({ kind: "error", title: "Request rejected" });
      back();
    } catch (err) {
      toast({ kind: "error", title: "Rejection error", body: err.message });
    }
  }

  return (
    <div className="page slide-up">
      <button className="btn btn-ghost btn-sm" onClick={back}>
        <Icon name="chevron-left" size={13}/> Back
      </button>

      <div className="page-header" style={{ marginTop: 12 }}>
        <div>
          <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
          <h1 className="page-title">Approve request: {request.employee.name}</h1>
          <p className="page-subtitle">Confirm this access is needed. Approving sends it to the ICT team to action.</p>
        </div>
      </div>

      <div className="dir-layout">
        <section className="card card-pad">
          <div className="row" style={{ justifyContent: "space-between", marginBottom: 16 }}>
            <h2 className="card-title" style={{ marginBottom: 0 }}>Request summary</h2>
            <span className="badge badge-amber"><span className="dot"/>Awaiting your approval</span>
          </div>

          <div className="dir-summary-grid">
            <div>
              <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Employee</span>
              <strong style={{ display: "block", fontSize: 14 }}>{request.employee.name}</strong>
              <span className="muted" style={{ fontSize: 12 }}>{request.employee.id}</span>
            </div>
            <div>
              <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Department</span>
              <strong style={{ display: "block", fontSize: 14 }}>
                {request.employee.department}{request.employee.division ? ` · ${request.employee.division}` : ""}
              </strong>
              <span className="muted" style={{ fontSize: 12 }}>{request.employee.title}</span>
            </div>
            <div>
              <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Start date</span>
              <strong style={{ display: "block", fontSize: 14 }}>{fmtDate(request.startDate)}</strong>
              <span className="muted" style={{ fontSize: 12 }}>requested by {submitterName(request)}</span>
            </div>
          </div>

          <span className="section-title" style={{ marginTop: 20 }}>Systems requested</span>
          <div className="col gap-2">
            {request.systems.map(s => {
              const sys = getSystem(s.id);
              return (
                <div key={s.id} className="sys-mini">
                  <div className="sys-mini-icon"><Icon name={sys.icon} size={14}/></div>
                  <div className="col" style={{ flex: 1, minWidth: 0 }}>
                    <strong style={{ fontSize: 13, fontWeight: 550 }}>{sys.name}</strong>
                    <span className="muted" style={{ fontSize: 12 }}>
                      {s.role}
                      {Array.isArray(s.subValues) && s.subValues.length
                        ? " · " + s.subValues.join(", ")
                        : (typeof s.subValues === "string" ? " · " + s.subValues : "")}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>

          <span className="section-title" style={{ marginTop: 20 }}>Justification</span>
          <p className="just-text">{request.justification}</p>
        </section>

        <aside className="col gap-4" style={{ position: "sticky", top: 84, alignSelf: "start" }}>
          {stage === "review" && (
            <section className="card card-pad">
              <h2 className="card-title">Your decision</h2>
              <p className="card-subtitle">Approve to send this to ICT, or reject with a reason.</p>
              <button className="btn btn-success btn-lg" style={{ width: "100%", justifyContent: "center" }}
                onClick={() => setStage("sign")}>
                <Icon name="check" size={16}/> Approve
              </button>
              <button className="btn btn-danger" style={{ width: "100%", justifyContent: "center", marginTop: 10 }}
                onClick={() => setStage("rejecting")}>
                Reject request
              </button>
            </section>
          )}

          {(stage === "sign" || stage === "submitting") && (
            <section className="card card-pad slide-up">
              <h2 className="card-title">Sign to approve</h2>
              <p className="card-subtitle">Your signature is recorded on the request.</p>
              <SignaturePad onChange={setSignature}/>
              <label className="confirm-row">
                <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)}/>
                <span>I confirm this employee needs the access listed to do their job.</span>
              </label>
              <div className="row gap-2" style={{ justifyContent: "flex-end", marginTop: 16 }}>
                <button className="btn btn-secondary" onClick={() => setStage("review")} disabled={stage === "submitting"}>Back</button>
                <button className="btn btn-primary" disabled={stage === "submitting"} onClick={approve}>
                  {stage === "submitting" ? <><span className="spin"/> Approving…</> : <><Icon name="check" size={14}/> Approve &amp; send to ICT</>}
                </button>
              </div>
            </section>
          )}

          {stage === "rejecting" && (
            <section className="card card-pad slide-up">
              <h2 className="card-title" style={{ color: "var(--red-700)" }}>Reject request</h2>
              <Field label="Reason" required help="Minimum 10 characters — the requester will see this">
                <textarea className="textarea" rows={5} value={rejectReason}
                  onChange={e => setRejectReason(e.target.value)}/>
              </Field>
              <div className="row gap-2" style={{ justifyContent: "flex-end", marginTop: 16 }}>
                <button className="btn btn-secondary" onClick={() => setStage("review")}>Cancel</button>
                <button className="btn" disabled={rejectReason.trim().length < 10} onClick={reject}
                  style={{ background: "var(--red-600)", color: "white" }}>Confirm rejection</button>
              </div>
            </section>
          )}
        </aside>
      </div>
    </div>
  );
}

window.SupervisorDashboard = SupervisorDashboard;
window.SupervisorSign = SupervisorSign;
