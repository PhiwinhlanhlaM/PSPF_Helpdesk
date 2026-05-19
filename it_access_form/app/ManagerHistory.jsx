// Screen 5 — Manager: My requests (status & history)

function ManagerHistory() {
  const { state, dispatch, me, toast } = useApp();
  const [filter, setFilter] = useState("all");
  const [openId, setOpenId] = useState(null);

  // submittedBy is the numeric DB id; me.id is the CRM username string.
  // Show all requests when acting as manager — the server already filtered to own requests.
  const myRequests = state.requests;
  const counts = {
    pending:   myRequests.filter(r => !["provisioned", "rejected"].includes(r.status)).length,
    approved:  myRequests.filter(r => r.status === "provisioned").length,
    rejected:  myRequests.filter(r => r.status === "rejected").length,
    all:       myRequests.length,
  };

  const visible = myRequests.filter(r => {
    if (filter === "pending")  return !["provisioned", "rejected"].includes(r.status);
    if (filter === "approved") return r.status === "provisioned";
    if (filter === "rejected") return r.status === "rejected";
    return true;
  });

  const open = state.requests.find(r => r.id === openId);

  return (
    <div className="page slide-up">
      <div className="page-header">
        <div>
          <h1 className="page-title">My IT access requests</h1>
          <p className="page-subtitle">Track in-flight requests and access historical records.</p>
        </div>
        <button className="btn btn-primary" onClick={() => dispatch({ type: "set-route", route: { name: "manager-form" } })}>
          <Icon name="plus" size={14}/> New request
        </button>
      </div>

      <div className="dash-tabs" style={{ background: "transparent", border: 0, padding: 0, marginBottom: 16 }}>
        {[
          ["all",      "All",      counts.all],
          ["pending",  "Pending",  counts.pending],
          ["approved", "Approved", counts.approved],
          ["rejected", "Rejected", counts.rejected],
        ].map(([k, label, c]) => (
          <button key={k} className={"dash-tab " + (filter === k ? "active" : "")} onClick={() => setFilter(k)}>
            {label} <span className="tab-count">{c}</span>
          </button>
        ))}
      </div>

      {visible.length === 0 ? (
        <div className="card empty"><Icon name="file" size={28}/><strong>No requests</strong>
          <span>Submit a new request to see it here.</span></div>
      ) : (
        <div className="hist-grid">
          {visible.map(r => <HistoryCard key={r.id} request={r} onOpen={() => setOpenId(r.id)}
            onResubmit={() => {
              dispatch({ type: "set-route", route: { name: "manager-form" } });
              toast({ kind: "info", title: "Form prefilled", body: "Revise as needed and resubmit." });
            }}/>)}
        </div>
      )}

      {open && <RequestDetailModal request={open} onClose={() => setOpenId(null)}/>}
    </div>
  );
}

function HistoryCard({ request, onOpen, onResubmit }) {
  const meta = statusMeta(request.status);
  const total = 4; // chain length
  const done = request.approvals.filter(a => a.action === "approved").length;
  const isRejected = request.status === "rejected";
  const isProvisioned = request.status === "provisioned";

  return (
    <article className={"hist-card hist-" + (isRejected ? "rejected" : isProvisioned ? "approved" : "pending")}>
      <div className="hist-card-head">
        <span className="mono muted" style={{ fontSize: 11.5 }}>{request.id}</span>
        <span className={"badge " + meta.cls}>{meta.dot && <span className="dot"/>}{meta.label}</span>
      </div>

      <h3 className="hist-name">{request.employee.name}</h3>
      <p className="muted" style={{ fontSize: 12.5, margin: "0 0 14px" }}>
        {request.employee.title} · {request.employee.department}{request.employee.division ? ` · ${request.employee.division}` : ""}
      </p>

      <div className="hist-systems">
        {request.systems.slice(0, 4).map(s => (
          <span key={s.id} className="badge badge-gray">{getSystem(s.id).name.split(" ")[0]}</span>
        ))}
        {request.systems.length > 4 && <span className="badge badge-gray">+{request.systems.length - 4}</span>}
      </div>

      {!isRejected && !isProvisioned && (
        <div className="hist-progress">
          <div className="row" style={{ justifyContent: "space-between", marginBottom: 6 }}>
            <span className="muted" style={{ fontSize: 11.5 }}>{done} of {total} approvals</span>
            <span className="muted" style={{ fontSize: 11.5 }}>{Math.round((done / total) * 100)}%</span>
          </div>
          <div className="progress-bar"><div className="progress-fill" style={{ width: `${(done / total) * 100}%` }}/></div>
          <div className="hist-mini-chain">
            {chainSteps(request).map((s, i) => {
              const a = s.approval;
              const cls = a ? (a.action === "rejected" ? "rejected" : "done") :
                (i === chainSteps(request).findIndex(x => !x.approval) ? "pending" : "");
              return (
                <div key={i} className="mini-step">
                  <div className={"chain-dot " + cls} style={{ width: 18, height: 18 }}>
                    {cls === "done" && <Icon name="check" size={9} stroke={2.8}/>}
                    {cls === "pending" && <Icon name="clock" size={9}/>}
                    {!cls && <span style={{ fontSize: 9 }}>{i+1}</span>}
                  </div>
                  <span style={{ fontSize: 10.5, color: "var(--ink-500)" }}>
                    {s.label.replace("IT Officer ", "Officer ").replace(" of ICT", "")}
                  </span>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {isProvisioned && (
        <div className="hist-status-row hist-success">
          <Icon name="check-circle" size={14}/>
          <span>Provisioned {fmtDate(request.provisionedAt)} · access active since {fmtDate(request.startDate)}</span>
        </div>
      )}

      {isRejected && (
        <div className="hist-status-row hist-rejected">
          <Icon name="alert" size={14}/>
          <span>{request.approvals.find(a => a.action === "rejected")?.reason}</span>
        </div>
      )}

      <div className="hist-foot">
        <span className="muted" style={{ fontSize: 11.5 }}>Requested {fmtDate(request.submittedAt)}</span>
        <div className="row gap-2">
          {isRejected && <button className="btn btn-secondary btn-sm" onClick={onResubmit}>Resubmit</button>}
          {isProvisioned && <button className="btn btn-secondary btn-sm" onClick={onOpen}><Icon name="file" size={12}/> View signed</button>}
          {!isRejected && !isProvisioned && <button className="btn btn-secondary btn-sm" onClick={onOpen}>View details</button>}
        </div>
      </div>
    </article>
  );
}

function RequestDetailModal({ request, onClose }) {
  return (
    <div className="ita-modal-backdrop fade-in" onClick={onClose}>
      <div className="ita-modal scale-in" onClick={e => e.stopPropagation()}>
        <div className="ita-modal-head">
          <div>
            <span className="mono muted" style={{ fontSize: 12 }}>{request.id}</span>
            <h2 style={{ fontFamily: "var(--font-display)", fontSize: 20, fontWeight: 600, margin: "4px 0 0", letterSpacing: "-0.01em" }}>
              {request.employee.name}
            </h2>
          </div>
          <div className="row gap-2">
            {request.status === "provisioned" && (
              <a
                href={"/pspf_crm/api/it_access/download_pdf.php?id=" + request.db_id}
                target="_blank"
                rel="noreferrer"
                className="btn btn-secondary btn-sm"
              >
                <Icon name="download" size={12}/> Download PDF
              </a>
            )}
            <button className="btn btn-icon btn-ghost" onClick={onClose}><Icon name="x" size={16}/></button>
          </div>
        </div>

        <div className="ita-modal-body">
          <div className="kv-grid" style={{ gridTemplateColumns: "120px 1fr 120px 1fr" }}>
            <span>Employee ID</span><span className="mono">{request.employee.id}</span>
            <span>Department</span><span>{request.employee.department}{request.employee.division ? ` · ${request.employee.division}` : ""}</span>
            <span>Title</span><span>{request.employee.title}</span>
            <span>Start date</span><span>{fmtDate(request.startDate)}</span>
          </div>

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
        </div>
      </div>
    </div>
  );
}

window.ManagerHistory = ManagerHistory;
