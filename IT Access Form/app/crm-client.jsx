// CRM Integration Client
// Provides employee lookup and department data from the organisation's CRM.
// Configure CRM_CONFIG before deploying; the app falls back to local data
// when baseUrl is empty (useful during development).

const CRM_CONFIG = {
  baseUrl: "http://localhost/pspf_crm/api",
  apiKey: "",  // unused - CRM uses session cookie auth
  endpoints: {
    employee:    "/employees/lookup.php",
    employees:   "/employees/lookup.php",
    departments: "/departments/list.php",
  },
};

// Normalise a raw CRM employee record into the shape the form expects.
function normaliseCrmEmployee(raw) {
  return {
    id:         raw.employeeId   || raw.id         || "",
    name:       raw.fullName     || raw.name        || "",
    email:      raw.email        || "",
    department: raw.department   || raw.dept        || "",
    title:      raw.jobTitle     || raw.title       || "",
    managerId:  raw.managerId    || raw.manager?.id || null,
  };
}

function crmHeaders() {
  return {
    "Content-Type":  "application/json",
    "Authorization": `Bearer ${CRM_CONFIG.apiKey}`,
  };
}

/**
 * Look up a single employee by their employee ID.
 * Returns a normalised employee object, or null if not found / CRM unavailable.
 */
async function crmLookupEmployee(employeeId) {
  if (!CRM_CONFIG.baseUrl || !employeeId) return null;
  try {
    const url = CRM_CONFIG.baseUrl +
      CRM_CONFIG.endpoints.employee + "?id=" + encodeURIComponent(employeeId);
    const res = await fetch(url, { credentials: "include" }); // session cookie
    if (!res.ok) return null;
    return normaliseCrmEmployee(await res.json());
  } catch {
    return null;
  }
}

/**
 * Search employees by partial name (for autocomplete).
 * Returns an array of normalised employee objects.
 */
async function crmSearchEmployees(query) {
  if (!CRM_CONFIG.baseUrl || !query) return [];
  try {
    const url = CRM_CONFIG.baseUrl + CRM_CONFIG.endpoints.employees +
      `?q=${encodeURIComponent(query)}&limit=10`;
    const res = await fetch(url, { headers: crmHeaders() });
    if (!res.ok) return [];
    const data = await res.json();
    return (Array.isArray(data) ? data : data.results ?? []).map(normaliseCrmEmployee);
  } catch {
    return [];
  }
}

/**
 * Fetch the list of department names.
 * Falls back to the local DEPARTMENTS constant when the CRM is unreachable.
 */
async function crmFetchDepartments() {
  if (!CRM_CONFIG.baseUrl) return DEPARTMENTS;
  try {
    const url = CRM_CONFIG.baseUrl + CRM_CONFIG.endpoints.departments;
    const res = await fetch(url, { headers: crmHeaders() });
    if (!res.ok) return DEPARTMENTS;
    const data = await res.json();
    const names = (Array.isArray(data) ? data : data.results ?? [])
      .map(d => d.name || d)
      .filter(Boolean);
    return names.length ? names : DEPARTMENTS;
  } catch {
    return DEPARTMENTS;
  }
}

Object.assign(window, {
  CRM_CONFIG,
  crmLookupEmployee,
  crmSearchEmployees,
  crmFetchDepartments,
});
