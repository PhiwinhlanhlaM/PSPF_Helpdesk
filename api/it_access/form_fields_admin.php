<?php
/**
 * IT Access - custom form fields (write). Superadmin only.
 *
 * Backs the form-builder UI. Every write is CSRF-checked, runs in a
 * transaction, and is recorded in audit_logs.
 *
 * POST JSON, with `action` selecting the operation:
 *   { action: "save",       field: {...} }            create or update a field
 *   { action: "deactivate", key: "cost_centre" }      retire (history resolves)
 *   { action: "activate",   key: "cost_centre" }
 *   { action: "delete",     key: "cost_centre" }      only when usageCount = 0
 *   { action: "reorder",    order: ["a","b","c"] }     field_keys top-to-bottom
 *
 * Every successful call returns the full field list (including retired), so the
 * builder never renders a stale list.
 *
 * KEY DISCIPLINE: a field's `field_key` is the contract with stored request
 * answers (it_access_requests.custom_values). Editing a field keeps its key;
 * a new field mints a fresh key checked against the it_form_field_keys ledger,
 * so a key is never reused even after a field is deleted. Reordering touches
 * only sort_order.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once '../session_config.php';
require_once '../db.php';
require_once '../includes/auth_helpers.php';
require_once __DIR__ . '/form_fields_shared.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

if (!hasRole('superadmin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a superadmin can manage form fields']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token mismatch']);
    exit;
}

$userId = (int)$_SESSION['user']['id'];

/** Record a form-field change in audit_logs. */
function itaFieldAudit(mysqli $conn, int $userId, string $action, string $details): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) return;
    $stmt->bind_param("issss", $userId, $action, $details, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

/** Slugify a label into a candidate field_key: lowercase, alnum + underscore. */
function itaFieldSlug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim((string)$s, '_');
}

/** How many stored requests reference this field_key in custom_values? */
function itaFieldUsage(mysqli $conn, string $key): int {
    // custom_values is a JSON map; a stored key appears as "key": in the text.
    // A LIKE on the quoted key is sufficient and index-agnostic (small table).
    $needle = '%' . $conn->real_escape_string('"' . $key . '"') . '%';
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM it_access_requests
         WHERE custom_values LIKE ?"
    );
    $stmt->bind_param("s", $needle);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

/** Mint a permanent field_key from a label, unique against the ledger. */
function itaMintFieldKey(mysqli $conn, string $label): string {
    $base = itaFieldSlug($label);
    if ($base === '') $base = 'field';
    if (mb_strlen($base) > 55) $base = mb_substr($base, 0, 55);
    $key = $base; $n = 2;
    while (true) {
        $chk = $conn->prepare("SELECT 1 FROM it_form_field_keys WHERE field_key = ? LIMIT 1");
        $chk->bind_param("s", $key);
        $chk->execute();
        $exists = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$exists) break;
        $key = $base . '_' . $n++;
    }
    return $key;
}

const ITA_FIELD_KINDS = ['text','textarea','number','date','select','multiselect','checkbox'];

$action = $body['action'] ?? '';

try {
    switch ($action) {

        // -------------------------------------------------------------
        // Create or update a field.
        // -------------------------------------------------------------
        case 'save': {
            $f = $body['field'] ?? null;
            if (!is_array($f)) throw new InvalidArgumentException('field object required');

            $label = trim((string)($f['label'] ?? ''));
            $kind  = (string)($f['kind'] ?? 'text');
            $required    = !empty($f['required']) ? 1 : 0;
            $placeholder = trim((string)($f['placeholder'] ?? ''));
            $helpText    = trim((string)($f['helpText'] ?? ''));
            $isNew = empty($f['fieldKey']);

            if ($label === '')            throw new InvalidArgumentException('Label is required');
            if (mb_strlen($label) > 150)  throw new InvalidArgumentException('Label is too long (max 150)');
            if (!in_array($kind, ITA_FIELD_KINDS, true)) throw new InvalidArgumentException('Unknown field type');
            if (mb_strlen($placeholder) > 255) $placeholder = mb_substr($placeholder, 0, 255);
            if (mb_strlen($helpText) > 500)    $helpText = mb_substr($helpText, 0, 500);

            // Options only for select/multiselect, and required there.
            $optsJson = null;
            if (in_array($kind, ['select', 'multiselect'], true)) {
                $opts = array_values(array_filter(array_map(
                    static fn($o) => trim((string)$o),
                    is_array($f['options'] ?? null) ? $f['options'] : []
                ), static fn($o) => $o !== ''));
                if (!$opts) throw new InvalidArgumentException("A {$kind} field needs at least one option");
                $optsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }

            $placeholderVal = $placeholder !== '' ? $placeholder : null;
            $helpTextVal    = $helpText !== '' ? $helpText : null;

            $conn->begin_transaction();

            if ($isNew) {
                $key = itaMintFieldKey($conn, $label);
                // Claim the key in the ledger first - permanent, never reused.
                $led = $conn->prepare("INSERT INTO it_form_field_keys (field_key) VALUES (?)");
                $led->bind_param("s", $key);
                $led->execute();
                $led->close();

                // Append at the end (highest sort_order + 10).
                $ord = (int)$conn->query(
                    "SELECT COALESCE(MAX(sort_order), 0) + 10 AS o FROM it_form_fields"
                )->fetch_assoc()['o'];

                $stmt = $conn->prepare(
                    "INSERT INTO it_form_fields
                       (field_key, label, kind, options, placeholder, help_text, is_required, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
                );
                $stmt->bind_param("ssssssii", $key, $label, $kind, $optsJson, $placeholderVal, $helpTextVal, $required, $ord);
                $stmt->execute();
                $stmt->close();
                itaFieldAudit($conn, $userId, 'it_form_field_create', "key={$key} label={$label} kind={$kind}");
            } else {
                $key = (string)$f['fieldKey'];
                $chk = $conn->prepare("SELECT 1 FROM it_form_fields WHERE field_key = ? LIMIT 1");
                $chk->bind_param("s", $key);
                $chk->execute();
                $found = (bool)$chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$found) throw new InvalidArgumentException("Unknown field: {$key}");

                $stmt = $conn->prepare(
                    "UPDATE it_form_fields
                        SET label = ?, kind = ?, options = ?, placeholder = ?, help_text = ?, is_required = ?
                      WHERE field_key = ?"
                );
                $stmt->bind_param("sssssis", $label, $kind, $optsJson, $placeholderVal, $helpTextVal, $required, $key);
                $stmt->execute();
                $stmt->close();
                itaFieldAudit($conn, $userId, 'it_form_field_update', "key={$key} label={$label} kind={$kind}");
            }

            $conn->commit();
            break;
        }

        case 'deactivate':
        case 'activate': {
            $key = trim((string)($body['key'] ?? ''));
            if ($key === '') throw new InvalidArgumentException('key required');
            $active = $action === 'activate' ? 1 : 0;
            $stmt = $conn->prepare("UPDATE it_form_fields SET is_active = ? WHERE field_key = ?");
            $stmt->bind_param("is", $active, $key);
            $stmt->execute();
            $stmt->close();
            itaFieldAudit($conn, $userId, "it_form_field_{$action}", "key={$key}");
            break;
        }

        case 'delete': {
            $key = trim((string)($body['key'] ?? ''));
            if ($key === '') throw new InvalidArgumentException('key required');
            // Refuse hard-delete while any request references the key - retire it
            // instead so historical answers keep resolving to a label.
            $used = itaFieldUsage($conn, $key);
            if ($used > 0) {
                throw new InvalidArgumentException(
                    "This field is used by {$used} request(s). Retire it instead of deleting."
                );
            }
            $conn->begin_transaction();
            $stmt = $conn->prepare("DELETE FROM it_form_fields WHERE field_key = ?");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $stmt->close();
            // Tombstone the key in the ledger so it can never be reissued.
            $led = $conn->prepare("UPDATE it_form_field_keys SET retired_at = NOW() WHERE field_key = ?");
            $led->bind_param("s", $key);
            $led->execute();
            $led->close();
            $conn->commit();
            itaFieldAudit($conn, $userId, 'it_form_field_delete', "key={$key}");
            break;
        }

        case 'reorder': {
            $order = $body['order'] ?? null;
            if (!is_array($order)) throw new InvalidArgumentException('order array required');
            $conn->begin_transaction();
            $up = $conn->prepare("UPDATE it_form_fields SET sort_order = ? WHERE field_key = ?");
            $o = 10;
            foreach ($order as $key) {
                $key = (string)$key;
                $up->bind_param("is", $o, $key);
                $up->execute();
                $o += 10;
            }
            $up->close();
            $conn->commit();
            itaFieldAudit($conn, $userId, 'it_form_field_reorder', 'order=' . implode(',', array_map('strval', $order)));
            break;
        }

        default:
            throw new InvalidArgumentException("Unknown action: {$action}");
    }

    // Return the full list (including retired) so the builder stays in sync.
    echo json_encode(['ok' => true, 'fields' => itaFormFields($conn, true)], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    if ($conn->errno) { /* ignore */ }
    @$conn->rollback();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    @$conn->rollback();
    error_log('form_fields_admin error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
