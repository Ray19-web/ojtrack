<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/normalized.php';
require_once __DIR__ . '/../config/normalized_training.php';
require_once __DIR__ . '/../config/normalized_communications.php';

$checks=0;
function lwcheck(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}

lwcheck(normalized_lean_schema_ready(),'lean schema active');

// Coordinator assignment now lives directly on ojt_enrollments.
$enrollment=query_one(
    "SELECT oe.*,p.company_id,p.starts_on,p.ends_on
     FROM ojt_enrollments oe
     LEFT JOIN placements p ON p.ojt_enrollment_id=oe.id
     WHERE oe.coordinator_id IS NOT NULL
     ORDER BY oe.id LIMIT 1"
);
lwcheck((bool)$enrollment,'test enrollment available');
$otherCoord=query_one(
    "SELECT id FROM coordinators WHERE id<>? ORDER BY id LIMIT 1",
    [(int)$enrollment['coordinator_id']],
    'i'
);
lwcheck((bool)$otherCoord,'alternate coordinator available');
$originalCoordinator=(int)$enrollment['coordinator_id'];
normalized_upsert_student_training(
    (int)$enrollment['student_id'],
    (int)$enrollment['program_id'],
    (int)$enrollment['required_hours'],
    (string)$enrollment['status'],
    (int)$otherCoord['id'],
    !empty($enrollment['company_id']) ? (int)$enrollment['company_id'] : null,
    $enrollment['starts_on'] ?? null,
    $enrollment['ends_on'] ?? null,
    'Lean schema write test',
    1
);
$changed=query_one("SELECT coordinator_id FROM ojt_enrollments WHERE id=?",[(int)$enrollment['id']],'i');
lwcheck((int)$changed['coordinator_id']===(int)$otherCoord['id'],'coordinator changed directly on enrollment');
normalized_upsert_student_training(
    (int)$enrollment['student_id'],
    (int)$enrollment['program_id'],
    (int)$enrollment['required_hours'],
    (string)$enrollment['status'],
    $originalCoordinator,
    !empty($enrollment['company_id']) ? (int)$enrollment['company_id'] : null,
    $enrollment['starts_on'] ?? null,
    $enrollment['ends_on'] ?? null,
    'Lean schema write test restore',
    1
);
$restored=query_one("SELECT coordinator_id FROM ojt_enrollments WHERE id=?",[(int)$enrollment['id']],'i');
lwcheck((int)$restored['coordinator_id']===$originalCoordinator,'coordinator restored directly on enrollment');

// Requirement resubmission copies one direct attachment_id forward.
$req=query_one(
    "SELECT rs.id submission_id,rs.requirement_assignment_id,rs.attachment_id,
            oe.student_id,s.user_id
     FROM requirement_submissions rs
     JOIN requirement_assignments ra ON ra.id=rs.requirement_assignment_id
     JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
     JOIN students s ON s.id=oe.student_id
     WHERE rs.attachment_id IS NOT NULL
     ORDER BY rs.id LIMIT 1"
);
lwcheck((bool)$req,'requirement with attachment available');
query("UPDATE requirement_submissions SET status='returned' WHERE id=?",[(int)$req['submission_id']],'i');
query("UPDATE requirement_assignments SET status='assigned' WHERE id=?",[(int)$req['requirement_assignment_id']],'i');
$newReq=normalized_requirement_submit(
    (int)$req['requirement_assignment_id'],
    (int)$req['student_id'],
    (int)$req['user_id'],
    null,
    null
);
$newReqRow=query_one("SELECT attachment_id FROM requirement_submissions WHERE id=?",[$newReq],'i');
lwcheck((int)$newReqRow['attachment_id']===(int)$req['attachment_id'],'requirement resubmission copied direct attachment');

// Report resubmission does the same.
$report=query_one(
    "SELECT rs.id submission_id,rs.report_assignment_id,rs.attachment_id,
            oe.student_id,s.user_id
     FROM report_submissions rs
     JOIN report_assignments ra ON ra.id=rs.report_assignment_id
     JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
     JOIN students s ON s.id=oe.student_id
     WHERE rs.attachment_id IS NOT NULL
     ORDER BY rs.id LIMIT 1"
);
lwcheck((bool)$report,'report with attachment available');
query("UPDATE report_submissions SET status='returned' WHERE id=?",[(int)$report['submission_id']],'i');
query("UPDATE report_assignments SET status='assigned' WHERE id=?",[(int)$report['report_assignment_id']],'i');
$newReport=normalized_report_submit(
    (int)$report['report_assignment_id'],
    (int)$report['student_id'],
    (int)$report['user_id'],
    'Lean schema resubmission',
    null,
    null,
    null
);
$newReportRow=query_one("SELECT attachment_id FROM report_submissions WHERE id=?",[$newReport],'i');
lwcheck((int)$newReportRow['attachment_id']===(int)$report['attachment_id'],'report resubmission copied direct attachment');

// Announcement attachment now lives directly on announcement_posts.
$publicAttachment=query_one(
    "SELECT storage_key,original_filename
     FROM attachments
     WHERE storage_key LIKE 'announcements/%'
     ORDER BY id LIMIT 1"
);
lwcheck((bool)$publicAttachment,'public announcement attachment available');
$newPost=normalized_announcement_create(
    1,
    'Lean schema attachment test',
    'Synthetic post created after table consolidation.',
    'General',
    'all',
    false,
    null,
    (string)$publicAttachment['storage_key'],
    (string)$publicAttachment['original_filename']
);
$post=query_one("SELECT attachment_id FROM announcement_posts WHERE id=?",[$newPost],'i');
lwcheck(!empty($post['attachment_id']),'announcement stores direct attachment id');
$viewPost=query_one("SELECT attachment_id FROM announcement_attachments WHERE announcement_post_id=?",[$newPost],'i');
lwcheck((int)$viewPost['attachment_id']===(int)$post['attachment_id'],'announcement compatibility view reflects direct attachment');

echo json_encode(['lean_schema_write_checks_passed'=>$checks,'result'=>'PASS']).PHP_EOL;
