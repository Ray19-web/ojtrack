<?php
/**
 * OJTrack migration 008: application cutover checkpoint.
 *
 * This migration does not copy or delete business data. It verifies that the
 * normalized migrations 001-007 are present and that all explicit legacy
 * mapping tables still have complete parity before recording the application
 * cutover checkpoint.
 *
 * Usage:
 * php bin/migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase8-cutover.php --academic-year=2026-2027 --semester=1st --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$options=getopt('', ['academic-year:','semester:','apply','dry-run']);
$academicYear=trim((string)($options['academic-year'] ?? ''));
$semesterInput=strtolower(trim((string)($options['semester'] ?? '')));
$apply=array_key_exists('apply',$options);
$dryRun=array_key_exists('dry-run',$options) || !$apply;

if ($apply && array_key_exists('dry-run',$options)) {
    fwrite(STDERR,"Choose either --apply or --dry-run, not both.\n");
    exit(2);
}
if (!preg_match('/^(\d{4})-(\d{4})$/',$academicYear,$m)) {
    fwrite(STDERR,"Use --academic-year=YYYY-YYYY.\n");
    exit(2);
}
$yearStart=(int)$m[1];
$yearEnd=(int)$m[2];
if ($yearEnd!==$yearStart+1) {
    fwrite(STDERR,"Academic year end must be exactly one year after the start.\n");
    exit(2);
}
$aliases=['1'=>'1st','1st'=>'1st','first'=>'1st','2'=>'2nd','2nd'=>'2nd','second'=>'2nd','summer'=>'summer'];
if (!isset($aliases[$semesterInput])) {
    fwrite(STDERR,"Use --semester=1st, --semester=2nd, or --semester=summer.\n");
    exit(2);
}
$semester=$aliases[$semesterInput];

require __DIR__ . '/../config/db.php';

function p8_scalar(string $sql,array $params=[],string $types=''): int {
    $row=query_one($sql,$params,$types);
    if (!$row) return 0;
    return (int)reset($row);
}
function p8_table_exists(string $table): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=? LIMIT 1",
        [$table],
        's'
    );
}

$required=[
    '001_core_identity_and_terms',
    '002_attendance',
    '003_journals_and_attachments',
    '004_requirements',
    '005_reports',
    '006_evaluations',
    '007_certificates_and_announcements',
];
foreach ($required as $version) {
    if (!query_one("SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1",[$version],'s')) {
        echo json_encode([
            'migration'=>'008_application_cutover',
            'mode'=>$dryRun?'dry-run':'apply',
            'result'=>'BLOCKED',
            'preflight_problems'=>['missing_required_migration'=>$version],
        ],JSON_PRETTY_PRINT).PHP_EOL;
        exit(1);
    }
}

$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='008_application_cutover' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'008_application_cutover',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'ALREADY_APPLIED',
        'applied_at'=>$already['applied_at'],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

$term=query_one(
    "SELECT id FROM academic_terms
     WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",
    [$yearStart,$yearEnd,$semester],
    'iis'
);
if (!$term) {
    echo json_encode([
        'migration'=>'008_application_cutover',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$termId=(int)$term['id'];

$requiredTables=[
    'ojt_enrollments','placements','attendance_days','attendance_sessions',
    'journal_days','journal_revisions','attachments',
    'requirement_definitions','requirement_definition_versions','requirement_assignments','requirement_submissions',
    'report_templates','report_template_versions','report_assignments','report_submissions',
    'evaluation_definitions','evaluation_definition_versions','evaluation_requests','evaluation_submissions','evaluation_answers',
    'certificate_templates','certificates',
    'announcement_posts','announcement_recipients',
    'legacy_requirement_migration_map','legacy_report_migration_map',
    'legacy_evaluation_form_migration_map','legacy_eval_submission_migration_map',
    'legacy_fixed_evaluation_migration_map','legacy_certificate_template_migration_map',
    'legacy_announcement_migration_map',
];
$missingTables=[];
foreach ($requiredTables as $table) if (!p8_table_exists($table)) $missingTables[]=$table;

$verification=[
    'missing_normalized_tables'=>count($missingTables),
    'requirement_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM ojt_requirements") -
        p8_scalar("SELECT COUNT(*) FROM legacy_requirement_migration_map")
    ),
    'report_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM reports") -
        p8_scalar("SELECT COUNT(*) FROM legacy_report_migration_map")
    ),
    'dynamic_form_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM evaluation_forms") -
        p8_scalar("SELECT COUNT(*) FROM legacy_evaluation_form_migration_map")
    ),
    'dynamic_evaluation_request_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM eval_submissions") -
        p8_scalar("SELECT COUNT(*) FROM legacy_eval_submission_migration_map")
    ),
    'fixed_evaluation_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM evaluations") -
        p8_scalar("SELECT COUNT(*) FROM legacy_fixed_evaluation_migration_map")
    ),
    'certificate_template_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM companies") -
        p8_scalar("SELECT COUNT(*) FROM legacy_certificate_template_migration_map")
    ),
    'announcement_map_mismatch'=>abs(
        p8_scalar("SELECT COUNT(*) FROM announcements") -
        p8_scalar("SELECT COUNT(*) FROM legacy_announcement_migration_map")
    ),
    'orphan_requirement_submissions'=>p8_scalar(
        "SELECT COUNT(*) FROM requirement_submissions rs
         LEFT JOIN requirement_assignments ra ON ra.id=rs.requirement_assignment_id
         WHERE ra.id IS NULL"
    ),
    'orphan_report_submissions'=>p8_scalar(
        "SELECT COUNT(*) FROM report_submissions rs
         LEFT JOIN report_assignments ra ON ra.id=rs.report_assignment_id
         WHERE ra.id IS NULL"
    ),
    'orphan_evaluation_submissions'=>p8_scalar(
        "SELECT COUNT(*) FROM evaluation_submissions es
         LEFT JOIN evaluation_requests er ON er.id=es.evaluation_request_id
         WHERE er.id IS NULL"
    ),
    'orphan_evaluation_answers'=>p8_scalar(
        "SELECT COUNT(*) FROM evaluation_answers ea
         LEFT JOIN evaluation_submissions es ON es.id=ea.evaluation_submission_id
         LEFT JOIN evaluation_version_criteria ec ON ec.id=ea.evaluation_criterion_id
         WHERE es.id IS NULL OR ec.id IS NULL"
    ),
    'orphan_announcement_recipients'=>p8_scalar(
        "SELECT COUNT(*) FROM announcement_recipients ar
         LEFT JOIN announcement_posts ap ON ap.id=ar.announcement_post_id
         LEFT JOIN users u ON u.id=ar.user_id
         WHERE ap.id IS NULL OR u.id IS NULL"
    ),
];

$problems=[];
foreach ($verification as $key=>$value) if ($value!==0) $problems[$key]=$value;

$summary=[
    'migration'=>'008_application_cutover',
    'mode'=>$dryRun?'dry-run':'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'normalized'=>[
        'enrollments'=>p8_scalar("SELECT COUNT(*) FROM ojt_enrollments WHERE academic_term_id=?",[$termId],'i'),
        'placements'=>p8_scalar(
            "SELECT COUNT(*) FROM placements p JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id WHERE oe.academic_term_id=?",
            [$termId],'i'
        ),
        'attendance_days'=>p8_scalar(
            "SELECT COUNT(*) FROM attendance_days ad JOIN placements p ON p.id=ad.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id WHERE oe.academic_term_id=?",
            [$termId],'i'
        ),
        'journal_days'=>p8_scalar(
            "SELECT COUNT(*) FROM journal_days jd JOIN placements p ON p.id=jd.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id WHERE oe.academic_term_id=?",
            [$termId],'i'
        ),
        'requirement_assignments'=>p8_scalar(
            "SELECT COUNT(*) FROM requirement_assignments ra JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id WHERE oe.academic_term_id=?",
            [$termId],'i'
        ),
        'report_assignments'=>p8_scalar(
            "SELECT COUNT(*) FROM report_assignments ra JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id WHERE oe.academic_term_id=?",
            [$termId],'i'
        ),
        'evaluation_requests'=>p8_scalar(
            "SELECT COUNT(*) FROM evaluation_requests er JOIN placements p ON p.id=er.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id WHERE oe.academic_term_id=?",
            [$termId],'i'
        ),
        'certificate_templates'=>p8_scalar("SELECT COUNT(*) FROM certificate_templates"),
        'issued_certificates'=>p8_scalar("SELECT COUNT(*) FROM certificates"),
        'announcement_posts'=>p8_scalar("SELECT COUNT(*) FROM announcement_posts"),
    ],
    'verification'=>$verification,
    'preflight_problems'=>$problems,
];

if ($missingTables) $summary['missing_tables']=$missingTables;

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='Application cutover checkpoint not recorded. Resolve parity/integrity problems first.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Normalized data parity/integrity checks passed. Re-run with --apply to record the application cutover checkpoint.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$version='008_application_cutover';
$description='Application reads/writes cut over to normalized OJT training, evaluation, certificate and announcement models';
$stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
$stmt->bind_param('ss',$version,$description);
$stmt->execute();

$summary['result']='PASS';
$summary['legacy_tables_preserved']=true;
$summary['legacy_retirement_performed']=false;
$summary['message']='Application cutover checkpoint recorded. Legacy tables remain available for Phase 9 retirement validation.';
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
