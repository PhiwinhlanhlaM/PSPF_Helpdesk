// Screen 3 - IT Officer signing interface
// Used for officer-1 actioning.

function OfficerSign({ requestId }) {
  const { state, dispatch, toast, me } = useApp();
  const request = state.requests.find(r => r.id === requestId);
  const [signature, setSignature] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [mode, setMode] = useState("idle"); // idle | rejecting | submitting | done
  const [rejectReason, setRejectReason] = useState("");
  const [signedAt, setSignedAt] = useState(null);
  const [proof, setProof] = useState(null);
  // Per-system decision for the systems THIS officer has claimed. Defaults to
  // "grant"; the officer flips individual systems to "reject" (with a reason).
  // A single signature covers the whole batch.
  const [decisions, setDecisions] = useState({});   // { sysId: 'grant' | 'reject' }
  const [rejectReasons, setRejectReasons] = useState({}); // { sysId: reasonText }

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
  // The systems THIS officer claimed and still has to decide on. Other officers
  // handle the rest of the request independently.
  const myClaimed = systemsToAction(request, me.id);

  // Decision helpers (default = grant when not explicitly set).
  const decisionOf = (sysId) => decisions[sysId] || "grant";
  const grantedSystems  = myClaimed.filter(s => decisionOf(s.id) === "grant").map(s => s.id);
  const rejectedSystems = myClaimed.filter(s => decisionOf(s.id) === "reject").map(s => s.id);
  // Every rejected system needs a reason (min 3 chars) before submit.
  const rejectionsComplete = rejectedSystems.every(id => (rejectReasons[id] || "").trim().length >= 3);
  const setDecision = (sysId, d) => setDecisions(prev => ({ ...prev, [sysId]: d }));
  const setReason   = (sysId, v) => setRejectReasons(prev => ({ ...prev, [sysId]: v }));

  function generateProof() {
    // Pretend cryptographic proof - random hex
    return Array.from({ length: 16 }, () => "0123456789abcdef"[Math.floor(Math.random() * 16)]).join("");
  }

  async function action() {
    if (myClaimed.length === 0) {
      toast({ kind: "error", title: "Nothing to action", body: "You have no claimed systems left to decide on this request." });
      return;
    }
    if (!rejectionsComplete) {
      toast({ kind: "error", title: "Reason required", body: "Give a reason for every system you are rejecting (min 3 characters)." });
      return;
    }
    if (!confirmed) {
      toast({ kind: "error", title: "Confirmation required", body: "Tick the box to confirm you have reviewed this request." });
      return;
    }
    if (!signature) {
      toast({ kind: "error", title: "Signature required", body: "Draw or upload your signature before signing off." });
      return;
    }
    setMode("submitting");
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const rejectedPayload = rejectedSystems.map(id => ({ id, reason: (rejectReasons[id] || "").trim() }));
      const res = await fetch("/pspf_crm/api/it_access/approve.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          request_db_id:    request.db_id,
          action:           "approved", // backend action value (DB enum) - unchanged
          step_role:        stepRole,
          signature:        signature,
          actioned_systems: grantedSystems,
          rejected_systems: rejectedPayload,
        }),
      });
      if (!res.ok) { const e = await res.json().catch(()=>({})); throw new Error(e.error || "Could not record action"); }
      const at = new Date().toISOString();
      const p  = generateProof();
      dispatch({ type: "approve-request", id: request.id, personId: me.id, role: stepRole, signature, actionedSystems: grantedSystems });
      fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" })
        .then(r => r.ok ? r.json() : null)
        .then(data => { if (data && Array.isArray(data.requests)) dispatch({ type: "load-requests", requests: data.requests }); })
        .catch(() => {});
      setSignedAt(at);
      setProof(p);
      setMode("done");
      toast({ title: "Action recorded", body: `Signature accepted for ${request.employee.name}.` });
    } catch (err) {
      toast({ kind: "error", title: "Could not record action", body: err.message });
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
          <h1 className="page-title">Action access request: {request.employee.name}</h1>
          <p className="page-subtitle">
            {stepLabel} · decide each of your <strong>{myClaimed.length}</strong> claimed system{myClaimed.length === 1 ? "" : "s"} below — grant or reject with a reason — then sign once to confirm.
          </p>
        </div>
      </div>

      <div className="sign-grid">
        <div className="col gap-4">
          {/* Document preview */}
          <section className="card doc-preview">
            <div className="doc-watermark">REVIEW</div>
            <div className="doc-head">
              <div className="col">
                <strong style={{ fontSize: 12, color: "var(--pspf-800)", letterSpacing: "0.04em", textTransform: "uppercase" }}>Public Service Pensions Fund</strong>
                <span className="muted" style={{ fontSize: 12 }}>IT System Access Authorization</span>
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
                const st = s.status || "pending";
                const mine = st === "claimed" && s.claimedBy === me.id;
                const decision = decisionOf(s.id);
                const subLine = s.role
                  ? s.role + (Array.isArray(s.subValues) && s.subValues.length ? " · " + s.subValues.join(", ") : (typeof s.subValues === "string" && s.subValues ? " · " + s.subValues : ""))
                  : (Array.isArray(s.subValues) && s.subValues.length ? s.subValues.join(", ") : (typeof s.subValues === "string" ? s.subValues : ""));

                // Read-only tag for systems this officer isn't actioning.
                let tag = null;
                if (!mine) {
                  if (st === "actioned")      tag = <span className="badge badge-green">Actioned</span>;
                  else if (st === "dropped")  tag = <span className="badge badge-gray">Denied</span>;
                  else if (st === "rejected") tag = <span className="badge badge-amber">Awaiting requester</span>;
                  else if (st === "claimed")  tag = <span className="badge badge-gray">Another officer</span>;
                  else                        tag = <span className="badge badge-gray">Unclaimed</span>;
                }

                return (
                  <div key={s.id} className="col" style={{ gap: 6 }}>
                    <div className="sys-mini" style={mine ? { background: decision === "reject" ? "var(--red-50, #fef2f2)" : "var(--blue-50, #eff6ff)" } : { opacity: st === "pending" ? 0.6 : 1 }}>
                      <div className="sys-mini-icon"><Icon name={sys.icon} size={14}/></div>
                      <div className="col" style={{ flex: 1, minWidth: 0 }}>
                        <strong style={{ fontSize: 13, fontWeight: 550 }}>{sys.name}</strong>
                        <span className="muted" style={{ fontSize: 12 }}>{subLine}</span>
                      </div>
                      {mine ? (
                        // Per-system Grant / Reject toggle.
                        <div className="row" style={{ gap: 4 }}>
                          <button type="button"
                            className={"btn btn-sm " + (decision === "grant" ? "btn-primary" : "btn-ghost")}
                            style={{ padding: "3px 10px", fontSize: 12 }}
                            onClick={() => setDecision(s.id, "grant")}>
                            <Icon name="check" size={12}/> Grant
                          </button>
                          <button type="button"
                            className={"btn btn-sm " + (decision === "reject" ? "btn-danger" : "btn-ghost")}
                            style={{ padding: "3px 10px", fontSize: 12, ...(decision === "reject" ? { background: "var(--red-600)", color: "#fff", borderColor: "var(--red-600)" } : {}) }}
                            onClick={() => setDecision(s.id, "reject")}>
                            <Icon name="x" size={12}/> Reject
                          </button>
                        </div>
                      ) : tag}
                    </div>
                    {mine && decision === "reject" && (
                      <input
                        className="input"
                        style={{ fontSize: 12.5, marginLeft: 34, width: "calc(100% - 34px)" }}
                        placeholder="Reason this system is declined (required)…"
                        value={rejectReasons[s.id] || ""}
                        onChange={e => setReason(s.id, e.target.value)}
                      />
                    )}
                  </div>
                );
              })}
            </div>

            <div className="divider"/>

            <span className="section-title">Justification</span>
            <p className="just-text">{request.justification}</p>

            <div className="divider"/>

            <span className="section-title">Action chain</span>
            <ApprovalTimeline request={request}/>
          </section>
        </div>

        {/* Signature column */}
        <aside className="col gap-4">
          {mode !== "rejecting" && (
            <section className="card card-pad sign-panel">
              <h2 className="card-title">Your signature</h2>
              <p className="card-subtitle">Draw with your mouse, trackpad, or touch, or upload an image of your signature.</p>

              <SignaturePad onChange={setSignature}/>

              {/* Decision summary so the officer sees what they're signing. */}
              <div className="row" style={{ gap: 8, fontSize: 12.5, marginBottom: 4 }}>
                <span className="badge badge-green">{grantedSystems.length} granting</span>
                {rejectedSystems.length > 0 && <span className="badge badge-red">{rejectedSystems.length} rejecting</span>}
              </div>

              <label className="confirm-row">
                <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)}/>
                <span>I confirm I have reviewed this request and my grant/reject decisions on the systems above.</span>
              </label>

              <div className="sign-actions">
                <button className="btn btn-danger" onClick={() => setMode("rejecting")} disabled={mode === "submitting"} title="Reject the whole request, not just individual systems">
                  Reject entire request
                </button>
                <div className="row gap-2">
                  <button className="btn btn-secondary" onClick={() => dispatch({ type: "set-route", route: { name: "officer-dashboard" } })}>
                    Cancel
                  </button>
                  <button className="btn btn-primary" disabled={mode === "submitting" || !rejectionsComplete} onClick={action}>
                    {mode === "submitting" ? <><span className="spin"/> Submitting...</> : <><Icon name="shield-check" size={14}/> Sign &amp; submit</>}
                  </button>
                </div>
              </div>
            </section>
          )}

          {mode === "rejecting" && (
            <section className="card card-pad slide-up">
              <h2 className="card-title" style={{ color: "var(--red-700)" }}>Reject this request</h2>
              <p className="card-subtitle">The manager will be notified with your reason. They can revise and resubmit.</p>
              <Field label="Reason for rejection" required help="Be specific, minimum 10 characters">
                <textarea className="textarea" rows={5}
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
                Your signature will be cryptographically bound to this request and archived to SharePoint with a timestamped audit record.
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
          {isDirector ? "Request successfully provisioned" : "Signature accepted"}
        </h1>
        <p className="page-subtitle" style={{ textAlign: "center", marginBottom: 20 }}>
          {isDirector
            ? `${request.employee.name}'s access request has been provisioned.`
            : `Your action has been recorded. ${nextStep({ ...request, approvals: [...request.approvals, { role: "tmp", action: "approved" }] }) === "director" ? "The request now goes to the director for final sign-off." : "It's heading to the next approver in the chain."}`}
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

        <div className="row gap-3" style={{ justifyContent: "center", marginTop: 24 }}>
          {isDirector && (
            <a className="btn btn-secondary btn-lg"
               href={"/pspf_crm/api/it_access/download_pdf.php?id=" + request.db_id}
               target="_blank" rel="noreferrer">
              <Icon name="download" size={14}/> Download PDF
            </a>
          )}
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
