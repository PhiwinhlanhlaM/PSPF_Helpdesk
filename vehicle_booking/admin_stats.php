<?php
// admin_stats.php
//
// Figures for the Administrator dashboard's "System Statistics" section and
// its CSV export (admin_stats_export.php). Everything is read from the tables
// the system already writes to:
//
//   vehicle_requests      requests raised, status, mileage
//   request_logs          every action taken (approve / reject / assign / return),
//                         including the "(via email)" ones from email_action.php
//   email_action_tokens   approval links sent by email and whether they were used
//   users                 accounts and roles
//   login_logs            sign-ins (optional: created by sql/login_logs.sql)
//
// All figures are for one reporting period: from/to dates picked on the
// dashboard (defaults to the current month).

require_once __DIR__ . '/trip_helpers.php';

/**
 * Reporting period from the query string. Returns [from 'Y-m-d', to 'Y-m-d'].
 * Invalid or missing dates fall back to the 1st of this month .. today.
 */
function vbStatsPeriod(array $src): array
{
    $valid = static function ($d): bool {
        if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y);
    };
    $from = $valid($src['from'] ?? null) ? $src['from'] : date('Y-m-01');
    $to   = $valid($src['to'] ?? null)   ? $src['to']   : date('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

/**
 * SQL CASE that sorts a request_logs.action into a stage and outcome.
 * Matches the exact wording written by the approve/reject/assign pages and
 * by email_action.php (which appends " (via email)").
 */
const VB_LOG_STAGE_SQL = "
    CASE
        WHEN rl.action LIKE 'Vehicle assigned%'                     THEN 'driver'
        WHEN rl.action LIKE 'Request rejected at vehicle assignment%' THEN 'driver'
        WHEN rl.action LIKE 'Supervisor %'                          THEN 'supervisor'
        WHEN rl.action LIKE 'HRM %'                                 THEN 'hrm'
        ELSE NULL
    END";

const VB_LOG_OUTCOME_SQL = "
    CASE
        WHEN rl.action LIKE 'Vehicle assigned%'  THEN 'approved'
        WHEN rl.action LIKE '% approved%'        THEN 'approved'
        WHEN rl.action LIKE '%rejected%'         THEN 'rejected'
        ELSE NULL
    END";

const VB_STAGE_LABELS = [
    'driver'     => 'Vehicle assignment (Driver)',
    'supervisor' => 'Supervisor',
    'hrm'        => 'HRM',
];

/** True when the optional login_logs table exists. */
function vbHasLoginLogs(PDO $conn): bool
{
    try {
        $conn->query("SELECT 1 FROM login_logs LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** True when the email_action_tokens table exists (email approvals installed). */
function vbHasEmailTokens(PDO $conn): bool
{
    try {
        $conn->query("SELECT 1 FROM email_action_tokens LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Record a successful sign-in. Never breaks login: if login_logs has not
 * been created yet the error is ignored.
 */
function vbRecordLogin(PDO $conn, int $userId, string $method): void
{
    try {
        $conn->prepare("
            INSERT INTO login_logs (user_id, method, ip_address, user_agent, logged_in_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $userId,
            $method,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            vbNow(),
        ]);
    } catch (PDOException $e) {
        // login_logs not installed yet; sign-in carries on.
    }
}

/**
 * Time buckets for the "requests over time" chart: daily for up to a month,
 * weekly up to six months, monthly beyond. Returns [unit, [key => label]].
 */
function vbStatsBuckets(string $from, string $to): array
{
    $start = new DateTimeImmutable($from);
    $end   = new DateTimeImmutable($to);
    $days  = (int)$start->diff($end)->days + 1;

    $buckets = [];
    if ($days <= 31) {
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $buckets[$d->format('Y-m-d')] = $d->format('j M');
        }
        return ['day', $buckets];
    }
    if ($days <= 186) {
        // Weeks start on Monday, matching MySQL YEARWEEK(date, 3) / ISO weeks.
        $d = $start->modify('monday this week');
        for (; $d <= $end; $d = $d->modify('+1 week')) {
            $buckets[$d->format('o-W')] = 'Wk of ' . $d->format('j M');
        }
        return ['week', $buckets];
    }
    $d = $start->modify('first day of this month');
    for (; $d <= $end; $d = $d->modify('+1 month')) {
        $buckets[$d->format('Y-m')] = $d->format('M Y');
    }
    return ['month', $buckets];
}

/** Every figure the dashboard and export show, for one period. */
function vbCollectStats(PDO $conn, string $from, string $to): array
{
    $fromTs = $from . ' 00:00:00';
    $toTs   = $to . ' 23:59:59';
    $range  = [$fromTs, $toTs];
    $stats  = ['from' => $from, 'to' => $to];

    $one = static function (string $sql, array $params = []) use ($conn) {
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    };
    $all = static function (string $sql, array $params = []) use ($conn): array {
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    // ---- Requests raised in the period ---------------------------------
    $stats['requests_total'] = (int)$one(
        "SELECT COUNT(*) FROM vehicle_requests vr WHERE vr.created_at BETWEEN ? AND ?", $range
    );

    $statusCounts = array_fill_keys(array_keys(VB_STATUS_LABELS), 0);
    $rows = $all("
        SELECT vr.status, vr.date_required, vr.time_required
        FROM vehicle_requests vr
        WHERE vr.created_at BETWEEN ? AND ?
    ", $range);
    foreach ($rows as $r) {
        $s = vbTripStatus($r);
        $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
    }
    $stats['by_status'] = $statusCounts;
    $stats['pending']   = $statusCounts['pending_driver'] + $statusCounts['pending_supervisor'] + $statusCounts['pending_hrm'];

    $stats['by_department'] = $all("
        SELECT COALESCE(NULLIF(TRIM(vr.department), ''), 'Not specified') AS label, COUNT(*) AS value
        FROM vehicle_requests vr
        WHERE vr.created_at BETWEEN ? AND ?
        GROUP BY label
        ORDER BY value DESC, label
    ", $range);

    // Requests over time, with empty buckets filled in as zero.
    [$unit, $buckets] = vbStatsBuckets($from, $to);
    $keySql = [
        'day'   => "DATE_FORMAT(vr.created_at, '%Y-%m-%d')",
        'week'  => "DATE_FORMAT(vr.created_at - INTERVAL WEEKDAY(vr.created_at) DAY, '%x-%v')",
        'month' => "DATE_FORMAT(vr.created_at, '%Y-%m')",
    ][$unit];
    $series = array_fill_keys(array_keys($buckets), 0);
    foreach ($all("
        SELECT $keySql AS k, COUNT(*) AS n
        FROM vehicle_requests vr
        WHERE vr.created_at BETWEEN ? AND ?
        GROUP BY k
    ", $range) as $r) {
        if (array_key_exists($r['k'], $series)) {
            $series[$r['k']] = (int)$r['n'];
        }
    }
    $stats['timeline_unit'] = $unit;
    $stats['timeline'] = [];
    foreach ($buckets as $k => $label) {
        $stats['timeline'][] = ['label' => $label, 'value' => $series[$k]];
    }

    // ---- Actions taken (approvals, rejections, assignments) ------------
    $stage   = VB_LOG_STAGE_SQL;
    $outcome = VB_LOG_OUTCOME_SQL;
    $actions = $all("
        SELECT $stage AS stage,
               $outcome AS outcome,
               (rl.action LIKE '%(via email)%') AS via_email,
               COUNT(*) AS n,
               COUNT(DISTINCT rl.request_id) AS requests
        FROM request_logs rl
        WHERE rl.created_at BETWEEN ? AND ?
          AND rl.request_id IS NOT NULL
        GROUP BY stage, outcome, via_email
    ", $range);

    $channel = [];
    foreach (array_keys(VB_STAGE_LABELS) as $s) {
        $channel[$s] = ['system' => 0, 'email' => 0, 'approved' => 0, 'rejected' => 0];
    }
    $stats['actions_total']  = 0;
    $stats['email_actions']  = 0;
    $stats['email_approved'] = 0;
    $stats['email_rejected'] = 0;
    $stats['approved_total'] = 0;
    $stats['rejected_total'] = 0;
    foreach ($actions as $a) {
        if ($a['stage'] === null || $a['outcome'] === null) continue;
        $n = (int)$a['n'];
        $isEmail = (int)$a['via_email'] === 1;
        $channel[$a['stage']][$isEmail ? 'email' : 'system'] += $n;
        $channel[$a['stage']][$a['outcome']] += $n;
        $stats['actions_total'] += $n;
        $stats[$a['outcome'] . '_total'] += $n;
        if ($isEmail) {
            $stats['email_actions'] += $n;
            $stats['email_' . $a['outcome']] += $n;
        }
    }
    $stats['channel'] = $channel;

    $stats['requests_actioned'] = (int)$one("
        SELECT COUNT(DISTINCT rl.request_id)
        FROM request_logs rl
        WHERE rl.created_at BETWEEN ? AND ?
          AND rl.request_id IS NOT NULL
          AND ($stage) IS NOT NULL
    ", $range);

    $stats['trips_completed'] = (int)$one("
        SELECT COUNT(*) FROM request_logs rl
        WHERE rl.created_at BETWEEN ? AND ? AND rl.action = 'Vehicle returned'
    ", $range);

    // Average time from submission to final HRM approval, for requests
    // fully approved in the period.
    $avg = $one("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, sub.submitted_at, fin.approved_at))
        FROM (
            SELECT request_id, MAX(created_at) AS approved_at
            FROM request_logs
            WHERE action LIKE 'HRM approved%' AND created_at BETWEEN ? AND ?
            GROUP BY request_id
        ) fin
        JOIN (
            SELECT request_id, MIN(created_at) AS submitted_at
            FROM request_logs
            WHERE action = 'Request submitted'
            GROUP BY request_id
        ) sub ON sub.request_id = fin.request_id
    ", $range);
    $stats['avg_approval_hours'] = $avg === null || $avg === false ? null : round(((float)$avg) / 60, 1);

    // ---- Email approval links ------------------------------------------
    $stats['email_tokens'] = null;
    if (vbHasEmailTokens($conn)) {
        $t = $all("
            SELECT COUNT(*) AS sent,
                   SUM(used_at IS NOT NULL) AS used,
                   SUM(used_at IS NULL AND expires_at < ?) AS expired
            FROM email_action_tokens
            WHERE created_at BETWEEN ? AND ?
        ", [vbNow(), $fromTs, $toTs])[0];
        $stats['email_tokens'] = [
            'sent'    => (int)$t['sent'],
            'used'    => (int)$t['used'],
            'expired' => (int)$t['expired'],
        ];
    }

    // ---- Users -----------------------------------------------------------
    $stats['users_total']  = (int)$one("SELECT COUNT(*) FROM users");
    $stats['users_active'] = (int)$one("SELECT COUNT(*) FROM users WHERE active = 1");
    $stats['users_new']    = (int)$one("SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?", $range);
    $stats['users_by_role'] = $all("
        SELECT role AS label, COUNT(*) AS value
        FROM users WHERE active = 1
        GROUP BY role ORDER BY value DESC, role
    ");

    // People who did something in the period: raised a request or took an action.
    $stats['users_engaged'] = (int)$one("
        SELECT COUNT(DISTINCT uid) FROM (
            SELECT rl.action_by AS uid FROM request_logs rl
            WHERE rl.created_at BETWEEN ? AND ? AND rl.action_by IS NOT NULL
            UNION
            SELECT vr.requester_id FROM vehicle_requests vr
            WHERE vr.created_at BETWEEN ? AND ?
        ) x
    ", [$fromTs, $toTs, $fromTs, $toTs]);

    $stats['top_users'] = $all("
        SELECT u.name, u.role, u.department,
               SUM(rl.action = 'Request submitted') AS submitted,
               SUM(rl.action <> 'Request submitted') AS actioned,
               SUM(rl.action LIKE '%(via email)%') AS via_email,
               COUNT(*) AS total
        FROM request_logs rl
        JOIN users u ON u.user_id = rl.action_by
        WHERE rl.created_at BETWEEN ? AND ? AND rl.request_id IS NOT NULL
        GROUP BY u.user_id, u.name, u.role, u.department
        ORDER BY total DESC, u.name
        LIMIT 10
    ", $range);

    $stats['logins'] = null;
    if (vbHasLoginLogs($conn)) {
        $l = $all("
            SELECT COUNT(*) AS total,
                   COUNT(DISTINCT user_id) AS users,
                   SUM(method = 'sso') AS sso
            FROM login_logs
            WHERE logged_in_at BETWEEN ? AND ?
        ", $range)[0];
        $stats['logins'] = [
            'total' => (int)$l['total'],
            'users' => (int)$l['users'],
            'sso'   => (int)$l['sso'],
        ];
    }

    // ---- Vehicles ----------------------------------------------------------
    $stats['vehicle_usage'] = $all("
        SELECT CONCAT(v.registration, ' · ', COALESCE(v.make, ''), ' ', COALESCE(v.model, '')) AS label,
               COUNT(vr.request_id) AS trips,
               COALESCE(SUM(CASE WHEN vr.mileage_in >= vr.mileage_out
                                 THEN vr.mileage_in - vr.mileage_out END), 0) AS km
        FROM vehicles v
        LEFT JOIN vehicle_requests vr
               ON vr.vehicle_id = v.vehicle_id
              AND vr.status IN ('approved', 'closed')
              AND vr.created_at BETWEEN ? AND ?
        GROUP BY v.vehicle_id, v.registration, v.make, v.model
        ORDER BY trips DESC, km DESC, v.registration
    ", $range);
    $stats['km_total'] = array_sum(array_map(static fn($v) => (int)$v['km'], $stats['vehicle_usage']));

    // ---- Activity log ------------------------------------------------------
    $stats['recent_logs'] = $all("
        SELECT rl.created_at, rl.request_id, rl.action, u.name
        FROM request_logs rl
        LEFT JOIN users u ON u.user_id = rl.action_by
        WHERE rl.created_at BETWEEN ? AND ?
        ORDER BY rl.created_at DESC, rl.log_id DESC
        LIMIT 25
    ", $range);
    $stats['logs_total'] = (int)$one(
        "SELECT COUNT(*) FROM request_logs rl WHERE rl.created_at BETWEEN ? AND ?", $range
    );

    return $stats;
}

/** Percentage helper: "42%" or "–" when the base is zero. */
function vbPct(int $part, int $whole): string
{
    return $whole > 0 ? round($part * 100 / $whole) . '%' : '–';
}

/** Role key to the label used in the navbar. */
function vbRoleLabel(string $role): string
{
    return [
        'user' => 'Staff', 'driver' => 'Driver', 'supervisor' => 'Supervisor',
        'hrm' => 'HRM', 'admin' => 'Administrator', 'viewer' => 'Viewer',
        'superuser' => 'IT Superuser',
    ][$role] ?? ucfirst($role);
}
