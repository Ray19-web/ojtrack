<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function lcheck(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function lbase(string $name): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=? AND table_type='BASE TABLE' LIMIT 1",
        [$name],'s'
    );
}
function lview(string $name): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=? AND table_type='VIEW' LIMIT 1",
        [$name],'s'
    );
}
function lcount(string $sql): int {
    return (int)(query_one($sql)['c'] ?? 0);
}

lcheck((bool)query_one("SELECT 1 FROM schema_migrations WHERE version='010_lean_schema'"),'lean migration recorded');
lcheck(lcount("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")===40,'exactly 40 base tables remain');

$removed=[
    'attendance_corrections','placement_supervisors','company_users','enrollment_coordinators',
    'journal_revision_attachments','requirement_submission_attachments',
    'report_submission_attachments','announcement_attachments'
];
foreach ($removed as $name) lcheck(!lbase($name),'redundant base table removed: '.$name);

$views=[
    'company_users','enrollment_coordinators','journal_revision_attachments',
    'requirement_submission_attachments','report_submission_attachments','announcement_attachments'
];
foreach ($views as $name) lcheck(lview($name),'compatibility view exists: '.$name);

lcheck(
    lcount("SELECT COUNT(*) c FROM company_users")===lcount("SELECT COUNT(*) c FROM companies"),
    'one derived company user per company'
);
lcheck(
    lcount("SELECT COUNT(*) c FROM enrollment_coordinators")===
    lcount("SELECT COUNT(*) c FROM ojt_enrollments WHERE coordinator_id IS NOT NULL"),
    'coordinator view matches direct enrollment coordinators'
);
lcheck(
    lcount("SELECT COUNT(*) c FROM journal_revision_attachments")===
    lcount("SELECT COUNT(*) c FROM journal_revisions WHERE attachment_id IS NOT NULL"),
    'journal attachments are direct'
);
lcheck(
    lcount("SELECT COUNT(*) c FROM requirement_submission_attachments")===
    lcount("SELECT COUNT(*) c FROM requirement_submissions WHERE attachment_id IS NOT NULL"),
    'requirement attachments are direct'
);
lcheck(
    lcount("SELECT COUNT(*) c FROM report_submission_attachments")===
    lcount("SELECT COUNT(*) c FROM report_submissions WHERE attachment_id IS NOT NULL"),
    'report attachments are direct'
);
lcheck(
    lcount("SELECT COUNT(*) c FROM announcement_attachments")===
    lcount("SELECT COUNT(*) c FROM announcement_posts WHERE attachment_id IS NOT NULL"),
    'announcement attachments are direct'
);
lcheck(
    lcount(
        "SELECT COUNT(*) c
         FROM evaluation_requests er
         JOIN placements p ON p.id=er.placement_id
         JOIN companies c ON c.id=p.company_id
         WHERE er.evaluator_company_user_id<>c.user_id"
    )===0,
    'evaluation requests use direct company user ids'
);
lcheck(normalized_lean_schema_ready(),'application detects lean schema');

echo json_encode(['lean_schema_checks_passed'=>$checks,'base_tables'=>40,'views'=>6,'result'=>'PASS']).PHP_EOL;
