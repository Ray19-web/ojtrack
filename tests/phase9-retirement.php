<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p9check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p9exists(string $table): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1",
        [$table],'s'
    );
}
$retire=[
    'attendance'=>'legacy_retired_attendance',
    'journal_entries'=>'legacy_retired_journal_entries',
    'requirement_templates'=>'legacy_retired_requirement_templates',
    'ojt_requirements'=>'legacy_retired_ojt_requirements',
    'reports'=>'legacy_retired_reports',
    'evaluations'=>'legacy_retired_evaluations',
    'evaluation_assignments'=>'legacy_retired_evaluation_assignments',
    'evaluation_forms'=>'legacy_retired_evaluation_forms',
    'eval_sections'=>'legacy_retired_eval_sections',
    'eval_criteria'=>'legacy_retired_eval_criteria',
    'eval_rating_rules'=>'legacy_retired_eval_rating_rules',
    'eval_submissions'=>'legacy_retired_eval_submissions',
    'eval_answers'=>'legacy_retired_eval_answers',
    'announcements'=>'legacy_retired_announcements'
];
p9check((bool)query_one("SELECT 1 FROM schema_migrations WHERE version='009_legacy_retirement'"),'phase 9 recorded');
foreach ($retire as $source=>$target) {
    p9check(!p9exists($source),'legacy source retired: '.$source);
    p9check(p9exists($target),'retired table preserved: '.$target);
}
p9check((int)query_one("SELECT COUNT(*) n FROM legacy_retired_ojt_requirements")['n']===7,'retired requirement fixture preserved');
p9check((int)query_one("SELECT COUNT(*) n FROM legacy_retired_reports")['n']===7,'retired report fixture preserved');
p9check((int)query_one("SELECT COUNT(*) n FROM legacy_retired_evaluation_forms")['n']===4,'retired evaluation form fixture preserved');
p9check((int)query_one("SELECT COUNT(*) n FROM legacy_retired_announcements")['n']===6,'retired announcement fixture preserved');
p9check((int)query_one("SELECT COUNT(*) n FROM requirement_assignments")['n']===7,'normalized requirements remain available');
p9check((int)query_one("SELECT COUNT(*) n FROM report_assignments")['n']===7,'normalized reports remain available');
p9check((int)query_one("SELECT COUNT(*) n FROM evaluation_requests")['n']===4,'normalized evaluations remain available');
p9check((int)query_one("SELECT COUNT(*) n FROM announcement_posts")['n']===6,'normalized announcements remain available');

echo json_encode(['phase9_retirement_checks_passed'=>$checks,'result'=>'PASS']).PHP_EOL;
