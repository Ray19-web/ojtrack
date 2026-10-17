<?php
function activity_log_filters(array $input): array {
    $search = is_string($input['q'] ?? '') ? trim($input['q'] ?? '') : '';
    $date = is_string($input['date'] ?? '') ? trim($input['date'] ?? '', " \t\r\n") : '';
    $role = is_string($input['role'] ?? '') ? trim($input['role'] ?? '') : '';
    $error = '';
    if (!in_array($role, ['', 'admin', 'student', 'coordinator', 'company', 'system'], true)) {
        $role = '';
        $error = 'Choose a valid role.';
    }
    if ($date !== '') {
        $parsed = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int)$parsed->format('Y') < 1000) {
            $date = '';
            $error = 'Choose a valid date.';
        }
    }
    $where = '1=1'; $params = []; $types = '';
    if ($search !== '') {
        $where .= ' AND (al.action LIKE ? OR al.details LIKE ? OR u.name LIKE ?)';
        $params = ["%$search%", "%$search%", "%$search%"];
        $types .= 'sss';
    }
    if ($date !== '') {
        $where .= ' AND al.created_at>=? AND al.created_at<?';
        $params[] = $date . ' 00:00:00';
        $params[] = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $types .= 'ss';
    }
    if ($role === 'system') {
        $where .= ' AND u.id IS NULL';
    } elseif ($role !== '') {
        $where .= ' AND u.role=?';
        $params[] = $role;
        $types .= 's';
    }
    return compact('search', 'date', 'role', 'where', 'params', 'types', 'error');
}
