<?php
// SharePoint / Microsoft Graph configuration
// Register an Azure AD app at portal.azure.com with:
//   - Application permission: Sites.ReadWrite.All (or Files.ReadWrite.All)
//   - Grant admin consent
// Then fill in the values below.

define('SP_TENANT_ID',     getenv('SP_TENANT_ID')     ?: 'YOUR_TENANT_ID');
define('SP_CLIENT_ID',     getenv('SP_CLIENT_ID')     ?: 'YOUR_CLIENT_ID');
define('SP_CLIENT_SECRET', getenv('SP_CLIENT_SECRET') ?: 'YOUR_CLIENT_SECRET');

// SharePoint site and library where PDFs will be stored
// Example: https://contoso.sharepoint.com/sites/ICT
define('SP_SITE_ID',       getenv('SP_SITE_ID')       ?: '');   // Graph site ID (run: GET /sites/{hostname}:/{site-path})
define('SP_DRIVE_ID',      getenv('SP_DRIVE_ID')      ?: '');   // Drive ID (run: GET /sites/{site-id}/drives)
define('SP_FOLDER_PATH',   getenv('SP_FOLDER_PATH')   ?: 'IT Access Requests'); // Folder inside the drive

/**
 * Get an OAuth2 access token for the Graph API using client credentials.
 * Returns the token string or null on failure.
 */
function getGraphToken(): ?string {
    $url = 'https://login.microsoftonline.com/' . SP_TENANT_ID . '/oauth2/v2.0/token';
    $body = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => SP_CLIENT_ID,
        'client_secret' => SP_CLIENT_SECRET,
        'scope'         => 'https://graph.microsoft.com/.default',
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

/**
 * Upload $pdfBytes as $filename to the configured SharePoint folder.
 * Returns the Graph item ID on success, null on failure.
 */
function uploadToSharePoint(string $pdfBytes, string $filename): ?string {
    if (!SP_SITE_ID || !SP_DRIVE_ID) {
        error_log('SharePoint not configured — skipping upload');
        return null;
    }
    $token = getGraphToken();
    if (!$token) { error_log('Graph token acquisition failed'); return null; }

    $encodedFolder = rawurlencode(SP_FOLDER_PATH);
    $encodedFile   = rawurlencode($filename);
    $uploadUrl = "https://graph.microsoft.com/v1.0/drives/" . SP_DRIVE_ID
               . "/root:/" . SP_FOLDER_PATH . "/" . $filename . ":/content";

    $ctx = stream_context_create(['http' => [
        'method'  => 'PUT',
        'header'  => implode("\r\n", [
            "Authorization: Bearer $token",
            "Content-Type: application/pdf",
            "Content-Length: " . strlen($pdfBytes),
        ]),
        'content' => $pdfBytes,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($uploadUrl, false, $ctx);
    if (!$resp) { error_log('SharePoint upload: no response'); return null; }
    $data = json_decode($resp, true);
    if (isset($data['id'])) return $data['id'];
    error_log('SharePoint upload error: ' . $resp);
    return null;
}
