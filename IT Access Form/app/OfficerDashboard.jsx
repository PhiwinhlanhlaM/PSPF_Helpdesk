// Screen 2 - IT Officer dashboard with detail side panel.

// Modal that lets an officer select which systems they are claiming/actioning.
function ClaimModal({ request, onConfirm, onCancel }) {
  // Only systems still free to claim can be selected; ones already taken by
  // another officer are shown greyed out for context.
  const available = claimableSystems(request);
  const taken = request.systems.filter(s => sysStatus(s) !== "pending");
  const [selected, setSelected] = useState(() => available.map(s => s.id));

  function toggle(id) {
    setSelected(prev =>
      prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
    );
  }

  function selectAll() { setSelected(available.map(s => s.id)); }
  function clearAll()  { setSelected([]); }

  return (
    <div style={{
      position: "fixed", inset: 0, background: "rgba(0,0,0,0.45)",
      zIndex: 9999, display: "flex", alignItems: "center", justifyContent: "center",
    }}>
      <div className="card card-pad slide-up" style={{ width: 480, maxWidth: "95vw", maxHeight: "85vh", overflowY: "auto" }}>
        <h2 className="card-title">Claim request: select systems to action</h2>
        <p className="card-subtitle">
          By claiming, you confirm you can provision the selected systems. Other
          officers can claim the remaining systems. At least one must be selected.
        </p>

        <div className="row gap-2" style={{ marginBottom: 12 }}>
          <button type="button" className="btn btn-secondary btn-sm" onClick={selectAll}>Select all</button>
          <button type="button" className="btn btn-ghost btn-sm" onClick={clearAll}>Clear</button>
        </div>

        <div className="col gap-2" style={{ marginBottom: 20 }}>
          {available.map(s => {
            const sys = getSystem(s.id);
            const on = selected.includes(s.id);
            return (
              <label key={s.id} style={{
                display: "flex", alignItems: "center", gap: 10,
                padding: "10px 12px", borderRadius: 8, cursor: "pointer",
                background: on ? "var(--blue-50, #eff6ff)" : "var(--ink-50, #f8fafc)",
                border: `1.5px solid ${on ? "var(--blue-400, #60a5fa)" : "var(--ink-200, #e2e8f0)"}`,
              }}>
                <input type="checkbox" checked={on} onChange={() => toggle(s.id)}
                  style={{ width: 16, height: 16, accentColor: "var(--pspf-700, #3d5a7e)" }}/>
                <div className="col" style={{ flex: 1, minWidth: 0 }}>
                  <strong style={{ fontSize: 13 }}>{sys ? sys.name : s.id}</strong>
                  <span className="muted" style={{ fontSize: 12 }}>
                    {s.role && s.role}{Array.isArray(s.subValues?.sub_0) && s.subValues.sub_0.length ? " · " + s.subValues.sub_0.join(", ") : ""}
                  </span>
                </div>
              </label>
            );
          })}

          {taken.map(s => {
            const sys = getSystem(s.id);
            return (
              <div key={s.id} style={{
                display: "flex", alignItems: "center", gap: 10,
                padding: "10px 12px", borderRadius: 8, opacity: 0.6,
                background: "var(--ink-50, #f8fafc)",
                border: "1.5px dashed var(--ink-200, #e2e8f0)",
              }}>
                <Icon name={sysStatus(s) === "actioned" ? "check-circle" : "lock"} size={14}/>
                <div className="col" style={{ flex: 1, minWidth: 0 }}>
                  <strong style={{ fontSize: 13 }}>{sys ? sys.name : s.id}</strong>
                  <span className="muted" style={{ fontSize: 12 }}>
                    {sysStatus(s) === "actioned" ? "Already actioned" : "Claimed by another officer"}
                  </span>
                </div>
              </div>
            );
          })}
        </div>

        <div className="row gap-2" style={{ justifyContent: "flex-end" }}>
          <button type="button" className="btn btn-secondary" onClick={onCancel}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={selected.length === 0}
            onClick={() => onConfirm(selected)}>
            <Icon name="check" size={14}/> Claim &amp; action selected
          </button>
        </div>
      </div>
    </div>
  );
}

function OfficerDashboard() {
  const { state, dispatch, toast, me } = useApp();
  const [filter, setFilter] = useState("pending"); // new | pending | all
  const [selectedId, setSelectedId] = useState(null);
  const [search, setSearch] = useState("");
  const [claimingRequest, setClaimingRequest] = useState(null); // request being claimed

  // A request is "mine to action" once I have claimed at least one of its systems
  // and still owe a signature for it.
  const isPendingMine = (r) => canOfficerSign(r, me.id);
  // A request appears in "New" while any of its systems are still free to claim.
  const hasClaimable = (r) => canOfficerClaim(r);

  const counts = useMemo(() => {
    const reqs = state.requests;
    return {
      new: reqs.filter(hasClaimable).length,
      pending: reqs.filter(isPendingMine).length,
      all: reqs.length,
    };
  }, [state.requests, me.id]);

  const visibleRequests = useMemo(() => {
    let list = state.requests;
    if (filter === "new") list = list.filter(hasClaimable);
    else if (filter === "pending") list = list.filter(isPendingMine);
    if (search.trim()) {
      const q = search.toLowerCase();
      list = list.filter(r =>
        r.employee.name.toLowerCase().includes(q) ||
        r.id.toLowerCase().includes(q) ||
        r.employee.department.toLowerCase().includes(q)
      );
    }
    return list;
  }, [state.requests, filter, search, me.id]);

  const selected = state.requests.find(r => r.id === selectedId);

  function openClaim(request) {
    setClaimingRequest(request);
  }

  async function confirmClaim(request, actionedSystems) {
    setClaimingRequest(null);
    try {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
      const res = await fetch("/pspf_crm/api/it_access/claim.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({ request_db_id: request.db_id, actioned_systems: actionedSystems }),
      });
      if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error(e.error || "Claim failed"); }
    } catch (err) {
      toast({ kind: "error", title: "Claim error", body: err.message });
      return;
    }
    // Persisted on the server, now reflect it locally and go to the sign screen
    dispatch({ type: "claim-request", id: request.id, personId: me.id, actionedSystems });
    toast({ title: "Request claimed", body: `You are actioning ${actionedSystems.length} system(s).` });
    dispatch({ type: "set-route", route: { name: "officer-sign" }, params: { requestId: request.id } });
  }

  function gotoSign(id) {
    dispatch({ type: "set-route", route: { name: "officer-sign" }, params: { requestId: id } });
  }

  return (
    <div className="page slide-up">
      {claimingRequest && (
        <ClaimModal
          request={claimingRequest}
          onConfirm={(systems) => confirmClaim(claimingRequest, systems)}
          onCancel={() => setClaimingRequest(null)}
        />
      )}
      <div className="page-header">
        <div>
          <h1 className="page-title">IT Access · Dashboard</h1>
          <p className="page-subtitle">Review and action access requests across the organization.</p>
        </div>
        <div className="row gap-2">
          <div className="search-input">
            <Icon name="search" size={14}/>
            <input placeholder="Search by name, ID, department…" value={search} onChange={e => setSearch(e.target.value)}/>
          </div>
        </div>
      </div>

      <div className="dash-stats">
        <StatCard label="New requests" value={counts.new} kind="blue"/>
        <StatCard label="Awaiting my action" value={counts.pending} kind="amber"/>
        <StatCard label="Provisioned this month" value={state.requests.filter(r => r.status === "provisioned").length} kind="green"/>
      </div>

      <div className={"dash-layout " + (selected ? "with-panel" : "")}>
        <div className="card" style={{ overflow: "hidden" }}>
          <div className="dash-tabs">
            <button className={"dash-tab " + (filter === "new" ? "active" : "")} onClick={() => setFilter("new")}>
              New <span className="tab-count">{counts.new}</span>
            </button>
            <button className={"dash-tab " + (filter === "pending" ? "active" : "")} onClick={() => setFilter("pending")}>
              Pending my action <span className="tab-count">{counts.pending}</span>
            </button>
            <button className={"dash-tab " + (filter === "all" ? "active" : "")} onClick={() => setFilter("all")}>
              All <span className="tab-count">{counts.all}</span>
            </button>
          </div>

          {visibleRequests.length === 0 ? (
            <div className="empty">
              <Icon name="check-circle" size={28}/>
              <strong>You're all caught up</strong>
              <span>No requests in this view.</span>
            </div>
          ) : (
            <div style={{ overflowX: "auto" }}>
              <table className="table">
                <thead>
                  <tr>
                    <th>Request</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Systems</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th style={{ textAlign: "right" }}>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {visibleRequests.map(r => {
                    const meta = statusMeta(r.status);
                    const canClaim = canOfficerClaim(r);
                    const canSign = canOfficerSign(r, me.id);
                    const submitter = submitterName(r);
                    return (
                      <tr key={r.id}
                          className={selectedId === r.id ? "selected" : ""}
                          onClick={() => setSelectedId(r.id)}>
                        <td>
                          <span className="mono" style={{ fontSize: 12, color: "var(--ink-500)" }}>{r.id}</span>
                        </td>
                        <td>
                          <div className="col">
                            <strong style={{ fontWeight: 550 }}>{r.employee.name}</strong>
                            <span className="muted" style={{ fontSize: 12 }}>{r.employee.title}</span>
                          </div>
                        </td>
                        <td>{r.employee.department}{r.employee.division ? ` · ${r.employee.division}` : ""}</td>
                        <td>
                          <div className="row gap-1" style={{ flexWrap: "wrap" }}>
                            {r.systems.slice(0, 2).map(s => (
                              <span key={s.id} className="badge badge-gray">{getSystem(s.id).name.split(" ")[0]}</span>
                            ))}
                            {r.systems.length > 2 && <span className="badge badge-gray">+{r.systems.length - 2}</span>}
                          </div>
                        </td>
                        <td>
                          <span className={"badge " + meta.cls}>
                            {meta.dot && <span className="dot"/>}
                            {meta.label}
                          </span>
                        </td>
                        <td>
                          <div className="col">
                            <span style={{ fontSize: 12.5 }}>{fmtDate(r.submittedAt)}</span>
                            <span className="muted" style={{ fontSize: 11.5 }}>by {submitter}</span>
                          </div>
                        </td>
                        <td style={{ textAlign: "right" }}>
                          <div className="row gap-2" style={{ justifyContent: "flex-end" }} onClick={e => e.stopPropagation()}>
                            {canClaim && (
                              <button className="btn btn-primary btn-sm" onClick={() => openClaim(r)}>
                                Claim
                              </button>
                            )}
                            {canSign && (
                              <button className="btn btn-primary btn-sm" onClick={() => gotoSign(r.id)}>
                                Action <Icon name="chevron-right" size={12}/>
                              </button>
                            )}
                            {!canClaim && !canSign && (
                              <button className="btn btn-secondary btn-sm" onClick={() => setSelectedId(r.id)}>
                                View
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {selected && (
          <DetailPanel
            request={selected}
            onClose={() => setSelectedId(null)}
            onClaim={() => openClaim(selected)}
            onSign={() => gotoSign(selected.id)}
          />
        )}
      </div>
    </div>
  );
}

function StatCard({ label, value, kind, subtle }) {
  return (
    <div className={"stat-card stat-" + kind + (subtle ? " stat-subtle" : "")}>
      <div className="stat-value">{value}</div>
      <div className="stat-label">{label}</div>
    </div>
  );
}

function DetailPanel({ request, onClose, onClaim, onSign }) {
  const { me } = useApp();
  const meta = statusMeta(request.status);
  const submitter = submitterName(request);
  const canClaim = canOfficerClaim(request);
  const canSign = canOfficerSign(request, me.id);

  return (
    <aside className="detail-panel scale-in">
      <div className="detail-head">
        <div>
          <span className="mono" style={{ fontSize: 12, color: "var(--ink-500)" }}>{request.id}</span>
          <h2 className="detail-title">{request.employee.name}</h2>
          <span className={"badge " + meta.cls}>
            {meta.dot && <span className="dot"/>}{meta.label}
          </span>
        </div>
        <button className="btn btn-icon btn-ghost" onClick={onClose} aria-label="Close">
          <Icon name="x" size={16}/>
        </button>
      </div>

      <div className="detail-body">
        <section>
          <span className="section-title">Employee</span>
          <div className="kv-grid">
            <span>ID</span><span className="mono">{request.employee.id}</span>
            <span>Department</span><span>{request.employee.department}{request.employee.division ? ` · ${request.employee.division}` : ""}</span>
            <span>Title</span><span>{request.employee.title}</span>
            <span>Start date</span><span>{fmtDate(request.startDate)}</span>
            <span>Submitted by</span><span>{submitter}</span>
          </div>
        </section>

        <section>
          <span className="section-title">Requested systems</span>
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
        </section>

        <section>
          <span className="section-title">Justification</span>
          <p className="just-text">{request.justification}</p>
        </section>

        <section>
          <span className="section-title">Action chain</span>
          <ApprovalTimeline request={request}/>
        </section>
      </div>

      <div className="detail-actions">
        {canClaim && (
          <button className="btn btn-primary" style={{ flex: 1 }} onClick={onClaim}>
            <Icon name="check" size={14}/> Claim task
          </button>
        )}
        {canSign && (
          <button className="btn btn-primary" style={{ flex: 1 }} onClick={onSign}>
            Action &amp; sign <Icon name="chevron-right" size={14}/>
          </button>
        )}
        {request.status === "provisioned" && (
          <a className="btn btn-secondary" style={{ flex: 1 }}
             href={"/pspf_crm/api/it_access/download_pdf.php?id=" + request.db_id}
             target="_blank" rel="noreferrer">
            <Icon name="download" size={13}/> Download PDF
          </a>
        )}
        {!canClaim && !canSign && request.status !== "provisioned" && (
          <span className="muted" style={{ fontSize: 12.5, textAlign: "center", flex: 1 }}>
            {request.status === "rejected" ? "This request was rejected." :
             request.status === "awaiting-director" ? "Awaiting director final sign-off." :
             "No actions available for your role on this request."}
          </span>
        )}
      </div>
    </aside>
  );
}

function ApprovalTimeline({ request }) {
  const steps = chainSteps(request);
  const isRejected = request.status === "rejected";
  const rejectedAt = request.approvals.find(a => a.action === "rejected");
  return (
    <div className="timeline">
      {steps.map((step, i) => {
        const a = step.approval;
        const done = a && a.action === "approved";
        const rejected = a && a.action === "rejected";
        const pending = !a && !isRejected && i === steps.findIndex(s => !s.approval);
        const blocked = !a && (isRejected || (i > steps.findIndex(s => !s.approval) && !pending));
        const dotCls = done ? "done" : rejected ? "rejected" : pending ? "pending" : "";
        return (
          <div key={step.key} className={"timeline-step " + dotCls}>
            <div className={"chain-dot " + dotCls}>
              {done && <Icon name="check" size={11} stroke={2.6}/>}
              {rejected && <Icon name="x" size={11} stroke={2.6}/>}
              {pending && <Icon name="clock" size={11}/>}
              {!done && !rejected && !pending && <span>{i+1}</span>}
            </div>
            <div className="col" style={{ flex: 1, minWidth: 0 }}>
              <div className="row" style={{ justifyContent: "space-between", gap: 8 }}>
                <strong style={{ fontWeight: 550, fontSize: 13 }}>{step.label}</strong>
                {a && <span className="muted" style={{ fontSize: 11.5 }}>{fmtDateTime(a.at)}</span>}
              </div>
              <span className="muted" style={{ fontSize: 12 }}>
                {step.person?.name || "Pending assignment"}
                {a && a.action === "approved" && " · actioned"}
                {a && a.action === "rejected" && " · rejected"}
                {pending && " · awaiting"}
                {blocked && !rejected && !done && " · blocked"}
              </span>
              {a?.signature && (
                <div className="sig-thumb">
                  <SignatureRender signature={a.signature} width={120} height={32}/>
                </div>
              )}
              {a?.reason && (
                <div className="reject-reason">
                  <Icon name="alert" size={12}/>
                  <span>{a.reason}</span>
                </div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

window.OfficerDashboard = OfficerDashboard;
window.ApprovalTimeline = ApprovalTimeline;
// Shared with the director dashboard, which renders the same stat cards.
window.StatCard = StatCard;
