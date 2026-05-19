<?php
/**
 * Generates a PDF for a provisioned IT access request.
 * Included by approve.php and download_pdf.php — not a public endpoint.
 *
 * @param mysqli $conn
 * @param int    $requestId
 * @return string|null  pdf filename on success, null on failure
 */
function generateAndUploadPdf(mysqli $conn, int $requestId): ?string {
    $rStmt = $conn->prepare("
        SELECT r.*, u.Username AS submitter_name, u.Email AS submitter_email
        FROM it_access_requests r
        JOIN users u ON u.id = r.submitted_by
        WHERE r.id = ?
    ");
    $rStmt->bind_param("i", $requestId);
    $rStmt->execute();
    $req = $rStmt->get_result()->fetch_assoc();
    $rStmt->close();
    if (!$req) return null;

    $sStmt = $conn->prepare("SELECT * FROM it_request_systems WHERE request_id = ? ORDER BY id");
    $sStmt->bind_param("i", $requestId);
    $sStmt->execute();
    $systems = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt->close();

    $aStmt = $conn->prepare("
        SELECT a.*, u.Username AS approver_name
        FROM it_request_approvals a
        JOIN users u ON u.id = a.approver_id
        WHERE a.request_id = ?
        ORDER BY a.acted_at ASC
    ");
    $aStmt->bind_param("i", $requestId);
    $aStmt->execute();
    $approvals = $aStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $aStmt->close();

    // ── Systems rows ────────────────────────────────────────
    // Two columns side-by-side to halve vertical space
    $sysItems = [];
    foreach ($systems as $sys) {
        $sysId   = htmlspecialchars($sys['system_id']);
        $sysRole = htmlspecialchars($sys['role'] ?? '');
        $subStr  = '';
        if ($sys['sub_values']) {
            $decoded = json_decode($sys['sub_values'], true);
            if (is_array($decoded)) {
                $parts = [];
                foreach ($decoded as $v) {
                    $parts[] = is_array($v) ? implode(', ', $v) : $v;
                }
                $subStr = implode(' · ', array_filter($parts));
            } else {
                $subStr = htmlspecialchars($sys['sub_values']);
            }
        }
        $detail = $sysRole . ($subStr ? ' · ' . htmlspecialchars($subStr) : '');
        $sysItems[] = '<td class="sys-cell"><span class="sys-name">' . $sysId . '</span>'
                    . ($detail ? '<br/><span class="sys-detail">' . $detail . '</span>' : '')
                    . '</td>';
    }

    // Pair items into two-column rows
    $systemsHtml = '';
    for ($i = 0; $i < count($sysItems); $i += 2) {
        $col1 = $sysItems[$i];
        $col2 = isset($sysItems[$i + 1]) ? $sysItems[$i + 1] : '<td class="sys-cell"></td>';
        $systemsHtml .= '<tr>' . $col1 . $col2 . '</tr>';
    }

    // ── Approval blocks — all three side-by-side in one table row ──────────
    $stepLabels = [
        'manager'   => 'Admin',
        'officer-1' => 'IT Officer',
        'director'  => 'Director',
    ];
    // Bucket approvals by role so we can render fixed columns
    $approvalByRole = [];
    foreach ($approvals as $appr) {
        $approvalByRole[$appr['step_role']] = $appr;
    }
    $apprCols = '';
    foreach (['manager', 'officer-1', 'director'] as $role) {
        $label = $stepLabels[$role];
        if (isset($approvalByRole[$role])) {
            $appr    = $approvalByRole[$role];
            $approver = htmlspecialchars($appr['approver_name']);
            $approved = $appr['action'] === 'approved';
            $actCls   = $approved ? 'aa' : 'ar';
            $actTxt   = $approved ? 'Approved' : 'Rejected';
            $at       = date('d M Y, H:i', strtotime($appr['acted_at']));
            $reasonHtml = $appr['reason']
                ? '<div class="appr-reason">' . htmlspecialchars($appr['reason']) . '</div>'
                : '';
            $sigHtml = '';
            if ($appr['sig_kind'] === 'drawn' && $appr['sig_data']) {
                $sigHtml = _renderDrawnSig($appr['sig_data'], 180, 50);
            } elseif ($appr['sig_kind'] === 'uploaded' && $appr['sig_data']) {
                $sigHtml = _dataUriImg($appr['sig_data'], 120, 36);
            }
            $sigBlock = $sigHtml ? '<div class="appr-sig">' . $sigHtml . '</div>' : '<div class="appr-sig-empty">No signature</div>';
            $apprCols .= '<td class="appr-col">
              <div class="appr-role">' . $label . '</div>
              <div class="appr-name">' . $approver . '</div>
              <div class="appr-date">' . $at . '</div>
              <span class="' . $actCls . '">' . $actTxt . '</span>
              ' . $reasonHtml . $sigBlock . '
            </td>';
        } else {
            $apprCols .= '<td class="appr-col appr-pending">
              <div class="appr-role">' . $label . '</div>
              <div class="appr-name" style="color:#aaa;">—</div>
              <span class="appr-pending-lbl">Pending</span>
            </td>';
        }
    }
    $approvalsHtml = '<table class="appr-table"><tr>' . $apprCols . '</tr></table>';

    // ── Logo ────────────────────────────────────────────────
    $logoPath = __DIR__ . '/../../it_access_form/assets/pspf-logo.png';
    $logoHtml = '';
    if (file_exists($logoPath)) {
        $logoB64  = base64_encode(file_get_contents($logoPath));
        $logoHtml = '<img src="data:image/png;base64,' . $logoB64 . '" style="height:40px;width:auto;" />';
    }

    $refNum   = htmlspecialchars($req['ref_number']);
    $reqType  = strtoupper($req['request_type'] ?? 'new');
    $empName  = htmlspecialchars($req['employee_name']);
    $empId    = htmlspecialchars($req['employee_id'] ?? '');
    $dept     = htmlspecialchars($req['department']);
    $division = htmlspecialchars($req['division'] ?? '');
    $divHtml  = ''; // division now always rendered inline in the meta-grid
    $title    = htmlspecialchars($req['job_title']);
    $start    = date('d M Y', strtotime($req['start_date']));
    $submBy   = htmlspecialchars($req['submitter_name']);
    $submAt   = date('d M Y H:i', strtotime($req['submitted_at']));
    $provAt   = $req['provisioned_at'] ? date('d M Y H:i', strtotime($req['provisioned_at'])) : 'N/A';
    $just     = nl2br(htmlspecialchars($req['justification']));

    // Build HTML with a placeholder font-size token so we can swap it after the page-count probe
    $htmlTemplate = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: __F1__px; line-height: 1.5; color: #1c2839; background: #fff; }

  /* ── Header ── */
  .hdr-inner { width: 100%; border-collapse: collapse; background: #1f3450; }
  .hdr-inner td { vertical-align: middle; padding: 12px 20px; }
  .hdr-inner td:first-child { width: 46px; padding-right: 0; }
  .hdr-inner td:last-child { text-align: right; white-space: nowrap; }
  .hdr-org   { font-size: __F72__px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #9aafc5; }
  .hdr-title { font-size: __F135__px; font-weight: 700; color: #fff; line-height: 1.2; }
  .ref-badge { font-size: __F85__px; font-weight: 700; font-family: monospace; color: #c2d1e0; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); border-radius: 3px; padding: 2px 7px; }
  .type-badge { font-size: __F72__px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; background: #dfe7f0; color: #1f3450; border-radius: 20px; padding: 2px 8px; }
  .prov-date  { font-size: __F75__px; color: #9aafc5; margin-top: 3px; }

  /* ── Content ── */
  .content { padding: 14px 20px 10px; }

  /* ── Section heading ── */
  .sh {
    font-size: __F78__px; font-weight: 700; text-transform: uppercase; letter-spacing: .09em;
    color: #1f3450; background: #eef2f7;
    border-left: 3px solid #3d5a7e; border-bottom: 1px solid #c2d1e0;
    padding: 4px 0 4px 10px; margin-bottom: 8px; margin-top: 14px;
  }
  .sh:first-child { margin-top: 0; }

  /* ── Employee meta — 4-column ── */
  .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .meta-table td { padding: 4px 8px 4px 0; vertical-align: top; width: 25%; }
  .ml { font-size: __F72__px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #6c7a92; margin-bottom: 2px; }
  .mv { font-size: __F1__px; font-weight: 600; color: #0e1726; }

  /* ── Justification ── */
  .just-box { background: #f2f4f8; border-left: 3px solid #9aafc5; padding: 8px 12px; font-size: __F92__px; line-height: 1.55; color: #344256; margin-bottom: 4px; }

  /* ── Systems — 2-column grid ── */
  .sys-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .sys-table thead th { background: #2a4868; color: #dfe7f0; font-size: __F78__px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: 5px 10px; text-align: left; }
  .sys-cell { width: 50%; padding: 5px 10px; border-bottom: 1px solid #e8ecf2; vertical-align: top; }
  .sys-table tbody tr:nth-child(even) .sys-cell { background: #f6f8fb; }
  .sys-name   { font-weight: 700; font-size: __F1__px; color: #1f3450; }
  .sys-detail { font-size: __F82__px; color: #5a6a82; margin-top: 2px; }

  /* ── Approval chain — 3 columns side-by-side ── */
  .appr-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .appr-col { width: 33.33%; border: 1px solid #dfe3eb; padding: 10px 12px; vertical-align: top; background: #fafbfc; }
  .appr-col + .appr-col { border-left: none; }
  .appr-role { font-size: __F75__px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #3d5a7e; margin-bottom: 5px; border-bottom: 1px solid #dfe3eb; padding-bottom: 4px; }
  .appr-name  { font-size: __F1__px; font-weight: 600; color: #0e1726; margin-bottom: 2px; }
  .appr-date  { font-size: __F82__px; color: #6c7a92; margin-bottom: 6px; }
  .aa { font-size: __F75__px; font-weight: 700; text-transform: uppercase; color: #0f7a4a; background: #e3f5ec; border: 1px solid #a3dbbf; border-radius: 20px; padding: 2px 8px; }
  .ar { font-size: __F75__px; font-weight: 700; text-transform: uppercase; color: #a01b1b; background: #fde6e6; border: 1px solid #f3c5c5; border-radius: 20px; padding: 2px 8px; }
  .appr-reason    { font-size: __F82__px; color: #a01b1b; font-style: italic; margin-top: 4px; }
  .appr-sig       { margin-top: 8px; }
  .appr-sig-empty { font-size: __F78__px; color: #b0b8c6; margin-top: 8px; font-style: italic; }
  .appr-pending     { background: #fafafa; }
  .appr-pending-lbl { font-size: __F78__px; color: #b0b8c6; font-style: italic; }

  /* ── Footer ── */
  .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #dfe3eb; font-size: __F75__px; color: #8d99ae; width: 100%; border-collapse: collapse; }
  .footer td { padding: 0; }
</style>
</head>
<body>
<table class="hdr-inner" style="width:100%;">
  <tr>
    <td>{$logoHtml}</td>
    <td>
      <div class="hdr-org">Public Service Pensions Fund</div>
      <div class="hdr-title">IT System Access Authorization</div>
    </td>
    <td>
      <div class="ref-badge">{$refNum}</div><br/>
      <span class="type-badge">{$reqType} Request</span>
      <div class="prov-date">Provisioned: {$provAt}</div>
    </td>
  </tr>
</table>
<div class="content">
  <div class="sh">Employee Details</div>
  <table class="meta-table">
    <tr>
      <td><div class="ml">Full Name</div><div class="mv">{$empName}</div></td>
      <td><div class="ml">Employee ID</div><div class="mv">{$empId}</div></td>
      <td><div class="ml">Department</div><div class="mv">{$dept}</div></td>
      <td><div class="ml">Division</div><div class="mv">{$division}</div></td>
    </tr>
    <tr>
      <td><div class="ml">Job Title</div><div class="mv">{$title}</div></td>
      <td><div class="ml">Access Start Date</div><div class="mv">{$start}</div></td>
      <td><div class="ml">Submitted By</div><div class="mv">{$submBy}</div></td>
      <td><div class="ml">Submitted On</div><div class="mv">{$submAt}</div></td>
    </tr>
  </table>
  <div class="sh">Justification</div>
  <div class="just-box">{$just}</div>
  <div class="sh">Systems &amp; Access Granted</div>
  <table class="sys-table">
    <thead><tr><th colspan="2">System &middot; Role / Access Level</th></tr></thead>
    <tbody>{$systemsHtml}</tbody>
  </table>
  <div class="sh">Approval Chain &amp; Signatures</div>
  {$approvalsHtml}
  <table class="footer">
    <tr>
      <td>PSPF CRM &middot; IT Access Authorization System</td>
      <td style="text-align:right;">Reference: {$refNum} &middot; Generated: {$provAt}</td>
    </tr>
  </table>
</div>
</body>
</html>
HTML;

    try {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $cfg = [
            'margin_top'    => 0,
            'margin_left'   => 0,
            'margin_right'  => 0,
            'margin_bottom' => 0,
            'format'        => 'A4',
        ];

        // ── Pass 1: render at the ideal comfortable font size ────────────────
        $idealFs  = 11;
        $html     = _applyFontScale($htmlTemplate, $idealFs);

        $mpdf = new \Mpdf\Mpdf($cfg);
        $mpdf->SetTitle("IT Access Authorization – $refNum");
        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->WriteHTML($html);
        $pageCount = $mpdf->page;

        if ($pageCount <= 1) {
            // Fits — use as-is
            $pdfBytes = $mpdf->Output('', 'S');
        } else {
            // ── Pass 2: scale font down proportionally to fit exactly one page ─
            // Scale factor: target slightly under 1 page worth of content.
            // Use 0.92 of ideal / pageCount to leave a small bottom margin.
            $scaledFs = max(7.5, round(($idealFs / $pageCount) * 0.96, 2));
            $html2    = _applyFontScale($htmlTemplate, $scaledFs);

            $mpdf2 = new \Mpdf\Mpdf($cfg);
            $mpdf2->SetTitle("IT Access Authorization – $refNum");
            $mpdf2->shrink_tables_to_fit = 1;
            $mpdf2->WriteHTML($html2);
            $pdfBytes = $mpdf2->Output('', 'S');
        }
    } catch (\Throwable $e) {
        error_log('IT access PDF generation failed: ' . $e->getMessage());
        return null;
    }

    $pdfDir  = __DIR__ . '/../../uploads/it_access_pdfs/';
    if (!is_dir($pdfDir)) @mkdir($pdfDir, 0755, true);

    // Filename: FirstName_LastName-REQ-YYYY-NNNN.pdf  (filesystem-safe)
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', str_replace(' ', '_', trim($req['employee_name'])));
    $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '', $refNum);
    $filename = $safeName . '-' . $safeRef . '.pdf';

    // Remove any previously generated file for this request before writing the new one
    $existing = glob($pdfDir . $safeName . '-' . $safeRef . '*.pdf');
    foreach ((array)$existing as $old) { @unlink($old); }

    file_put_contents($pdfDir . $filename, $pdfBytes);

    $upStmt = $conn->prepare("UPDATE it_access_requests SET pdf_filename = ? WHERE id = ?");
    if ($upStmt) {
        $upStmt->bind_param("si", $filename, $requestId);
        $upStmt->execute();
        $upStmt->close();
    }

    // SharePoint upload (optional — skip gracefully if config absent)
    $spConfig = __DIR__ . '/../sharepoint_config.php';
    if (file_exists($spConfig)) {
        try {
            require_once $spConfig;
            $iniPath      = __DIR__ . '/../includes/confi.ini';
            $ini          = file_exists($iniPath) ? parse_ini_file($iniPath, true) : [];
            $spFolder     = trim($ini['sharepoint']['folder'] ?? 'IT Access Requests');
            $spId = uploadToSharePoint($pdfBytes, $filename, $spFolder);
            if ($spId) {
                $spStmt = $conn->prepare("UPDATE it_access_requests SET sharepoint_id = ? WHERE id = ?");
                if ($spStmt) {
                    $spStmt->bind_param("si", $spId, $requestId);
                    $spStmt->execute();
                    $spStmt->close();
                }
            }
        } catch (\Throwable $e) {
            error_log('SharePoint upload failed: ' . $e->getMessage());
        }
    }

    return $filename;
}

/**
 * Substitutes all __Fratio__px tokens in the CSS template with computed pixel values.
 * Token __F1__ = base size, __F72__ = base * 0.72, __F135__ = base * 1.35, etc.
 */
function _applyFontScale(string $template, float $base): string {
    // Longest tokens first so shorter prefixes (e.g. __F1__) don't corrupt them
    $ratios = [
        '__F135__' => 1.35,
        '__F92__'  => 0.92,
        '__F85__'  => 0.85,
        '__F82__'  => 0.82,
        '__F78__'  => 0.78,
        '__F75__'  => 0.75,
        '__F72__'  => 0.72,
        '__F1__'   => 1.00,
    ];
    $search  = array_keys($ratios);
    $replace = array_map(fn($r) => round($base * $r, 2), array_values($ratios));
    return str_replace($search, $replace, $template);
}

function _renderDrawnSig(string $strokesJson, int $w = 300, int $h = 80): string {
    $strokes = json_decode($strokesJson, true);
    if (!$strokes || !is_array($strokes) || !function_exists('imagecreatetruecolor')) return '';

    $im  = imagecreatetruecolor($w, $h);
    $bg  = imagecolorallocate($im, 255, 255, 255);
    $ink = imagecolorallocate($im, 14, 23, 38);
    imagefill($im, 0, 0, $bg);
    imagesetthickness($im, 2);

    foreach ($strokes as $stroke) {
        if (!is_array($stroke) || count($stroke) < 2) continue;
        for ($i = 0; $i < count($stroke) - 1; $i++) {
            [$x1, $y1] = $stroke[$i];
            [$x2, $y2] = $stroke[$i + 1];
            imageline($im, (int)($x1 * $w), (int)($y1 * $h), (int)($x2 * $w), (int)($y2 * $h), $ink);
        }
    }

    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);

    return '<img src="data:image/png;base64,' . base64_encode($png) . '" style="max-width:150px;max-height:45px;display:block;" />';
}

function _dataUriImg(string $dataUri, int $maxW = 150, int $maxH = 45): string {
    if (!preg_match('/^data:image\/(png|jpeg|jpg|gif);base64,/i', $dataUri)) return '';
    return '<img src="' . $dataUri . '" style="max-width:' . $maxW . 'px;max-height:' . $maxH . 'px;display:block;" />';
}
