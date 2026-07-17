<?php
// Shared helpers for dashboard KPI calculations.
//
// Keeps the "done" definition and duration formatting consistent across the
// admin and agent dashboards so the two never disagree about what counts as
// overdue or how a resolution time is displayed.

if (!function_exists('formatDuration')) {
    /**
     * Format a duration given in MINUTES into a compact human-readable string.
     *
     *   null / non-numeric -> "N/A"   (no completed tickets to average)
     *   0                  -> "0m"
     *   45                 -> "45m"
     *   150                -> "2h 30m"
     *   2880               -> "2d 0h"
     */
    function formatDuration($minutes)
    {
        if ($minutes === null || $minutes === '' || !is_numeric($minutes)) {
            return 'N/A';
        }

        $minutes = (int) round($minutes);
        if ($minutes <= 0) {
            return '0m';
        }

        $days  = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins  = $minutes % 60;

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        }
        if ($hours > 0) {
            return $hours . 'h ' . $mins . 'm';
        }
        return $mins . 'm';
    }
}

// Statuses that mean the ticket is finished. A finished ticket is never
// "overdue", regardless of how old it is.
//
//   - Resolved / Closed:      work is complete.
//   - Pending Feedback:       agent work is done; waiting on the requester,
//                             so it is not an agent/department SLA breach.
if (!defined('TERMINAL_TICKET_STATUSES')) {
    define('TERMINAL_TICKET_STATUSES', "'Resolved', 'Closed', 'Pending Feedback'");
}

// SQL that resolves to the moment a ticket actually reached a completed state,
// taken from the status-change log (the real resolve/close timestamp) rather
// than tickets.updated_at, which any later edit would bump. Falls back to
// updated_at for legacy rows with no matching log entry.
//
// Assumes the tickets table is aliased "t" in the surrounding query.
if (!defined('RESOLVED_AT_SQL')) {
    define('RESOLVED_AT_SQL',
        "COALESCE(" .
        "(SELECT MAX(l.change_date) FROM ticket_status_logs l " .
        " WHERE l.ticket_id = t.id AND l.new_status IN ('Resolved', 'Closed')), " .
        "t.updated_at)"
    );
}
