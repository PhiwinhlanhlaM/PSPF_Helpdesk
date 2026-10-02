<?php
// trip_helpers.php
//
// Shared trip status and report helpers used by the dashboards, reports and
// the request details popup.
//
// "In Progress" is not stored in the database. A request is in progress when
// it is fully approved and its date/time required has passed, i.e. the vehicle
// has left and has not yet been returned (returning it sets status 'closed').

// status => [Bootstrap badge classes, label]
const VB_STATUS_LABELS = [
    'pending_driver'     => ['bg-secondary',        'Awaiting Driver'],
    'pending_supervisor' => ['bg-info text-dark',   'Awaiting Supervisor'],
    'pending_hrm'        => ['bg-primary',          'Awaiting HRM'],
    'approved'           => ['bg-success',          'Approved'],
    'in_progress'        => ['bg-warning text-dark', 'In Progress'],
    'rejected'           => ['bg-danger',           'Rejected'],
    'closed'             => ['bg-dark',             'Trip Completed'],
];

/** Current time as MySQL DATETIME, from PHP so display and filters agree. */
function vbNow(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Status to display for a request row: 'in_progress' for an approved trip
 * whose departure time has passed, otherwise the stored status.
 */
function vbTripStatus(array $r): string
{
    $status = (string)($r['status'] ?? '');
    if ($status !== 'approved' || empty($r['date_required'])) {
        return $status;
    }
    $departs = strtotime($r['date_required'] . ' ' . ($r['time_required'] ?: '00:00:00'));
    return ($departs !== false && $departs <= time()) ? 'in_progress' : 'approved';
}

function vbStatusLabel(string $status): string
{
    return VB_STATUS_LABELS[$status][1] ?? ucwords(str_replace('_', ' ', $status));
}

/** Badge HTML for a request row (uses the derived status). */
function vbStatusBadge(array $r): string
{
    $status = vbTripStatus($r);
    $class  = VB_STATUS_LABELS[$status][0] ?? 'bg-light text-dark';
    return '<span class="badge ' . $class . '">' . htmlspecialchars(vbStatusLabel($status)) . '</span>';
}

/** "HH:MM" from a TIME column, or '' when empty. */
function vbTime(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

/**
 * SQL WHERE parts for the transport report (page, Excel and PDF share this).
 * The 'in_progress' status filter is derived; 'approved' then means approved
 * trips that have not left yet.
 */
function vbReportFilters(array $source, array &$params): array
{
    $where = [];

    if (!empty($source['from_date'])) {
        $where[] = "vr.created_at >= ?";
        $params[] = $source['from_date'] . " 00:00:00";
    }
    if (!empty($source['to_date'])) {
        $where[] = "vr.created_at <= ?";
        $params[] = $source['to_date'] . " 23:59:59";
    }
    if (!empty($source['department'])) {
        $where[] = "vr.department LIKE ?";
        $params[] = "%" . $source['department'] . "%";
    }
    if (!empty($source['destination'])) {
        $where[] = "vr.destination LIKE ?";
        $params[] = "%" . $source['destination'] . "%";
    }
    if (!empty($source['requester'])) {
        $where[] = "u.name LIKE ?";
        $params[] = "%" . $source['requester'] . "%";
    }
    if (!empty($source['vehicle_id'])) {
        $where[] = "vr.vehicle_id = ?";
        $params[] = $source['vehicle_id'];
    }
    if (!empty($source['status'])) {
        $departs = "TIMESTAMP(vr.date_required, COALESCE(vr.time_required, '00:00:00'))";
        if ($source['status'] === 'in_progress') {
            $where[] = "vr.status = 'approved' AND $departs <= ?";
            $params[] = vbNow();
        } elseif ($source['status'] === 'approved') {
            $where[] = "vr.status = 'approved' AND $departs > ?";
            $params[] = vbNow();
        } else {
            $where[] = "vr.status = ?";
            $params[] = $source['status'];
        }
    }
    if (($source['mileage_min'] ?? '') !== '') {
        $where[] = "vr.mileage_out >= ?";
        $params[] = $source['mileage_min'];
    }
    if (($source['mileage_max'] ?? '') !== '') {
        $where[] = "vr.mileage_in <= ?";
        $params[] = $source['mileage_max'];
    }

    return $where;
}
