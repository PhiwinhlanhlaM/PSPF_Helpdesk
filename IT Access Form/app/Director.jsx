// Screen 4 - Director final review

const { useState } = React;

// Date range presets for the dashboard stats.
//
// Timezone note: request timestamps arrive from list.php as UTC ISO strings
// ('...Z'), while the ranges below are built in the director's LOCAL time. That
// is deliberate — "this month" should mean the month as the director sees it on
// their calendar, not the UTC month. Comparison happens on absolute epoch
// milliseconds (Date.getTime()), so the two representations line up correctly
// and a request submitted at 23:00 local on the 31st still counts in that month.
const DIR_RANGE_PRESETS = [
  { id: "this-month",   label: "This month" },
  { id: "last-month",   label: "Last month" },
  { id: "this-quarter", label: "This quarter" },
  { id: "this-year",    label: "This year" },
  { id: "all",          label: "All time" },
  { id: "custom",       label: "Custom range…" },
];

function dirRangeBounds(presetId, customFrom, customTo) {
  const now = new Date();
  const y = now.getFullYear();
  const m = now.getMonth();
  const startOf = (yy, mm, dd) => new Date(yy, mm, dd, 0, 0, 0, 0);
  const endOf   = (yy, mm, dd) => new Date(yy, mm, dd, 23, 59, 59, 999);

  switch (presetId) {
    case "this-month":
      return [startOf(y, m, 1), endOf(y, m + 1, 0)];
    case "last-month":
      return [startOf(y, m - 1, 1), endOf(y, m, 0)];
    case "this-quarter": {
      const qStart = Math.floor(m / 3) * 3;
      return [startOf(y, qStart, 1), endOf(y, qStart + 3, 0)];
    }
    case "this-year":
      return [startOf(y, 0, 1), endOf(y, 11, 31)];
    case "custom": {
      if (!customFrom || !customTo) return null;
      const f = new Date(customFrom + "T00:00:00");
      const t = new Date(customTo   + "T00:00:00");
      if (isNaN(f) || isNaN(t)) return null;
      return [f, endOf(t.getFullYear(), t.getMonth(), t.getDate())];
    }
    case "all":
    default:
      return null;
  }
}

// Is `iso` inside [from, to]? A null range means "all time" (always true).
function dirInRange(iso, range) {
  if (!range) return true;
  if (!iso) return false;
  const t = new Date(iso).getTime();
  if (isNaN(t)) return false;
  return t >= range[0].getTime() && t <= range[1].getTime();
}

// The date a request's outcome was decided. Provisioned requests carry
// provisionedAt; rejected ones only have the rejecting approval's timestamp.
function dirDecidedAt(r) {
  if (r.status === "provisioned") {
    return r.provisionedAt || r.approvals.filter(a => a.action === "approved").slice(-1)[0]?.at || null;
  }
  if (r.status === "rejected") {
    return r.approvals.find(a => a.action === "rejected")?.at || null;
  }
  return null;
}

function DirectorDashboard() {
  const { state, dispatch } = useApp();
  const [rangeId, setRangeId] = useState("this-month");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");

  const range = React.useMemo(
    () => dirRangeBounds(rangeId, customFrom, customTo),
    [rangeId, customFrom, customTo]
  );

  const pending = state.requests.filter(r => r.status === "awaiting-director");
  const recent = state.requests.filter(r => r.status === "provisioned" || r.status === "rejected").slice(0, 5);

  // Period-scoped counts. "Awaiting your review" is deliberately NOT date-scoped:
  // it is a live backlog, not a statistic about a past period — a request that
  // has been waiting since before the range still needs the director's decision.
  const stats = React.useMemo(() => {
    const submitted = state.requests.filter(r => dirInRange(r.submittedAt, range)).length;
    const provisioned = state.requests.filter(
      r => r.status === "provisioned" && dirInRange(dirDecidedAt(r), range)
    ).length;
    const rejected = state.requests.filter(
      r => r.status === "rejected" && dirInRange(dirDecidedAt(r), range)
    ).length;
    return { submitted, provisioned, rejected, awaiting: pending.length };
  }, [state.requests, range, pending.length]);

  const rangeLabel = rangeId === "all" ? "all time" : "in period";
  const customIncomplete = rangeId === "custom" && !range;

  // The export re-resolves the period server-side from these same parameters,
  // so the spreadsheet always matches what the stats above are showing.
  const exportUrl = (() => {
    const p = new URLSearchParams({ range: rangeId });
    if (rangeId === "custom") { p.set("from", customFrom); p.set("to", customTo); }
    return "/pspf_crm/api/it_access/export_requests_excel.php?" + p.toString();
  })();

  return (
    <div className="page slide-up">
      <div className="page-header">
        <div>
          <h1 className="page-title">Director reviews</h1>
          <p className="page-subtitle">Final sign-off on access requests fully reviewed by IT.</p>
        </div>
      </div>

      <div className="row gap-2" style={{ flexWrap: "wrap", alignItems: "flex-end", marginBottom: 14 }}>
        <div className="field" style={{ minWidth: 180 }}>
          <label className="label" htmlFor="dir-range">Date range</label>
          <select id="dir-range" className="select" value={rangeId} onChange={e => setRangeId(e.target.value)}>
            {DIR_RANGE_PRESETS.map(p => <option key={p.id} value={p.id}>{p.label}</option>)}
          </select>
        </div>
        {rangeId === "custom" && (
          <>
            <div className="field">
              <label className="label" htmlFor="dir-from">From</label>
              <input id="dir-from" type="date" className="input" value={customFrom}
                max={customTo || undefined}
                onChange={e => setCustomFrom(e.target.value)}/>
            </div>
            <div className="field">
              <label className="label" htmlFor="dir-to">To</label>
              <input id="dir-to" type="date" className="input" value={customTo}
                min={customFrom || undefined}
                onChange={e => setCustomTo(e.target.value)}/>
            </div>
          </>
        )}
        <div style={{ marginLeft: "auto" }}>
          {customIncomplete ? (
            <button type="button" className="btn btn-secondary" disabled
              title="Pick both dates to export this range">
              <Icon name="download" size={13}/> Export
            </button>
          ) : (
            <a className="btn btn-secondary" href={exportUrl}>
              <Icon name="download" size={13}/> Export
            </a>
          )}
        </div>
      </div>

      {customIncomplete && (
        <p className="help" style={{ marginBottom: 12 }}>Pick both a from and a to date to filter the statistics.</p>
      )}

      <div className="dash-stats">
        <StatCard label={`Submitted · ${rangeLabel}`}   value={stats.submitted}   kind="blue"/>
        <StatCard label={`Provisioned · ${rangeLabel}`} value={stats.provisioned} kind="green"/>
        <StatCard label={`Rejected · ${rangeLabel}`}    value={stats.rejected}    kind="red"/>
        <StatCard label="Awaiting your review · now"    value={stats.awaiting}    kind="amber"/>
      </div>

      <h2 className="section-title" style={{ marginBottom: 12 }}>Awaiting your review · {pending.length}</h2>
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
        <span className="badge badge-amber"><span className="dot"/>Awaiting review</span>
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
        <button className="btn btn-primary btn-sm">Review <Icon name="chevron-right" size={12}/></button>
      </div>
    </div>
  );
}

function DirectorSign({ requestId }) {
  const { state, dispatch, toast, me } = useApp();
  const request = state.requests.find(r => r.id === requestId);
  const [stage, setStage] = useState("review"); // review | sign | rejecting | done | submitting
  // NOTE: `stage` values and the backend `action` payload ('approved'/'rejected')
  // are internal identifiers, not display text — the director-facing wording is
  // "review" but the DB enum and step_role stay unchanged.
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
      toast({ kind: "error", title: "Confirmation required", body: "Tick the box to confirm provisioning." });
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
      toast({ title: "Review recorded", body: `Provisioning ${request.employee.name}'s access now.` });
    } catch (err) {
      toast({ kind: "error", title: "Could not record review", body: err.message });
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
          <h1 className="page-title">Director review: {request.employee.name}</h1>
          <p className="page-subtitle">All IT officer actions are complete. Your review and signature are the final step before provisioning.</p>
        </div>
      </div>

      <div className="dir-layout">
        <section className="card card-pad">
          <div className="row" style={{ justifyContent: "space-between", marginBottom: 16 }}>
            <h2 className="card-title" style={{ marginBottom: 0 }}>Request summary</h2>
            <span className="badge badge-amber"><span className="dot"/>Awaiting review</span>
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
              <h2 className="card-title">Review decision</h2>
              <p className="card-subtitle">Review and provision access, or reject with a reason.</p>
              <button className="btn btn-success btn-lg" style={{ width: "100%", justifyContent: "center" }} onClick={() => setStage("sign")}>
                <Icon name="shield-check" size={16}/> Review &amp; provision
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
                <span>I have reviewed this request and confirm provisioning of the access listed above.</span>
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
