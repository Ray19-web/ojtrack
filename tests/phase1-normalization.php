<?php
// Synthetic verification for normalized migration 001.
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks = 0;
function phase1_check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    $checks++;
}

function phase1_count(string $sql): int {
    $row = query_one($sql);
    return (int)($row['n'] ?? 0);
}

phase1_check(phase1_count("SELECT COUNT(*) n FROM users") === 8, 'legacy users preserved');
phase1_check(phase1_count("SELECT COUNT(*) n FROM students") === 3, 'legacy students preserved');
phase1_check(phase1_count("SELECT COUNT(*) n FROM coordinators") === 2, 'legacy coordinators preserved');
phase1_check(phase1_count("SELECT COUNT(*) n FROM companies") === 2, 'legacy companies preserved');

$term = query_one("SELECT * FROM academic_terms WHERE academic_year_start=2026 AND academic_year_end=2027 AND semester='1st'");
phase1_check((bool)$term, 'academic term created');
$termId = (int)$term['id'];

phase1_check(
    (int)query_one("SELECT COUNT(*) n FROM ojt_enrollments WHERE academic_term_id=?", [$termId], 'i')['n'] === 3,
    'one OJT enrollment per synthetic student'
);
phase1_check(phase1_count("SELECT COUNT(*) n FROM company_users") === 2, 'company accounts normalized');
phase1_check(
    (int)query_one(
        "SELECT COUNT(*) n
         FROM enrollment_coordinators ec
         JOIN ojt_enrollments oe ON oe.id=ec.ojt_enrollment_id
         WHERE oe.academic_term_id=? AND ec.ended_at IS NULL",
        [$termId],
        'i'
    )['n'] === 3,
    'coordinator assignments normalized'
);
phase1_check(
    (int)query_one(
        "SELECT COUNT(*) n
         FROM placements p
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.academic_term_id=?",
        [$termId],
        'i'
    )['n'] === 3,
    'company placements normalized'
);
phase1_check(
    (int)query_one(
        "SELECT COUNT(*) n
         FROM placement_supervisors ps
         JOIN placements p ON p.id=ps.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.academic_term_id=? AND ps.ended_at IS NULL",
        [$termId],
        'i'
    )['n'] === 3,
    'placement supervisors normalized'
);

$ongoing = query_one(
    "SELECT oe.status enrollment_status, p.status placement_status
     FROM ojt_enrollments oe
     JOIN placements p ON p.ojt_enrollment_id=oe.id
     WHERE oe.student_id=1 AND oe.academic_term_id=?",
    [$termId],
    'i'
);
phase1_check($ongoing && $ongoing['enrollment_status'] === 'ongoing', 'ongoing enrollment status preserved');
phase1_check($ongoing && $ongoing['placement_status'] === 'active', 'ongoing maps to active placement');

$pending = query_one(
    "SELECT oe.status enrollment_status, p.status placement_status
     FROM ojt_enrollments oe
     JOIN placements p ON p.ojt_enrollment_id=oe.id
     WHERE oe.student_id=3 AND oe.academic_term_id=?",
    [$termId],
    'i'
);
phase1_check($pending && $pending['enrollment_status'] === 'pending', 'pending enrollment status preserved');
phase1_check($pending && $pending['placement_status'] === 'planned', 'pending maps to planned placement');

phase1_check(
    phase1_count("SELECT COUNT(*) n FROM schema_migrations WHERE version='001_core_identity_and_terms'") === 1,
    'migration ledger recorded exactly once'
);

echo json_encode(['phase1_normalization_checks_passed' => $checks, 'result' => 'PASS']) . PHP_EOL;
