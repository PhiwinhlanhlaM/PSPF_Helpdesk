<?php
// ticket/send_department_digests.php
//
// Daily "department digest": for each department (division) that has at least
// one active admin, classify the tickets it received in the reporting window
// and email a summary to that department's admin(s). Categories come from the
// stored tickets.category value (filled at submission time / by the backfill),
// with a live classifier fallback for any un-tagged row.
//
// Driven by a scheduled task once a day. Examples:
//   Linux cron (07:00 daily):
//     0 7 * * * php /var/www/pspf_crm/api/ticket/send_department_digests.php >> /var/log/pspf_digest.log 2>&1
//   Windows Task Scheduler:
//     php.exe C:\xampp\htdocs\pspf_crm\api\ticket\send_department_digests.php
//
// Options:
//   --date=YYYY-MM-DD   Reporting date (default: yesterday).
//   --window-days=N     Days of history to include, ending on --date (default 1).
//   --include-empty     Also email departments with no new tickets and no backlog.
//   --force             Ignore the per-day send log (allow re-send).
//   --dry               Print digests to STDOUT; do not email or log.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/ticket_classifier.php';
require_once __DIR__ . '/../includes/metrics_helpers.php';
require_once __DIR__ . '/../mail_config.php'; // getMailer()

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$opts         = getopt('', ['date:', 'window-days:', 'include-empty', 'force', 'dry']);
$reportDate   = $opts['date'] ?? date('Y-m-d', strtotime('-1 day'));
$windowDays   = max(1, (int)($opts['window-days'] ?? 1));
$includeEmpty = array_key_exists('include-empty', $opts);
$force        = array_key_exists('force', $opts);
$dryRun       = array_key_exists('dry', $opts);

// Validate --date.
$dt = DateTime::createFromFormat('Y-m-d', $reportDate);
if (!$dt || $dt->format('Y-m-d') !== $reportDate) {
    fwrite(STDERR, "Invalid --date '$reportDate' (expected YYYY-MM-DD).\n");
    exit(1);
}

// Window: [windowStart, windowEnd). For window-days=1 this is the single
// reporting day; for N it is the N days ending on (and including) --date.
$windowStart = date('Y-m-d 00:00:00', strtotime("$reportDate -" . ($windowDays - 1) . " day"));
$windowEnd   = date('Y-m-d 00:00:00', strtotime("$reportDate +1 day"));

$rangeLabel = $windowDays === 1
    ? date('D, j M Y', strtotime($reportDate))
    : date('j M Y', strtotime($windowStart)) . ' – ' . date('j M Y', strtotime($reportDate));

// ---------------------------------------------------------------------
// Recipients: active admins, grouped by their department (division).
// ---------------------------------------------------------------------
$adminSql = "
    SELECT DISTINCT u.email, u.username, u.division_id, d.division_name
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r       ON r.id = ur.role_id
    LEFT JOIN divisions d ON d.id = u.division_id
    WHERE r.name = 'admin'
      AND (u.is_active = 1 OR u.is_active IS NULL)
      AND u.email IS NOT NULL AND u.email <> ''
      AND u.division_id IS NOT NULL AND u.division_id <> 0
    ORDER BY u.division_id
";
$adminRes = $conn->query($adminSql);
if (!$adminRes) {
    fwrite(STDERR, "Admin lookup failed: " . $conn->error . "\n");
    exit(1);
}

$departments = []; // division_id => ['name' => ..., 'recipients' => [ [email,name], ... ]]
while ($row = $adminRes->fetch_assoc()) {
    $divId = (int)$row['division_id'];
    if (!isset($departments[$divId])) {
        $departments[$divId] = [
            'name'       => $row['division_name'] ?: ('Division #' . $divId),
            'recipients' => [],
        ];
    }
    $departments[$divId]['recipients'][] = [
        'email' => $row['email'],
        'name'  => $row['username'] ?? '',
    ];
}

if (!$departments) {
    echo "No departments with active admins found. Nothing to send.\n";
    exit(0);
}

// Prepared statements reused across departments.
$newTicketsStmt = $conn->prepare("
    SELECT id, title, description, member_type, source, category,
           priority, status, created_by, query_date
    FROM tickets
    WHERE division_id = ? AND query_date >= ? AND query_date < ?
    ORDER BY query_date ASC
");
$backlogStmt = $conn->prepare("
    SELECT COUNT(*) AS open_backlog
    FROM tickets
    WHERE division_id = ?
      AND status NOT IN (" . TERMINAL_TICKET_STATUSES . ")
");

$sentCount    = 0;
$skippedEmpty = 0;
$skippedDup   = 0;

foreach ($departments as $divId => $dept) {
    // --- gather new tickets in the window ---
    $newTicketsStmt->bind_param('iss', $divId, $windowStart, $windowEnd);
    $newTicketsStmt->execute();
    $res = $newTicketsStmt->get_result();

    $tickets = [];
    while ($t = $res->fetch_assoc()) {
        // Prefer the stored category; classify on the fly if it's missing.
        if (empty($t['category'])) {
            $t['category'] = classifyTicket(
                (string)$t['title'], (string)$t['description'],
                (string)$t['member_type'] . ' ' . (string)$t['source']
            );
        }
        $tickets[] = $t;
    }

    // --- open backlog for the department (all-time, not just the window) ---
    $backlogStmt->bind_param('i', $divId);
    $backlogStmt->execute();
    $backlog = (int)($backlogStmt->get_result()->fetch_assoc()['open_backlog'] ?? 0);

    $newCount = count($tickets);

    // Nothing worth emailing? Skip unless the operator asked for empty digests.
    if ($newCount === 0 && $backlog === 0 && !$includeEmpty) {
        $skippedEmpty++;
        continue;
    }

    // --- per-day idempotency: claim this (division, date) unless forced/dry ---
    if (!$dryRun && !$force) {
        $recipientList = implode(', ', array_map(fn($r) => $r['email'], $dept['recipients']));
        $claim = $conn->prepare("
            INSERT INTO department_digest_log
                (division_id, digest_date, window_start, window_end, ticket_count, recipients)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $claim->bind_param('isssis', $divId, $reportDate, $windowStart, $windowEnd, $newCount, $recipientList);
        // Claim this (division, date). A duplicate key means it was already sent
        // today -> skip. Works whether mysqli is in exception mode (PHP 8.1+
        // default, execute() throws) or silent mode (execute() returns false).
        $claimed = false;
        try {
            $claimed = (bool)$claim->execute();
        } catch (\mysqli_sql_exception $e) {
            $claimed = false;
        }
        if (!$claimed) {
            // errno 1062 = duplicate key (expected, already sent). Anything else
            // is a real problem worth surfacing (e.g. migration not applied).
            if ($conn->errno && $conn->errno !== 1062) {
                fwrite(STDERR, "Digest log insert error for division $divId (errno {$conn->errno}): {$conn->error}\n");
            }
            $claim->close();
            $skippedDup++;
            continue;
        }
        $claim->close();
    }

    // --- build the digest and send ---
    $agg  = aggregateDigest($tickets, $backlog);
    $html = buildDigestHtml($dept['name'], $rangeLabel, $agg, $tickets);
    $text = buildDigestText($dept['name'], $rangeLabel, $agg, $tickets);
    $subject = "Daily Ticket Summary — {$dept['name']} — " . date('j M Y', strtotime($reportDate));

    if ($dryRun) {
        echo "========================================================\n";
        echo "TO: " . implode(', ', array_map(fn($r) => $r['email'], $dept['recipients'])) . "\n";
        echo "SUBJECT: $subject\n\n";
        echo $text . "\n\n";
        $sentCount++;
        continue;
    }

    try {
        $mail = getMailer();
        $mail->setFrom('administrator@pspf.co.sz', 'PSPF Helpdesk');
        foreach ($dept['recipients'] as $r) {
            $mail->addAddress($r['email'], $r['name']);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        $sentCount++;
    } catch (\Throwable $e) {
        error_log("Department digest email failed for division $divId: " . $e->getMessage());
        fwrite(STDERR, "Send failed for {$dept['name']} (division $divId): " . $e->getMessage() . "\n");
    }
}

echo "\nDepartment digests for $rangeLabel:\n";
echo "  Sent/printed : $sentCount\n";
echo "  Skipped empty: $skippedEmpty\n";
echo "  Skipped (already sent today): $skippedDup\n";

// =====================================================================
// Helpers
// =====================================================================

/**
 * Roll a list of ticket rows into counts by category / priority / status,
 * plus a shortlist of the tickets that need attention (High/Urgent priority
 * or Escalated status).
 *
 * @param array<int,array<string,mixed>> $tickets
 * @return array{new:int, backlog:int, byCategory:array<string,int>, byPriority:array<string,int>, byStatus:array<string,int>, attention:array<int,array<string,mixed>>}
 */
function aggregateDigest(array $tickets, int $backlog): array
{
    $byCategory = [];
    $byPriority = [];
    $byStatus   = [];
    $attention  = [];

    foreach ($tickets as $t) {
        $cat  = $t['category'] ?: 'General';
        $prio = $t['priority'] ?: 'Unspecified';
        $stat = $t['status'] ?: 'Unknown';

        $byCategory[$cat]  = ($byCategory[$cat]  ?? 0) + 1;
        $byPriority[$prio] = ($byPriority[$prio] ?? 0) + 1;
        $byStatus[$stat]   = ($byStatus[$stat]   ?? 0) + 1;

        $isHot = in_array(strtolower($prio), ['high', 'urgent', 'critical'], true)
              || strcasecmp($stat, 'Escalated') === 0;
        if ($isHot) {
            $attention[] = $t;
        }
    }

    arsort($byCategory);
    // Priority in a sensible order rather than by count.
    $byPriority = orderByKnown($byPriority, ['Urgent', 'Critical', 'High', 'Medium', 'Low', 'Unspecified']);
    $byStatus   = orderByKnown($byStatus, ['Open', 'In Progress', 'Escalated', 'Pending Feedback', 'Resolved', 'Closed']);

    return [
        'new'        => count($tickets),
        'backlog'    => $backlog,
        'byCategory' => $byCategory,
        'byPriority' => $byPriority,
        'byStatus'   => $byStatus,
        'attention'  => $attention,
    ];
}

/** Reorder an assoc count map by a known key order; unknown keys are appended. */
function orderByKnown(array $counts, array $order): array
{
    $out = [];
    foreach ($order as $k) {
        if (isset($counts[$k])) {
            $out[$k] = $counts[$k];
            unset($counts[$k]);
        }
    }
    foreach ($counts as $k => $v) {
        $out[$k] = $v;
    }
    return $out;
}

function fmtTicketId($id): string
{
    return 'TCK-' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT);
}

/** Build the HTML email body. */
function buildDigestHtml(string $deptName, string $rangeLabel, array $agg, array $tickets): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $countRows = function (array $map) use ($h): string {
        if (!$map) {
            return '<tr><td style="padding:6px 10px;color:#666;">None</td><td></td></tr>';
        }
        $rows = '';
        foreach ($map as $label => $n) {
            $rows .= '<tr>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $h($label) . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;font-weight:600;">' . (int)$n . '</td>'
                . '</tr>';
        }
        return $rows;
    };

    $attentionHtml = '';
    if (!empty($agg['attention'])) {
        $items = '';
        foreach ($agg['attention'] as $t) {
            $items .= '<tr>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;font-family:monospace;">' . $h(fmtTicketId($t['id'])) . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $h($t['title']) . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $h($t['category']) . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $h($t['priority']) . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $h($t['status']) . '</td>'
                . '</tr>';
        }
        $attentionHtml = '
            <h3 style="margin:24px 0 8px;color:#b02a37;">Needs attention (' . count($agg['attention']) . ')</h3>
            <table style="border-collapse:collapse;width:100%;font-size:14px;">
                <thead><tr style="background:#f8d7da;">
                    <th style="padding:6px 10px;text-align:left;">Ticket</th>
                    <th style="padding:6px 10px;text-align:left;">Title</th>
                    <th style="padding:6px 10px;text-align:left;">Category</th>
                    <th style="padding:6px 10px;text-align:left;">Priority</th>
                    <th style="padding:6px 10px;text-align:left;">Status</th>
                </tr></thead>
                <tbody>' . $items . '</tbody>
            </table>';
    }

    return '
    <div style="font-family:Arial,Helvetica,sans-serif;color:#222;max-width:680px;">
        <h2 style="margin:0 0 4px;">Daily Ticket Summary</h2>
        <p style="margin:0 0 2px;color:#555;"><strong>' . $h($deptName) . '</strong></p>
        <p style="margin:0 0 16px;color:#777;">' . $h($rangeLabel) . '</p>

        <table style="border-collapse:collapse;margin-bottom:18px;">
            <tr>
                <td style="padding:10px 18px;background:#0d6efd;color:#fff;font-size:26px;font-weight:700;text-align:center;">' . (int)$agg['new'] . '</td>
                <td style="padding:0 18px;color:#555;">new ticket(s) in this period</td>
            </tr>
            <tr>
                <td style="padding:10px 18px;background:#6c757d;color:#fff;font-size:26px;font-weight:700;text-align:center;">' . (int)$agg['backlog'] . '</td>
                <td style="padding:0 18px;color:#555;">still open (total backlog)</td>
            </tr>
        </table>

        <h3 style="margin:18px 0 8px;">By category</h3>
        <table style="border-collapse:collapse;width:100%;font-size:14px;">
            <thead><tr style="background:#eef;"><th style="padding:6px 10px;text-align:left;">Category</th><th style="padding:6px 10px;text-align:right;">Count</th></tr></thead>
            <tbody>' . $countRows($agg['byCategory']) . '</tbody>
        </table>

        <h3 style="margin:18px 0 8px;">By priority</h3>
        <table style="border-collapse:collapse;width:100%;font-size:14px;">
            <thead><tr style="background:#eef;"><th style="padding:6px 10px;text-align:left;">Priority</th><th style="padding:6px 10px;text-align:right;">Count</th></tr></thead>
            <tbody>' . $countRows($agg['byPriority']) . '</tbody>
        </table>

        <h3 style="margin:18px 0 8px;">By status</h3>
        <table style="border-collapse:collapse;width:100%;font-size:14px;">
            <thead><tr style="background:#eef;"><th style="padding:6px 10px;text-align:left;">Status</th><th style="padding:6px 10px;text-align:right;">Count</th></tr></thead>
            <tbody>' . $countRows($agg['byStatus']) . '</tbody>
        </table>
        ' . $attentionHtml . '

        <p style="margin-top:24px;color:#888;font-size:12px;">
            This is an automated summary from the PSPF Helpdesk. Sign in to review and action these tickets.
        </p>
    </div>';
}

/** Build the plain-text email body (also used for --dry output and AltBody). */
function buildDigestText(string $deptName, string $rangeLabel, array $agg, array $tickets): string
{
    $lines = [];
    $lines[] = 'DAILY TICKET SUMMARY';
    $lines[] = str_repeat('=', 40);
    $lines[] = 'Department: ' . $deptName;
    $lines[] = 'Period    : ' . $rangeLabel;
    $lines[] = '';
    $lines[] = 'New tickets this period : ' . (int)$agg['new'];
    $lines[] = 'Open backlog (total)    : ' . (int)$agg['backlog'];
    $lines[] = '';

    $block = function (string $title, array $map) use (&$lines) {
        $lines[] = $title;
        $lines[] = str_repeat('-', strlen($title));
        if (!$map) {
            $lines[] = '  (none)';
        } else {
            foreach ($map as $label => $n) {
                $lines[] = '  ' . str_pad((string)$label, 24) . (int)$n;
            }
        }
        $lines[] = '';
    };

    $block('By category', $agg['byCategory']);
    $block('By priority', $agg['byPriority']);
    $block('By status', $agg['byStatus']);

    if (!empty($agg['attention'])) {
        $lines[] = 'NEEDS ATTENTION (' . count($agg['attention']) . ')';
        $lines[] = str_repeat('-', 40);
        foreach ($agg['attention'] as $t) {
            $lines[] = '  ' . fmtTicketId($t['id']) . '  [' . $t['priority'] . '/' . $t['status'] . '] '
                     . '(' . $t['category'] . ') ' . $t['title'];
        }
        $lines[] = '';
    }

    $lines[] = '--';
    $lines[] = 'Automated summary from the PSPF Helpdesk. Sign in to action these tickets.';

    return implode("\n", $lines);
}
