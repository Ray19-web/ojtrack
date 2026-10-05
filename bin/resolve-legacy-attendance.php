<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

$options=getopt('', ['attendance-id:', 'academic-year:', 'semester:', 'company-id::', 'note::', 'apply', 'dry-run']);
$attendanceId=(int)($options['attendance-id'] ?? 0);
$academicYear=trim((string)($options['academic-year'] ?? ''));
$semesterInput=strtolower(trim((string)($options['semester'] ?? '')));
$companyId=isset($options['company-id']) ? (int)$options['company-id'] : 0;
$note=trim((string)($options['note'] ?? 'Explicit historical placement selected during attendance normalization.'));
$apply=array_key_exists('apply',$options);
$dryRun=array_key_exists('dry-run',$options) || !$apply;

if ($attendanceId<=0) { fwrite(STDERR,"Use --attendance-id=<id>.\n"); exit(2); }
if (!preg_match('/^(\d{4})-(\d{4})$/',$academicYear,$m)) { fwrite(STDERR,"Use --academic-year=YYYY-YYYY.\n"); exit(2); }
$yearStart=(int)$m[1]; $yearEnd=(int)$m[2];
if ($yearEnd!==$yearStart+1) { fwrite(STDERR,"Academic year end must be one year after start.\n"); exit(2); }
$aliases=['1'=>'1st','1st'=>'1st','first'=>'1st','2'=>'2nd','2nd'=>'2nd','second'=>'2nd','summer'=>'summer'];
if (!isset($aliases[$semesterInput])) { fwrite(STDERR,"Use --semester=1st, 2nd, or summer.\n"); exit(2); }
$semester=$aliases[$semesterInput];

require __DIR__ . '/../config/db.php';

function lar_ddl(mysqli $conn): void {
    $sql=file_get_contents(__DIR__ . '/../database/migrations/002a_legacy_attendance_resolution.sql');
    if ($sql===false || trim($sql)==='') throw new RuntimeException('Resolution DDL missing.');
    if (!$conn->multi_query($sql)) throw new RuntimeException('Resolution DDL failed: '.$conn->error);
    do {
        if ($r=$conn->store_result()) $r->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('Resolution DDL failed: '.$conn->error);
}

$att=query_one(
    "SELECT a.*, s.user_id, s.student_id_no, s.ojt_status, u.name student_name
     FROM attendance a
     JOIN students s ON s.id=a.student_id
     JOIN users u ON u.id=s.user_id
     WHERE a.id=?",
    [$attendanceId],'i'
);
if (!$att) { fwrite(STDERR,"Attendance row not found.\n"); exit(1); }

$term=query_one(
    "SELECT id FROM academic_terms
     WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",
    [$yearStart,$yearEnd,$semester],'iis'
);
if (!$term) { fwrite(STDERR,"Academic term not found. Apply migration 001 first.\n"); exit(1); }
$termId=(int)$term['id'];

$enrollment=query_one(
    "SELECT id FROM ojt_enrollments WHERE student_id=? AND academic_term_id=? LIMIT 1",
    [(int)$att['student_id'],$termId],'ii'
);
if (!$enrollment) { fwrite(STDERR,"No normalized enrollment exists for this student/term.\n"); exit(1); }
$enrollmentId=(int)$enrollment['id'];

$companies=query("SELECT id,company_name,status FROM companies ORDER BY id") ?: [];

if ($companyId<=0) {
    echo json_encode([
        'result'=>'NEEDS_COMPANY',
        'attendance'=>[
            'id'=>(int)$att['id'],
            'student_id'=>(int)$att['student_id'],
            'student_id_no'=>$att['student_id_no'],
            'student_name'=>$att['student_name'],
            'date'=>$att['date'],
            'hours_rendered'=>(float)$att['hours_rendered']
        ],
        'company_candidates'=>array_map(fn($c)=>[
            'id'=>(int)$c['id'],
            'company_name'=>$c['company_name'],
            'status'=>$c['status']
        ],$companies),
        'message'=>'Choose the real historical company; no company is inferred automatically.'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$company=query_one("SELECT id,company_name,status FROM companies WHERE id=?",[$companyId],'i');
if (!$company) { fwrite(STDERR,"Selected company does not exist.\n"); exit(1); }

$placement=query_one(
    "SELECT id,status,starts_on,ends_on FROM placements
     WHERE ojt_enrollment_id=? AND company_id=?
     ORDER BY id DESC LIMIT 1",
    [$enrollmentId,$companyId],'ii'
);

$summary=[
    'mode'=>$dryRun?'dry-run':'apply',
    'attendance_id'=>$attendanceId,
    'student_id'=>(int)$att['student_id'],
    'student_name'=>$att['student_name'],
    'date'=>$att['date'],
    'company_id'=>$companyId,
    'company_name'=>$company['company_name'],
    'existing_placement_id'=>$placement ? (int)$placement['id'] : null,
    'will_create_historical_placement'=>!$placement,
];

if ($dryRun) {
    $summary['result']='READY';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

try {
    lar_ddl($conn);
    $conn->begin_transaction();

    if (!$placement) {
        $status=match($att['ojt_status']) {
            'completed'=>'completed',
            'on_hold'=>'on_hold',
            'withdrawn'=>'terminated',
            'ongoing'=>'active',
            default=>'planned'
        };
        $start=$att['date'];
        $placementNote="Historical placement created to resolve legacy attendance #{$attendanceId}. ".$note;
        $stmt=$conn->prepare(
            "INSERT INTO placements
             (ojt_enrollment_id,company_id,status,starts_on,ends_on,assigned_by,notes)
             VALUES (?,?,?,?,NULL,NULL,?)"
        );
        $stmt->bind_param('iisss',$enrollmentId,$companyId,$status,$start,$placementNote);
        $stmt->execute();
        $placementId=(int)$conn->insert_id;

        $companyUser=query_one(
            "SELECT id FROM company_users
             WHERE company_id=? AND status='active'
             ORDER BY is_primary DESC,id ASC LIMIT 1",
            [$companyId],'i'
        );
        if ($companyUser) {
            insert(
                "INSERT INTO placement_supervisors(placement_id,company_user_id)
                 VALUES(?,?)",
                [$placementId,(int)$companyUser['id']],'ii'
            );
        }
    } else {
        $placementId=(int)$placement['id'];
    }

    $existing=query_one("SELECT attendance_id,placement_id FROM legacy_attendance_resolutions WHERE attendance_id=?",[$attendanceId],'i');
    if ($existing && (int)$existing['placement_id']!==$placementId) {
        throw new RuntimeException('Attendance already has a different explicit placement resolution.');
    }
    if (!$existing) {
        insert(
            "INSERT INTO legacy_attendance_resolutions(attendance_id,placement_id,resolution_note)
             VALUES(?,?,?)",
            [$attendanceId,$placementId,$note],'iis'
        );
    }

    $conn->commit();
    $summary['placement_id']=$placementId;
    $summary['result']='PASS';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR,"Resolution failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
