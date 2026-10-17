<?php
// Real SQL on connection-scoped temporary tables; application records are untouched.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../config/db.php';
$tables = [
    'academic_terms' => 'id INT, status VARCHAR(20), academic_year_start INT, academic_year_end INT',
    'users' => 'id INT, name VARCHAR(100), status VARCHAR(20)',
    'students' => 'id INT, user_id INT, student_id_no VARCHAR(30), is_archived INT',
    'programs' => 'id INT, code VARCHAR(20)',
    'ojt_enrollments' => 'id INT, student_id INT, academic_term_id INT, program_id INT',
    'enrollment_coordinators' => 'ojt_enrollment_id INT, coordinator_id INT, ended_at DATETIME',
    'requirement_definitions' => 'id INT, title VARCHAR(100)',
    'requirement_definition_versions' => 'id INT, requirement_definition_id INT',
    'requirement_assignments' => 'id INT, ojt_enrollment_id INT, requirement_definition_version_id INT, due_date DATE, assignment_note TEXT, status VARCHAR(20)',
    'requirement_submissions' => 'id INT, requirement_assignment_id INT, version_no INT, status VARCHAR(20), submitted_at DATETIME, reviewed_by INT, reviewed_at DATETIME, review_notes TEXT',
    'requirement_submission_attachments' => 'requirement_submission_id INT, attachment_id INT',
    'attachments' => 'id INT, storage_key VARCHAR(100)',
    'report_templates' => 'id INT, title VARCHAR(100)',
    'report_template_versions' => 'id INT, report_template_id INT, report_type VARCHAR(30)',
    'report_assignments' => 'id INT, ojt_enrollment_id INT, report_template_version_id INT, due_date DATE, period_start DATE, period_end DATE, status VARCHAR(20)',
    'report_submissions' => 'id INT, report_assignment_id INT, version_no INT, status VARCHAR(20), submitted_at DATETIME, student_note TEXT, reviewed_by INT, reviewed_at DATETIME, review_notes TEXT, evidence_snapshot TEXT',
    'report_submission_attachments' => 'report_submission_id INT, attachment_id INT',
    'placements' => 'id INT, ojt_enrollment_id INT, company_id INT',
    'companies' => 'id INT, company_name VARCHAR(100)',
    'company_users' => 'id INT, user_id INT',
    'evaluation_definitions' => 'id INT, title VARCHAR(100), description TEXT',
    'evaluation_definition_versions' => 'id INT, evaluation_definition_id INT, version_no INT, score_mode VARCHAR(20), rating_max INT',
    'evaluation_requests' => 'id INT, evaluation_definition_version_id INT, placement_id INT, evaluator_company_user_id INT, requested_by INT, status VARCHAR(20), evaluation_kind VARCHAR(20), created_at DATETIME',
    'evaluation_submissions' => 'id INT, evaluation_request_id INT, overall_score DECIMAL(5,2), overall_equivalent VARCHAR(20), comments TEXT, submitted_at DATETIME',
    'evaluation_answers' => 'id INT, evaluation_submission_id INT, evaluation_criterion_id INT, section_title_snapshot VARCHAR(100), criterion_label_snapshot VARCHAR(100), score INT, equivalent VARCHAR(20)',
    'evaluation_version_criteria' => 'id INT, criterion_code VARCHAR(30), weight DECIMAL(6,3)',
    'journal_days' => 'id INT, placement_id INT, entry_date DATE',
    'journal_revisions' => 'id INT, journal_day_id INT, revision_no INT, status VARCHAR(20), review_notes TEXT, reviewed_by INT, reviewed_at DATETIME',
];
foreach ($tables as $name => $columns) db()->query("CREATE TEMPORARY TABLE $name ($columns)");
db()->query("INSERT INTO academic_terms VALUES (9,'active',2026,2027)");
db()->query("INSERT INTO programs VALUES (1,'BSIT')");
db()->query("INSERT INTO requirement_definitions VALUES (1,'Medical certificate')");
db()->query("INSERT INTO requirement_definition_versions VALUES (1,1)");
db()->query("INSERT INTO report_templates VALUES (1,'Monthly report')");
db()->query("INSERT INTO report_template_versions VALUES (1,1,'monthly')");
db()->query("ALTER TABLE ojt_enrollments ADD status VARCHAR(20) DEFAULT 'ongoing'");
db()->query("INSERT INTO companies VALUES (1,'Test company')");
db()->query("INSERT INTO company_users VALUES (1,99)");
db()->query("INSERT INTO evaluation_definitions VALUES (1,'Evaluation','')");
db()->query("INSERT INTO evaluation_definition_versions VALUES (1,1,1,'percentage',100)");
db()->query("INSERT INTO users VALUES (99,'Supervisor','active')");
foreach ([1 => [0,'active'], 2 => [1,'active'], 3 => [0,'archived'], 4 => [1,'archived']] as $id => [$flag,$status]) {
    query("INSERT INTO users VALUES (?, ?, ?)", [$id,"Student $id",$status], 'iss');
    query("INSERT INTO students VALUES (?, ?, ?, ?)", [$id,$id,"S$id",$flag], 'iisi');
    query("INSERT INTO ojt_enrollments (id,student_id,academic_term_id,program_id) VALUES (?, ?, 9, 1)", [$id,$id], 'ii');
    query("INSERT INTO enrollment_coordinators VALUES (?, 7, NULL)", [$id], 'i');
    query("INSERT INTO requirement_assignments VALUES (?, ?, 1, NULL, '', 'assigned')", [$id,$id], 'ii');
    query("INSERT INTO requirement_submissions VALUES (?, ?, 1, 'submitted', NOW(), NULL, NULL, '')", [$id,$id], 'ii');
    query("INSERT INTO report_assignments VALUES (?, ?, 1, NULL, NULL, NULL, 'assigned')", [$id,$id], 'ii');
    query("INSERT INTO report_submissions VALUES (?, ?, 1, 'submitted', NOW(), '', NULL, NULL, '', NULL)", [$id,$id], 'ii');
    query("INSERT INTO placements VALUES (?, ?, 1)", [$id,$id], 'ii');
    query("INSERT INTO evaluation_requests VALUES (?,1,?,1,99,'submitted','custom',NOW())", [$id,$id], 'ii');
    query("INSERT INTO evaluation_submissions VALUES (?, ?, 90, 'Good', '', NOW())", [$id,$id], 'ii');
    query("INSERT INTO journal_days VALUES (?, ?, '2026-10-13')", [$id,$id], 'ii');
    query("INSERT INTO journal_revisions VALUES (?, ?, 1, 'submitted', '', NULL, NULL)", [$id,$id], 'ii');
}
$checks = 0;
function archive_review_check($actual, $expected, string $label): void {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException("FAIL: $label");
    $checks++;
}
function archive_review_ids(array $rows): array {
    $ids = array_map('intval', array_column($rows, 'student_id'));
    sort($ids);
    return $ids;
}
archive_review_check(archive_review_ids(normalized_requirement_rows_for_coordinator(7)), [1], 'requirements counts exclude both archive markers');
archive_review_check(archive_review_ids(normalized_requirement_rows_for_coordinator(7,'pending')), [1], 'pending requirements exclude archived students');
archive_review_check(normalized_requirement_rows_for_coordinator(7,'','Student 2'), [], 'search cannot reveal archived requirements');
archive_review_check(normalized_requirement_rows_for_coordinator(8), [], 'coordinator boundary remains enforced');
archive_review_check(archive_review_ids(normalized_report_rows_for_coordinator(7)), [1], 'reports counts exclude both archive markers');
archive_review_check(archive_review_ids(normalized_report_rows_for_coordinator(7,'for_review')), [1], 'report review tab excludes archived students');
archive_review_check(normalized_report_rows_for_coordinator(7,'','Student 3'), [], 'search cannot reveal archived reports');
archive_review_check(archive_review_ids(normalized_eval_completed_for_coordinator(7)), [1], 'completed evaluations exclude archived students');
archive_review_check(archive_review_ids(normalized_eval_requests_for_company(1)), [1], 'company evaluations exclude archived students');
archive_review_check(normalized_eval_requests_for_company(1,2), [], 'direct company student filter excludes archived');
archive_review_check(array_map('intval',array_column(normalized_eval_submissions_for_requester(99),'id')), [1], 'requester evaluations exclude archived students');
foreach ([2,3,4] as $id) {
    archive_review_check(normalized_requirement_review($id,7,99,'returned','Revision'), null, 'stale requirement review blocked');
    archive_review_check(normalized_report_review($id,7,99,'returned','Revision'), null, 'stale report review blocked');
    archive_review_check(normalized_journal_review($id,7,99,'returned','Revision'), null, 'stale journal review blocked');
    archive_review_check(normalized_requirement_assign(1,$id,7,99,null), 'invalid', 'new requirement assignment blocked');
    try {
        normalized_submit_evaluation(1,$id,[],'',99);
        throw new RuntimeException('Archived evaluation was accepted');
    } catch (DomainException $error) {
        archive_review_check($error->getMessage(), 'Evaluation not found.', 'stale evaluation submission blocked');
    }
    try {
        normalized_attendance_punch($id,1,'2026-10-13','08:00:00',99);
        throw new RuntimeException('Archived attendance was accepted');
    } catch (DomainException $error) {
        archive_review_check($error->getMessage(), 'Archived trainees cannot record attendance.', 'archived attendance blocked');
    }
}
archive_review_check((int)query_one("SELECT COUNT(*) n FROM requirement_submissions WHERE status='submitted'")['n'], 4, 'archived requirement history unchanged');
archive_review_check((int)query_one("SELECT COUNT(*) n FROM report_submissions WHERE status='submitted'")['n'], 4, 'archived report history unchanged');
archive_review_check((int)query_one("SELECT COUNT(*) n FROM journal_revisions WHERE status='submitted'")['n'], 4, 'archived journal history unchanged');
archive_review_check((int)normalized_requirement_review(1,7,99,'returned','Revision')['student_id'], 1, 'active requirement review still works');
archive_review_check((int)normalized_report_review(1,7,99,'returned','Revision')['student_id'], 1, 'active report review still works');
archive_review_check((int)normalized_journal_review(1,7,99,'returned','Revision')['student_id'], 1, 'active journal review still works');
query("UPDATE students SET is_archived=0 WHERE id=4");
archive_review_check(normalized_requirement_rows_for_coordinator(7,'','Student 4'), [], 'clearing only one marker does not restore access');
query("UPDATE users SET status='active' WHERE id=4");
archive_review_check(archive_review_ids(normalized_requirement_rows_for_coordinator(7)), [1,4], 'restoring account brings back preserved requirements');
archive_review_check(archive_review_ids(normalized_report_rows_for_coordinator(7)), [1,4], 'restoring account brings back preserved reports');
echo "$checks archived student review checks passed.\n";
