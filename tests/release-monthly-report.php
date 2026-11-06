<?php
// Uses only an explicitly named disposable release-verification database.
if (PHP_SAPI !== 'cli') exit;
if (!preg_match('/^ojtrack_release_verify_[a-z0-9_]+$/D', getenv('OJTRACK_DB_NAME') ?: '')) exit("Set OJTRACK_DB_NAME to a disposable ojtrack_release_verify_* database.\n");
require __DIR__.'/../config/db.php';
$checks=0;
function release_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
$studentUser=insert("INSERT INTO users(name,email,password,role,status) VALUES('Release Student','release-student@example.invalid','synthetic','student','active')");
$coordUser=insert("INSERT INTO users(name,email,password,role,status) VALUES('Release Coordinator','release-coordinator@example.invalid','synthetic','coordinator','active')");
$companyUser=insert("INSERT INTO users(name,email,password,role,status) VALUES('Release Company','release-company@example.invalid','synthetic','company','active')");
$program=insert("INSERT INTO programs(code,name,status) VALUES('RELTEST','Release Test Program','active')");
$term=insert("INSERT INTO academic_terms(academic_year_start,academic_year_end,semester,status) VALUES(2026,2027,'1st','active')");
$coordinator=insert("INSERT INTO coordinators(user_id,coordinator_id_no,department) VALUES(?,'RELTEST-C','RELTEST')",[$coordUser],'i');
$company=insert("INSERT INTO companies(user_id,company_name,status) VALUES(?,'Synthetic release company','active')",[$companyUser],'i');
$student=insert("INSERT INTO students(user_id,student_id_no,program_id,program,coordinator_id,company_id,onboarding_completed_at,ojt_status) VALUES(?,'RELEASE-STU',?,'RELTEST',?,?,NOW(),'ongoing')",[$studentUser,$program,$coordinator,$company],'iiii');
$enrollment=insert("INSERT INTO ojt_enrollments(student_id,academic_term_id,program_id,coordinator_id,required_hours,status) VALUES(?,?,?,?,486,'ongoing')",[$student,$term,$program,$coordinator],'iiii');
$placement=insert("INSERT INTO placements(ojt_enrollment_id,company_id,status) VALUES(?,?,'active')",[$enrollment,$company],'ii');
insert("INSERT INTO attendance_days(placement_id,attendance_date,status,credited_minutes) VALUES(?,'2026-10-01','present',480)",[$placement],'i');
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ojtrack-release-private-'.bin2hex(random_bytes(6));
mkdir($directory,0700);
putenv('OJTRACK_PUBLIC_ROOT='.dirname(__DIR__,2));
putenv('OJTRACK_PRIVATE_UPLOAD_DIR='.$directory.DIRECTORY_SEPARATOR.'missing');
try {
    try { normalized_report_monthly_submit($student,$studentUser,'2026-10'); release_check(false,'Invalid storage rejected'); }
    catch (RuntimeException $expected) {
        release_check((int)query_one('SELECT COUNT(*) n FROM report_templates')['n']===0,'Failed PDF storage rolls back template');
        release_check((int)query_one('SELECT COUNT(*) n FROM report_assignments')['n']===0,'Failed PDF storage rolls back assignment');
        release_check((int)query_one('SELECT COUNT(*) n FROM report_submissions')['n']===0,'Failed PDF storage leaves no submission');
    }
    putenv('OJTRACK_PRIVATE_UPLOAD_DIR='.$directory);
    $submission=normalized_report_monthly_submit($student,$studentUser,'2026-10');
    release_check($submission>0,'Successful monthly submission');
    $files=query('SELECT a.storage_key FROM report_submission_files sf JOIN attachments a ON a.id=sf.attachment_id WHERE sf.report_submission_id=?',[$submission],'i');
    release_check(count($files)===1,'Generated PDF is attached');
    $pdf=resolve_private_document($files[0]['storage_key']);
    release_check($pdf && str_starts_with(file_get_contents($pdf),'%PDF-'),'Stored attachment contains PDF bytes');
    $rows=normalized_report_rows_for_coordinator($coordinator);
    release_check(count($rows)===1 && count($rows[0]['attachments'])===1,'Coordinator receives the generated PDF attachment');
    release_check(!empty(query_one('SELECT evidence_snapshot FROM report_submissions WHERE id=?',[$submission],'i')['evidence_snapshot']),'Evidence snapshot saved');
} finally {
    foreach (glob($directory.DIRECTORY_SEPARATOR.'reports'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) if (is_file($file)) unlink($file);
    if (is_dir($directory.DIRECTORY_SEPARATOR.'reports')) rmdir($directory.DIRECTORY_SEPARATOR.'reports');
    rmdir($directory);
}
echo "$checks release monthly-report checks passed.\n";
