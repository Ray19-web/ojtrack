<?php
function coordinator_filters(array $input): array {
    $text = static fn($key) => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    $search = mb_substr($text('q'), 0, 150);
    $program = mb_substr($text('program'), 0, 150);
    $status = in_array($text('status'), ['active', 'inactive'], true) ? $text('status') : '';
    $where = "u.status!='archived'";
    $params = []; $types = '';
    if ($search !== '') {
        // Escape LIKE wildcards so typed IDs, underscores and percent signs are literal.
        $like = '%' . strtr($search, ['!'=>'!!', '%'=>'!%', '_'=>'!_']) . '%';
        $where .= " AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR c.coordinator_id_no LIKE ? ESCAPE '!' OR c.contact_number LIKE ? ESCAPE '!' OR c.department LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM programs p WHERE (p.code=c.department OR p.name=c.department) AND p.name LIKE ? ESCAPE '!'))";
        $params = array_fill(0, 6, $like); $types = 'ssssss';
    }
    if ($program !== '') {
        $where .= ' AND (c.department=? OR EXISTS (SELECT 1 FROM programs p WHERE p.code=? AND p.name=c.department))';
        array_push($params, $program, $program); $types .= 'ss';
    }
    if ($status !== '') {
        $where .= ' AND u.status=?'; $params[] = $status; $types .= 's';
    }
    return compact('search', 'program', 'status', 'where', 'params', 'types');
}
