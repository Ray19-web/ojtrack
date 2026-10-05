<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not available over HTTP.\n"); }
$options=getopt('', ['apply','dry-run']);
$apply=array_key_exists('apply',$options);
require __DIR__ . '/../config/db.php';

function rb9_exists(string $table): bool {
    return (bool)query_one("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1",[$table],'s');
}
function rb9_id(string $name): string {
    $tick=chr(96);
    return $tick.str_replace($tick,$tick.$tick,$name).$tick;
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

$missing=[]; $existing=[];
foreach ($retire as $source=>$target) {
    if (!rb9_exists($target)) $missing[]=$target;
    if (rb9_exists($source)) $existing[]=$source;
}
$recorded=(bool)query_one("SELECT 1 FROM schema_migrations WHERE version='009_legacy_retirement' LIMIT 1");
$problems=[];
if (!$recorded) $problems['retirement_checkpoint_missing']=1;
if ($missing) $problems['missing_retired_tables']=count($missing);
if ($existing) $problems['legacy_source_names_already_exist']=count($existing);

$out=['rollback'=>'009_legacy_retirement','mode'=>$apply?'apply':'dry-run','preflight_problems'=>$problems];
if ($missing) $out['missing_retired_tables']=$missing;
if ($existing) $out['existing_source_tables']=$existing;

if ($problems) {
    $out['result']='BLOCKED';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}
if (!$apply) {
    $out['result']='READY';
    $out['message']='Re-run with --apply to restore legacy table names. Normalized writes are not reverted.';
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$parts=[];
foreach ($retire as $source=>$target) $parts[]=rb9_id($target).' TO '.rb9_id($source);
if (!$conn->query("RENAME TABLE ".implode(', ',$parts))) {
    fwrite(STDERR,"Rollback rename failed: ".$conn->error.PHP_EOL);
    exit(1);
}
execute("DELETE FROM schema_migrations WHERE version='009_legacy_retirement'");
$out['result']='PASS';
$out['message']='Legacy table names restored. Normalized application cutover remains in place.';
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
