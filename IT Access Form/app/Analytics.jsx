// Analytics - shared oversight + operations dashboard for the IT Access module.
// Visible to it_officer / it_director / superadmin. Reads org-wide numbers from
// stats.php. Charts are hand-drawn inline SVG/CSS (no external chart library),
// consistent with the app's no-build Babel-standalone setup and the page CSP.

const { useState, useEffect } = React;

// --- small formatting helpers ---
function fmtHrs(h) {
  if (h == null) return "-";
  if (h < 24) return `${Math.round(h)}h`;
  const d = h / 24;
  return d < 10 ? `${d.toFixed(1)}d` : `${Math.round(d)}d`;
}
function pct(n) { return n == null ? "-" : `${n}%`; }

// A labelled horizontal bar row. `max` scales the fill.
// A muted, varied palette - deliberately desaturated so a chart of many bars
// reads calm rather than loud. Rotated across bar rows via anColor(i).
const AN_PALETTE = [
  "#5b7a9d", // slate blue
  "#6a9a8a", // muted teal-green
  "#c08a57", // soft ochre
  "#8a6fa3", // dusty purple
  "#a5768a", // muted rose
  "#7f9b6a", // sage
  "#5f8a9a", // steel cyan
  "#b08a6a", // clay
];
function anColor(i) { return AN_PALETTE[i % AN_PALETTE.length]; }

function BarRow({ label, value, max, sub, color }) {
  const w = max > 0 ? Math.max(2, Math.round((value / max) * 100)) : 0;
  return (
    <div className="an-bar-row">
      <div className="an-bar-label" title={label}>{label}</div>
      <div className="an-bar-track">
        <div className="an-bar-fill" style={{ width: `${w}%`, background: color || AN_PALETTE[0] }}/>
      </div>
      <div className="an-bar-value">{value}{sub ? <span className="muted"> {sub}</span> : null}</div>
    </div>
  );
}

// A green/red split bar showing granted vs denied for one system.
function SplitBar({ granted, denied }) {
  const total = granted + denied;
  const g = total ? Math.round((granted / total) * 100) : 0;
  return (
    <div className="an-split-track" title={`${granted} granted / ${denied} denied`}>
      <div className="an-split-g" style={{ width: `${g}%` }}/>
      <div className="an-split-d" style={{ width: `${100 - g}%` }}/>
    </div>
  );
}

// Weekly volume as labelled vertical bars, so each week's count is legible
// (a bare line gave no numbers to read). Week label under each bar, count above.
function VolumeBars({ points }) {
  if (!points || points.length === 0) return <span className="muted">No data</span>;
  const max = Math.max(1, ...points.map(p => p.count));
  return (
    <div className="an-vol">
      {points.map((p, i) => {
        const h = Math.round((p.count / max) * 100);
        // "2026-W33" -> "W33" for a compact axis label.
        const wk = (p.week || "").split("-").pop();
        return (
          <div key={i} className="an-vol-col" title={`${p.week}: ${p.count}`}>
            <span className="an-vol-num">{p.count}</span>
            <div className="an-vol-bar-wrap">
              <div className="an-vol-bar" style={{ height: `${Math.max(4, h)}%`, background: anColor(i) }}/>
            </div>
            <span className="an-vol-wk">{wk}</span>
          </div>
        );
      })}
    </div>
  );
}

function Section({ title, subtitle, children }) {
  return (
    <div className="an-section">
      <div className="an-section-head">
        <h2 className="section-title" style={{ margin: 0 }}>{title}</h2>
        {subtitle && <span className="muted" style={{ fontSize: 12.5 }}>{subtitle}</span>}
      </div>
      {children}
    </div>
  );
}

function Analytics() {
  const [data, setData] = useState(null);
  const [state, setState] = useState("loading"); // loading | ready | error | forbidden

  useEffect(() => {
    fetch("/pspf_crm/api/it_access/stats.php", { credentials: "include" })
      .then(r => {
        if (r.status === 403) { setState("forbidden"); return null; }
        if (!r.ok) return Promise.reject();
        return r.json();
      })
      .then(d => { if (d) { setData(d); setState("ready"); } })
      .catch(() => setState("error"));
  }, []);

  if (state === "loading") return <div className="page slide-up"><div className="card empty"><Icon name="clock" size={26}/><strong>Loading analytics…</strong></div></div>;
  if (state === "forbidden") return <div className="page slide-up"><div className="card empty"><Icon name="lock" size={26}/><strong>Not available</strong><span>Analytics is limited to IT officers, the director, and admins.</span></div></div>;
  if (state === "error") return <div className="page slide-up"><div className="card empty"><Icon name="alert" size={26}/><strong>Couldn't load analytics</strong><span>Try again shortly.</span></div></div>;

  const { approvalRate, statusFunnel, cycleTime, byDepartment, volumeByWeek,
          officerStats, stuckClaimed, topSystems } = data;

  const totalReq = approvalRate.total || 0;
  const enoughForTiming = cycleTime.count >= 5; // small-N guard

  // Status funnel ordered for the pipeline read.
  const funnelOrder = ["new", "claimed", "awaiting-requester", "awaiting-director", "provisioned", "rejected"];
  const funnelLabels = {
    "new": "New", "claimed": "Under review", "awaiting-requester": "Awaiting requester",
    "awaiting-director": "Awaiting director", "provisioned": "Provisioned", "rejected": "Rejected",
    "awaiting-supervisor": "Awaiting supervisor",
  };
  const funnelRows = funnelOrder
    .filter(k => statusFunnel[k])
    .map(k => ({ label: funnelLabels[k] || k, value: statusFunnel[k] }));
  const funnelMax = Math.max(1, ...funnelRows.map(r => r.value));

  const deptMax = Math.max(1, ...byDepartment.map(d => d.count));
  const officerMax = Math.max(1, ...officerStats.map(o => o.actioned));
  const sysMax = Math.max(1, ...topSystems.map(s => s.total));

  return (
    <div className="page slide-up">
      <div className="page-header">
        <div>
          <h1 className="page-title">IT Access analytics</h1>
          <p className="page-subtitle">Org-wide request and system metrics.</p>
        </div>
      </div>

      {/* ---- OVERSIGHT ---- */}
      <Section title="Oversight">
        <div className="dash-stats" style={{ marginBottom: 18 }}>
          <StatCard label="Total requests"      value={totalReq}                         kind="blue"/>
          <StatCard label="Provisioned"         value={approvalRate.provisioned}         kind="green"/>
          <StatCard label="Rejected"            value={approvalRate.rejected}            kind="red"/>
          <StatCard label="In flight"           value={approvalRate.inFlight}            kind="amber"/>
          <StatCard label="Approval rate"       value={pct(approvalRate.approvalPct)}    kind="blue" subtle/>
        </div>

        <div className="an-grid-2">
          <div className="card card-pad">
            <span className="section-title">Weekly volume · last 12 weeks</span>
            <div style={{ marginTop: 10 }}><VolumeBars points={volumeByWeek}/></div>
          </div>

          <div className="card card-pad">
            <span className="section-title">Where requests sit now</span>
            <div style={{ marginTop: 10 }}>
              {funnelRows.length === 0
                ? <span className="muted">No requests yet.</span>
                : funnelRows.map((r, i) => <BarRow key={r.label} label={r.label} value={r.value} max={funnelMax} color={anColor(i)}/>)}
            </div>
          </div>
        </div>

        <div className="card card-pad" style={{ marginTop: 14 }}>
          <span className="section-title">Requests by department</span>
          <div style={{ marginTop: 10 }}>
            {byDepartment.length === 0
              ? <span className="muted">No data.</span>
              : byDepartment.map((d, i) => <BarRow key={d.department} label={d.department} value={d.count} max={deptMax} color={anColor(i)}/>)}
          </div>
        </div>

        {!enoughForTiming && (
          <p className="help" style={{ marginTop: 10 }}>
            Time-to-access needs at least 5 provisioned requests before it's meaningful ({cycleTime.count} so far).
          </p>
        )}
        {enoughForTiming && (
          <p className="help" style={{ marginTop: 10 }}>
            Time to access - median {fmtHrs(cycleTime.medianHrs)}, 90th percentile {fmtHrs(cycleTime.p90Hrs)} (n={cycleTime.count}).
          </p>
        )}
      </Section>

      {/* ---- OPERATIONS ---- */}
      <Section title="Operations">
        <div className="an-grid-2">
          <div className="card card-pad">
            <span className="section-title">Officer workload &amp; throughput</span>
            <div style={{ marginTop: 10 }}>
              {officerStats.length === 0
                ? <span className="muted">No systems actioned yet.</span>
                : officerStats.map((o, i) => (
                    <BarRow key={o.userId} label={o.name} value={o.actioned} max={officerMax} color={anColor(i)}
                      sub={o.avgActionHrs != null ? `· ~${fmtHrs(o.avgActionHrs)} avg` : ""}/>
                  ))}
            </div>
          </div>

          <div className="card card-pad">
            <span className="section-title">Claimed, not yet actioned</span>
            <div style={{ marginTop: 10 }}>
              {stuckClaimed.length === 0
                ? <span className="muted">Nothing stuck - all claimed systems are moving.</span>
                : (
                  <div className="col gap-1">
                    {stuckClaimed.slice(0, 8).map((s, i) => (
                      <div key={i} className="an-stuck-row">
                        <div className="col" style={{ minWidth: 0 }}>
                          <strong style={{ fontSize: 12.5 }}>{s.system}</strong>
                          <span className="muted" style={{ fontSize: 11.5 }}>{s.ref} · {s.officer}</span>
                        </div>
                        <span className={"badge " + (s.waitingHrs >= 72 ? "badge-red" : s.waitingHrs >= 24 ? "badge-amber" : "badge-gray")}>
                          {fmtHrs(s.waitingHrs)}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
            </div>
          </div>
        </div>

        <div className="card card-pad" style={{ marginTop: 14 }}>
          <span className="section-title">Systems · demand and grant rate</span>
          <div className="an-sys-head">
            <span>System</span><span>Requested</span><span>Grant rate</span>
          </div>
          {topSystems.length === 0
            ? <span className="muted">No systems requested yet.</span>
            : topSystems.map((s, i) => (
                <div key={s.system} className="an-sys-row">
                  <div className="an-sys-name" title={s.system}>{s.system}</div>
                  <div className="an-sys-bar">
                    <div className="an-bar-track" style={{ flex: 1 }}>
                      <div className="an-bar-fill" style={{ width: `${Math.max(2, Math.round((s.total / sysMax) * 100))}%`, background: anColor(i) }}/>
                    </div>
                    <span className="an-sys-count">{s.total}</span>
                  </div>
                  <div className="an-sys-grant">
                    <SplitBar granted={s.granted} denied={s.denied}/>
                    <span className="muted" style={{ fontSize: 11.5, minWidth: 38, textAlign: "right" }}>
                      {s.grantPct != null ? pct(s.grantPct) : "-"}
                    </span>
                  </div>
                </div>
              ))}
          <p className="help" style={{ marginTop: 10 }}>
            Grant rate = granted ÷ (granted + denied) for systems that reached a decision.
          </p>
        </div>
      </Section>
    </div>
  );
}

window.Analytics = Analytics;
