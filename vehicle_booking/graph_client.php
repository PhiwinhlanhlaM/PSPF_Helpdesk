<?php
/**
 * Minimal Microsoft Graph client for reading the booking mailbox (app-only).
 *
 * Uses the OAuth2 client-credentials flow to get an app-only token, then plain
 * HTTPS calls to Microsoft Graph. No IMAP extension and no third-party library
 * required - just PHP cURL.
 *
 * Credentials come from mail_inbox_config.php (tenant_id, client_id,
 * client_secret) - see EMAIL_APPROVALS_README.md for the Entra app setup.
 */

/**
 * Acquire an app-only access token via client credentials.
 * Throws RuntimeException on failure.
 */
function graphGetToken(array $cfg): string {
    $tenant = $cfg['tenant_id'] ?? '';
    $url = "https://login.microsoftonline.com/" . rawurlencode($tenant) . "/oauth2/v2.0/token";
    $post = http_build_query([
        'client_id'     => $cfg['client_id'] ?? '',
        'client_secret' => $cfg['client_secret'] ?? '',
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);
    [$code, $body] = graphHttp('POST', $url, $post, [
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    $data = json_decode($body, true);
    if ($code !== 200 || empty($data['access_token'])) {
        $err = $data['error_description'] ?? ($data['error']['message'] ?? $body);
        throw new RuntimeException("Token request failed (HTTP $code): $err");
    }
    return $data['access_token'];
}

/**
 * Call a Graph endpoint with a bearer token.
 * Returns [httpCode, decodedArrayOrNull].
 */
function graphApi(string $token, string $method, string $url, ?array $json = null): array {
    $headers = [
        "Authorization: Bearer $token",
        "Accept: application/json",
        // Ask Graph for plain-text bodies so reply parsing stays simple.
        'Prefer: outlook.body-content-type="text"',
    ];
    $payload = null;
    if ($json !== null) {
        $headers[] = "Content-Type: application/json";
        $payload = json_encode($json);
    }
    [$code, $body] = graphHttp($method, $url, $payload, $headers);
    $decoded = ($body !== '' && $body !== null) ? json_decode($body, true) : null;
    return [$code, $decoded];
}

/**
 * Low-level HTTPS via cURL. Returns [httpCode, rawBody].
 * TLS verification stays on; ensure curl.cainfo points at a cacert.pem in php.ini.
 */
function graphHttp(string $method, string $url, ?string $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $e = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTPS request failed: $e");
    }
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $resp];
}
