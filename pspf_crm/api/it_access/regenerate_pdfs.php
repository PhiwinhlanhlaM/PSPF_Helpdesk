<?php
/**
 * One-off maintenance script — regenerate the authorization PDF for every
 * PROVISIONED IT access request and re-upload it to SharePoint.
 *
 * WHY: earlier PDFs embedded the logo and signatures as base64 data: URIs,
 * which the live mPDF did not render (broken-image boxes). generate_pdf.php now
 * writes those images to temp files and references them by path. This script
 * re-runs the (fixed) generator for existing provisioned requests so their
 * stored PDFs are correct.
 *
 * SAFETY:
 *   - CLI ONLY. Refuses to run over the web (no HTTP exposure).
 *   - Read-only preview by default. Pass --apply to actually regenerate.
 *   - Optional --ref=REQ-2026-0004 to target a single request.
 *   - Each run writes a NEW timestamped PDF file and updates pdf_filename /
 *     sharepoint_id. It never deletes the old PDF file (kept as history).
 *
 * RUN ON THE LIVE BOX (needs mPDF in vendor/ + the live sharepoint_config.php):
 *   cd C:\xampp\htdocs\pspf_crm\api\it_access
 *   C:\xampp\php\php.exe regenerate_pdfs.php            (preview — lists what would run)
 *   C:\xampp\php\php.exe regenerate_pdfs.php --apply    (regenerate + upload all)
 *   C:\xampp\php\php.exe regenerate_pdfs.php --apply --ref=REQ-2026-0004
 */

// --- CLI guard: never runnable via the web server ---
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this maintenance script runs from the command line only.\n");
}

$apply     = in_array('--apply', $argv, true);
$refFilter = null;
foreach ($argv as $a) {
    if (strpos($a, '--ref=') === 0) $refFilter = substr($a, strlen('--ref='));
}

require_once __DIR__ . '/../db.php';                 // provides $conn
require_once __DIR__ . '/generate_pdf.php';          // provides generateAndUploadPdf()

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    exit("ERROR: no database connection (check db.php).\n");
}

// Find provisioned requests (these are the only ones that have an authorization
// PDF). Order oldest-first for stable, readable output.
$sql = "SELECT id, ref_number, employee_name, pdf_filename, sharepoint_id
        FROM it_access_requests
        WHERE status = 'provisioned'";
$params = [];
$types  = '';
if ($refFilter !== null) {
    $sql .= " AND ref_number = ?";
    $types  = 's';
    $params = [$refFilter];
}
$sql .= " ORDER BY provisioned_at ASC, id ASC";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = count($rows);
echo "===========================================================\n";
echo " IT Access — regenerate provisioned authorization PDFs\n";
echo " Mode : " . ($apply ? "APPLY (will regenerate + upload)" : "PREVIEW (no changes)") . "\n";
if ($refFilter !== null) echo " Filter: ref = {$refFilter}\n";
echo " Found : {$total} provisioned request(s)\n";
echo "===========================================================\n\n";

if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

foreach ($rows as $r) {
    printf("  %-16s  %-28s  current PDF: %s\n",
        $r['ref_number'],
        mb_strimwidth((string)$r['employee_name'], 0, 28, '…'),
        $r['pdf_filename'] ?: '(none)'
    );
}

if (!$apply) {
    echo "\nPreview only. Re-run with --apply to regenerate these " . $total . " PDF(s).\n";
    exit(0);
}

echo "\nRegenerating...\n\n";

$ok = 0; $spOk = 0; $fail = 0;
foreach ($rows as $r) {
    $ref = $r['ref_number'];
    echo "  {$ref} ... ";
    try {
        $spId = generateAndUploadPdf($conn, (int)$r['id']);
        // Re-read the new filename the generator stored.
        $chk = $conn->prepare("SELECT pdf_filename FROM it_access_requests WHERE id = ?");
        $chk->bind_param("i", $r['id']);
        $chk->execute();
        $newName = $chk->get_result()->fetch_assoc()['pdf_filename'] ?? '';
        $chk->close();

        if ($newName && $newName !== $r['pdf_filename']) {
            $ok++;
            if ($spId) { $spOk++; echo "OK  (pdf: {$newName}, SharePoint: {$spId})\n"; }
            else       { echo "OK  (pdf: {$newName}) — SharePoint upload FAILED, PDF saved locally\n"; }
        } else {
            $fail++;
            echo "FAILED — generator returned no new PDF (check php error_log)\n";
        }
    } catch (\Throwable $e) {
        $fail++;
        echo "ERROR — " . $e->getMessage() . "\n";
    }
}

echo "\n-----------------------------------------------------------\n";
echo " Regenerated : {$ok}/{$total}\n";
echo " Uploaded to SharePoint: {$spOk}/{$ok}\n";
if ($fail) echo " FAILED      : {$fail} (see C:\\xampp\\php\\logs or php error_log)\n";
echo "-----------------------------------------------------------\n";
echo ($fail === 0 ? "Done.\n" : "Completed with errors — review the failures above.\n");
