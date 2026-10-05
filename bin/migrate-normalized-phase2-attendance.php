<?php
/**
 * OJTrack normalized migration 002: attendance.
 *
 * Additive and data-preserving:
 * - requires successful phase 1
 * - migrates legacy attendance into placement-scoped attendance_days/sessions
 * - creates correction-audit storage
 * - never deletes or updates the legacy attendance table
 *
 * Usage:
 * php bin/migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase2-attendance.php --academic-year=2026-2027 --semester=1st --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$options = getopt('', ['academic-year:', 'semester:', 'apply', 'dry-run']);
$academicYear = trim((string)($options['academic-year'] ?? ''));
$semesterInput = strtolower(trim((string)($options['semester'] ?? '')));
$apply = array_key_exists('apply', $options);
$dryRun = array_key_exists('dry-run', $options) || !$apply;

if ($apply && array_key_exists('dry-run', $options)) {
    fwrite(STDERR, "Choose either --apply or --dry-run, not both.\n");
    exit(2);
}
if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m)) {
    fwrite(STDERR, "Use --academic-year=YYYY-YYYY.\n");
    exit(2);
}
$yearStart = (int)$m[1];
$yearEnd = (int)$m[2];
if ($yearEnd !== $yearStart + 1) {
    fwrite(STDERR, "Academic year end must be exactly one year after the start.\n");
    exit(2);
}
$semesterAliases = ['1'=>'1st','1st'=>'1st','first'=>'1st','2'=>'2nd','2nd'=>'2nd','second'=>'2nd','summer'=>'summer'];
if (!isset($semesterAliases[$semesterInput])) {
    fwrite(STDERR, "Use --semester=1st, --semester=2nd, or --semester=summer.\n");
    exit(2);
}
$semester = $semesterAliases[$semesterInput];

require __DIR__ . '/../config/db.php';

function phase2_scalar(string $sql, array $params = [], string $types = ''): int
{
    $row = query_one($sql, $params, $types);
    if (!$row) return 0;
    return (int)reset($row);
}

function phase2_ddl(mysqli $conn, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') throw new RuntimeException("Missing migration DDL: $path");
    if (!$conn->multi_query($sql)) throw new RuntimeException('DDL failed: ' . $conn->error);
    do {
        if ($result = $conn->store_result()) $result->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('DDL failed: ' . $conn->error);
}

function phase2_datetime(string $date, $time): ?string
{
    $time = trim((string)$time);
    if ($time === '' || $time === '00:00:00') return null;
    return $date . ' ' . $time;
}

function phase2_elapsed_minutes(?string $in, ?string $out): int
{
    if (!$in || !$out) return 0;
    $a = strtotime($in);
    $b = strtotime($out);
    if ($a === false || $b === false || $b < $a) return 0;
    return (int)round(($b - $a) / 60);
}

function phase2_snapshot(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}

$phase1 = query_one("SELECT applied_at FROM schema_migrations WHERE version='001_core_identity_and_terms' LIMIT 1");
if (!$phase1) {
    echo json_encode([
        'migration'=>'002_attendance',
        'mode'=>$dryRun ? 'dry-run' : 'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['phase1_not_applied'=>1],
        'message'=>'Apply migration 001 first.'
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$already = query_one("SELECT applied_at FROM schema_migrations WHERE version='002_attendance' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'002_attendance',
        'mode'=>$dryRun ? 'dry-run' : 'apply',
        'result'=>'ALREADY_APPLIED',
        'applied_at'=>$already['applied_at']
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$term = query_one(
    "SELECT id FROM academic_terms
     WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",
    [$yearStart, $yearEnd, $semester],
    'iis'
);
if (!$term) {
    echo json_encode([
        'migration'=>'002_attendance',
        'mode'=>$dryRun ? 'dry-run' : 'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
        'message'=>'The requested normalized academic term does not exist.'
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
$termId = (int)$term['id'];

$legacyRows = query(
    "SELECT id, student_id, date, time_in, time_out,
            morning_in, morning_out, afternoon_in, afternoon_out,
            hours_rendered, remarks, status
     FROM attendance ORDER BY id"
);
$legacySnapshot = phase2_snapshot($legacyRows);

$unmapped = query(
    "SELECT a.id attendance_id, a.student_id, a.date, u.name student_name, s.student_id_no,
            COUNT(p.id) placement_count
     FROM attendance a
     JOIN students s ON s.id=a.student_id
     JOIN users u ON u.id=s.user_id
     LEFT JOIN ojt_enrollments oe
       ON oe.student_id=s.id AND oe.academic_term_id=?
     LEFT JOIN placements p ON p.ojt_enrollment_id=oe.id
     GROUP BY a.id, a.student_id, a.date, u.name, s.student_id_no
     HAVING COUNT(p.id) <> 1
     ORDER BY a.id",
    [$termId],
    'i'
);

$invalidStatus = phase2_scalar(
    "SELECT COUNT(*) FROM attendance WHERE status NOT IN ('present','absent','excused') OR status IS NULL"
);
$invalidHours = phase2_scalar(
    "SELECT COUNT(*) FROM attendance WHERE hours_rendered < 0 OR hours_rendered > 24"
);
$duplicates = phase2_scalar(
    "SELECT COUNT(*) FROM (
       SELECT student_id, date FROM attendance GROUP BY student_id, date HAVING COUNT(*)>1
     ) x"
);

$problems = [];
if ($unmapped) $problems['attendance_without_exactly_one_placement'] = count($unmapped);
if ($invalidStatus) $problems['invalid_attendance_status'] = $invalidStatus;
if ($invalidHours) $problems['invalid_hours_rendered'] = $invalidHours;
if ($duplicates) $problems['duplicate_student_date_rows'] = $duplicates;

$expectedSessions = 0;
foreach ($legacyRows as $row) {
    $hasSplit = !empty($row['morning_in']) || !empty($row['morning_out']) || !empty($row['afternoon_in']) || !empty($row['afternoon_out']);
    if ($hasSplit) {
        if (!empty($row['morning_in']) || !empty($row['morning_out'])) $expectedSessions++;
        if (!empty($row['afternoon_in']) || !empty($row['afternoon_out'])) $expectedSessions++;
    } elseif (!empty($row['time_in']) || !empty($row['time_out'])) {
        $expectedSessions++;
    }
}

$summary = [
    'migration'=>'002_attendance',
    'mode'=>$dryRun ? 'dry-run' : 'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy'=>[
        'attendance_rows'=>count($legacyRows),
        'expected_sessions'=>$expectedSessions,
        'credited_minutes'=>(int)round(array_sum(array_map(fn($r)=>(float)$r['hours_rendered'], $legacyRows))*60),
    ],
    'preflight_problems'=>$problems,
];

if ($unmapped) {
    $summary['unmapped_attendance'] = array_map(fn($r)=>[
        'attendance_id'=>(int)$r['attendance_id'],
        'student_id'=>(int)$r['student_id'],
        'student_id_no'=>$r['student_id_no'],
        'student_name'=>$r['student_name'],
        'date'=>$r['date'],
        'placement_count'=>(int)$r['placement_count'],
    ], $unmapped);
}

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='No data was changed. Resolve the listed legacy attendance/placement issues first.';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Preflight passed. Re-run with --apply to migrate attendance.';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

try {
    phase2_ddl($conn, __DIR__ . '/../database/migrations/002_attendance.sql');

    $existing = phase2_scalar(
        "SELECT COUNT(*)
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.academic_term_id=?",
        [$termId],
        'i'
    );
    if ($existing > 0) {
        throw new RuntimeException(
            "Normalized attendance already contains $existing row(s) for this term but migration 002 is not recorded. Review before retrying."
        );
    }

    $mapped = query(
        "SELECT a.*, p.id AS placement_id
         FROM attendance a
         JOIN ojt_enrollments oe
           ON oe.student_id=a.student_id AND oe.academic_term_id=?
         JOIN placements p ON p.ojt_enrollment_id=oe.id
         ORDER BY a.id",
        [$termId],
        'i'
    );

    $conn->begin_transaction();

    $insertDay = $conn->prepare(
        "INSERT INTO attendance_days
           (placement_id, attendance_date, status, credited_minutes, remarks)
         VALUES (?, ?, ?, ?, ?)"
    );
    $insertSession = $conn->prepare(
        "INSERT INTO attendance_sessions
           (attendance_day_id, time_in, time_out, credited_minutes, source, recorded_by)
         VALUES (?, ?, ?, ?, 'migration', NULL)"
    );

    $daysCreated = 0;
    $sessionsCreated = 0;

    foreach ($mapped as $row) {
        $placementId = (int)$row['placement_id'];
        $date = $row['date'];
        $status = $row['status'];
        $dayMinutes = max(0, (int)round(((float)$row['hours_rendered']) * 60));
        $remarks = $row['remarks'];

        $insertDay->bind_param('issis', $placementId, $date, $status, $dayMinutes, $remarks);
        $insertDay->execute();
        $dayId = (int)$conn->insert_id;
        $daysCreated++;

        $sessions = [];
        $hasSplit = !empty($row['morning_in']) || !empty($row['morning_out']) || !empty($row['afternoon_in']) || !empty($row['afternoon_out']);
        if ($hasSplit) {
            if (!empty($row['morning_in']) || !empty($row['morning_out'])) {
                $sessions[] = [
                    phase2_datetime($date, $row['morning_in']),
                    phase2_datetime($date, $row['morning_out'])
                ];
            }
            if (!empty($row['afternoon_in']) || !empty($row['afternoon_out'])) {
                $sessions[] = [
                    phase2_datetime($date, $row['afternoon_in']),
                    phase2_datetime($date, $row['afternoon_out'])
                ];
            }
        } elseif (!empty($row['time_in']) || !empty($row['time_out'])) {
            $sessions[] = [
                phase2_datetime($date, $row['time_in']),
                phase2_datetime($date, $row['time_out'])
            ];
        }

        foreach ($sessions as [$timeIn, $timeOut]) {
            $sessionMinutes = phase2_elapsed_minutes($timeIn, $timeOut);
            $insertSession->bind_param('issi', $dayId, $timeIn, $timeOut, $sessionMinutes);
            $insertSession->execute();
            $sessionsCreated++;
        }
    }

    $verification = [
        'missing_days'=>phase2_scalar(
            "SELECT COUNT(*)
             FROM attendance a
             JOIN ojt_enrollments oe
               ON oe.student_id=a.student_id AND oe.academic_term_id=?
             JOIN placements p ON p.ojt_enrollment_id=oe.id
             LEFT JOIN attendance_days ad
               ON ad.placement_id=p.id AND ad.attendance_date=a.date
             WHERE ad.id IS NULL",
            [$termId],
            'i'
        ),
        'status_mismatches'=>phase2_scalar(
            "SELECT COUNT(*)
             FROM attendance a
             JOIN ojt_enrollments oe
               ON oe.student_id=a.student_id AND oe.academic_term_id=?
             JOIN placements p ON p.ojt_enrollment_id=oe.id
             JOIN attendance_days ad
               ON ad.placement_id=p.id AND ad.attendance_date=a.date
             WHERE ad.status<>a.status",
            [$termId],
            'i'
        ),
        'credited_minute_mismatches'=>phase2_scalar(
            "SELECT COUNT(*)
             FROM attendance a
             JOIN ojt_enrollments oe
               ON oe.student_id=a.student_id AND oe.academic_term_id=?
             JOIN placements p ON p.ojt_enrollment_id=oe.id
             JOIN attendance_days ad
               ON ad.placement_id=p.id AND ad.attendance_date=a.date
             WHERE ad.credited_minutes<>ROUND(a.hours_rendered*60)",
            [$termId],
            'i'
        ),
    ];

    if ($daysCreated !== count($legacyRows)) {
        throw new RuntimeException("Verification failed: migrated days=$daysCreated legacy rows=" . count($legacyRows));
    }
    if ($sessionsCreated !== $expectedSessions) {
        throw new RuntimeException("Verification failed: sessions=$sessionsCreated expected=$expectedSessions");
    }
    foreach ($verification as $label=>$count) {
        if ($count !== 0) throw new RuntimeException("Verification failed: $label=$count");
    }

    $afterLegacy = query(
        "SELECT id, student_id, date, time_in, time_out,
                morning_in, morning_out, afternoon_in, afternoon_out,
                hours_rendered, remarks, status
         FROM attendance ORDER BY id"
    );
    if (phase2_snapshot($afterLegacy) !== $legacySnapshot) {
        throw new RuntimeException('Legacy attendance changed during migration.');
    }

    $version='002_attendance';
    $description='Normalize legacy attendance into placement-scoped days, sessions and correction audit storage';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    $conn->commit();

    $summary['result']='PASS';
    $summary['normalized']=[
        'attendance_days'=>$daysCreated,
        'attendance_sessions'=>$sessionsCreated,
        'credited_minutes'=>phase2_scalar(
            "SELECT COALESCE(SUM(ad.credited_minutes),0)
             FROM attendance_days ad
             JOIN placements p ON p.id=ad.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
             WHERE oe.academic_term_id=?",
            [$termId],
            'i'
        ),
        'attendance_corrections'=>phase2_scalar("SELECT COUNT(*) FROM attendance_corrections"),
    ];
    $summary['verification']=$verification;
    $summary['legacy_attendance_preserved']=true;
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR, "Migration 002 failed: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
