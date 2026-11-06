<?php
/**
 * Optional post-migration cleanup.
 *
 * Removes ONLY migration/legacy artifacts after Phase 9 has been applied.
 * Keeps the 48 active normalized OJTrack tables, including schema_migrations.
 *
 * Usage:
 * php bin/cleanup-normalized-migration-artifacts.php --dry-run
 * php bin/cleanup-normalized-migration-artifacts.php --apply
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

function cleanup_exists(string $table): bool {
    return (bool)query_one(
        "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1",
        [$table],'s'
    );
}
function cleanup_id(string $name): string {
    $tick=chr(96);
    return $tick.str_replace($tick,$tick.$tick,$name).$tick;
}

$activeTables=[
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

$artifactTables=[
    // Drop relationship/migration tables first.
    'legacy_attendance_resolutions',
    'legacy_requirement_migration_map',
    'legacy_requirement_template_migration_map',
    'legacy_report_migration_map',
    'legacy_evaluation_form_migration_map',
    'legacy_eval_submission_migration_map',
    'legacy_fixed_evaluation_migration_map',
    'legacy_certificate_template_migration_map',
    'legacy_announcement_migration_map',

    // Then the quarantined legacy workflow tables.
    'legacy_retired_attendance',
    'legacy_retired_journal_entries',
    'legacy_retired_requirement_templates',
    'legacy_retired_ojt_requirements',
    'legacy_retired_reports',
    'legacy_retired_evaluations',

    // Legacy evaluation tables must be dropped child-first because they
    // still retain their original foreign-key relationships after rename.
    'legacy_retired_eval_answers',
    'legacy_retired_eval_submissions',
    'legacy_retired_eval_criteria',
    'legacy_retired_eval_sections',
    'legacy_retired_eval_rating_rules',
    'legacy_retired_evaluation_assignments',
    'legacy_retired_evaluation_forms',

    'legacy_retired_announcements'
];

$oldNames=[
    'attendance','journal_entries','requirement_templates','ojt_requirements','reports','evaluations',
    'evaluation_assignments','evaluation_forms','eval_sections','eval_criteria','eval_rating_rules',
    'eval_submissions','eval_answers','announcements'
];

$problems=[];
$missingActive=[];
foreach ($activeTables as $table) if (!cleanup_exists($table)) $missingActive[]=$table;
if ($missingActive) $problems['missing_active_normalized_tables']=count($missingActive);

$phase9=(bool)query_one("SELECT 1 FROM schema_migrations WHERE version='009_legacy_retirement' LIMIT 1");
if (!$phase9) $problems['phase9_not_applied']=1;

$restoredLegacy=[];
foreach ($oldNames as $table) if (cleanup_exists($table)) $restoredLegacy[]=$table;
if ($restoredLegacy) $problems['legacy_source_names_still_active']=count($restoredLegacy);

$missingArtifacts=[];
foreach ($artifactTables as $table) if (!cleanup_exists($table)) $missingArtifacts[]=$table;
if ($missingArtifacts) $problems['expected_cleanup_tables_missing']=count($missingArtifacts);

$currentCount=(int)(query_one(
    "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE()"
)['c'] ?? 0);

if (!$missingActive && !$restoredLegacy && count($missingArtifacts)===count($artifactTables) && $phase9 && $currentCount===count($activeTables)) {
    echo json_encode([
        'cleanup'=>'normalized_migration_artifacts',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'ALREADY_CLEAN',
        'tables_remaining'=>$currentCount,
        'active_tables_preserved'=>true
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$out=[
    'cleanup'=>'normalized_migration_artifacts',
    'mode'=>$dryRun?'dry-run':'apply',
    'current_table_count'=>$currentCount,
    'active_tables_to_keep'=>count($activeTables),
    'artifact_tables_to_remove'=>count($artifactTables),
    'expected_table_count_after_cleanup'=>count($activeTables),
    'preflight_problems'=>$problems
];
if ($missingActive) $out['missing_active_tables']=$missingActive;
if ($restoredLegacy) $out['legacy_source_names_still_active']=$restoredLegacy;
if ($missingArtifacts) $out['missing_cleanup_tables']=$missingArtifacts;

if ($problems) {
    $out['result']='BLOCKED';
    $out['message']='Nothing was deleted. Phase 9 must be applied and all 48 normalized tables must exist before cleanup.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $out['result']='READY';
    $out['tables_to_remove']=$artifactTables;
    $out['message']='Preflight passed. --apply will permanently drop only the 23 legacy/migration artifact tables. Keep an external database backup.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

foreach ($artifactTables as $table) {
    if (!$conn->query("DROP TABLE ".cleanup_id($table))) {
        fwrite(STDERR,"Cleanup failed while dropping $table: ".$conn->error.PHP_EOL);
        exit(1);
    }
}

$remainingArtifacts=[];
foreach ($artifactTables as $table) if (cleanup_exists($table)) $remainingArtifacts[]=$table;
$missingAfter=[];
foreach ($activeTables as $table) if (!cleanup_exists($table)) $missingAfter[]=$table;
$afterCount=(int)(query_one(
    "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE()"
)['c'] ?? 0);

if ($remainingArtifacts || $missingAfter || $afterCount!==count($activeTables)) {
    fwrite(STDERR,"Cleanup verification failed. Active table count/state is not what was expected.\n");
    exit(1);
}

$out['result']='PASS';
$out['tables_removed']=count($artifactTables);
$out['tables_remaining']=$afterCount;
$out['active_tables_preserved']=true;
$out['schema_migrations_preserved']=true;
$out['message']='Cleanup complete. Only the 48 active normalized OJTrack tables remain.';
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
