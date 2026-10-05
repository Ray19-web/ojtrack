<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p2check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p2count(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    return (int)($r['n'] ?? 0);
}

p2check(p2count("SELECT COUNT(*) n FROM attendance")===4,'legacy attendance preserved');
p2check(p2count("SELECT COUNT(*) n FROM schema_migrations WHERE version='002_attendance'")===1,'phase 2 recorded');
p2check(p2count("SELECT COUNT(*) n FROM attendance_days")===4,'one normalized day per legacy row');
p2check(p2count("SELECT COUNT(*) n FROM attendance_sessions")===4,'sessions reconstructed');
p2check(p2count("SELECT COUNT(*) n FROM attendance_corrections")===0,'no fake corrections created');

$term=query_one("SELECT id FROM academic_terms WHERE academic_year_start=2026 AND academic_year_end=2027 AND semester='1st'");
$termId=(int)$term['id'];
$total=query_one(
    "SELECT SUM(ad.credited_minutes) mins
     FROM attendance_days ad
     JOIN placements p ON p.id=ad.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.academic_term_id=?",
    [$termId],'i'
);
p2check((int)$total['mins']===1020,'credited minutes exactly preserved');

$generic=query_one(
    "SELECT ad.credited_minutes, ats.time_in, ats.time_out, ats.source
     FROM attendance_days ad
     JOIN placements p ON p.id=ad.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     JOIN attendance_sessions ats ON ats.attendance_day_id=ad.id
     WHERE oe.student_id=1 AND ad.attendance_date='2026-01-05'"
);
p2check((int)$generic['credited_minutes']===540,'generic day credit preserved');
p2check(str_ends_with($generic['time_in'],'08:00:00') && str_ends_with($generic['time_out'],'17:00:00'),'generic punch times preserved');
p2check($generic['source']==='migration','migration source recorded');

$split=p2count(
    "SELECT COUNT(*) n
     FROM attendance_sessions ats
     JOIN attendance_days ad ON ad.id=ats.attendance_day_id
     JOIN placements p ON p.id=ad.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.student_id=2 AND ad.attendance_date='2026-01-06'"
);
p2check($split===2,'morning and afternoon sessions separated');

$partial=query_one(
    "SELECT ats.time_in, ats.time_out, ats.credited_minutes
     FROM attendance_sessions ats
     JOIN attendance_days ad ON ad.id=ats.attendance_day_id
     JOIN placements p ON p.id=ad.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.student_id=3 AND ad.attendance_date='2026-01-07'"
);
p2check($partial && str_ends_with($partial['time_in'],'08:15:00') && $partial['time_out']===null,'partial punch preserved without invented time');
p2check((int)$partial['credited_minutes']===0,'partial punch receives no invented credit');

$excused=query_one(
    "SELECT ad.status,ad.credited_minutes
     FROM attendance_days ad
     JOIN placements p ON p.id=ad.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.student_id=1 AND ad.attendance_date='2026-01-08'"
);
p2check($excused['status']==='excused' && (int)$excused['credited_minutes']===0,'non-present status preserved');

echo json_encode(['phase2_attendance_checks_passed'=>$checks,'result'=>'PASS']) . PHP_EOL;
