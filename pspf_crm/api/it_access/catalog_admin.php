<?php
/**
 * IT Access — system catalog (write). Superadmin only.
 *
 * Backs the catalog management UI. Every write is CSRF-checked, runs inside a
 * transaction, and is recorded in audit_logs so the audit can answer "who
 * changed the catalog, and when".
 *
 * POST JSON, with `action` selecting the operation:
 *   { action: "save",       system: {...} }        create or update a system
 *   { action: "deactivate", id: "trust" }          retire (history still resolves)
 *   { action: "activate",   id: "trust" }
 *   { action: "delete",     id: "trust" }          only when usageCount = 0
 *   { action: "reorder",    order: ["ad","trust"] }
 *
 * Every successful call returns the full updated catalog, so the client never
 * has to re-fetch and can't render a stale list.
 *
 * WHY sub_key MATTERS HERE: a sub-option's key is the contract with stored
 * request answers (it_request_systems.sub_values). Editing a sub-option keeps
 * its key; adding one mints a fresh key that is never reused, even if an
 * earlier sub-option with the same label was removed. Reordering only touches
 * sort_order. This is what makes structural editing safe for historical data.
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
require_once __DIR__ . '/catalog_shared.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
enforceActiveUser($conn);

// Managing the catalog is a superadmin function. Checked via hasRole() so it
// works regardless of which persona is currently active.
if (!hasRole('superadmin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Only a superadmin can manage the system catalog']);
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

// CSRF
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF token mismatch']);
    exit;
}

$userId   = (int)$_SESSION['user']['id'];
$username = $_SESSION['user']['username'] ?? '';

/** Record a catalog change in audit_logs (same shape settings/profile.php uses). */
function itaAudit(mysqli $conn, int $userId, string $action, string $details): void {
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

/** Slugify a name into a candidate system id: lowercase, alnum + underscore. */
function itaSlug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim((string)$s, '_');
}

/** How many stored request rows reference this system? */
function itaUsageCount(mysqli $conn, string $systemId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM it_request_systems WHERE system_id = ?");
    $stmt->bind_param("s", $systemId);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

$action = $body['action'] ?? '';

try {
    switch ($action) {

        // -------------------------------------------------------------
        // Create or update a system, including its roles and sub-options.
        // -------------------------------------------------------------
        case 'save': {
            $sys = $body['system'] ?? null;
            if (!is_array($sys)) throw new InvalidArgumentException('system object required');

            $name = trim((string)($sys['name'] ?? ''));
            $desc = trim((string)($sys['desc'] ?? ''));
            $icon = trim((string)($sys['icon'] ?? 'archive'));
            $multiRole = !empty($sys['multiRole']) ? 1 : 0;
            $isNew = empty($sys['id']);

            if ($name === '')            throw new InvalidArgumentException('Name is required');
            if (mb_strlen($name) > 255)  throw new InvalidArgumentException('Name is too long (max 255)');
            if (mb_strlen($desc) > 500)  throw new InvalidArgumentException('Description is too long (max 500)');

            // Icon must be one Icon.jsx actually renders, otherwise the row
            // silently shows nothing. Keep in step with Icon.jsx's switch.
            $allowedIcons = ['shield','shield-check','bank','key','door','phone','archive','scale','user','clock','check','alert'];
            if (!in_array($icon, $allowedIcons, true)) $icon = 'archive';

            $roles = array_values(array_filter(array_map(
                static fn($r) => trim((string)$r),
                is_array($sys['roles'] ?? null) ? $sys['roles'] : []
            ), static fn($r) => $r !== ''));

            $subs = is_array($sys['subOptions'] ?? null) ? $sys['subOptions'] : [];

            $conn->begin_transaction();

            if ($isNew) {
                // Derive a slug and make it unique. The slug is permanent — it
                // is what request history will reference forever.
                $base = itaSlug($name);
                if ($base === '') $base = 'system';
                $id = $base; $n = 2;
                while (true) {
                    $chk = $conn->prepare("SELECT 1 FROM it_systems WHERE id = ? LIMIT 1");
                    $chk->bind_param("s", $id);
                    $chk->execute();
                    $exists = (bool)$chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (!$exists) break;
                    $id = $base . '_' . $n++;
                }
                if (mb_strlen($id) > 100) $id = mb_substr($id, 0, 100);

                $ord = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 10 AS o FROM it_systems")
                            ->fetch_assoc()['o'];
                $stmt = $conn->prepare(
                    "INSERT INTO it_systems (id, name, description, icon, multi_role, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)"
                );
                $stmt->bind_param("ssssii", $id, $name, $desc, $icon, $multiRole, $ord);
                $stmt->execute();
                $stmt->close();
            } else {
                $id = (string)$sys['id'];
                $chk = $conn->prepare("SELECT 1 FROM it_systems WHERE id = ? LIMIT 1");
                $chk->bind_param("s", $id);
                $chk->execute();
                $found = (bool)$chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$found) throw new InvalidArgumentException("Unknown system: {$id}");

                $stmt = $conn->prepare(
                    "UPDATE it_systems SET name = ?, description = ?, icon = ?, multi_role = ? WHERE id = ?"
                );
                $stmt->bind_param("sssis", $name, $desc, $icon, $multiRole, $id);
                $stmt->execute();
                $stmt->close();
            }

            // ---- Roles: replace wholesale. Roles are stored on the request as
            // a free-text label, so removing one never breaks history.
            $del = $conn->prepare("DELETE FROM it_system_roles WHERE system_id = ?");
            $del->bind_param("s", $id);
            $del->execute();
            $del->close();

            if ($roles) {
                $ins = $conn->prepare(
                    "INSERT INTO it_system_roles (system_id, label, sort_order) VALUES (?, ?, ?)"
                );
                $o = 10;
                foreach ($roles as $label) {
                    if (mb_strlen($label) > 100) $label = mb_substr($label, 0, 100);
                    $ins->bind_param("ssi", $id, $label, $o);
                    $ins->execute();
                    $o += 10;
                }
                $ins->close();
            }

            // ---- Sub-options: reconcile by sub_key, never by position.
            //
            // An incoming sub-option with a key updates that row in place; one
            // without a key is new and gets a freshly minted key. Keys absent
            // from the payload are deleted. Because keys are stable, a stored
            // answer keyed 'ad_duration' always means the same question no
            // matter how the list is reordered.
            $existing = [];
            $exStmt = $conn->prepare("SELECT sub_key FROM it_system_suboptions WHERE system_id = ?");
            $exStmt->bind_param("s", $id);
            $exStmt->execute();
            $exRes = $exStmt->get_result();
            while ($r = $exRes->fetch_assoc()) $existing[$r['sub_key']] = true;
            $exStmt->close();

            $seen = [];
            $order = 10;
            foreach ($subs as $so) {
                $label = trim((string)($so['label'] ?? ''));
                if ($label === '') continue;
                if (mb_strlen($label) > 150) $label = mb_substr($label, 0, 150);

                $isText = !empty($so['text']);
                $kind   = $isText ? 'text' : (!empty($so['multi']) ? 'multi' : 'single');

                $optsJson = null;
                if (!$isText) {
                    $opts = array_values(array_filter(array_map(
                        static fn($o) => trim((string)$o),
                        is_array($so['options'] ?? null) ? $so['options'] : []
                    ), static fn($o) => $o !== ''));
                    if (!$opts) {
                        throw new InvalidArgumentException("Sub-option \"{$label}\" needs at least one choice, or should be free text.");
                    }
                    $optsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
                }

                $key = trim((string)($so['key'] ?? ''));
                if ($key !== '' && isset($existing[$key])) {
                    // Update in place — key preserved, so stored answers still map.
                    $up = $conn->prepare(
                        "UPDATE it_system_suboptions SET label = ?, kind = ?, options = ?, sort_order = ?
                         WHERE system_id = ? AND sub_key = ?"
                    );
                    $up->bind_param("sssiss", $label, $kind, $optsJson, $order, $id, $key);
                    $up->execute();
                    $up->close();
                } else {
                    // Mint a permanent key, checked against the LEDGER rather than
                    // the live table. Checking only live rows would let a deleted
                    // key be handed out again: delete "Building" (physical_building)
                    // then re-add a "Building" and the old key comes back, silently
                    // re-pointing historical answers at a different question. The
                    // ledger keeps every key ever issued, so that cannot happen.
                    $base = $id . '_' . itaSlug($label);
                    if (mb_strlen($base) > 55) $base = mb_substr($base, 0, 55);
                    $key = $base; $n = 2;
                    while (true) {
                        $kc = $conn->prepare(
                            "SELECT 1 FROM it_system_suboption_keys WHERE system_id = ? AND sub_key = ? LIMIT 1"
                        );
                        $kc->bind_param("ss", $id, $key);
                        $kc->execute();
                        $taken = (bool)$kc->get_result()->fetch_assoc();
                        $kc->close();
                        if (!$taken && !isset($seen[$key])) break;
                        $key = $base . '_' . $n++;
                    }

                    // Claim the key permanently before use.
                    $claim = $conn->prepare(
                        "INSERT INTO it_system_suboption_keys (system_id, sub_key) VALUES (?, ?)"
                    );
                    $claim->bind_param("ss", $id, $key);
                    $claim->execute();
                    $claim->close();

                    $ins = $conn->prepare(
                        "INSERT INTO it_system_suboptions (system_id, sub_key, label, kind, options, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $ins->bind_param("sssssi", $id, $key, $label, $kind, $optsJson, $order);
                    $ins->execute();
                    $ins->close();
                }

                $seen[$key] = true;
                $order += 10;
            }

            // Delete sub-options the payload dropped. The ledger row is KEPT and
            // stamped retired_at, so the key can never be issued again.
            foreach (array_keys($existing) as $oldKey) {
                if (isset($seen[$oldKey])) continue;
                $dk = $conn->prepare("DELETE FROM it_system_suboptions WHERE system_id = ? AND sub_key = ?");
                $dk->bind_param("ss", $id, $oldKey);
                $dk->execute();
                $dk->close();

                $rk = $conn->prepare(
                    "UPDATE it_system_suboption_keys SET retired_at = NOW()
                     WHERE system_id = ? AND sub_key = ? AND retired_at IS NULL"
                );
                $rk->bind_param("ss", $id, $oldKey);
                $rk->execute();
                $rk->close();
            }

            $conn->commit();
            itaAudit($conn, $userId, $isNew ? 'it_catalog_create' : 'it_catalog_update',
                     ($isNew ? 'Created' : 'Updated') . " IT Access system '{$id}' ({$name})");
            break;
        }

        // -------------------------------------------------------------
        // Retire / restore. Retiring hides a system from new requests but
        // leaves history able to resolve its name — this is the safe
        // alternative to deletion and the one the UI steers toward.
        // -------------------------------------------------------------
        case 'deactivate':
        case 'activate': {
            $id = trim((string)($body['id'] ?? ''));
            if ($id === '') throw new InvalidArgumentException('id required');
            $to = $action === 'activate' ? 1 : 0;

            $stmt = $conn->prepare("UPDATE it_systems SET is_active = ? WHERE id = ?");
            $stmt->bind_param("is", $to, $id);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed === 0) {
                // Either unknown id, or already in that state — distinguish.
                $chk = $conn->prepare("SELECT 1 FROM it_systems WHERE id = ? LIMIT 1");
                $chk->bind_param("s", $id);
                $chk->execute();
                $found = (bool)$chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$found) throw new InvalidArgumentException("Unknown system: {$id}");
            }
            itaAudit($conn, $userId, 'it_catalog_' . $action,
                     ($to ? 'Restored' : 'Retired') . " IT Access system '{$id}'");
            break;
        }

        // -------------------------------------------------------------
        // Hard delete — only when nothing references the system.
        //
        // system_id carries no FK (deliberately, so retired systems stay
        // readable in history), which means the database will not stop this.
        // The check therefore lives here.
        // -------------------------------------------------------------
        case 'delete': {
            $id = trim((string)($body['id'] ?? ''));
            if ($id === '') throw new InvalidArgumentException('id required');

            $uses = itaUsageCount($conn, $id);
            if ($uses > 0) {
                http_response_code(409);
                echo json_encode([
                    'error' => "This system is used by {$uses} existing request" . ($uses === 1 ? '' : 's')
                             . " and cannot be deleted. Retire it instead — it will disappear from new"
                             . " requests while past records keep showing it.",
                    'usageCount' => $uses,
                ]);
                exit;
            }

            $conn->begin_transaction();
            // Roles and sub-options cascade via FK.
            $stmt = $conn->prepare("DELETE FROM it_systems WHERE id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $gone = $stmt->affected_rows;
            $stmt->close();
            $conn->commit();

            if ($gone === 0) throw new InvalidArgumentException("Unknown system: {$id}");
            itaAudit($conn, $userId, 'it_catalog_delete', "Deleted unused IT Access system '{$id}'");
            break;
        }

        // -------------------------------------------------------------
        // Reorder. Touches sort_order only — never stored data.
        // -------------------------------------------------------------
        case 'reorder': {
            $order = is_array($body['order'] ?? null) ? $body['order'] : [];
            if (!$order) throw new InvalidArgumentException('order array required');

            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE it_systems SET sort_order = ? WHERE id = ?");
            $o = 10;
            foreach ($order as $sid) {
                $sid = (string)$sid;
                $stmt->bind_param("is", $o, $sid);
                $stmt->execute();
                $o += 10;
            }
            $stmt->close();
            $conn->commit();
            itaAudit($conn, $userId, 'it_catalog_reorder', 'Reordered the IT Access system catalog');
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
            exit;
    }

    // Always hand back the fresh catalog so the client cannot go stale.
    echo json_encode(['ok' => true, 'systems' => itaBuildCatalog($conn, true, true)]);

} catch (InvalidArgumentException $e) {
    if ($conn->errno === 0) { @$conn->rollback(); }
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($conn->errno === 0) { @$conn->rollback(); }
    error_log('catalog_admin error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the catalog. Please try again.']);
}
