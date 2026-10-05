<?php
/**
 * OJTrack migration 010: lean schema simplification.
 *
 * Reduces the post-cleanup normalized database from 48 base tables to 40:
 * - removes unused attendance_corrections
 * - removes write-only placement_supervisors
 * - folds company_users into companies.user_id
 * - folds enrollment_coordinators into ojt_enrollments
 * - folds four one-file-only attachment junctions into direct attachment_id columns
 *
 * Six compatibility views keep existing read queries stable without duplicate storage.
 *
 * Usage:
 * php bin/migrate-normalized-phase10-lean-schema.php --dry-run
 * php bin/migrate-normalized-phase10-lean-schema.php --apply
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

$options=getopt('', ['apply','dry-run']);
$apply=array_key_exists('apply',$options);
$dryRun=array_key_exists('dry-run',$options) || !$apply;
if ($apply && array_key_exists('dry-run',$options)) {
    fwrite(STDERR,"Choose either --apply or --dry-run, not both.\n");
    exit(2);
}

require __DIR__ . '/../config/db.php';

function lean_scalar(string $sql,array $params=[],string $types=''): int {
    $row=query_one($sql,$params,$types);
    if (!$row) return 0;
    return (int)reset($row);
}
function lean_base_table_exists(string $table): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=? AND table_type='BASE TABLE' LIMIT 1",
        [$table],'s'
    );
}
function lean_view_exists(string $view): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=? AND table_type='VIEW' LIMIT 1",
        [$view],'s'
    );
}
function lean_column_exists(string $table,string $column): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1",
        [$table,$column],'ss'
    );
}
function lean_fk_for_column(string $table,string $column): ?string {
    $row=query_one(
        "SELECT constraint_name
         FROM information_schema.key_column_usage
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND column_name=?
           AND referenced_table_name IS NOT NULL
         ORDER BY constraint_name LIMIT 1",
        [$table,$column],
        'ss'
    );
    return $row ? (string)$row['constraint_name'] : null;
}
function lean_id(string $name): string {
    $tick=chr(96);
    return $tick.str_replace($tick,$tick.$tick,$name).$tick;
}
function lean_exec(mysqli $conn,string $sql): void {
    if (!$conn->query($sql)) throw new RuntimeException($conn->error." | SQL: ".$sql);
}

$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='010_lean_schema' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'010_lean_schema',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'ALREADY_APPLIED',
        'applied_at'=>$already['applied_at'],
        'base_tables'=>lean_scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='009_legacy_retirement' LIMIT 1")) {
    echo json_encode([
        'migration'=>'010_lean_schema',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['phase9_not_applied'=>1]
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}

$removeTables=[
    'attendance_corrections',
    'placement_supervisors',
    'company_users',
    'enrollment_coordinators',
    'journal_revision_attachments',
    'requirement_submission_attachments',
    'report_submission_attachments',
    'announcement_attachments'
];
$compatibilityViews=[
    'company_users',
    'enrollment_coordinators',
    'journal_revision_attachments',
    'requirement_submission_attachments',
    'report_submission_attachments',
    'announcement_attachments'
];

$problems=[];
$currentBaseCount=lean_scalar(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_type='BASE TABLE'"
);
if ($currentBaseCount!==48) $problems['expected_48_base_tables_before_simplification']=$currentBaseCount;

$missingRemove=[];
foreach ($removeTables as $table) if (!lean_base_table_exists($table)) $missingRemove[]=$table;
if ($missingRemove) $problems['missing_redundant_source_tables']=count($missingRemove);

$requiredColumns=[
    ['companies','user_id'],
    ['companies','supervisor_name'],
];
$missingColumns=[];
foreach ($requiredColumns as [$table,$column]) {
    if (!lean_column_exists($table,$column)) $missingColumns[]=$table.'.'.$column;
}
if ($missingColumns) $problems['missing_required_direct_columns']=count($missingColumns);

$partialColumns=[
    ['ojt_enrollments','coordinator_id'],
    ['journal_revisions','attachment_id'],
    ['requirement_submissions','attachment_id'],
    ['report_submissions','attachment_id'],
    ['announcement_posts','attachment_id']
];
$alreadyAdded=[];
foreach ($partialColumns as [$table,$column]) {
    if (lean_column_exists($table,$column)) $alreadyAdded[]=$table.'.'.$column;
}
if ($alreadyAdded) $problems['partial_lean_schema_columns_already_exist']=count($alreadyAdded);

$existingViews=[];
foreach ($compatibilityViews as $view) if (lean_view_exists($view)) $existingViews[]=$view;
if ($existingViews) $problems['compatibility_views_already_exist']=count($existingViews);

$attendanceCorrectionRows=lean_base_table_exists('attendance_corrections')
    ? lean_scalar("SELECT COUNT(*) FROM attendance_corrections") : 0;
if ($attendanceCorrectionRows!==0) $problems['attendance_corrections_contains_data']=$attendanceCorrectionRows;

$companyUserCount=lean_base_table_exists('company_users') ? lean_scalar("SELECT COUNT(*) FROM company_users") : 0;
$companyCount=lean_scalar("SELECT COUNT(*) FROM companies");
$companyMappingMismatch=lean_base_table_exists('company_users') ? lean_scalar(
    "SELECT COUNT(*)
     FROM company_users cu
     LEFT JOIN companies c ON c.id=cu.company_id
     WHERE c.id IS NULL OR c.user_id IS NULL OR c.user_id<>cu.user_id"
) : 0;
if ($companyMappingMismatch) $problems['company_user_mapping_mismatch']=$companyMappingMismatch;

$companyWithoutMapping=lean_base_table_exists('company_users') ? lean_scalar(
    "SELECT COUNT(*)
     FROM companies c
     LEFT JOIN company_users cu ON cu.company_id=c.id AND cu.user_id=c.user_id
     WHERE c.user_id IS NULL OR cu.id IS NULL"
) : 0;
if ($companyWithoutMapping) $problems['company_without_exact_user_mapping']=$companyWithoutMapping;

$companiesWithMultipleUsers=lean_base_table_exists('company_users') ? lean_scalar(
    "SELECT COUNT(*) FROM (
       SELECT company_id FROM company_users GROUP BY company_id HAVING COUNT(*)<>1
     ) x"
) : 0;
if ($companiesWithMultipleUsers) $problems['companies_with_multiple_company_users']=$companiesWithMultipleUsers;

$enrollmentCoordinatorCount=lean_base_table_exists('enrollment_coordinators')
    ? lean_scalar("SELECT COUNT(*) FROM enrollment_coordinators") : 0;
$enrollmentCoordinatorHistory=lean_base_table_exists('enrollment_coordinators') ? lean_scalar(
    "SELECT COUNT(*) FROM (
       SELECT ojt_enrollment_id FROM enrollment_coordinators
       GROUP BY ojt_enrollment_id HAVING COUNT(*)>1
     ) x"
) : 0;
if ($enrollmentCoordinatorHistory) $problems['enrollments_with_multiple_coordinator_history_rows']=$enrollmentCoordinatorHistory;

$supervisorMismatch=lean_base_table_exists('placement_supervisors') ? lean_scalar(
    "SELECT COUNT(*)
     FROM placement_supervisors ps
     JOIN placements p ON p.id=ps.placement_id
     JOIN company_users cu ON cu.id=ps.company_user_id
     WHERE cu.company_id<>p.company_id"
) : 0;
if ($supervisorMismatch) $problems['placement_supervisor_company_mismatch']=$supervisorMismatch;

$evaluatorMissing=lean_base_table_exists('company_users') ? lean_scalar(
    "SELECT COUNT(*)
     FROM evaluation_requests er
     LEFT JOIN company_users cu ON cu.id=er.evaluator_company_user_id
     WHERE cu.id IS NULL"
) : 0;
if ($evaluatorMissing) $problems['evaluation_request_evaluator_mapping_missing']=$evaluatorMissing;

$evaluatorCompanyMismatch=lean_base_table_exists('company_users') ? lean_scalar(
    "SELECT COUNT(*)
     FROM evaluation_requests er
     JOIN placements p ON p.id=er.placement_id
     JOIN company_users cu ON cu.id=er.evaluator_company_user_id
     WHERE cu.company_id<>p.company_id"
) : 0;
if ($evaluatorCompanyMismatch) $problems['evaluation_request_evaluator_company_mismatch']=$evaluatorCompanyMismatch;

$junctions=[
    ['journal_revision_attachments','journal_revision_id'],
    ['requirement_submission_attachments','requirement_submission_id'],
    ['report_submission_attachments','report_submission_id'],
    ['announcement_attachments','announcement_post_id']
];
$multiAttachment=[];
$junctionCounts=[];
foreach ($junctions as [$table,$parent]) {
    if (!lean_base_table_exists($table)) continue;
    $junctionCounts[$table]=lean_scalar("SELECT COUNT(*) FROM ".lean_id($table));
    $n=lean_scalar(
        "SELECT COUNT(*) FROM (
           SELECT ".lean_id($parent)." FROM ".lean_id($table)."
           GROUP BY ".lean_id($parent)." HAVING COUNT(*)>1
         ) x"
    );
    if ($n) $multiAttachment[$table]=$n;
}
if ($multiAttachment) $problems['parents_with_multiple_attachments']=array_sum($multiAttachment);

$out=[
    'migration'=>'010_lean_schema',
    'mode'=>$dryRun?'dry-run':'apply',
    'current_base_tables'=>$currentBaseCount,
    'target_base_tables'=>40,
    'tables_to_remove'=>$removeTables,
    'compatibility_views_to_create'=>$compatibilityViews,
    'data_profile'=>[
        'companies'=>$companyCount,
        'company_users'=>$companyUserCount,
        'enrollment_coordinator_rows'=>$enrollmentCoordinatorCount,
        'placement_supervisor_rows'=>lean_base_table_exists('placement_supervisors') ? lean_scalar("SELECT COUNT(*) FROM placement_supervisors") : 0,
        'attendance_corrections'=>$attendanceCorrectionRows,
        'attachment_links'=>$junctionCounts
    ],
    'preflight_problems'=>$problems
];
if ($missingRemove) $out['missing_redundant_tables']=$missingRemove;
if ($missingColumns) $out['missing_required_columns']=$missingColumns;
if ($alreadyAdded) $out['partial_columns']=$alreadyAdded;
if ($existingViews) $out['existing_views']=$existingViews;
if ($multiAttachment) $out['multiple_attachment_parents']=$multiAttachment;

if ($problems) {
    $out['result']='BLOCKED';
    $out['message']='No schema changes were made. Resolve the listed assumptions before simplifying.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $out['result']='READY';
    $out['message']='Preflight passed. --apply will consolidate redundant relations and reduce the database from 48 to 40 base tables.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

try {
    // 1) Add the direct columns that replace redundant relation tables.
    lean_exec($conn,
        "ALTER TABLE ojt_enrollments
         ADD COLUMN coordinator_id INT NULL AFTER program_id,
         ADD COLUMN coordinator_assigned_by INT NULL AFTER coordinator_id,
         ADD COLUMN coordinator_assigned_at TIMESTAMP NULL DEFAULT NULL AFTER coordinator_assigned_by"
    );
    lean_exec($conn,"ALTER TABLE journal_revisions ADD COLUMN attachment_id BIGINT NULL AFTER review_notes");
    lean_exec($conn,"ALTER TABLE requirement_submissions ADD COLUMN attachment_id BIGINT NULL AFTER review_notes");
    lean_exec($conn,"ALTER TABLE report_submissions ADD COLUMN attachment_id BIGINT NULL AFTER evidence_snapshot");
    lean_exec($conn,"ALTER TABLE announcement_posts ADD COLUMN attachment_id BIGINT NULL AFTER expires_at");

    // 2) Backfill direct relationships.
    lean_exec($conn,
        "UPDATE ojt_enrollments oe
         LEFT JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id
         SET oe.coordinator_id=ec.coordinator_id,
             oe.coordinator_assigned_by=ec.assigned_by,
             oe.coordinator_assigned_at=ec.assigned_at"
    );
    lean_exec($conn,
        "UPDATE journal_revisions jr
         JOIN journal_revision_attachments jra ON jra.journal_revision_id=jr.id
         SET jr.attachment_id=jra.attachment_id"
    );
    lean_exec($conn,
        "UPDATE requirement_submissions rs
         JOIN requirement_submission_attachments rsa ON rsa.requirement_submission_id=rs.id
         SET rs.attachment_id=rsa.attachment_id"
    );
    lean_exec($conn,
        "UPDATE report_submissions rs
         JOIN report_submission_attachments rsa ON rsa.report_submission_id=rs.id
         SET rs.attachment_id=rsa.attachment_id"
    );
    lean_exec($conn,
        "UPDATE announcement_posts ap
         JOIN announcement_attachments aa ON aa.announcement_post_id=ap.id
         SET ap.attachment_id=aa.attachment_id"
    );

    // 3) Convert evaluation-request evaluator IDs from company_users.id to users.id.
    $evaluatorFk=lean_fk_for_column('evaluation_requests','evaluator_company_user_id');
    if ($evaluatorFk) {
        lean_exec($conn,"ALTER TABLE evaluation_requests DROP FOREIGN KEY ".lean_id($evaluatorFk));
    }
    lean_exec($conn,
        "UPDATE evaluation_requests er
         JOIN company_users cu ON cu.id=er.evaluator_company_user_id
         SET er.evaluator_company_user_id=cu.user_id"
    );

    // 4) Add direct foreign keys.
    lean_exec($conn,
        "ALTER TABLE ojt_enrollments
         ADD CONSTRAINT fk_ojt_enrollments_coordinator
           FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE SET NULL,
         ADD CONSTRAINT fk_ojt_enrollments_coordinator_assigner
           FOREIGN KEY (coordinator_assigned_by) REFERENCES users(id) ON DELETE SET NULL"
    );
    lean_exec($conn,
        "ALTER TABLE journal_revisions
         ADD CONSTRAINT fk_journal_revisions_attachment
           FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL"
    );
    lean_exec($conn,
        "ALTER TABLE requirement_submissions
         ADD CONSTRAINT fk_requirement_submissions_attachment
           FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL"
    );
    lean_exec($conn,
        "ALTER TABLE report_submissions
         ADD CONSTRAINT fk_report_submissions_attachment
           FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL"
    );
    lean_exec($conn,
        "ALTER TABLE announcement_posts
         ADD CONSTRAINT fk_announcement_posts_attachment
           FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL"
    );
    lean_exec($conn,
        "ALTER TABLE evaluation_requests
         ADD CONSTRAINT fk_evaluation_requests_evaluator_user
           FOREIGN KEY (evaluator_company_user_id) REFERENCES users(id) ON DELETE RESTRICT"
    );

    // 5) Drop the eight redundant base tables in FK-safe order.
    foreach ([
        'attendance_corrections',
        'placement_supervisors',
        'journal_revision_attachments',
        'requirement_submission_attachments',
        'report_submission_attachments',
        'announcement_attachments',
        'enrollment_coordinators',
        'company_users'
    ] as $table) {
        lean_exec($conn,"DROP TABLE ".lean_id($table));
    }

    // 6) Create storage-free compatibility views for existing read queries.
    lean_exec($conn,
        "CREATE VIEW company_users AS
         SELECT
           c.user_id AS id,
           c.id AS company_id,
           c.user_id,
           c.supervisor_name AS position_title,
           'supervisor' AS company_role,
           1 AS is_primary,
           CASE WHEN c.status='active' AND u.status='active' THEN 'active' ELSE 'inactive' END AS status,
           c.created_at
         FROM companies c
         JOIN users u ON u.id=c.user_id"
    );
    lean_exec($conn,
        "CREATE VIEW enrollment_coordinators AS
         SELECT
           oe.id AS id,
           oe.id AS ojt_enrollment_id,
           oe.coordinator_id,
           oe.coordinator_assigned_by AS assigned_by,
           COALESCE(oe.coordinator_assigned_at,oe.created_at) AS assigned_at,
           NULL AS ended_at
         FROM ojt_enrollments oe
         WHERE oe.coordinator_id IS NOT NULL"
    );
    lean_exec($conn,
        "CREATE VIEW journal_revision_attachments AS
         SELECT id AS journal_revision_id,attachment_id
         FROM journal_revisions WHERE attachment_id IS NOT NULL"
    );
    lean_exec($conn,
        "CREATE VIEW requirement_submission_attachments AS
         SELECT id AS requirement_submission_id,attachment_id
         FROM requirement_submissions WHERE attachment_id IS NOT NULL"
    );
    lean_exec($conn,
        "CREATE VIEW report_submission_attachments AS
         SELECT id AS report_submission_id,attachment_id
         FROM report_submissions WHERE attachment_id IS NOT NULL"
    );
    lean_exec($conn,
        "CREATE VIEW announcement_attachments AS
         SELECT id AS announcement_post_id,attachment_id
         FROM announcement_posts WHERE attachment_id IS NOT NULL"
    );

    $version='010_lean_schema';
    $description='Consolidate redundant company/coordinator/supervisor and one-file attachment relations into a 40-table lean normalized schema';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    // 7) Verify final state.
    $baseAfter=lean_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'"
    );
    $viewAfter=lean_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='VIEW'"
    );

    $verification=[
        'base_table_count_mismatch'=>abs(40-$baseAfter),
        'compatibility_view_missing'=>0,
        'company_view_count_mismatch'=>abs($companyCount-lean_scalar("SELECT COUNT(*) FROM company_users")),
        'coordinator_view_count_mismatch'=>abs($enrollmentCoordinatorCount-lean_scalar("SELECT COUNT(*) FROM enrollment_coordinators")),
        'journal_attachment_count_mismatch'=>abs(($junctionCounts['journal_revision_attachments'] ?? 0)-lean_scalar("SELECT COUNT(*) FROM journal_revision_attachments")),
        'requirement_attachment_count_mismatch'=>abs(($junctionCounts['requirement_submission_attachments'] ?? 0)-lean_scalar("SELECT COUNT(*) FROM requirement_submission_attachments")),
        'report_attachment_count_mismatch'=>abs(($junctionCounts['report_submission_attachments'] ?? 0)-lean_scalar("SELECT COUNT(*) FROM report_submission_attachments")),
        'announcement_attachment_count_mismatch'=>abs(($junctionCounts['announcement_attachments'] ?? 0)-lean_scalar("SELECT COUNT(*) FROM announcement_attachments")),
        'evaluation_evaluator_user_mismatch'=>lean_scalar(
            "SELECT COUNT(*)
             FROM evaluation_requests er
             JOIN placements p ON p.id=er.placement_id
             JOIN companies c ON c.id=p.company_id
             WHERE er.evaluator_company_user_id<>c.user_id"
        )
    ];
    foreach ($compatibilityViews as $view) {
        if (!lean_view_exists($view)) $verification['compatibility_view_missing']++;
    }
    foreach ($verification as $label=>$count) {
        if ($count!==0) throw new RuntimeException("Post-migration verification failed: $label=$count");
    }

    $out['result']='PASS';
    $out['base_tables_removed']=8;
    $out['base_tables_remaining']=$baseAfter;
    $out['compatibility_views']=$viewAfter;
    $out['verification']=$verification;
    $out['message']='Lean schema applied. OJTrack now uses 40 base tables; compatibility views store no duplicate rows.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,"Migration 010 failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
