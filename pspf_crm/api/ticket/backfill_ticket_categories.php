<?php
// ticket/backfill_ticket_categories.php
//
// One-off (safe to re-run) CLI backfill: classify every ticket that has no
// category yet and store the result in tickets.category. New tickets are
// classified at submission time; this catches the rows that predate the
// classifier.
//
// Run from the CLI on the server AFTER applying migration
// 003_add_ticket_category.sql:
//
//   php api/ticket/backfill_ticket_categories.php
//
// Options:
//   --all    Re-classify ALL tickets, not just those with a NULL/empty
//            category (use after tuning the classifier rules).
//   --dry    Show what would change without writing.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/ticket_classifier.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$opts   = getopt('', ['all', 'dry']);
$reAll  = array_key_exists('all', $opts);
$dryRun = array_key_exists('dry', $opts);

$where = $reAll ? '1=1' : "(category IS NULL OR category = '')";
$res   = $conn->query("SELECT id, title, description, member_type, source FROM tickets WHERE $where");

if (!$res) {
    fwrite(STDERR, "Query failed: " . $conn->error . "\n");
    exit(1);
}

$update = $conn->prepare("UPDATE tickets SET category = ? WHERE id = ?");

$counts = [];
$total  = 0;
$changed = 0;

while ($row = $res->fetch_assoc()) {
    $total++;
    $category = classifyTicket(
        (string)($row['title'] ?? ''),
        (string)($row['description'] ?? ''),
        (string)($row['member_type'] ?? '') . ' ' . (string)($row['source'] ?? '')
    );
    $counts[$category] = ($counts[$category] ?? 0) + 1;

    if ($dryRun) {
        printf("  #%-6d -> %s\n", $row['id'], $category);
        continue;
    }

    $id = (int)$row['id'];
    $update->bind_param('si', $category, $id);
    $update->execute();
    $changed += $update->affected_rows > 0 ? 1 : 0;
}

echo "\nClassified $total ticket(s)" . ($dryRun ? " (dry run, nothing written)" : ", $changed row(s) updated") . ".\n";
echo "Category distribution:\n";
arsort($counts);
foreach ($counts as $cat => $n) {
    printf("  %-24s %d\n", $cat, $n);
}
