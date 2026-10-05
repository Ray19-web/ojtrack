<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function ccheck(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function cexists(string $table): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1",
        [$table],'s'
    );
}

$active=[
    'schema_migrations','users','programs','academic_terms','students','coordinators','companies','company_users',
    'ojt_enrollments','enrollment_coordinators','placements','placement_supervisors',
    'attendance_days','attendance_sessions','attendance_corrections',
    'journal_days','journal_revisions','attachments','journal_revision_attachments',
    'requirement_definitions','requirement_definition_versions','requirement_assignments','requirement_submissions','requirement_submission_attachments',
    'report_templates','report_template_versions','report_assignments','report_submissions','report_submission_attachments',
    'evaluation_definitions','evaluation_definition_versions','evaluation_version_sections','evaluation_version_criteria','evaluation_version_rating_rules',
    'evaluation_requests','evaluation_submissions','evaluation_answers',
    'certificate_templates','certificates',
    'announcement_posts','announcement_attachments','announcement_recipients',
    'message_threads','thread_members','messages','message_reads','notifications','activity_log'
];
$artifacts=[
    'legacy_attendance_resolutions',
    'legacy_requirement_migration_map','legacy_requirement_template_migration_map','legacy_report_migration_map',
    'legacy_evaluation_form_migration_map','legacy_eval_submission_migration_map','legacy_fixed_evaluation_migration_map',
    'legacy_certificate_template_migration_map','legacy_announcement_migration_map',
    'legacy_retired_attendance','legacy_retired_journal_entries','legacy_retired_requirement_templates',
    'legacy_retired_ojt_requirements','legacy_retired_reports','legacy_retired_evaluations',
    'legacy_retired_evaluation_assignments','legacy_retired_evaluation_forms','legacy_retired_eval_sections',
    'legacy_retired_eval_criteria','legacy_retired_eval_rating_rules','legacy_retired_eval_submissions',
    'legacy_retired_eval_answers','legacy_retired_announcements'
];

$count=(int)(query_one("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE()")['c'] ?? 0);
ccheck($count===48,'exactly 48 tables remain');
foreach ($active as $table) ccheck(cexists($table),'active table exists: '.$table);
foreach ($artifacts as $table) ccheck(!cexists($table),'artifact removed: '.$table);
ccheck((bool)query_one("SELECT 1 FROM schema_migrations WHERE version='009_legacy_retirement'"),'phase 9 checkpoint remains');
ccheck((int)(query_one("SELECT COUNT(*) c FROM requirement_assignments")['c'] ?? 0)>0,'normalized requirements remain');
ccheck((int)(query_one("SELECT COUNT(*) c FROM report_assignments")['c'] ?? 0)>0,'normalized reports remain');
ccheck((int)(query_one("SELECT COUNT(*) c FROM evaluation_requests")['c'] ?? 0)>0,'normalized evaluations remain');

echo json_encode(['cleanup_checks_passed'=>$checks,'tables_remaining'=>$count,'result'=>'PASS']).PHP_EOL;
