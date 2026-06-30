<?php
/**
 * Generates a PDF for a provisioned IT access request and uploads it to SharePoint.
 * Called internally by approve.php after director approval.
 * Not a public endpoint — included via require_once.
 *
 * @param mysqli $conn       Active DB connection
 * @param int    $requestId  DB id of the request
 * @return string|null       SharePoint item ID on success, null on failure
 */
function generateAndUploadPdf(mysqli $conn, int $requestId): ?string {
    // Fetch request + submitter
    $rStmt = $conn->prepare("
        SELECT r.*, u.username AS submitter_name, u.email AS submitter_email
        FROM it_access_requests r
        JOIN users u ON u.id = r.submitted_by
        WHERE r.id = ?
    ");
    $rStmt->bind_param("i", $requestId);
    $rStmt->execute();
    $req = $rStmt->get_result()->fetch_assoc();
    $rStmt->close();
    if (!$req) return null;

    // Fetch systems
    $sStmt = $conn->prepare("SELECT * FROM it_request_systems WHERE request_id = ? ORDER BY id");
    $sStmt->bind_param("i", $requestId);
    $sStmt->execute();
    $systems = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt->close();

    // Fetch approvals
    $aStmt = $conn->prepare("
        SELECT a.*, u.username AS approver_name
        FROM it_request_approvals a
        JOIN users u ON u.id = a.approver_id
        WHERE a.request_id = ?
        ORDER BY a.acted_at ASC
    ");
    $aStmt->bind_param("i", $requestId);
    $aStmt->execute();
    $approvals = $aStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $aStmt->close();

    // Build systems HTML
    $systemsHtml = '';
    foreach ($systems as $sys) {
        $sysId   = htmlspecialchars($sys['system_id']);
        $sysRole = htmlspecialchars($sys['role'] ?? '');
        $subRaw  = $sys['sub_values'];
        $subStr  = '';
        if ($subRaw) {
            $decoded = json_decode($subRaw, true);
            if (is_array($decoded)) {
                $parts = [];
                foreach ($decoded as $k => $v) {
                    if (is_array($v))       $parts[] = implode(', ', $v);
                    elseif (is_string($v))  $parts[] = $v;
                }
                $subStr = implode(' · ', array_filter($parts));
            } else {
                $subStr = htmlspecialchars($subRaw);
            }
        }
        $systemsHtml .= '<tr>
            <td><span class="sys-name">' . $sysId . '</span></td>
            <td><span class="sys-role">' . $sysRole . '</span></td>
            <td><span class="sys-sub">' . htmlspecialchars($subStr) . '</span></td>
        </tr>';
    }

    // Build approvals HTML (with inline signature images)
    $stepLabels = [
        'manager'  => 'Admin (Requesting)',
        'officer-1'=> 'IT Officer',
        'officer-2'=> 'IT Officer 2',
        'director' => 'Director',
    ];
    $approvalsHtml = '';
    foreach ($approvals as $appr) {
        $label      = $stepLabels[$appr['step_role']] ?? $appr['step_role'];
        $approver   = htmlspecialchars($appr['approver_name']);
        $isApproved = $appr['action'] === 'approved';
        $actionCls  = $isApproved ? 'approval-action-approved' : 'approval-action-rejected';
        $actionTxt  = $isApproved ? 'Approved' : 'Rejected';
        $at         = date('d M Y, H:i', strtotime($appr['acted_at']));
        $reasonHtml = $appr['reason']
            ? '<div class="approval-reason">Reason: ' . htmlspecialchars($appr['reason']) . '</div>'
            : '';

        // Signature — mPDF cannot use data: URIs; write to a temp file and use file path
        $sigHtml = '';
        if ($appr['sig_kind'] === 'drawn' && $appr['sig_data']) {
            $sigHtml = renderDrawnSigAsFile($appr['sig_data'], 300, 80);
        } elseif ($appr['sig_kind'] === 'uploaded' && $appr['sig_data']) {
            $sigHtml = dataUriToImgTag($appr['sig_data'], 150, 45);
        }
        $sigBlock = $sigHtml
            ? '<div class="approval-sig"><div class="sig-label">Signature</div>' . $sigHtml . '</div>'
            : '';

        $approvalsHtml .= '
        <div class="approval-block">
          <div class="approval-header">
            <span class="approval-step">' . $label . '</span>
            <span class="' . $actionCls . '">' . $actionTxt . '</span>
          </div>
          <div class="approval-body">
            <div class="approval-meta">
              <div class="approval-name">' . $approver . '</div>
              <div class="approval-date">' . $at . '</div>
              ' . $reasonHtml . '
            </div>
            ' . $sigBlock . '
          </div>
        </div>';
    }

    // Logo — embed as base64 so mPDF never needs to resolve a file path.
    // Use the inverted (white) logo so it is visible against the dark header band.
    $logoPath = __DIR__ . '/assets/pspf-logo-inverted.png';
    if (!file_exists($logoPath)) {
        $logoPath = __DIR__ . '/assets/pspf-logo.png'; // fallback
    }
    $logoHtml = '';
    if (file_exists($logoPath)) {
        $logoB64  = base64_encode(file_get_contents($logoPath));
        $logoHtml = '<img src="data:image/png;base64,' . $logoB64 . '" style="height:40px;width:auto;" />';
    }

    $refNum   = htmlspecialchars($req['ref_number']);
    $reqType  = strtoupper($req['request_type'] ?? 'new');
    $empName      = htmlspecialchars($req['employee_name']);
    $empId        = htmlspecialchars($req['employee_id'] ?? '');
    $dept         = htmlspecialchars($req['department']);
    $division     = htmlspecialchars($req['division'] ?? '');
    $divisionHtml = $division
        ? '<div class="meta-item"><div class="meta-lbl">Division</div><div class="meta-val">' . $division . '</div></div>'
        : '';
    $title    = htmlspecialchars($req['job_title']);
    $start    = date('d M Y', strtotime($req['start_date']));
    $submBy   = htmlspecialchars($req['submitter_name']);
    $submAt   = date('d M Y H:i', strtotime($req['submitted_at']));
    $provAt   = $req['provisioned_at'] ? date('d M Y H:i', strtotime($req['provisioned_at'])) : 'N/A';
    $just     = nl2br(htmlspecialchars($req['justification']));

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  @import url('https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700&display=swap');

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: "Titillium Web", "Segoe UI", Arial, sans-serif;
    font-size: 11px;
    line-height: 1.55;
    color: #1c2839;
    background: #ffffff;
  }

  /* ── Header ── */
  .header {
    background: #3d5c80; /* solid — matches CRM navbar (--pspf-primary) */
    color: #ffffff;
    padding: 20px 28px;
  }
  .header-inner {
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .header-logo { flex-shrink: 0; }
  .header-text { flex: 1; }
  .header-org {
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #9aafc5;
    margin-bottom: 3px;
  }
  .header-title {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #ffffff;
  }
  .header-right { text-align: right; }
  .ref-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 600;
    font-family: "Courier New", monospace;
    color: #c2d1e0;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 4px;
    padding: 3px 8px;
    margin-bottom: 4px;
  }
  .type-badge {
    display: inline-block;
    font-size: 8.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    background: #dfe7f0;
    color: #1f3450;
    border-radius: 20px;
    padding: 2px 8px;
  }
  .prov-date { font-size: 9px; color: #9aafc5; margin-top: 3px; }

  /* ── Content ── */
  .content { padding: 14px 22px; }

  /* ── Section headings ── */
  .section-heading {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #4b5a72;
    border-bottom: 1.5px solid #dfe3eb;
    padding-bottom: 4px;
    margin-bottom: 8px;
    margin-top: 12px;
  }
  .section-heading:first-child { margin-top: 0; }

  /* ── Meta grid ── */
  .meta-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px 16px;
    margin-bottom: 4px;
  }
  .meta-item {}
  .meta-lbl {
    font-size: 8.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #6c7a92;
    margin-bottom: 2px;
  }
  .meta-val {
    font-size: 11px;
    font-weight: 600;
    color: #0e1726;
  }

  /* ── Justification box ── */
  .just-box {
    background: #f2f4f8;
    border: 1px solid #dfe3eb;
    border-radius: 6px;
    padding: 10px 14px;
    font-size: 11px;
    line-height: 1.6;
    color: #344256;
    margin-bottom: 4px;
  }

  /* ── Systems table ── */
  .sys-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4px;
    font-size: 10.5px;
  }
  .sys-table thead th {
    background: #eef2f7;
    color: #4b5a72;
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    padding: 6px 10px;
    text-align: left;
    border-bottom: 1.5px solid #dfe3eb;
  }
  .sys-table tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid #ebeef3;
    color: #1c2839;
    vertical-align: top;
  }
  .sys-table tbody tr:last-child td { border-bottom: none; }
  .sys-name { font-weight: 600; }
  .sys-role { color: #3d5a7e; font-weight: 600; }
  .sys-sub  { color: #6c7a92; font-size: 10px; }

  /* ── Approval chain ── */
  .approval-block {
    border: 1px solid #dfe3eb;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 6px;
  }
  .approval-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 14px;
    background: #f2f4f8;
    border-bottom: 1px solid #dfe3eb;
  }
  .approval-step {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #4b5a72;
  }
  .approval-action-approved {
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #0f7a4a;
    background: #e3f5ec;
    border: 1px solid #a3dbbf;
    border-radius: 20px;
    padding: 2px 8px;
  }
  .approval-action-rejected {
    font-size: 8.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #a01b1b;
    background: #fde6e6;
    border: 1px solid #f3c5c5;
    border-radius: 20px;
    padding: 2px 8px;
  }
  .approval-body {
    padding: 7px 14px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
  }
  .approval-meta { flex: 1; }
  .approval-name { font-weight: 600; font-size: 11px; color: #0e1726; }
  .approval-date { font-size: 9.5px; color: #6c7a92; margin-top: 2px; }
  .approval-reason { font-size: 10px; color: #a01b1b; margin-top: 4px; font-style: italic; }
  .approval-sig { flex-shrink: 0; text-align: right; }
  .sig-label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.06em; color: #8d99ae; margin-bottom: 4px; }

  /* ── Footer ── */
  .footer {
    margin-top: 28px;
    padding-top: 10px;
    border-top: 1px solid #ebeef3;
    font-size: 8.5px;
    color: #8d99ae;
    display: flex;
    justify-content: space-between;
  }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div class="header-inner">
    <div class="header-logo">{$logoHtml}</div>
    <div class="header-text">
      <div class="header-org">Public Service Pensions Fund</div>
      <div class="header-title">IT System Access Authorization</div>
    </div>
    <div class="header-right">
      <div class="ref-badge">{$refNum}</div><br/>
      <span class="type-badge">{$reqType} Request</span>
      <div class="prov-date">Provisioned: {$provAt}</div>
    </div>
  </div>
</div>

<div class="content">

  <!-- Employee Details -->
  <div class="section-heading">Employee Details</div>
  <div class="meta-grid">
    <div class="meta-item"><div class="meta-lbl">Full Name</div><div class="meta-val">{$empName}</div></div>
    <div class="meta-item"><div class="meta-lbl">Employee ID</div><div class="meta-val">{$empId}</div></div>
    <div class="meta-item"><div class="meta-lbl">Job Title</div><div class="meta-val">{$title}</div></div>
    <div class="meta-item"><div class="meta-lbl">Department</div><div class="meta-val">{$dept}</div></div>
    {$divisionHtml}
    <div class="meta-item"><div class="meta-lbl">Access Start Date</div><div class="meta-val">{$start}</div></div>
    <div class="meta-item"><div class="meta-lbl">Submitted By</div><div class="meta-val">{$submBy}</div></div>
    <div class="meta-item"><div class="meta-lbl">Submitted On</div><div class="meta-val">{$submAt}</div></div>
  </div>

  <!-- Justification -->
  <div class="section-heading">Justification</div>
  <div class="just-box">{$just}</div>

  <!-- Systems -->
  <div class="section-heading">Systems &amp; Access Granted</div>
  <table class="sys-table">
    <thead>
      <tr>
        <th>System</th>
        <th>Role / Access Level</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>{$systemsHtml}</tbody>
  </table>

  <!-- Approvals -->
  <div class="section-heading">Approval Chain &amp; Signatures</div>
  {$approvalsHtml}

  <div class="footer">
    <span>PSPF CRM · IT Access Authorization System</span>
    <span>Reference: {$refNum} · Generated: {$provAt}</span>
  </div>

</div>
</body>
</html>
HTML;

    // Generate PDF using mPDF — ALWAYS fit to exactly one A4 page.
    // Strategy: iteratively shrink a global scale factor until the rendered
    // document is a single page, keeping text as large (readable) as possible.
    try {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $mpdfConfig = [
            'margin_top'    => 0,
            'margin_left'   => 0,
            'margin_right'  => 0,
            'margin_bottom' => 0,
            'format'        => 'A4',
            'allow_charset_conversion' => true,
        ];

        // Uniformly scale EVERY px-based dimension in the whole document (the
        // <style> block AND inline style="" attributes, including signature image
        // heights) by a factor. The template uses ~21 explicit px font-sizes plus
        // many fixed paddings/margins/heights, so scaling only a few rules barely
        // changes the layout — scaling all px proportionally gives a real
        // shrink-to-fit while keeping the design's proportions intact.
        $scaleCss = function (string $html, float $scale): string {
            if ($scale >= 0.999) return $html;
            $scalePx = function (string $chunk) use ($scale) {
                return preg_replace_callback(
                    '/(-?\d*\.?\d+)px\b/',
                    function ($p) use ($scale) {
                        $v = (float)$p[1] * $scale;
                        if ($v > 0 && $v < 0.75) $v = 0.75; // keep hairline borders visible
                        return round($v, 2) . 'px';
                    },
                    $chunk
                );
            };
            // Split out base64 data: URIs (which may contain "..px.." by chance) and
            // scale px only in the surrounding markup, never inside the image data.
            $parts = preg_split('/(data:[^"\')\s]+)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $i => $part) {
                if ($i % 2 === 0) $parts[$i] = $scalePx($part); // even = markup, odd = data URI
            }
            return implode('', $parts);
        };

        // Render the document at a given scale (1.0 = full size) and report page count.
        $renderAt = function (float $scale) use ($html, $mpdfConfig, $refNum, $scaleCss) {
            $scaledHtml = $scaleCss($html, $scale);
            $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            $mpdf->SetTitle("IT Access Authorization – $refNum");
            $mpdf->shrink_tables_to_fit = 1;
            $mpdf->allowCJKorphans      = false;
            $mpdf->WriteHTML($scaledHtml);
            return [$mpdf->Output('', 'S'), $mpdf->page];
        };

        // Try full size first; if it spills onto a 2nd page, step the scale down
        // until it fits. Floor at 0.6 (≈6.6px) to keep it legible; the last
        // attempt is used regardless so output is always a single physical render.
        $usedScale = 1.0;
        [$pdfBytes, $pageCount] = $renderAt(1.0);
        if ($pageCount > 1) {
            foreach ([0.92, 0.85, 0.78, 0.72, 0.66, 0.6, 0.55, 0.5] as $scale) {
                $usedScale = $scale;
                [$pdfBytes, $pageCount] = $renderAt($scale);
                if ($pageCount <= 1) break;
            }
        }
        if (getenv('ITA_PDF_DEBUG')) error_log("ITA_PDF: ref=$refNum usedScale=$usedScale pages=$pageCount");
    } catch (\Throwable $e) {
        error_log('PDF generation failed: ' . $e->getMessage());
        return null;
    }

    // Store PDF path locally as backup
    $pdfDir = __DIR__ . '/../../uploads/it_access_pdfs/';
    if (!is_dir($pdfDir)) @mkdir($pdfDir, 0755, true);
    $filename = $refNum . '_' . date('Ymd_His') . '.pdf';
    file_put_contents($pdfDir . $filename, $pdfBytes);

    // Store filename in DB
    $upStmt = $conn->prepare("UPDATE it_access_requests SET pdf_filename = ? WHERE id = ?");
    if ($upStmt) {
        $upStmt->bind_param("si", $filename, $requestId);
        $upStmt->execute();
        $upStmt->close();
    }

    // Upload to SharePoint
    try {
        require_once __DIR__ . '/../sharepoint_config.php';
        $spId = uploadToSharePoint($pdfBytes, $filename);
        if ($spId) {
            $spStmt = $conn->prepare("UPDATE it_access_requests SET sharepoint_id = ? WHERE id = ?");
            if ($spStmt) {
                $spStmt->bind_param("si", $spId, $requestId);
                $spStmt->execute();
                $spStmt->close();
            }
        }
        return $spId;
    } catch (\Throwable $e) {
        error_log('SharePoint upload failed: ' . $e->getMessage());
        return null; // PDF is still saved locally
    }
}

/**
 * Render drawn signature strokes onto a GD canvas and return a base64 <img> tag.
 */
function renderDrawnSigAsFile(string $strokesJson, int $w = 300, int $h = 80): string {
    $strokes = json_decode($strokesJson, true);
    if (!$strokes || !is_array($strokes)) return '';
    if (!function_exists('imagecreatetruecolor')) return '';

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
            imageline($im,
                (int)($x1 * $w), (int)($y1 * $h),
                (int)($x2 * $w), (int)($y2 * $h),
                $ink
            );
        }
    }

    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);

    $b64 = base64_encode($png);
    return '<img src="data:image/png;base64,' . $b64 . '" style="max-width:150px;max-height:45px;display:block;" />';
}

/**
 * Pass an uploaded data: URI signature straight through as a base64 <img> tag.
 * mPDF supports data: URIs when using WriteHTML — no temp file needed.
 */
function dataUriToImgTag(string $dataUri, int $maxW = 150, int $maxH = 45): string {
    if (!preg_match('/^data:image\/(png|jpeg|jpg|gif);base64,/i', $dataUri)) return '';
    return '<img src="' . $dataUri . '" style="max-width:' . $maxW . 'px;max-height:' . $maxH . 'px;display:block;" />';
}
