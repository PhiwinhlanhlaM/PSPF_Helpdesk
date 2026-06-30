// Screen 4 - Director final action

function DirectorDashboard() {
  const { state, dispatch } = useApp();
  const pending = state.requests.filter(r => r.status === "awaiting-director");
  const recent = state.requests.filter(r => r.status === "provisioned" || r.status === "rejected").slice(0, 5);

  return (
    <div className="page slide-up">
      <div className="page-header">
        <div>
          <h1 className="page-title">Director actions</h1>
          <p className="page-subtitle">Final sign-off on access requests fully reviewed by IT.</p>
        </div>
      </div>

      <h2 className="section-title" style={{ marginBottom: 12 }}>Awaiting your action · {pending.length}</h2>
      {pending.length === 0 ? (
        <div className="card empty"><Icon name="check-circle" size={28}/><strong>Nothing pending</strong><span>You're all caught up.</span></div>
      ) : (
        <div className="dir-grid">
          {pending.map(r => <DirectorCard key={r.id} request={r}
            onOpen={() => dispatch({ type: "set-route", route: { name: "director-sign" }, params: { requestId: r.id } })}/>)}
        </div>
      )}

      <h2 className="section-title" style={{ margin: "32px 0 12px" }}>Recent decisions</h2>
      <div className="card" style={{ overflow: "hidden" }}>
        <table className="table">
          <thead><tr><th>Request</th><th>Employee</th><th>Status</th><th>Decided</th><th style={{ textAlign: "right" }}>Document</th></tr></thead>
          <tbody>
            {recent.map(r => {
              const meta = statusMeta(r.status);
              return (
                <tr key={r.id}>
                  <td><span className="mono muted" style={{ fontSize: 12 }}>{r.id}</span></td>
                  <td><strong style={{ fontWeight: 550 }}>{r.employee.name}</strong> · <span className="muted">{r.employee.department}</span></td>
                  <td><span className={"badge " + meta.cls}>{meta.dot && <span className="dot"/>}{meta.label}</span></td>
                  <td className="muted" style={{ fontSize: 12.5 }}>{fmtDate(r.provisionedAt || r.approvals.slice(-1)[0]?.at)}</td>
                  <td style={{ textAlign: "right" }}>
                    {r.status === "provisioned" && (
                      <a className="btn btn-secondary btn-sm"
                         href={"/pspf_crm/api/it_access/download_pdf.php?id=" + r.db_id}
                         target="_blank" rel="noreferrer">
                        <Icon name="download" size={12}/> PDF
                      </a>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function DirectorCard({ request, onOpen }) {
  return (
    <div className="dir-card" onClick={onOpen}>
      <div className="row" style={{ justifyContent: "space-between", marginBottom: 12 }}>
        <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
        <span className="badge badge-amber"><span className="dot"/>Awaiting director</span>
      </div>
      <h3 style={{ fontFamily: "var(--font-display)", fontSize: 17, fontWeight: 600, margin: "0 0 4px" }}>{request.employee.name}</h3>
      <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>{request.employee.title} · {request.employee.department}</p>

      <div className="dir-card-systems">
        {request.systems.slice(0, 4).map(s => (
          <span key={s.id} className="badge badge-gray">{getSystem(s.id).name.split(" ")[0]} · {s.role}</span>
        ))}
        {request.systems.length > 4 && <span className="badge badge-gray">+{request.systems.length - 4}</span>}
      </div>

      <div className="dir-card-foot">
        <div className="row gap-1">
          {chainSteps(request).slice(0, 3).map((s, i) => (
            <div key={i} className="chain-dot done" style={{ width: 18, height: 18 }}><Icon name="check" size={9} stroke={2.8}/></div>
          ))}
          <div className="chain-dot pending" style={{ width: 18, height: 18 }}><Icon name="clock" size={9}/></div>
        </div>
        <button className="btn btn-primary btn-sm">Review & sign <Icon name="chevron-right" size={12}/></button>
      </div>
    </div>
  );
}

function DirectorSign({ requestId }) {
  const { state, dispatch, toast, me } = useApp();
  const request = state.requests.find(r => r.id === requestId);
  const [stage, setStage] = useState("review"); // review | sign | rejecting | done | submitting
  const [signature, setSignature] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [signedAt, setSignedAt] = useState(null);
  const [proof, setProof] = useState(null);
  const [rejectReason, setRejectReason] = useState("");

  if (!request) {
    return (
      <div className="page"><div className="empty"><Icon name="alert" size={28}/><strong>Request not found</strong>
        <button className="btn btn-secondary" onClick={() => dispatch({ type: "set-route", route: { name: "director-dashboard" } })}>Back</button></div></div>
    );
  }

  async function approve() {
    if (!confirmed) {
      toast({ kind: "error", title: "Authorization required", body: "Tick the box to authorize provisioning." });
      return;
    }
    if (!signature) {
      toast({ kind: "error", title: "Signature required", body: "Draw or upload your signature to finalize." });
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
          step_role:     "director",
          signature:     signature,
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Could not record action"); }
      const at = new Date().toISOString();
      const p  = Array.from({ length: 16 }, () => "0123456789abcdef"[Math.floor(Math.random() * 16)]).join("");
      dispatch({ type: "approve-request", id: request.id, personId: me.id, role: "director", signature });
      // Re-fetch all requests so pdfFilename and final status are up to date
      fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" })
        .then(r => r.ok ? r.json() : null)
        .then(data => { if (data && Array.isArray(data.requests)) dispatch({ type: "load-requests", requests: data.requests }); })
        .catch(() => {});
      setSignedAt(at); setProof(p); setStage("done");
      toast({ title: "Final action recorded", body: `Provisioning ${request.employee.name}'s access now.` });
    } catch (err) {
      toast({ kind: "error", title: "Could not record action", body: err.message });
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
          step_role:     "director",
          reason:        rejectReason.trim(),
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Rejection failed"); }
      dispatch({ type: "reject-request", id: request.id, personId: me.id, role: "director", reason: rejectReason.trim() });
      toast({ kind: "error", title: "Request rejected" });
      dispatch({ type: "set-route", route: { name: "director-dashboard" } });
    } catch (err) {
      toast({ kind: "error", title: "Rejection error", body: err.message });
    }
  }

  if (stage === "done") {
    return <SignedSuccess request={request} signedAt={signedAt} proof={proof} role="director" onContinue={() => dispatch({ type: "set-route", route: { name: "director-dashboard" } })}/>;
  }

  return (
    <div className="page slide-up">
      <button className="btn btn-ghost btn-sm" onClick={() => dispatch({ type: "set-route", route: { name: "director-dashboard" } })}>
        <Icon name="chevron-left" size={13}/> Back
      </button>

      <div className="page-header" style={{ marginTop: 12 }}>
        <div>
          <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
          <h1 className="page-title">Director action: {request.employee.name}</h1>
          <p className="page-subtitle">All IT officer actions are complete. Your signature is the final step before provisioning.</p>
        </div>
      </div>

      <div className="dir-layout">
        <section className="card card-pad">
          <div className="row" style={{ justifyContent: "space-between", marginBottom: 16 }}>
            <h2 className="card-title" style={{ marginBottom: 0 }}>Request summary</h2>
            <span className="badge badge-amber"><span className="dot"/>Awaiting director</span>
          </div>

          <div className="dir-summary-grid">
            <div><span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Employee</span>
              <strong style={{ display: "block", fontSize: 14 }}>{request.employee.name}</strong>
              <span className="muted" style={{ fontSize: 12 }}>{request.employee.id}</span></div>
            <div><span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Department</span>
              <strong style={{ display: "block", fontSize: 14 }}>{request.employee.department}{request.employee.division ? ` · ${request.employee.division}` : ""}</strong>
              <span className="muted" style={{ fontSize: 12 }}>{request.employee.title}</span></div>
            <div><span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Start date</span>
              <strong style={{ display: "block", fontSize: 14 }}>{fmtDate(request.startDate)}</strong>
              <span className="muted" style={{ fontSize: 12 }}>requested by {submitterName(request)}</span></div>
          </div>

          <span className="section-title" style={{ marginTop: 20 }}>Actioned systems</span>
          <div className="col gap-2">
            {request.systems.map(s => {
              const sys = getSystem(s.id);
              return (
                <div key={s.id} className="sys-mini" style={{ background: "var(--green-100)" }}>
                  <Icon name="check" size={14} stroke={2.6} style={{ color: "var(--green-700)", flexShrink: 0 }}/>
                  <div className="sys-mini-icon" style={{ background: "var(--white)" }}><Icon name={sys.icon} size={14}/></div>
                  <div className="col" style={{ flex: 1, minWidth: 0 }}>
                    <strong style={{ fontSize: 13, fontWeight: 550 }}>{sys.name}</strong>
                    <span className="muted" style={{ fontSize: 12 }}>
                      {s.role}{Array.isArray(s.subValues) && s.subValues.length ? " · " + s.subValues.join(", ") : (typeof s.subValues === "string" ? " · " + s.subValues : "")}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>

          <span className="section-title" style={{ marginTop: 20 }}>Justification</span>
          <p className="just-text">{request.justification}</p>

          <span className="section-title" style={{ marginTop: 20 }}>Action chain</span>
          <ApprovalTimeline request={request}/>
        </section>

        <aside className="col gap-4" style={{ position: "sticky", top: 84, alignSelf: "start" }}>
          {stage === "review" && (
            <section className="card card-pad">
              <h2 className="card-title">Final decision</h2>
              <p className="card-subtitle">Action to provision access immediately, or reject with a reason.</p>
              <button className="btn btn-success btn-lg" style={{ width: "100%", justifyContent: "center" }} onClick={() => setStage("sign")}>
                <Icon name="shield-check" size={16}/> Action
              </button>
              <button className="btn btn-danger" style={{ width: "100%", justifyContent: "center", marginTop: 10 }} onClick={() => setStage("rejecting")}>
                Reject request
              </button>
            </section>
          )}

          {stage === "sign" || stage === "submitting" ? (
            <section className="card card-pad slide-up">
              <h2 className="card-title">Your signature is required</h2>
              <p className="card-subtitle">This finalizes the request and triggers AD provisioning.</p>
              <SignaturePad onChange={setSignature}/>
              <label className="confirm-row">
                <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)}/>
                <span>I authorize provisioning of the access listed above.</span>
              </label>
              <div className="row gap-2" style={{ justifyContent: "flex-end", marginTop: 16 }}>
                <button className="btn btn-secondary" onClick={() => setStage("review")} disabled={stage === "submitting"}>Back</button>
                <button className="btn btn-primary" disabled={stage === "submitting"} onClick={approve}>
                  {stage === "submitting" ? <><span className="spin"/> Finalizing...</> : <><Icon name="shield-check" size={14}/> Sign &amp; finalize</>}
                </button>
              </div>
            </section>
          ) : null}

          {stage === "rejecting" && (
            <section className="card card-pad slide-up">
              <h2 className="card-title" style={{ color: "var(--red-700)" }}>Reject request</h2>
              <Field label="Reason" required help="Minimum 10 characters">
                <textarea className="textarea" rows={5} value={rejectReason} onChange={e => setRejectReason(e.target.value)}/>
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

window.DirectorDashboard = DirectorDashboard;
window.DirectorSign = DirectorSign;
