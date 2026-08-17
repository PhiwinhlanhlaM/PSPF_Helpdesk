// Screen — Requester: respond to per-system rejections.
// Shown when a request is 'awaiting-requester': the ICT officer granted some
// systems and declined others. The requester must ACCEPT (drop) or APPEAL
// (re-justify, once) each declined system before the request can proceed.

function RequesterResolve({ requestId }) {
  const { state, dispatch, toast, me } = useApp();
  const request = state.requests.find(r => r.id === requestId)
    || state.requests.find(r => needsRequesterResponse(r)); // fallback: first needing response

  // Local per-system UI state: which system is being appealed + its justification text.
  const [appealing, setAppealing] = useState(null); // system id currently in "appeal" mode
  const [justif, setJustif] = useState("");
  const [busy, setBusy] = useState(null); // system id being submitted

  if (!request) {
    return (
      <div className="page">
        <div className="empty">
          <Icon name="check-circle" size={28}/>
          <strong>Nothing to respond to</strong>
          <span>You have no requests awaiting your response right now.</span>
          <button className="btn btn-secondary" style={{ marginTop: 12 }} onClick={() => dispatch({ type: "set-route", route: { name: "manager-history" } })}>
            Back to my requests
          </button>
        </div>
      </div>
    );
  }

  const rejected = systemsAwaitingRequester(request);
  const granted  = (request.systems || []).filter(s => sysStatus(s) === "actioned");

  async function resolve(sysId, action, justification) {
    setBusy(sysId);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const res = await fetch("/pspf_crm/api/it_access/resolve_system.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          request_db_id: request.db_id,
          system_id:     sysId,
          action:        action,               // 'accept' | 'appeal'
          ...(action === "appeal" ? { justification } : {}),
        }),
      });
      if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error(e.error || "Could not submit your response"); }
      // Refresh from the server so statuses reflect the new state.
      const data = await fetch("/pspf_crm/api/it_access/list.php", { credentials: "include" }).then(r => r.ok ? r.json() : null).catch(() => null);
      if (data && Array.isArray(data.requests)) dispatch({ type: "load-requests", requests: data.requests });
      setAppealing(null);
      setJustif("");
      toast({
        title: action === "accept" ? "Decision accepted" : "Appeal submitted",
        body: action === "accept"
          ? "The system was removed from your request."
          : "It has gone back to the ICT team for another review.",
      });
    } catch (err) {
      toast({ kind: "error", title: "Could not submit", body: err.message });
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="page slide-up">
      <button className="btn btn-ghost btn-sm" onClick={() => dispatch({ type: "set-route", route: { name: "manager-history" } })}>
        <Icon name="chevron-left" size={13}/> Back to my requests
      </button>

      <div className="page-header" style={{ marginTop: 12 }}>
        <div>
          <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
          <h1 className="page-title">Respond to declined access</h1>
          <p className="page-subtitle">
            The ICT team granted part of this request. For each declined system below, accept the decision or appeal it once with more detail. Your request stays on hold until you respond to all of them.
          </p>
        </div>
      </div>

      {/* Declined systems needing a response */}
      <div className="col gap-3">
        {rejected.map(s => {
          const sys = getSystem(s.id);
          const appealable = canAppealSystem(s);
          const inAppeal = appealing === s.id;
          return (
            <section key={s.id} className="card card-pad" style={{ borderLeft: "3px solid var(--red-500, #ef4444)" }}>
              <div className="row" style={{ justifyContent: "space-between", alignItems: "flex-start", gap: 12 }}>
                <div className="row" style={{ gap: 10, alignItems: "flex-start" }}>
                  <div className="sys-mini-icon"><Icon name={sys.icon} size={16}/></div>
                  <div className="col" style={{ minWidth: 0 }}>
                    <strong style={{ fontSize: 14 }}>{sys.name}</strong>
                    {s.role && <span className="muted" style={{ fontSize: 12 }}>{s.role}</span>}
                  </div>
                </div>
                <span className="badge badge-red"><span className="dot"/> Declined</span>
              </div>

              {/* Officer's reason (and any prior appeal context). */}
              {s.rejectReason && (
                <div className="just-box" style={{ marginTop: 10, background: "var(--red-50, #fef2f2)", borderColor: "var(--red-200, #fecaca)", whiteSpace: "pre-wrap" }}>
                  {s.rejectReason}
                </div>
              )}

              {!inAppeal ? (
                <div className="row gap-2" style={{ justifyContent: "flex-end", marginTop: 12 }}>
                  <button className="btn btn-secondary btn-sm" disabled={busy === s.id} onClick={() => resolve(s.id, "accept")}>
                    {busy === s.id ? <><span className="spin"/> …</> : "Accept decision"}
                  </button>
                  {appealable ? (
                    <button className="btn btn-primary btn-sm" disabled={busy === s.id} onClick={() => { setAppealing(s.id); setJustif(""); }}>
                      <Icon name="chevron-right" size={12}/> Appeal
                    </button>
                  ) : (
                    <span className="muted" style={{ fontSize: 12, alignSelf: "center" }}>Already appealed once — accept to proceed.</span>
                  )}
                </div>
              ) : (
                <div className="col gap-2" style={{ marginTop: 12 }}>
                  <Field label="Why should this access be granted?" required help="Add detail addressing the reason above. Minimum 10 characters. You can appeal a system once.">
                    <textarea className="textarea" rows={4} value={justif} onChange={e => setJustif(e.target.value)} placeholder="Explain why this access is needed…"/>
                  </Field>
                  <div className="row gap-2" style={{ justifyContent: "flex-end" }}>
                    <button className="btn btn-ghost btn-sm" onClick={() => { setAppealing(null); setJustif(""); }}>Cancel</button>
                    <button className="btn btn-primary btn-sm" disabled={busy === s.id || justif.trim().length < 10} onClick={() => resolve(s.id, "appeal", justif.trim())}>
                      {busy === s.id ? <><span className="spin"/> Submitting…</> : "Submit appeal"}
                    </button>
                  </div>
                </div>
              )}
            </section>
          );
        })}
      </div>

      {/* What was granted, for context. */}
      {granted.length > 0 && (
        <div style={{ marginTop: 20 }}>
          <span className="section-title">Already granted</span>
          <div className="col gap-2" style={{ marginTop: 6 }}>
            {granted.map(s => {
              const sys = getSystem(s.id);
              return (
                <div key={s.id} className="sys-mini" style={{ opacity: 0.9 }}>
                  <div className="sys-mini-icon"><Icon name={sys.icon} size={14}/></div>
                  <div className="col" style={{ flex: 1, minWidth: 0 }}>
                    <strong style={{ fontSize: 13 }}>{sys.name}</strong>
                    {s.role && <span className="muted" style={{ fontSize: 12 }}>{s.role}</span>}
                  </div>
                  <span className="badge badge-green">Granted</span>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

window.RequesterResolve = RequesterResolve;
