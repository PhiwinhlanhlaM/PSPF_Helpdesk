// Shared data, constants, helpers, and state context for the PSPF IT Access prototype.

const SYSTEM_CATALOG = [
  {
    id: "inpensions",
    name: "INPENSIONS",
    desc: "Pension member records, contributions, benefit calculations",
    icon: "shield",
    roles: ["Capturer", "Viewer", "Authorizer", "Admin"],
  },
  {
    id: "smartstream",
    name: "SMARTSTREAM / SAGE300",
    desc: "Financial management & general ledger",
    icon: "bank",
    roles: ["Capturer", "Viewer", "Authorizer", "Admin"],
  },
  {
    id: "ad",
    name: "ACTIVE DIRECTORY & EMAIL ACCESS",
    desc: "Windows login, Outlook mailbox, Teams, OneDrive",
    icon: "key",
    subOptions: { label: "Duration", multi: false, options: ["Normal hours", "After hours"] },
  },
  {
    id: "physical",
    name: "PHYSICAL ACCESS",
    desc: "Door and room access",
    icon: "door",
    subOptions: [
      { label: "Room", multi: true, options: ["Server room", "Board room"] },
      { label: "Duration", multi: false, options: ["Normal hours", "After hours"] },
    ],
  },
  {
    id: "telephone",
    name: "TELEPHONE SYSTEM ACCESS",
    desc: "PABX dialing privileges",
    icon: "phone",
    subOptions: { label: "Level", multi: false, options: ["Local", "Cell", "SA", "International"] },
  },
  {
    id: "datastor",
    name: "DATASTOR ACCESS",
    desc: "Document management archive",
    icon: "archive",
    roles: ["Capturer", "Viewer", "Authorizer", "Admin"],
    subOptions: { label: "Stor (folder/path)", multi: false, text: true },
  },
  {
    id: "banking",
    name: "BANKING ACCESS",
    desc: "Payment processing & reconciliation",
    icon: "bank",
    roles: ["Capturer", "Viewer", "Authorizer", "Admin"],
    subOptions: { label: "Platform", multi: true, options: ["FNB", "STD", "MTN MoMo", "Nedbank", "E-Mali", "Eswatini Bank"] },
  },
  {
    id: "helpdesk",
    name: "HELPDESK / CRM",
    desc: "PSPF internal helpdesk and CRM access",
    icon: "shield-check",
    roles: ["User", "Agent", "Admin", "Superadmin"],
    multiRole: true,
  },
  {
    id: "trust",
    name: "TRUST ACCESS",
    desc: "Trust fund administration",
    icon: "scale",
    roles: ["Capturer", "Viewer", "Authorizer", "Admin"],
  },
  {
    id: "biometric",
    name: "BIOMETRIC ACCESS",
    desc: "Biometric device operator access",
    icon: "key",
    roles: ["Operator", "Approver", "Admin"],
  },
  {
    id: "other",
    name: "OTHER SYSTEM",
    desc: "Any system not listed above",
    icon: "archive",
    subOptions: [
      { label: "System name", multi: false, text: true },
      { label: "Role / access level", multi: false, text: true },
    ],
  },
];

const DEPARTMENTS = ["Finance", "ICT", "Corporate Services", "Operations", "Internal Auditing", "Investments"];

const PEOPLE = {
  managers: [
    { id: "m1", name: "David Johnson", email: "djohnson@pspf.co", title: "Finance Manager", initials: "DJ" },
    { id: "m2", name: "Susan Lee",      email: "slee@pspf.co",      title: "IT Manager",      initials: "SL" },
    { id: "m3", name: "Sarah Chen",     email: "schen@pspf.co",     title: "HR Manager",      initials: "SC" },
  ],
  officers: [
    { id: "o1", name: "Robert Lee",  email: "rlee@pspf.co",  title: "IT Officer",        initials: "RL" },
    { id: "o2", name: "You",         email: "you@pspf.co",   title: "IT Officer",        initials: "YO", isSelf: true },
  ],
  director: { id: "d1", name: "Margaret Chen", email: "mchen@pspf.co", title: "Director of ICT", initials: "MC" },
};

function buildSeedRequests() {
  return [];
}

// ---------- Helpers ----------
function fmtDateTime(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, "0");
  const mi = String(d.getMinutes()).padStart(2, "0");
  return `${dd}/${mm}/${yyyy} · ${hh}:${mi}`;
}
function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
}
function fmtRelative(iso) {
  if (!iso) return "—";
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff/60) + "m ago";
  if (diff < 86400) return Math.floor(diff/3600) + "h ago";
  return Math.floor(diff/86400) + "d ago";
}

function getPerson(personId) {
  if (!personId) return null;
  if (personId === PEOPLE.director.id) return PEOPLE.director;
  return [...PEOPLE.managers, ...PEOPLE.officers].find(p => p.id === personId) || null;
}

function getSystem(systemId) {
  return SYSTEM_CATALOG.find(s => s.id === systemId);
}

function statusMeta(status) {
  switch (status) {
    case "new":               return { label: "New",               cls: "badge-blue",  dot: true };
    case "claimed":           return { label: "Under review",      cls: "badge-blue",  dot: true };
    case "awaiting-officer-2":return { label: "Under review",      cls: "badge-blue",  dot: true };
    case "awaiting-director": return { label: "Awaiting director", cls: "badge-amber", dot: true };
    case "provisioned":       return { label: "Provisioned",       cls: "badge-green", dot: false };
    case "rejected":          return { label: "Rejected",          cls: "badge-red",   dot: false };
    default:                  return { label: status,              cls: "badge-gray",  dot: true };
  }
}

// Approval chain: Admin → IT Officer → Director (single officer sufficient)
function chainSteps(req) {
  return [
    { key: "manager",  label: "Admin (Requesting)",  person: getPerson(req.submittedBy) },
    { key: "officer-1", label: "IT Officer (Action)", person: null },
    { key: "director",  label: "Director of ICT",     person: PEOPLE.director },
  ].map(step => {
    const approval = req.approvals.find(a => a.role === step.key);
    return { ...step, approval };
  });
}

// What's the next pending step? Single officer sufficient — no officer-2 required.
function nextStep(req) {
  if (req.status === "rejected" || req.status === "provisioned") return null;
  const order = ["manager", "officer-1", "director"];
  for (const k of order) {
    if (!req.approvals.find(a => a.role === k && a.action === "approved")) return k;
  }
  return null;
}

// Make these globals available to other JSX files.
Object.assign(window, {
  SYSTEM_CATALOG, DEPARTMENTS, PEOPLE,
  buildSeedRequests, fmtDateTime, fmtDate, fmtRelative,
  getPerson, getSystem, statusMeta, chainSteps, nextStep,
});
