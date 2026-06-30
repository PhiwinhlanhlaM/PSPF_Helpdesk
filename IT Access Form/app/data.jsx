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

// Identities always come from the embedding CRM (window.__CRM_USER__) and from
// each request's server-provided names. These neutral fallbacks exist only so the
// app does not crash if rendered standalone; no demo personal names are used.
const PEOPLE = {
  managers: [{ id: "m1", name: "Requestor", email: "", title: "Requesting Admin", initials: "RA" }],
  officers: [{ id: "o1", name: "IT Officer", email: "", title: "IT Officer", initials: "IO", isSelf: true }],
  director: { id: "d1", name: "IT Director", email: "", title: "Director of ICT", initials: "ID" },
};

function buildSeedRequests() {
  return [];
}

// ---------- Helpers ----------
function fmtDateTime(iso) {
  if (!iso) return "-";
  const d = new Date(iso);
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, "0");
  const mi = String(d.getMinutes()).padStart(2, "0");
  return `${dd}/${mm}/${yyyy} · ${hh}:${mi}`;
}
function fmtDate(iso) {
  if (!iso) return "-";
  const d = new Date(iso);
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
}
function fmtRelative(iso) {
  if (!iso) return "-";
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff/60) + "m ago";
  if (diff < 86400) return Math.floor(diff/3600) + "h ago";
  return Math.floor(diff/86400) + "d ago";
}

// Display name of a request's submitter, always from the server-provided
// submittedByName (saved full name, else email local-part, else username).
function submitterName(req) {
  return req.submittedByName || "-";
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

// Action chain: Admin -> IT Officer -> Director (single officer sufficient).
// The person shown for each step is the REAL actor: the request submitter for
// the manager step, and the actual approver (from the approval record) for the
// officer/director steps. No demo seed people are used.
function chainSteps(req) {
  return [
    { key: "manager",   label: "Admin (Requesting)",  person: { name: req.submittedByName || "-" } },
    { key: "officer-1", label: "IT Officer (Action)", person: null },
    { key: "director",  label: "Director of ICT",     person: null },
  ].map(step => {
    const approval = req.approvals.find(a => a.role === step.key);
    // Once a step has been actioned, show whoever actually actioned it.
    const person = approval && approval.personName
      ? { name: approval.personName }
      : step.person;
    return { ...step, person, approval };
  });
}

// ---- Per-system claim/action helpers ----
// Each system carries: status ('pending'|'claimed'|'actioned'), claimedBy, actionedBy.
// Older records without these fields are treated as plain 'pending'.
function sysStatus(s)  { return s.status || "pending"; }

// Systems still free for any officer to claim.
function claimableSystems(req) {
  return (req.systems || []).filter(s => sysStatus(s) === "pending");
}
// Systems this officer has claimed but not yet actioned (i.e. needs to sign for).
function systemsToAction(req, officerId) {
  return (req.systems || []).filter(s => sysStatus(s) === "claimed" && s.claimedBy === officerId);
}
// Can this officer claim anything on this request right now?
function canOfficerClaim(req) {
  return ["new", "claimed"].includes(req.status) && claimableSystems(req).length > 0;
}
// Does this officer have claimed-but-unactioned systems to sign for?
function canOfficerSign(req, officerId) {
  return req.status === "claimed" && systemsToAction(req, officerId).length > 0;
}

// What's the next pending step? Single officer sufficient, no officer-2 required.
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
  getPerson, getSystem, statusMeta, chainSteps, nextStep, submitterName,
  sysStatus, claimableSystems, systemsToAction, canOfficerClaim, canOfficerSign,
});
