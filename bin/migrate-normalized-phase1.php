<?php
/**
 * OJTrack normalized migration 001.
 *
 * Additive migration only:
 * - creates academic term / enrollment / placement structures
 * - copies current legacy relationships into them
 * - never deletes or alters legacy rows
 *
 * Usage:
 *   php bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --dry-run
 *   php bin/migrate-normalized-phase1.php --academic-year=2026-2027 --semester=1st --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$options = getopt('', ['academic-year:', 'semester:', 'apply', 'dry-run']);
$academicYear = trim((string)($options['academic-year'] ?? ''));
$semester = strtolower(trim((string)($options['semester'] ?? '')));
$apply = array_key_exists('apply', $options);
$dryRun = array_key_exists('dry-run', $options) || !$apply;

if ($apply && array_key_exists('dry-run', $options)) {
    fwrite(STDERR, "Choose either --apply or --dry-run, not both.\n");
    exit(2);
}

if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $matches)) {
    fwrite(STDERR, "Use --academic-year=YYYY-YYYY, for example 2026-2027.\n");
    exit(2);
}
$yearStart = (int)$matches[1];
$yearEnd = (int)$matches[2];
if ($yearEnd !== $yearStart + 1) {
    fwrite(STDERR, "Academic year end must be exactly one year after the start.\n");
    exit(2);
}

$semesterAliases = [
    '1' => '1st',
    '1st' => '1st',
    'first' => '1st',
    '2' => '2nd',
    '2nd' => '2nd',
    'second' => '2nd',
    'summer' => 'summer',
];
if (!isset($semesterAliases[$semester])) {
    fwrite(STDERR, "Use --semester=1st, --semester=2nd, or --semester=summer.\n");
    exit(2);
}
$semester = $semesterAliases[$semester];

require __DIR__ . '/../config/db.php';

function migration_scalar(string $sql, array $params = [], string $types = ''): int
{
    $row = query_one($sql, $params, $types);
    if (!$row) return 0;
    $value = reset($row);
    return (int)$value;
}

function migration_problem(string $label, int $count, array &$problems): void
{
    if ($count > 0) {
        $problems[$label] = $count;
    }
}

function migration_execute_ddl(mysqli $conn, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Migration DDL file is missing or empty: $path");
    }
    if (!$conn->multi_query($sql)) {
        throw new RuntimeException('DDL failed: ' . $conn->error);
    }
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
        if (!$conn->more_results()) break;
    } while ($conn->next_result());

    if ($conn->errno) {
        throw new RuntimeException('DDL failed: ' . $conn->error);
    }
}

function migration_placement_status(string $legacyStatus): string
{
    return match ($legacyStatus) {
        'ongoing' => 'active',
        'completed' => 'completed',
        'on_hold' => 'on_hold',
        'withdrawn' => 'terminated',
        default => 'planned',
    };
}

$problems = [];
migration_problem(
    'students_without_valid_student_user',
    migration_scalar("SELECT COUNT(*) FROM students s LEFT JOIN users u ON u.id=s.user_id WHERE u.id IS NULL OR u.role<>'student'"),
    $problems
);
migration_problem(
    'students_without_valid_program',
    migration_scalar("SELECT COUNT(*) FROM students s LEFT JOIN programs p ON p.id=s.program_id WHERE s.program_id IS NULL OR p.id IS NULL"),
    $problems
);
migration_problem(
    'students_with_invalid_coordinator',
    migration_scalar("SELECT COUNT(*) FROM students s LEFT JOIN coordinators c ON c.id=s.coordinator_id WHERE s.coordinator_id IS NOT NULL AND c.id IS NULL"),
    $problems
);
migration_problem(
    'students_with_invalid_company',
    migration_scalar("SELECT COUNT(*) FROM students s LEFT JOIN companies c ON c.id=s.company_id WHERE s.company_id IS NOT NULL AND c.id IS NULL"),
    $problems
);
migration_problem(
    'coordinators_without_valid_user',
    migration_scalar("SELECT COUNT(*) FROM coordinators c LEFT JOIN users u ON u.id=c.user_id WHERE u.id IS NULL OR u.role<>'coordinator'"),
    $problems
);
migration_problem(
    'companies_without_valid_company_user',
    migration_scalar("SELECT COUNT(*) FROM companies c LEFT JOIN users u ON u.id=c.user_id WHERE u.id IS NULL OR u.role<>'company'"),
    $problems
);
migration_problem(
    'duplicate_student_user_profiles',
    migration_scalar("SELECT COUNT(*) FROM (SELECT user_id FROM students GROUP BY user_id HAVING COUNT(*)>1) x"),
    $problems
);
migration_problem(
    'duplicate_coordinator_user_profiles',
    migration_scalar("SELECT COUNT(*) FROM (SELECT user_id FROM coordinators GROUP BY user_id HAVING COUNT(*)>1) x"),
    $problems
);
migration_problem(
    'duplicate_company_user_profiles',
    migration_scalar("SELECT COUNT(*) FROM (SELECT user_id FROM companies GROUP BY user_id HAVING COUNT(*)>1) x"),
    $problems
);

$summary = [
    'migration' => '001_core_identity_and_terms',
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'academic_year' => $academicYear,
    'semester' => $semester,
    'legacy' => [
        'students' => migration_scalar("SELECT COUNT(*) FROM students"),
        'students_with_coordinator' => migration_scalar("SELECT COUNT(*) FROM students WHERE coordinator_id IS NOT NULL"),
        'students_with_company' => migration_scalar("SELECT COUNT(*) FROM students WHERE company_id IS NOT NULL"),
        'companies' => migration_scalar("SELECT COUNT(*) FROM companies"),
        'coordinators' => migration_scalar("SELECT COUNT(*) FROM coordinators"),
    ],
    'preflight_problems' => $problems,
];

if ($problems) {
    $summary['result'] = 'BLOCKED';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    fwrite(STDERR, "Migration blocked: fix the preflight problems before applying. No legacy data was changed.\n");
    exit(1);
}

if ($dryRun) {
    $summary['result'] = 'READY';
    $summary['message'] = 'Preflight passed. Re-run with --apply to create/backfill phase 1 tables.';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$ddlPath = __DIR__ . '/../database/migrations/001_core_identity_and_terms.sql';

try {
    migration_execute_ddl($conn, $ddlPath);

    $already = query_one(
        "SELECT version, applied_at FROM schema_migrations WHERE version=? LIMIT 1",
        ['001_core_identity_and_terms'],
        's'
    );
    if ($already) {
        $summary['result'] = 'ALREADY_APPLIED';
        $summary['applied_at'] = $already['applied_at'];
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "INSERT INTO academic_terms
            (academic_year_start, academic_year_end, semester, status)
         VALUES (?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
    );
    $stmt->bind_param('iis', $yearStart, $yearEnd, $semester);
    $stmt->execute();
    $termId = (int)$conn->insert_id;
    if ($termId === 0) {
        $term = query_one(
            "SELECT id FROM academic_terms
             WHERE academic_year_start=? AND academic_year_end=? AND semester=?
             LIMIT 1",
            [$yearStart, $yearEnd, $semester],
            'iis'
        );
        $termId = (int)($term['id'] ?? 0);
    }
    if ($termId <= 0) {
        throw new RuntimeException('Could not resolve the academic term ID.');
    }

    query(
        "INSERT INTO company_users
            (company_id, user_id, company_role, is_primary, status)
         SELECT c.id, c.user_id, 'supervisor', 1,
                CASE WHEN c.status='active' THEN 'active' ELSE 'inactive' END
         FROM companies c
         LEFT JOIN company_users cu ON cu.user_id=c.user_id
         WHERE cu.id IS NULL"
    );

    $stmt = $conn->prepare(
        "INSERT INTO ojt_enrollments
            (student_id, academic_term_id, program_id, year_level, required_hours,
             status, onboarding_completed_at, status_notes, completed_at)
         SELECT s.id, ?, s.program_id, s.year_level,
                CASE WHEN s.required_hours IS NULL OR s.required_hours<=0 THEN 486 ELSE s.required_hours END,
                s.ojt_status, s.onboarding_completed_at, s.status_notes,
                CASE
                  WHEN s.ojt_status='completed' AND s.ojt_end_date IS NOT NULL
                  THEN CAST(CONCAT(s.ojt_end_date, ' 23:59:59') AS DATETIME)
                  ELSE NULL
                END
         FROM students s
         LEFT JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         WHERE oe.id IS NULL"
    );
    $stmt->bind_param('ii', $termId, $termId);
    $stmt->execute();

    $stmt = $conn->prepare(
        "INSERT INTO enrollment_coordinators
            (ojt_enrollment_id, coordinator_id, assigned_by)
         SELECT oe.id, s.coordinator_id, NULL
         FROM students s
         JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         LEFT JOIN enrollment_coordinators ec
           ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
         WHERE s.coordinator_id IS NOT NULL
           AND ec.id IS NULL"
    );
    $stmt->bind_param('i', $termId);
    $stmt->execute();

    $stmt = $conn->prepare(
        "INSERT INTO placements
            (ojt_enrollment_id, company_id, status, starts_on, ends_on, assigned_by, notes)
         SELECT oe.id, s.company_id,
                CASE s.ojt_status
                  WHEN 'ongoing' THEN 'active'
                  WHEN 'completed' THEN 'completed'
                  WHEN 'on_hold' THEN 'on_hold'
                  WHEN 'withdrawn' THEN 'terminated'
                  ELSE 'planned'
                END,
                s.ojt_start_date, s.ojt_end_date, NULL,
                'Migrated from legacy students.company_id by migration 001.'
         FROM students s
         JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         LEFT JOIN placements p ON p.ojt_enrollment_id=oe.id
         WHERE s.company_id IS NOT NULL
           AND p.id IS NULL"
    );
    $stmt->bind_param('i', $termId);
    $stmt->execute();

    $stmt = $conn->prepare(
        "INSERT INTO placement_supervisors
            (placement_id, company_user_id)
         SELECT p.id, cu.id
         FROM placements p
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN company_users cu
           ON cu.company_id=p.company_id AND cu.is_primary=1
         LEFT JOIN placement_supervisors ps
           ON ps.placement_id=p.id AND ps.ended_at IS NULL
         WHERE oe.academic_term_id=?
           AND ps.id IS NULL"
    );
    $stmt->bind_param('i', $termId);
    $stmt->execute();

    $verification = [];
    $verification['missing_enrollments'] = migration_scalar(
        "SELECT COUNT(*)
         FROM students s
         LEFT JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         WHERE oe.id IS NULL
            OR oe.program_id<>s.program_id
            OR oe.status<>s.ojt_status",
        [$termId],
        'i'
    );
    $verification['missing_company_users'] = migration_scalar(
        "SELECT COUNT(*)
         FROM companies c
         LEFT JOIN company_users cu
           ON cu.company_id=c.id AND cu.user_id=c.user_id
         WHERE cu.id IS NULL"
    );
    $verification['missing_coordinator_assignments'] = migration_scalar(
        "SELECT COUNT(*)
         FROM students s
         JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         LEFT JOIN enrollment_coordinators ec
           ON ec.ojt_enrollment_id=oe.id
          AND ec.coordinator_id=s.coordinator_id
          AND ec.ended_at IS NULL
         WHERE s.coordinator_id IS NOT NULL
           AND ec.id IS NULL",
        [$termId],
        'i'
    );
    $verification['missing_placements'] = migration_scalar(
        "SELECT COUNT(*)
         FROM students s
         JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         LEFT JOIN placements p
           ON p.ojt_enrollment_id=oe.id AND p.company_id=s.company_id
         WHERE s.company_id IS NOT NULL
           AND p.id IS NULL",
        [$termId],
        'i'
    );
    $verification['missing_placement_supervisors'] = migration_scalar(
        "SELECT COUNT(*)
         FROM students s
         JOIN ojt_enrollments oe
           ON oe.student_id=s.id AND oe.academic_term_id=?
         JOIN placements p
           ON p.ojt_enrollment_id=oe.id AND p.company_id=s.company_id
         JOIN companies c ON c.id=p.company_id
         JOIN company_users cu
           ON cu.company_id=c.id AND cu.user_id=c.user_id
         LEFT JOIN placement_supervisors ps
           ON ps.placement_id=p.id
          AND ps.company_user_id=cu.id
          AND ps.ended_at IS NULL
         WHERE s.company_id IS NOT NULL
           AND ps.id IS NULL",
        [$termId],
        'i'
    );

    foreach ($verification as $label => $count) {
        if ($count !== 0) {
            throw new RuntimeException("Verification failed: $label=$count");
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO schema_migrations (version, description)
         VALUES (?, ?)"
    );
    $version = '001_core_identity_and_terms';
    $description = 'Normalize academic term, OJT enrollment, company user, coordinator assignment and placement foundations';
    $stmt->bind_param('ss', $version, $description);
    $stmt->execute();

    $conn->commit();

    $summary['result'] = 'PASS';
    $summary['academic_term_id'] = $termId;
    $summary['normalized'] = [
        'ojt_enrollments_for_term' => migration_scalar(
            "SELECT COUNT(*) FROM ojt_enrollments WHERE academic_term_id=?",
            [$termId],
            'i'
        ),
        'company_users' => migration_scalar("SELECT COUNT(*) FROM company_users"),
        'coordinator_assignments_for_term' => migration_scalar(
            "SELECT COUNT(*)
             FROM enrollment_coordinators ec
             JOIN ojt_enrollments oe ON oe.id=ec.ojt_enrollment_id
             WHERE oe.academic_term_id=? AND ec.ended_at IS NULL",
            [$termId],
            'i'
        ),
        'placements_for_term' => migration_scalar(
            "SELECT COUNT(*)
             FROM placements p
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
             WHERE oe.academic_term_id=?",
            [$termId],
            'i'
        ),
        'placement_supervisors_for_term' => migration_scalar(
            "SELECT COUNT(*)
             FROM placement_supervisors ps
             JOIN placements p ON p.id=ps.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
             WHERE oe.academic_term_id=? AND ps.ended_at IS NULL",
            [$termId],
            'i'
        ),
    ];
    $summary['verification'] = $verification;
    $summary['legacy_tables_preserved'] = true;

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    try {
        if ($conn->errno === 0) {
            $conn->rollback();
        }
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "Migration 001 failed: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
