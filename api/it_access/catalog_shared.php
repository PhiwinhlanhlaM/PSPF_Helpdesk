<?php
/**
 * IT Access - shared catalog reader.
 *
 * Builds the system catalog in the exact shape the React app's SYSTEM_CATALOG
 * constant had, so getSystem() and every consumer keep working unchanged.
 *
 * Lives on its own (rather than inside catalog.php) so the read endpoint and
 * the admin write endpoint share one definition of the shape - a write can
 * then return the updated catalog in the same round trip, and there is no
 * second copy to drift.
 *
 * Include-only: defines a function and emits nothing.
 */

if (!function_exists('itaBuildCatalog')) {
    /**
     * @param bool $includeInactive  include retired systems (admin editor only)
     * @param bool $withUsage        attach usageCount - how many stored request
     *                               rows reference each system; drives the
     *                               admin UI's "safe to delete?" check
     * @return array<int,array<string,mixed>> systems in SYSTEM_CATALOG shape
     */
    function itaBuildCatalog(mysqli $conn, bool $includeInactive = false, bool $withUsage = false): array {
        $where = $includeInactive ? '' : 'WHERE s.is_active = 1';
        $res = $conn->query(
            "SELECT s.id, s.name, s.description, s.icon, s.multi_role, s.sort_order, s.is_active
             FROM it_systems s {$where}
             ORDER BY s.sort_order ASC, s.name ASC"
        );

        $systems = [];
        while ($row = $res->fetch_assoc()) {
            $sys = [
                'id'        => $row['id'],
                'name'      => $row['name'],
                'desc'      => $row['description'] ?? '',
                'icon'      => $row['icon'],
                'isActive'  => (int)$row['is_active'] === 1,
                'sortOrder' => (int)$row['sort_order'],
            ];
            // Emit multiRole only when true, mirroring the original constant
            // (which simply omitted the flag on single-role systems).
            if ((int)$row['multi_role'] === 1) $sys['multiRole'] = true;
            $systems[$row['id']] = $sys;
        }

        if (!$systems) return [];

        $roleRes = $conn->query(
            "SELECT system_id, label FROM it_system_roles
             ORDER BY system_id ASC, sort_order ASC, id ASC"
        );
        while ($r = $roleRes->fetch_assoc()) {
            if (isset($systems[$r['system_id']])) {
                $systems[$r['system_id']]['roles'][] = $r['label'];
            }
        }

        $subRes = $conn->query(
            "SELECT system_id, sub_key, label, kind, options FROM it_system_suboptions
             ORDER BY system_id ASC, sort_order ASC, id ASC"
        );
        while ($s = $subRes->fetch_assoc()) {
            if (!isset($systems[$s['system_id']])) continue;
            $entry = ['key' => $s['sub_key'], 'label' => $s['label']];
            if ($s['kind'] === 'text') {
                $entry['text']  = true;
                $entry['multi'] = false;
            } else {
                $entry['multi'] = ($s['kind'] === 'multi');
                $decoded = json_decode((string)$s['options'], true);
                $entry['options'] = is_array($decoded) ? $decoded : [];
            }
            $systems[$s['system_id']]['subOptions'][] = $entry;
        }

        if ($withUsage) {
            foreach ($systems as $sid => $_) { $systems[$sid]['usageCount'] = 0; }
            $useRes = $conn->query(
                "SELECT system_id, COUNT(*) AS c FROM it_request_systems GROUP BY system_id"
            );
            while ($u = $useRes->fetch_assoc()) {
                if (isset($systems[$u['system_id']])) {
                    $systems[$u['system_id']]['usageCount'] = (int)$u['c'];
                }
            }
        }

        return array_values($systems);
    }
}

if (!function_exists('itaSystemDisplay')) {
    /**
     * Resolve how one requested system should be shown to a human, at every
     * touchpoint (email, PDF, and via list.php the three web views).
     *
     * For the special "other" system the requester types a free-text system
     * name and role; those must REPLACE the generic "OTHER SYSTEM" label so the
     * actual system they named is shown. The form stores free-text answers
     * positionally (sub_0, sub_1, ...) in the order the catalog defines the
     * sub-options, so we read them by position: first text sub-option = the
     * system's display name, second = its role.
     *
     * For normal systems the catalog name and the stored role are used as-is,
     * with any remaining sub-values returned as a detail string.
     *
     * @param string            $systemId   e.g. 'other', 'banking'
     * @param string            $role       stored role text (may be empty)
     * @param array|string|null $subValues  decoded sub_values (assoc/array) or raw
     * @param array<int,array<string,mixed>>|null $catalog  itaBuildCatalog() output;
     *                          pass it to avoid a rebuild per row. When omitted the
     *                          system id is used as the fallback name.
     * @return array{name:string, role:string, detail:string}
     */
    function itaSystemDisplay(string $systemId, string $role, $subValues, ?array $catalog = null): array {
        // Normalise sub-values to an ordered list of [value] preserving insertion
        // order (sub_0, sub_1, ...). Multi-select arrays are joined.
        $ordered = [];
        if (is_array($subValues)) {
            foreach ($subValues as $v) {
                if (is_array($v))      $ordered[] = implode(', ', array_filter($v, 'strlen'));
                elseif ($v !== null)   $ordered[] = trim((string)$v);
                else                   $ordered[] = '';
            }
        } elseif (is_string($subValues) && $subValues !== '') {
            $ordered[] = trim($subValues);
        }

        // Catalog name lookup (fallback to the id).
        $catName = $systemId;
        if ($catalog) {
            foreach ($catalog as $c) {
                if (($c['id'] ?? null) === $systemId) { $catName = $c['name'] ?? $systemId; break; }
            }
        }

        if ($systemId === 'other') {
            // First typed value = the system's name; second = its role.
            // Uppercase the typed name so it matches the catalog systems, which
            // are stored uppercase (e.g. "BANKING ACCESS"). Roles are left as-is.
            $typedName = $ordered[0] ?? '';
            $typedRole = $ordered[1] ?? '';
            $extra     = array_slice($ordered, 2);
            return [
                'name'   => $typedName !== '' ? mb_strtoupper($typedName, 'UTF-8') : $catName,
                'role'   => $typedRole !== '' ? $typedRole : $role,
                'detail' => implode(' · ', array_filter($extra, 'strlen')),
            ];
        }

        return [
            'name'   => $catName,
            'role'   => $role,
            'detail' => implode(' · ', array_filter($ordered, 'strlen')),
        ];
    }
}
