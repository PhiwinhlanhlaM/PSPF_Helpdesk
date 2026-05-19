// Screen 3 — IT Officer signing interface
// Used for officer-1 and officer-2 approvals.

function OfficerSign({ requestId }) {
  const { state, dispatch, toast, me } = useApp();
  const request = state.requests.find(r => r.id === requestId);
  const [signature, setSignature] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [mode, setMode] = useState("idle"); // idle | rejecting | submitting | done
  const [rejectReason, setRejectReason] = useState("");
  const [signedAt, setSignedAt] = useState(null);
  const [proof, setProof] = useState(null);

  if (!request) {
    return (
      <div className="page">
        <div className="empty">
          <Icon name="alert" size={28}/>
          <strong>Request not found</strong>
          <span>It may have been removed or already completed.</span>
          <button className="btn btn-secondary" style={{ marginTop: 12 }} onClick={() => dispatch({ type: "set-route", route: { name: "officer-dashboard" } })}>
            Back to dashboard
          </button>
        </div>
      </div>
    );
  }

  const next = nextStep(request);
  const stepRole = "officer-1"; // single officer sufficient
  const stepLabel = "IT Officer action";
  // Systems this officer chose to action (set during claim, or all systems as fallback)
  const actionedSystems = request.actionedSystems || request.systems.map(s => s.id);

  function generateProof() {
    // Pretend cryptographic proof — random hex
    return Array.from({ length: 16 }, () => "0123456789abcdef"[Math.floor(Math.random() * 16)]).join("");
  }

  async function approve() {
    if (!signature || !confirmed) return;
    setMode("submitting");
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const res = await fetch("/pspf_crm/api/it_access/approve.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          request_db_id:    request.db_id,
          action:           "approved",
          step_role:        stepRole,
          signature:        signature,
          actioned_systems: actionedSystems,
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Approval failed"); }
      const at = new Date().toISOString();
      const p  = generateProof();
      dispatch({ type: "approve-request", id: request.id, personId: me.id, role: stepRole, signature });
      fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" })
        .then(r => r.ok ? r.json() : null)
        .then(data => { if (data && Array.isArray(data.requests)) dispatch({ type: "load-requests", requests: data.requests }); })
        .catch(() => {});
      setSignedAt(at);
      setProof(p);
      setMode("done");
      toast({ title: "Approval recorded", body: `Signature accepted for ${request.employee.name}.` });
    } catch (err) {
      toast({ kind: "error", title: "Approval error", body: err.message });
      setMode("idle");
    }
  }

  async function reject() {
    if (!rejectReason.trim() || rejectReason.trim().length < 10) return;
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const res = await fetch("/pspf_crm/api/it_access/approve.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          request_db_id: request.db_id,
          action:        "rejected",
          step_role:     stepRole,
          reason:        rejectReason.trim(),
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Rejection failed"); }
      dispatch({ type: "reject-request", id: request.id, personId: me.id, role: stepRole, reason: rejectReason.trim() });
      toast({ kind: "error", title: "Request rejected", body: "Manager has been notified." });
      dispatch({ type: "set-route", route: { name: "officer-dashboard" } });
    } catch (err) {
      toast({ kind: "error", title: "Rejection error", body: err.message });
    }
  }

  if (mode === "done") return <SignedSuccess request={request} signedAt={signedAt} proof={proof} role="officer" onContinue={() => dispatch({ type: "set-route", route: { name: "officer-dashboard" } })}/>;

  return (
    <div className="page slide-up">
      <button className="btn btn-ghost btn-sm" onClick={() => dispatch({ type: "set-route", route: { name: "officer-dashboard" } })}>
        <Icon name="chevron-left" size={13}/> Back to dashboard
      </button>

      <div className="page-header" style={{ marginTop: 12 }}>
        <div>
          <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
          <h1 className="page-title">Approve access request — {request.employee.name}</h1>
          <p className="page-subtitle">
            {stepLabel} · you are actioning: <strong>{actionedSystems.map(id => { const s = getSystem(id); return s ? s.name : id; }).join(", ")}</strong>. Sign to confirm.
          </p>
        </div>
      </div>

      <div className="sign-grid">
        <div className="col gap-4">
          {/* Document preview */}
          <section className="card doc-preview">
            <div className="doc-watermark">REVIEW</div>
            <div className="doc-head">
              <div className="row gap-3">
                <img src="/pspf_crm/it_access_form/assets/pspf-logo.png" alt="" style={{ width: 36, height: 36 }}/>
                <div className="col">
                  <strong style={{ fontSize: 12, color: "var(--pspf-800)", letterSpacing: "0.04em", textTransform: "uppercase" }}>Public Service Pensions Fund</strong>
                  <span className="muted" style={{ fontSize: 12 }}>IT System Access Authorization</span>
                </div>
              </div>
              <span className="mono muted" style={{ fontSize: 11.5 }}>{request.id}</span>
            </div>

            <h3 style={{ fontFamily: "var(--font-display)", fontWeight: 600, margin: "16px 0 4px", fontSize: 18 }}>{request.employee.name}</h3>
            <p className="muted" style={{ fontSize: 13, margin: 0 }}>{request.employee.title} · {request.employee.department}{request.employee.division ? ` · ${request.employee.division}` : ""} · {request.employee.id}</p>

            <div className="divider"/>

            <span className="section-title">Systems & roles</span>
            <div className="col gap-2">
              {request.systems.map(s => {
                const sys = getSystem(s.id);
                return (
                  <div key={s.id} className="sys-mini">
                    <div className="sys-mini-icon"><Icon name={sys.icon} size={14}/></div>
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

            <div className="divider"/>

            <span className="section-title">Justification</span>
            <p className="just-text">{request.justification}</p>

            <div className="divider"/>

            <span className="section-title">Approval chain</span>
            <ApprovalTimeline request={request}/>
          </section>
        </div>

        {/* Signature column */}
        <aside className="col gap-4">
          {mode !== "rejecting" && (
            <section className="card card-pad sign-panel">
              <h2 className="card-title">Your signature</h2>
              <p className="card-subtitle">Draw with your mouse, trackpad, or touch — or upload an image of your signature.</p>

              <SignaturePad onChange={setSignature}/>

              <label className="confirm-row">
                <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)}/>
                <span>I confirm I have reviewed this request and approve the access listed above.</span>
              </label>

              <div className="sign-actions">
                <button className="btn btn-danger" onClick={() => setMode("rejecting")} disabled={mode === "submitting"}>
                  Reject
                </button>
                <div className="row gap-2">
                  <button className="btn btn-secondary" onClick={() => dispatch({ type: "set-route", route: { name: "officer-dashboard" } })}>
                    Cancel
                  </button>
                  <button className="btn btn-primary" disabled={!signature || !confirmed || mode === "submitting"} onClick={approve}>
                    {mode === "submitting" ? <><span className="spin"/> Submitting…</> : <><Icon name="shield-check" size={14}/> Sign & approve</>}
                  </button>
                </div>
              </div>
            </section>
          )}

          {mode === "rejecting" && (
            <section className="card card-pad slide-up">
              <h2 className="card-title" style={{ color: "var(--red-700)" }}>Reject this request</h2>
              <p className="card-subtitle">The manager will be notified with your reason. They can revise and resubmit.</p>
              <Field label="Reason for rejection" required help="Be specific — minimum 10 characters">
                <textarea className="textarea" rows={5}
                  placeholder="e.g. Security clearance not verified — Level-2 background check required before banking authorizer access can be granted."
                  value={rejectReason}
                  onChange={e => setRejectReason(e.target.value)}/>
              </Field>
              <div className="row gap-2" style={{ justifyContent: "flex-end", marginTop: 16 }}>
                <button className="btn btn-secondary" onClick={() => setMode("idle")}>Cancel</button>
                <button className="btn btn-danger" disabled={rejectReason.trim().length < 10} onClick={reject} style={{ background: "var(--red-600)", color: "white", borderColor: "var(--red-600)" }}>
                  Confirm rejection
                </button>
              </div>
            </section>
          )}

          <div className="card card-pad info-card">
            <Icon name="info" size={16}/>
            <div className="col">
              <strong style={{ fontSize: 13 }}>Compliance note</strong>
              <span className="muted" style={{ fontSize: 12.5, lineHeight: 1.5 }}>
                By signing, you confirm that the listed access has been granted. Your signature is recorded against this request as proof of provisioning.
              </span>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}

function SignedSuccess({ request, signedAt, proof, role, onContinue }) {
  const isDirector = role === "director";
  return (
    <div className="page slide-up">
      <div className="success-card scale-in" style={{ maxWidth: 720 }}>
        <div className="success-icon">
          <Icon name="check" size={28} stroke={2.4}/>
        </div>
        <h1 className="page-title" style={{ textAlign: "center" }}>
          {isDirector ? "Request approved & provisioned" : "Signature accepted"}
        </h1>
        <p className="page-subtitle" style={{ textAlign: "center", marginBottom: 20 }}>
          {isDirector
            ? `${request.employee.name}'s access has been fully approved. The PDF has been generated and the manager has been notified.`
            : `Your approval has been recorded. ${nextStep({ ...request, approvals: [...request.approvals, { role: "tmp", action: "approved" }] }) === "director" ? "The request now goes to the director for final sign-off." : "It's heading to the next approver in the chain."}`}
        </p>

        <div className="success-meta">
          <div>
            <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Reference</span>
            <strong style={{ fontFamily: "var(--font-mono)", fontSize: 16, display: "block" }}>{request.id}</strong>
          </div>
          <div>
            <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Signed at</span>
            <strong style={{ fontSize: 14, display: "block" }}>{fmtDateTime(signedAt)}</strong>
          </div>
          <div>
            <span className="muted" style={{ fontSize: 11.5, textTransform: "uppercase", letterSpacing: 0.06 }}>Cryptographic proof</span>
            <strong className="mono" style={{ fontSize: 12, display: "block" }}>{proof}…</strong>
          </div>
        </div>

        {isDirector && (
          <div className="provisioning">
            <div className="provisioning-steps">
              <div className="prov-step done"><Icon name="check" size={11} stroke={2.6}/> Final approval recorded</div>
              <div className="prov-step done"><Icon name="check" size={11} stroke={2.6}/> PDF generated &amp; stored</div>
              <div className="prov-step done"><Icon name="check" size={11} stroke={2.6}/> Notification sent to manager</div>
              <div className="prov-step done"><Icon name="check" size={11} stroke={2.6}/> Access active from {fmtDate(request.startDate)}</div>
            </div>
          </div>
        )}

        <div className="row gap-3" style={{ justifyContent: "center", marginTop: 24 }}>
          <button className="btn btn-primary btn-lg" onClick={onContinue}>
            Back to dashboard <Icon name="chevron-right" size={14}/>
          </button>
        </div>
      </div>
    </div>
  );
}

window.OfficerSign = OfficerSign;
window.SignedSuccess = SignedSuccess;
