<?php
/** Fill missing assignments when a coordinator is configured for a program. */
function assign_unassigned_students_for_program(array $program): void {
    $coordinatorId = find_coordinator_for_program($program);
    if (!$coordinatorId) return;
    query(
        "UPDATE students s JOIN users u ON u.id=s.user_id
         SET s.coordinator_id=?
         WHERE s.program_id=? AND s.coordinator_id IS NULL
           AND s.is_archived=0 AND u.status!='archived'",
        [$coordinatorId, (int)$program['id']], 'ii'
    );
}

/**
 * Match an academic program to an active coordinator, preferring its exact code
 * or name before legacy department aliases. Lowest coordinator ID breaks ties.
 */
function find_coordinator_for_program(array $program) {
    // Resolve all exact program labels before trying legacy department aliases.
    foreach ([$program['code'], $program['name']] as $label) {
        $match = query_one(
            "SELECT c.id FROM coordinators c JOIN users u ON u.id=c.user_id
             WHERE u.status='active' AND LOWER(TRIM(c.department))=LOWER(TRIM(?))
             ORDER BY c.id LIMIT 1", [$label], 's'
        );
        if ($match) return (int)$match['id'];
    }
    return find_coordinator_for_department($program['code'])
        ?: find_coordinator_for_department($program['name']);
}

function find_coordinator_for_department($department) {
    $dept = trim((string)$department);
    if ($dept === '') {
        return null;
    }

    // Exact match first
    $match = query_one(
        "SELECT c.id FROM coordinators c
         JOIN users u ON u.id = c.user_id
         WHERE u.status = 'active' AND c.department = ?
         ORDER BY c.id ASC LIMIT 1",
        [$dept],
        's'
    );
    if ($match) {
        return (int)$match['id'];
    }

    $aliases = department_aliases($dept);
    $coords = query(
        "SELECT c.id, c.department FROM coordinators c
         JOIN users u ON u.id = c.user_id
         WHERE u.status = 'active' AND c.department IS NOT NULL AND c.department != ''
         ORDER BY c.id ASC",
        [],
        ''
    ) ?: [];

    $dept_lc = strtolower($dept);
    foreach ($coords as $c) {
        $c_dept = trim($c['department'] ?? '');
        $c_lc = strtolower($c_dept);
        if ($c_lc === $dept_lc) {
            return (int)$c['id'];
        }
        $c_aliases = department_aliases($c_dept);
        if (array_intersect($aliases, $c_aliases)) {
            return (int)$c['id'];
        }
    }

    return null;
}

/** Normalize department names into comparable alias tokens. */
function department_aliases($department) {
    $raw = strtolower(trim((string)$department));
    if ($raw === '') {
        return [];
    }

    $aliases = [$raw];

    // Strip common filler words for looser comparison
    $compact = preg_replace('/\b(department|dept|of|and|&)\b/', ' ', $raw);
    $compact = trim(preg_replace('/\s+/', ' ', $compact));
    if ($compact !== '' && $compact !== $raw) {
        $aliases[] = $compact;
    }

    $map = [
        'it' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'bsit' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'information technology' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'bachelor of science in information technology' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'cs' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'bscs' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'computer science' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'bachelor of science in computer science' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'hr' => ['hr', 'human resources', 'human resource'],
        'human resources' => ['hr', 'human resources', 'human resource'],
        'finance' => ['finance', 'financial'],
        'bsmet' => ['bsmet', 'marine engineering technology', 'bachelor of science in marine engineering technology'],
        'marine engineering technology' => ['bsmet', 'marine engineering technology', 'bachelor of science in marine engineering technology'],
        'bachelor of science in marine engineering technology' => ['bsmet', 'marine engineering technology', 'bachelor of science in marine engineering technology'],
        'mechanical engineering technology' => ['mechanical engineering technology', 'bachelor of science in mechanical engineering technology'],
        'bachelor of science in mechanical engineering technology' => ['mechanical engineering technology', 'bachelor of science in mechanical engineering technology'],
        'bsname' => ['bsname', 'naval architecture and marine engineering', 'bachelor of science in naval architecture and marine engineering'],
        'naval architecture and marine engineering' => ['bsname', 'naval architecture and marine engineering', 'bachelor of science in naval architecture and marine engineering'],
        'bachelor of science in naval architecture and marine engineering' => ['bsname', 'naval architecture and marine engineering', 'bachelor of science in naval architecture and marine engineering'],
        'bsesm' => ['bsesm', 'energy systems management', 'bachelor of science in energy systems management'],
        'energy systems management' => ['bsesm', 'energy systems management', 'bachelor of science in energy systems management'],
        'bachelor of science in energy systems management' => ['bsesm', 'energy systems management', 'bachelor of science in energy systems management'],
        'bset' => ['electrical technology', 'bset'],
        'electrical technology' => ['electrical technology', 'bset'],
        'bachelor of science in electrical technology' => ['electrical technology', 'bset'],
        'education' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'ed' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'bsed' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'bachelor of secondary education' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'technology communication management' => ['tcm', 'bstcm', 'technology communication management'],
        'tcm' => ['tcm', 'bstcm', 'technology communication management'],
        'bstcm' => ['tcm', 'bstcm', 'technology communication management'],
        'bachelor of science in technology communication management' => ['tcm', 'bstcm', 'technology communication management'],
    ];

    foreach ($map as $key => $vals) {
        if ($raw === $key || $compact === $key || in_array($raw, $vals, true) || in_array($compact, $vals, true)) {
            $aliases = array_merge($aliases, $vals);
        }
    }

    return array_values(array_unique($aliases));
}

