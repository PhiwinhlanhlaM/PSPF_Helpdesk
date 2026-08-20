<?php
/**
 * IT Access - shared custom form-field reader + validator.
 *
 * Custom fields are superadmin-defined request-level questions (see migration
 * 009). They live in it_form_fields and their answers in
 * it_access_requests.custom_values as a JSON map { field_key: answer }.
 *
 * This include is used by:
 *   form_fields.php         (public read for the form)
 *   form_fields_admin.php   (superadmin CRUD)
 *   submit.php / appeal.php (capture + validate answers on a request)
 *   list.php                (expose answers, labelled, for the web views)
 *
 * Include-only: defines functions, emits nothing.
 */

if (!function_exists('itaFormFields')) {
    /**
     * Read the custom field definitions.
     *
     * @param bool $includeInactive  include retired fields (admin editor only)
     * @return array<int,array<string,mixed>> fields in display order, each:
     *   ['id','fieldKey','label','kind','options'(array|null),'placeholder',
     *    'helpText','required'(bool),'isActive'(bool),'sortOrder']
     */
    function itaFormFields(mysqli $conn, bool $includeInactive = false): array {
        $where = $includeInactive ? '' : 'WHERE is_active = 1';
        $res = $conn->query(
            "SELECT id, field_key, label, kind, options, placeholder, help_text,
                    is_required, is_active, sort_order
             FROM it_form_fields {$where}
             ORDER BY sort_order ASC, id ASC"
        );
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $opts = null;
            if (in_array($row['kind'], ['select', 'multiselect'], true) && $row['options'] !== null) {
                $decoded = json_decode((string)$row['options'], true);
                $opts = is_array($decoded) ? array_values($decoded) : [];
            }
            $out[] = [
                'id'          => (int)$row['id'],
                'fieldKey'    => $row['field_key'],
                'label'       => $row['label'],
                'kind'        => $row['kind'],
                'options'     => $opts,
                'placeholder' => $row['placeholder'],
                'helpText'    => $row['help_text'],
                'required'    => (int)$row['is_required'] === 1,
                'isActive'    => (int)$row['is_active'] === 1,
                'sortOrder'   => (int)$row['sort_order'],
            ];
        }
        return $out;
    }
}

if (!function_exists('itaValidateCustomValues')) {
    /**
     * Validate and normalise submitted custom-field answers against the ACTIVE
     * field definitions. Required fields must be answered; select values must be
     * among the field's options; multiselect must be a subset; checkbox coerces
     * to a "true"/"false" string; number must be numeric; everything is capped.
     *
     * Only known, active field_keys are kept - unknown keys are dropped so a
     * client cannot smuggle arbitrary data into custom_values. Retired fields
     * are ignored on new submissions (their historical answers still resolve
     * for display via the admin-inclusive reader).
     *
     * @param array<string,mixed> $submitted  raw { field_key: answer } from client
     * @param array<int,array<string,mixed>> $fields  itaFormFields() active list
     * @param array<int,string> &$errors  collected human-readable errors
     * @return array<string,mixed>  normalised { field_key: answer } to store
     */
    function itaValidateCustomValues(array $submitted, array $fields, array &$errors): array {
        $clean = [];
        foreach ($fields as $f) {
            $key   = $f['fieldKey'];
            $label = $f['label'];
            $has   = array_key_exists($key, $submitted);
            $raw   = $has ? $submitted[$key] : null;

            switch ($f['kind']) {
                case 'multiselect': {
                    $vals = is_array($raw) ? array_values(array_filter(array_map(
                        static fn($v) => is_string($v) ? trim($v) : $v, $raw
                    ), static fn($v) => $v !== '' && $v !== null)) : [];
                    // Every chosen value must be a defined option.
                    $allowed = $f['options'] ?? [];
                    foreach ($vals as $v) {
                        if (!in_array($v, $allowed, true)) {
                            $errors[] = "{$label}: invalid choice";
                        }
                    }
                    if ($f['required'] && !$vals) $errors[] = "{$label} is required";
                    if ($vals) $clean[$key] = $vals;
                    break;
                }
                case 'select': {
                    $v = is_string($raw) ? trim($raw) : '';
                    $allowed = $f['options'] ?? [];
                    if ($v !== '' && !in_array($v, $allowed, true)) {
                        $errors[] = "{$label}: invalid choice";
                        $v = '';
                    }
                    if ($f['required'] && $v === '') $errors[] = "{$label} is required";
                    if ($v !== '') $clean[$key] = $v;
                    break;
                }
                case 'checkbox': {
                    $on = ($raw === true || $raw === 'true' || $raw === 1 || $raw === '1');
                    // A required checkbox must be ticked.
                    if ($f['required'] && !$on) $errors[] = "{$label} must be checked";
                    // Store the boolean either way so its state is explicit.
                    $clean[$key] = $on ? 'true' : 'false';
                    break;
                }
                case 'number': {
                    $v = is_string($raw) ? trim($raw) : (is_numeric($raw) ? (string)$raw : '');
                    if ($v !== '' && !is_numeric($v)) {
                        $errors[] = "{$label} must be a number";
                        $v = '';
                    }
                    if ($f['required'] && $v === '') $errors[] = "{$label} is required";
                    if ($v !== '') $clean[$key] = $v;
                    break;
                }
                case 'date': {
                    $v = is_string($raw) ? trim($raw) : '';
                    // Expect YYYY-MM-DD; anything else is rejected (not silently kept).
                    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        $errors[] = "{$label}: invalid date";
                        $v = '';
                    }
                    if ($f['required'] && $v === '') $errors[] = "{$label} is required";
                    if ($v !== '') $clean[$key] = $v;
                    break;
                }
                case 'textarea':
                case 'text':
                default: {
                    $v = is_string($raw) ? trim($raw) : '';
                    $cap = $f['kind'] === 'textarea' ? 2000 : 255;
                    if (mb_strlen($v) > $cap) $v = mb_substr($v, 0, $cap);
                    if ($f['required'] && $v === '') $errors[] = "{$label} is required";
                    if ($v !== '') $clean[$key] = $v;
                    break;
                }
            }
        }
        return $clean;
    }
}

if (!function_exists('itaResolveCustomValues')) {
    /**
     * Pair stored answers with their field labels for display. Uses the
     * admin-inclusive field list so answers to a since-retired field still show
     * a label. Answers whose key no longer exists at all are surfaced under the
     * raw key so nothing is silently hidden from an audit.
     *
     * @param array<string,mixed>|string|null $customValues stored JSON (decoded or raw)
     * @param array<int,array<string,mixed>> $allFields itaFormFields(includeInactive:true)
     * @return array<int,array{key:string,label:string,kind:string,value:string}>
     *         in field display order, then any orphaned keys.
     */
    function itaResolveCustomValues($customValues, array $allFields): array {
        $vals = $customValues;
        if (is_string($vals)) $vals = json_decode($vals, true);
        if (!is_array($vals)) return [];

        $byKey = [];
        foreach ($allFields as $f) $byKey[$f['fieldKey']] = $f;

        $out = [];
        $seen = [];
        // Field-defined order first.
        foreach ($allFields as $f) {
            $k = $f['fieldKey'];
            if (!array_key_exists($k, $vals)) continue;
            $out[] = [
                'key'   => $k,
                'label' => $f['label'],
                'kind'  => $f['kind'],
                'value' => itaCustomValueToString($vals[$k], $f['kind']),
            ];
            $seen[$k] = true;
        }
        // Orphaned keys (field deleted outright) - keep visible under raw key.
        foreach ($vals as $k => $v) {
            if (isset($seen[$k])) continue;
            $out[] = [
                'key'   => $k,
                'label' => $k,
                'kind'  => 'text',
                'value' => itaCustomValueToString($v),
            ];
        }
        return $out;
    }
}

if (!function_exists('itaCustomValueToString')) {
    /** Flatten one stored answer to a human display string. Dates are stored as
     *  YYYY-MM-DD and shown as dd/mm/yyyy. */
    function itaCustomValueToString($v, string $kind = 'text'): string {
        if (is_array($v)) return implode(', ', array_filter(array_map('strval', $v), 'strlen'));
        if ($v === 'true')  return 'Yes';
        if ($v === 'false') return 'No';
        if ($v === null) return '';
        $s = (string)$v;
        if ($kind === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        return $s;
    }
}
