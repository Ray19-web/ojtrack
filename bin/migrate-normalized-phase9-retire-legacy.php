<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }

$options=getopt('', ['academic-year:','semester:','apply','dry-run']);
$academicYear=trim((string)($options['academic-year'] ?? ''));
$semesterInput=strtolower(trim((string)($options['semester'] ?? '')));
$apply=array_key_exists('apply',$options);
$dryRun=array_key_exists('dry-run',$options) || !$apply;

if ($apply && array_key_exists('dry-run',$options)) { fwrite(STDERR,"Choose either --apply or --dry-run, not both.\n"); exit(2); }
if (!preg_match('/^(\d{4})-(\d{4})$/',$academicYear,$m)) { fwrite(STDERR,"Use --academic-year=YYYY-YYYY.\n"); exit(2); }
$yearStart=(int)$m[1]; $yearEnd=(int)$m[2];
if ($yearEnd!==$yearStart+1) { fwrite(STDERR,"Academic year end must be exactly one year after the start.\n"); exit(2); }
$aliases=['1'=>'1st','1st'=>'1st','first'=>'1st','2'=>'2nd','2nd'=>'2nd','second'=>'2nd','summer'=>'summer'];
if (!isset($aliases[$semesterInput])) { fwrite(STDERR,"Use --semester=1st, --semester=2nd, or --semester=summer.\n"); exit(2); }
$semester=$aliases[$semesterInput];

require __DIR__ . '/../config/db.php';

function p9_scalar(string $sql,array $params=[],string $types=''): int {
    $row=query_one($sql,$params,$types);
    if (!$row) return 0;
    return (int)reset($row);
}
function p9_exists(string $table): bool {
    return (bool)query_one("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1",[$table],'s');
}
function p9_id(string $name): string {
    $tick=chr(96);
    return $tick.str_replace($tick,$tick.$tick,$name).$tick;
}
function p9_source_refs(array $tables): array {
    $root=realpath(__DIR__.'/..');
    if (!$root) throw new RuntimeException('Repository root cannot be resolved.');
    $skip=['bin','tests','database','docs','.git','uploads','vendor','node_modules'];
    $hits=[];
    $filter=new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        function($current) use ($skip) {
            return !$current->isDir() || !in_array($current->getFilename(),$skip,true);
        }
    );
    $it=new RecursiveIteratorIterator($filter);
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension())!=='php') continue;
        $path=$file->getPathname();
        $text=file_get_contents($path);
        if ($text===false) continue;
        foreach ($tables as $table) {
            $q=preg_quote($table,'~');
            $pattern='~\b(?:FROM|JOIN|INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE\s+TABLE)\s+(?:\x60)?'.$q.'(?:\x60)?\b~i';
            if (!preg_match_all($pattern,$text,$matches,PREG_OFFSET_CAPTURE)) continue;
            foreach ($matches[0] as $match) {
                $line=substr_count(substr($text,0,$match[1]),"\n")+1;
                $relative=str_replace('\\','/',substr($path,strlen($root)+1));
                $hits[]=['file'=>$relative,'line'=>$line,'table'=>$table,'match'=>$match[0]];
            }
        }
    }
    return $hits;
}

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='008_application_cutover' LIMIT 1")) {
    echo json_encode(['migration'=>'009_legacy_retirement','mode'=>$dryRun?'dry-run':'apply','result'=>'BLOCKED','preflight_problems'=>['missing_required_migration'=>'008_application_cutover']],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='009_legacy_retirement' LIMIT 1");
if ($already) {
    echo json_encode(['migration'=>'009_legacy_retirement','mode'=>$dryRun?'dry-run':'apply','result'=>'ALREADY_APPLIED','applied_at'=>$already['applied_at']],JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}
$term=query_one("SELECT id FROM academic_terms WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",[$yearStart,$yearEnd,$semester],'iis');
if (!$term) {
    echo json_encode(['migration'=>'009_legacy_retirement','mode'=>$dryRun?'dry-run':'apply','result'=>'BLOCKED','preflight_problems'=>['academic_term_not_found'=>1]],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$termId=(int)$term['id'];

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

$missing=[]; $targets=[]; $counts=[];
foreach ($retire as $source=>$target) {
    if (!p9_exists($source)) $missing[]=$source;
    if (p9_exists($target)) $targets[]=$target;
    if (p9_exists($source)) $counts[$source]=p9_scalar("SELECT COUNT(*) FROM ".p9_id($source));
}
$refs=p9_source_refs(array_keys($retire));

$verification=[
    'missing_legacy_source_tables'=>count($missing),
    'preexisting_retired_tables'=>count($targets),
    'live_php_legacy_sql_references'=>count($refs),
    'requirement_template_map_mismatch'=>p9_exists('requirement_templates') ? abs(p9_scalar("SELECT COUNT(*) FROM requirement_templates")-p9_scalar("SELECT COUNT(*) FROM legacy_requirement_template_migration_map")) : 0,
    'requirement_map_mismatch'=>p9_exists('ojt_requirements') ? abs(p9_scalar("SELECT COUNT(*) FROM ojt_requirements")-p9_scalar("SELECT COUNT(*) FROM legacy_requirement_migration_map")) : 0,
    'report_map_mismatch'=>p9_exists('reports') ? abs(p9_scalar("SELECT COUNT(*) FROM reports")-p9_scalar("SELECT COUNT(*) FROM legacy_report_migration_map")) : 0,
    'dynamic_form_map_mismatch'=>p9_exists('evaluation_forms') ? abs(p9_scalar("SELECT COUNT(*) FROM evaluation_forms")-p9_scalar("SELECT COUNT(*) FROM legacy_evaluation_form_migration_map")) : 0,
    'dynamic_request_map_mismatch'=>p9_exists('eval_submissions') ? abs(p9_scalar("SELECT COUNT(*) FROM eval_submissions")-p9_scalar("SELECT COUNT(*) FROM legacy_eval_submission_migration_map")) : 0,
    'fixed_evaluation_map_mismatch'=>p9_exists('evaluations') ? abs(p9_scalar("SELECT COUNT(*) FROM evaluations")-p9_scalar("SELECT COUNT(*) FROM legacy_fixed_evaluation_migration_map")) : 0,
    'announcement_map_mismatch'=>p9_exists('announcements') ? abs(p9_scalar("SELECT COUNT(*) FROM announcements")-p9_scalar("SELECT COUNT(*) FROM legacy_announcement_migration_map")) : 0,
    'orphan_requirement_submissions'=>p9_scalar("SELECT COUNT(*) FROM requirement_submissions rs LEFT JOIN requirement_assignments ra ON ra.id=rs.requirement_assignment_id WHERE ra.id IS NULL"),
    'orphan_report_submissions'=>p9_scalar("SELECT COUNT(*) FROM report_submissions rs LEFT JOIN report_assignments ra ON ra.id=rs.report_assignment_id WHERE ra.id IS NULL"),
    'orphan_evaluation_submissions'=>p9_scalar("SELECT COUNT(*) FROM evaluation_submissions es LEFT JOIN evaluation_requests er ON er.id=es.evaluation_request_id WHERE er.id IS NULL"),
    'orphan_evaluation_answers'=>p9_scalar("SELECT COUNT(*) FROM evaluation_answers ea LEFT JOIN evaluation_submissions es ON es.id=ea.evaluation_submission_id LEFT JOIN evaluation_version_criteria ec ON ec.id=ea.evaluation_criterion_id WHERE es.id IS NULL OR ec.id IS NULL")
];

$problems=[];
foreach ($verification as $key=>$value) if ($value!==0) $problems[$key]=$value;
$out=[
    'migration'=>'009_legacy_retirement',
    'mode'=>$dryRun?'dry-run':'apply',
    'retirement_mode'=>'reversible_table_quarantine',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy_table_counts'=>$counts,
    'verification'=>$verification,
    'preflight_problems'=>$problems
];
if ($missing) $out['missing_source_tables']=$missing;
if ($targets) $out['preexisting_retired_tables']=$targets;
if ($refs) $out['live_php_legacy_sql_references']=$refs;

if ($problems) {
    $out['result']='BLOCKED';
    $out['message']='Legacy tables were not renamed. Resolve every retirement preflight problem first.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
if ($dryRun) {
    $out['result']='READY';
    $out['message']='Preflight passed. --apply will rename legacy workflow tables to legacy_retired_*; no rows will be deleted.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$rename=[]; $rollback=[];
foreach ($retire as $source=>$target) {
    $rename[]=p9_id($source).' TO '.p9_id($target);
    $rollback[]=p9_id($target).' TO '.p9_id($source);
}
try {
    if (!$conn->query("RENAME TABLE ".implode(', ',$rename))) throw new RuntimeException('Legacy table quarantine failed: '.$conn->error);
    try {
        $version='009_legacy_retirement';
        $description='Reversibly quarantine retired legacy training and workflow tables after normalized application cutover';
        $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
        $stmt->bind_param('ss',$version,$description);
        $stmt->execute();
    } catch (Throwable $recordError) {
        $conn->query("RENAME TABLE ".implode(', ',$rollback));
        throw new RuntimeException('Checkpoint recording failed; table rename was rolled back: '.$recordError->getMessage());
    }

    $remaining=[]; $missingRetired=[];
    foreach ($retire as $source=>$target) {
        if (p9_exists($source)) $remaining[]=$source;
        if (!p9_exists($target)) $missingRetired[]=$target;
    }
    if ($remaining || $missingRetired) throw new RuntimeException('Post-retirement table-state verification failed.');

    $out['result']='PASS';
    $out['retired_tables']=array_values($retire);
    $out['rows_deleted']=0;
    $out['legacy_data_preserved']=true;
    $out['rollback_command']='C:\\xampp\\php\\php.exe bin\\rollback-normalized-phase9-retirement.php --apply';
    $out['message']='Legacy workflow tables were quarantined by rename only. No historical rows were deleted.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,"Migration 009 failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
