<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p5check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p5count(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    return (int)($r['n'] ?? 0);
}

p5check(p5count("SELECT COUNT(*) n FROM reports")===7,'legacy reports preserved');
p5check(p5count("SELECT COUNT(*) n FROM schema_migrations WHERE version='005_reports'")===1,'phase 5 recorded');
p5check(p5count("SELECT COUNT(*) n FROM legacy_report_migration_map")===7,'all legacy reports mapped');
p5check(p5count("SELECT COUNT(*) n FROM report_assignments")===7,'one assignment per legacy report');
p5check(p5count("SELECT COUNT(*) n FROM report_submissions")===4,'only submitted reports became submissions');
p5check(p5count("SELECT COUNT(*) n FROM report_submission_attachments")===2,'report files linked');
p5check(p5count("SELECT COUNT(*) n FROM report_templates")===6,'duplicate acceptance report shares template');
p5check(p5count("SELECT COUNT(*) n FROM report_template_versions")===6,'one version per distinct report definition');

$acceptanceTemplates=p5count(
    "SELECT COUNT(DISTINCT m.report_template_id) n
     FROM legacy_report_migration_map m
     WHERE m.legacy_report_id IN (1,7)"
);
p5check($acceptanceTemplates===1,'same name/type shares one report template');

$forReview=query_one(
    "SELECT ra.status assignment_status,rs.status submission_status,rs.student_note,rs.review_notes
     FROM legacy_report_migration_map m
     JOIN report_assignments ra ON ra.id=m.report_assignment_id
     JOIN report_submissions rs ON rs.id=m.report_submission_id
     WHERE m.legacy_report_id=1"
);
p5check($forReview && $forReview['assignment_status']==='assigned','for-review assignment remains open');
p5check($forReview && $forReview['submission_status']==='submitted','for-review maps to submitted');
p5check($forReview && $forReview['student_note']==='Student acceptance note','student note preserved separately');
p5check($forReview && $forReview['review_notes']===null,'no fake review note created');

$returned=query_one(
    "SELECT ra.status assignment_status,rs.status submission_status,rs.student_note,rs.review_notes,rs.reviewed_by
     FROM legacy_report_migration_map m
     JOIN report_assignments ra ON ra.id=m.report_assignment_id
     JOIN report_submissions rs ON rs.id=m.report_submission_id
     WHERE m.legacy_report_id=2"
);
p5check($returned && $returned['assignment_status']==='assigned','returned assignment stays open');
p5check($returned && $returned['submission_status']==='returned','rejected maps to returned');
p5check($returned && $returned['student_note']===null,'lost historical student note is not invented');
p5check($returned && $returned['review_notes']==='Please revise the conclusion' && (int)$returned['reviewed_by']===2,'review feedback preserved');

$pending=query_one(
    "SELECT ra.status,m.report_submission_id
     FROM legacy_report_migration_map m
     JOIN report_assignments ra ON ra.id=m.report_assignment_id
     WHERE m.legacy_report_id=3"
);
p5check($pending && $pending['status']==='assigned' && $pending['report_submission_id']===null,'pending unsubmitted report stays assignment only');

$waived=query_one(
    "SELECT ra.status,m.report_submission_id
     FROM legacy_report_migration_map m
     JOIN report_assignments ra ON ra.id=m.report_assignment_id
     WHERE m.legacy_report_id=5"
);
p5check($waived && $waived['status']==='waived','approved without submission becomes waived');
p5check($waived && $waived['report_submission_id']===null,'waived legacy report gets no fake submission');

$approved=query_one(
    "SELECT ra.status assignment_status,rs.status submission_status,rs.review_notes
     FROM legacy_report_migration_map m
     JOIN report_assignments ra ON ra.id=m.report_assignment_id
     JOIN report_submissions rs ON rs.id=m.report_submission_id
     WHERE m.legacy_report_id=6"
);
p5check($approved && $approved['assignment_status']==='closed','approved submission closes assignment');
p5check($approved && $approved['submission_status']==='approved','approved status preserved');
p5check($approved && $approved['review_notes']==='Approved after review','approved feedback preserved');

$monthly=query_one(
    "SELECT ra.period_start,ra.period_end,rs.evidence_snapshot
     FROM legacy_report_migration_map m
     JOIN report_assignments ra ON ra.id=m.report_assignment_id
     JOIN report_submissions rs ON rs.id=m.report_submission_id
     WHERE m.legacy_report_id=4"
);
p5check($monthly && $monthly['period_start']==='2026-09-01' && $monthly['period_end']==='2026-09-30','monthly period normalized');
$snapshot=json_decode($monthly['evidence_snapshot'] ?? '',true);
p5check(is_array($snapshot) && $snapshot['schema_version']===1,'monthly evidence is valid versioned JSON');
p5check(($snapshot['basis'] ?? '')==='migration_current_normalized_records_not_original_submission_snapshot','snapshot basis is explicit');
p5check(isset($snapshot['attendance']) && isset($snapshot['journals']) && isset($snapshot['totals']),'monthly snapshot contains inspectable evidence');
p5check(($snapshot['totals']['attendance_days'] ?? 0)>0,'monthly snapshot includes normalized attendance');
p5check(($snapshot['totals']['journal_days'] ?? 0)>0,'monthly snapshot includes normalized journals');

$attachment=query_one(
    "SELECT a.storage_key,a.detected_mime,a.size_bytes,a.sha256
     FROM legacy_report_migration_map m
     JOIN report_submission_attachments rsa ON rsa.report_submission_id=m.report_submission_id
     JOIN attachments a ON a.id=rsa.attachment_id
     WHERE m.legacy_report_id=1"
);
p5check($attachment && $attachment['storage_key']==='reports/acceptance.pdf','report attachment key preserved');
p5check($attachment && $attachment['detected_mime']==='application/pdf','report MIME recorded');
p5check($attachment && (int)$attachment['size_bytes']>0 && strlen($attachment['sha256'])===64,'report hash and size recorded');

p5check(
    p5count("SELECT COUNT(*) n FROM reports WHERE id=2 AND status='rejected' AND remarks='Please revise the conclusion'")===1,
    'legacy returned report unchanged'
);
p5check(
    p5count("SELECT COUNT(*) n FROM reports WHERE id=4 AND report_type='monthly' AND status='for_review'")===1,
    'legacy monthly report unchanged'
);

echo json_encode(['phase5_report_checks_passed'=>$checks,'result'=>'PASS']) . PHP_EOL;
