<?php
// Shared name formatting and extension helpers. Loading this file opens no database connection.
function normalize_person_name($value): string {
    if (!is_string($value)) return '';
    return mb_strtoupper(trim($value), 'UTF-8');
}

function normalize_person_name_part($value): string {
    return normalize_person_name($value);
}

function person_name_from_parts($first, $middle, $last): string {
    $first = normalize_person_name_part($first);
    $middle = normalize_person_name_part($middle);
    $last = normalize_person_name_part($last);
    return trim(implode(' ', array_filter([$first, $middle, $last], static fn($part) => $part !== '')));
}

function name_extension_types(): array {
    return [
        'PROFESSIONAL_TITLE' => 'Professional Title',
        'ACADEMIC_CREDENTIAL' => 'Academic Credential',
        'PROFESSIONAL_CREDENTIAL' => 'Professional Credential',
        'NAME_SUFFIX' => 'Name Suffix',
    ];
}

function normalize_name_extension_type($value): string {
    if (!is_string($value)) return '';
    $type = strtoupper(trim($value));
    return array_key_exists($type, name_extension_types()) ? $type : '';
}

function normalize_name_extension_value($value): string {
    if (!is_string($value)) return '';
    return normalize_person_name($value);
}

function normalize_name_extensions($types, $values): array {
    $types = is_array($types) ? array_values($types) : [$types];
    $values = is_array($values) ? array_values($values) : [$values];
    if (count($types) !== count($values)) return ['extensions' => [], 'error' => 'Each name extension needs both a type and a value.'];
    if (count($types) > 10) return ['extensions' => [], 'error' => 'A person can have up to 10 name extensions.'];
    $extensions = [];
    foreach ($types as $index => $raw_type) {
        $raw_value = $values[$index] ?? null;
        if (!is_string($raw_type) || !is_string($raw_value)) return ['extensions' => [], 'error' => 'Enter valid name extensions.'];
        $raw_type = trim($raw_type);
        $raw_value = trim($raw_value);
        $type = normalize_name_extension_type($raw_type);
        $value = normalize_name_extension_value($raw_value);
        if ($raw_type !== '' && $type === '') return ['extensions' => [], 'error' => 'Select a valid name extension type.'];
        if (($type === '') !== ($value === '')) return ['extensions' => [], 'error' => 'Select a type and enter its value for each extension, or remove the blank row.'];
        if ($type === '') continue;
        if (mb_strlen($value) > 50) return ['extensions' => [], 'error' => 'Each name extension must be 50 characters or fewer.'];
        $extensions[] = ['type' => $type, 'value' => $value];
    }
    return ['extensions' => $extensions, 'error' => null];
}

function name_extensions_from_post(string $type_key = 'name_extension_type', string $value_key = 'name_extension_value'): array {
    return normalize_name_extensions($_POST[$type_key] ?? [], $_POST[$value_key] ?? []);
}

function normalize_extension_list($extensions, $legacy_value = ''): array {
    $legacy_type = '';
    if (!is_array($extensions)) {
        $legacy_type = $extensions;
        $legacy_value = $legacy_type !== '' ? $legacy_value : '';
        $extensions = [];
    }
    if (!$extensions && $legacy_type !== '' && $legacy_value !== '') {
        $extensions = [['type' => $legacy_type, 'value' => $legacy_value]];
    }
    $normalized = [];
    foreach ($extensions as $extension) {
        if (!is_array($extension)) continue;
        $type = normalize_name_extension_type($extension['type'] ?? $extension['name_extension_type'] ?? '');
        $value = normalize_name_extension_value($extension['value'] ?? $extension['name_extension_value'] ?? '');
        if ($type !== '' && $value !== '') $normalized[] = ['type' => $type, 'value' => $value];
    }
    return $normalized;
}

function validate_name_extension($type, $value): ?string {
    return normalize_name_extensions($type, $value)['error'];
}

function format_person_name($base, $extensions = '', $legacy_value = ''): string {
    $base = normalize_person_name($base);
    $extensions = normalize_extension_list($extensions, $legacy_value);
    if ($base === '' || !$extensions) return $base;
    $titles = [];
    $suffixes = [];
    foreach ($extensions as $extension) {
        if ($extension['type'] === 'PROFESSIONAL_TITLE') $titles[] = $extension['value'];
        else $suffixes[] = $extension['value'];
    }
    if ($titles) $base = implode(' ', $titles) . ' ' . $base;
    if ($suffixes) $base .= ', ' . implode(', ', $suffixes);
    return $base;
}

function base_person_name($formatted, $extensions = '', $legacy_value = ''): string {
    $formatted = trim((string)$formatted);
    $extensions = normalize_extension_list($extensions, $legacy_value);
    if ($formatted === '' || !$extensions) return $formatted;
    $titles = [];
    $suffixes = [];
    foreach ($extensions as $extension) {
        if ($extension['type'] === 'PROFESSIONAL_TITLE') $titles[] = $extension['value'];
        else $suffixes[] = $extension['value'];
    }
    if ($suffixes) {
        $ending = ', ' . implode(', ', $suffixes);
        if (strlen($formatted) >= strlen($ending) && strcasecmp(substr($formatted, -strlen($ending)), $ending) === 0) {
            $formatted = trim(substr($formatted, 0, -strlen($ending)));
        }
    }
    if ($titles) {
        $prefix = implode(' ', $titles) . ' ';
        if (strncasecmp($formatted, $prefix, strlen($prefix)) === 0) $formatted = trim(substr($formatted, strlen($prefix)));
    }
    return $formatted;
}

function user_name_extensions(int $user_id): array {
    $extensions = query("SELECT extension_type AS type, extension_value AS value FROM user_name_extensions WHERE user_id=? ORDER BY sort_order,id", [$user_id], 'i') ?: [];
    if (!$extensions) {
        $legacy = query_one("SELECT name_extension_type,name_extension_value FROM users WHERE id=?", [$user_id], 'i');
        if ($legacy && $legacy['name_extension_type'] && $legacy['name_extension_value']) $extensions[] = ['type' => $legacy['name_extension_type'], 'value' => $legacy['name_extension_value']];
    }
    return normalize_extension_list($extensions);
}

function company_supervisor_name_extensions(int $company_id): array {
    $extensions = query("SELECT extension_type AS type, extension_value AS value FROM company_supervisor_name_extensions WHERE company_id=? ORDER BY sort_order,id", [$company_id], 'i') ?: [];
    if (!$extensions) {
        $legacy = query_one("SELECT supervisor_name_extension_type,supervisor_name_extension_value FROM companies WHERE id=?", [$company_id], 'i');
        if ($legacy && $legacy['supervisor_name_extension_type'] && $legacy['supervisor_name_extension_value']) $extensions[] = ['type' => $legacy['supervisor_name_extension_type'], 'value' => $legacy['supervisor_name_extension_value']];
    }
    return normalize_extension_list($extensions);
}

function save_user_name_extensions(int $user_id, array $extensions): void {
    query("DELETE FROM user_name_extensions WHERE user_id=?", [$user_id], 'i');
    foreach (array_values($extensions) as $order => $extension) {
        insert("INSERT INTO user_name_extensions (user_id,extension_type,extension_value,sort_order) VALUES (?,?,?,?)", [$user_id,$extension['type'],$extension['value'],$order], 'issi');
    }
    $first = $extensions[0] ?? null;
    query("UPDATE users SET name_extension_type=?,name_extension_value=? WHERE id=?", [$first['type'] ?? null,$first['value'] ?? null,$user_id], 'ssi');
}

function save_company_supervisor_name_extensions(int $company_id, array $extensions): void {
    query("DELETE FROM company_supervisor_name_extensions WHERE company_id=?", [$company_id], 'i');
    foreach (array_values($extensions) as $order => $extension) {
        insert("INSERT INTO company_supervisor_name_extensions (company_id,extension_type,extension_value,sort_order) VALUES (?,?,?,?)", [$company_id,$extension['type'],$extension['value'],$order], 'issi');
    }
    $first = $extensions[0] ?? null;
    query("UPDATE companies SET supervisor_name_extension_type=?,supervisor_name_extension_value=? WHERE id=?", [$first['type'] ?? null,$first['value'] ?? null,$company_id], 'ssi');
}

