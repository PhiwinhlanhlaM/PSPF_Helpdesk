<?php
// it_access/mailer.php
// Shared email helpers for the IT Access workflow.
// Reuses the CRM-wide mail worker (getMailer() from mail_config.php) so all
// IT Access notifications go through the same SMTP relay as the rest of the CRM.

require_once __DIR__ . '/../mail_config.php';

/**
 * Return the active ICT-department recipients who should be notified of new
 * requests: every active user holding the it_officer role.
 * @return array<int, array{email:string, name:string}>
 */
function itAccessOfficers(mysqli $conn): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.email, u.username
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r       ON r.id = ur.role_id
         WHERE r.name = 'it_officer' AND u.is_active = 1 AND u.email <> ''"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(fn($r) => ['email' => $r['email'], 'name' => $r['username']], $rows);
}

/**
 * Return the active IT Director recipients (it_director role holders).
 * @return array<int, array{email:string, name:string}>
 */
function itAccessDirectors(mysqli $conn): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.email, u.username
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r       ON r.id = ur.role_id
         WHERE r.name = 'it_director' AND u.is_active = 1 AND u.email <> ''"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(fn($r) => ['email' => $r['email'], 'name' => $r['username']], $rows);
}

/**
 * Look up a single user's email + name by their numeric id.
 * @return array{email:string, name:string}|null
 */
function itAccessUserById(mysqli $conn, int $userId): ?array {
    if (!$userId) return null;
    $stmt = $conn->prepare("SELECT email, username FROM users WHERE id = ? AND email <> '' LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? ['email' => $row['email'], 'name' => $row['username']] : null;
}

/**
 * Build a readable plain-text email body from structured parts.
 *
 * The body is laid out for easy scanning in any mail client: a heading underline,
 * intro paragraphs, an aligned "Label: value" detail block, and any relevant link
 * shown in full so it is visible and clickable.
 *
 * Both array slots return the same plain-text string so existing callers that
 * destructure `[$html, $text]` keep working while the email is sent as text.
 *
 * @param string                 $heading  Title shown at the top.
 * @param array<int,string>      $intro    Intro paragraphs.
 * @param array<string,string>   $details  Label => value rows (values may be multi-line).
 * @param array{text:string,url:string}|null $cta  Optional link (label + URL shown in full).
 * @return array{0:string,1:string} [textBody, textBody]
 */
function itAccessEmailBody(string $heading, array $intro, array $details = [], ?array $cta = null): array {
    $lines = [];

    // Heading with an underline for visual separation.
    $lines[] = $heading;
    $lines[] = str_repeat('=', max(3, min(60, strlen($heading))));
    $lines[] = '';

    // Intro paragraphs, blank line between each.
    foreach ($intro as $p) {
        $lines[] = rtrim($p);
        $lines[] = '';
    }

    // Aligned detail block: pad labels to the longest so the colons line up.
    if ($details) {
        $labelWidth = 0;
        foreach (array_keys($details) as $label) {
            $labelWidth = max($labelWidth, strlen((string)$label));
        }
        foreach ($details as $label => $value) {
            $value = (string)$value;
            $pad   = str_pad((string)$label . ':', $labelWidth + 2);
            // Multi-line values (e.g. a list of systems) indent under the label.
            $valueLines = preg_split('/\r\n|\r|\n/', $value);
            $lines[] = $pad . array_shift($valueLines);
            foreach ($valueLines as $vl) {
                $lines[] = str_repeat(' ', $labelWidth + 2) . $vl;
            }
        }
        $lines[] = '';
    }

    // Call-to-action: show the descriptive text and the full URL on its own line.
    if ($cta && !empty($cta['url'])) {
        if (!empty($cta['text'])) {
            $lines[] = $cta['text'] . ':';
        }
        $lines[] = $cta['url'];
        $lines[] = '';
    }

    $lines[] = 'Regards,';
    $lines[] = 'PSPF IT Access';
    $lines[] = '';
    $lines[] = '--';
    $lines[] = 'This is an automated message from the PSPF IT Access system. Please do not reply.';

    $text = implode("\n", $lines);

    // Return the same plain text in both slots so callers using [$html, $text] work.
    return [$text, $text];
}

/**
 * Send a plain-text email to a list of recipients through the shared mailer.
 * From identity is overridden to "IT Access" for this module. Failures are
 * logged, never thrown, so the request flow is never blocked.
 *
 * The third/fourth parameters are kept for backwards compatibility with callers
 * that pass [$html, $text]; whichever non-empty body is supplied is sent as
 * plain text (any stray HTML is stripped to text).
 *
 * @param array<int, array{email:string, name:string}> $recipients
 */
function itAccessSendMail(array $recipients, string $subject, string $body, ?string $text = null): void {
    $recipients = array_values(array_filter($recipients, fn($r) => !empty($r['email'])));
    if (!$recipients) return;

    // Prefer the dedicated plain-text body when both are passed; fall back to the
    // first argument. Strip tags as a safety net so nothing HTML leaks through.
    $plain = $text !== null && $text !== '' ? $text : $body;
    if (preg_match('/<[a-z][\s\S]*>/i', $plain)) {
        $plain = trim(html_entity_decode(strip_tags($plain), ENT_QUOTES, 'UTF-8'));
    }

    try {
        $mail = getMailer();
        // IT Access emails are sent under the "IT Access" sender name.
        $mail->setFrom('administrator@pspf.co.sz', 'IT Access');
        foreach ($recipients as $r) {
            $mail->addAddress($r['email'], $r['name'] ?? '');
        }
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $plain;
        $mail->send();
    } catch (\Throwable $e) {
        error_log('IT access email error: ' . $e->getMessage());
    }
}

/**
 * Build the absolute URL of the IT Access landing page for use in email bodies.
 */
function itAccessAppUrl(): string {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . '/pspf_crm/api/it_access/index.php';
}
