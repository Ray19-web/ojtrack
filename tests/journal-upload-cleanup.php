<?php
// Real journal POST controller, temporary files and in-memory persistence only.
if (PHP_SAPI !== 'cli') exit;
function require_login($roles) {}
function current_user() { return ['id'=>1]; }
function normalized_student_context_by_user($id) { return ['id'=>1,'ojt_start_date'=>'2026-01-01','ojt_end_date'=>'2026-12-31']; }
function request_error($code,$message) { throw new DomainException($message); }
function normalized_journal_get($id,$sid) { return ['status'=>'pending']; }
function store_private_upload($source,$category,$filename) {
    $GLOBALS['stores']++;
    if ($GLOBALS['fail_store'] === $GLOBALS['stores']) throw new DomainException('Injected storage rejection');
    return copy($source,$GLOBALS['fixture_root'].DIRECTORY_SEPARATOR.$filename);
}
function resolve_private_document($key) { $path=$GLOBALS['fixture_root'].DIRECTORY_SEPARATOR.basename($key); return is_file($path) ? $path : null; }
function normalized_journal_save(...$args) { if ($GLOBALS['fail_save']) throw new DomainException('Injected save rejection'); return 1; }
function log_activity(...$args) {}
$source=file_get_contents(__DIR__.'/../student/journal.php');
$controller=substr($source,5,strpos($source,'$entries = normalized_journal_rows_for_student')-5);
$controller=preg_replace("~require_once __DIR__ \. '/../config/(?:db|auth)\.php';~",'',$controller);
$controller=str_replace("define('OJTRACK', true);",'',$controller);
$GLOBALS['fixture_root']=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ojtrack-journal-cleanup-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['fixture_root'],0700);
$original=tempnam(sys_get_temp_dir(),'ojt-proof-'); file_put_contents($original,'synthetic file');
$checks=0;
function cleanup_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function journal_case($fields=[],$failStore=0,$failSave=false) {
    global $controller,$original;
    foreach (glob($GLOBALS['fixture_root'].DIRECTORY_SEPARATOR.'*') ?: [] as $file) unlink($file);
    $GLOBALS['stores']=0;$GLOBALS['fail_store']=$failStore;$GLOBALS['fail_save']=$failSave;
    $_SERVER['REQUEST_METHOD']='POST';$_GET=[];
    $_POST=array_replace(['action'=>'submit_journal','entry_date'=>'2026-10-01','hours_rendered'=>8,'activities'=>'Tasks','learnings'=>'Skills','challenges'=>'Resolved'],$fields);
    $_FILES=['proof_image'=>['name'=>['a.jpg','b.jpg','c.jpg'],'tmp_name'=>[$original,$original,$original],'error'=>[0,0,0],'size'=>[14,14,14],'type'=>['image/jpeg','image/jpeg','image/jpeg']]];
    eval($controller);
    return ['error'=>$error,'success'=>$success,'files'=>glob($GLOBALS['fixture_root'].DIRECTORY_SEPARATOR.'*') ?: []];
}
try {
    $r=journal_case(['activities'=>'']); cleanup_check($GLOBALS['stores']===0 && !$r['files'],'Required fields checked before storage');
    $r=journal_case([],2); cleanup_check($r['error']!=='' && !$r['files'],'Later upload rejection removes prior new uploads');
    $r=journal_case([],0,true); cleanup_check($r['error']!=='' && !$r['files'],'Save rejection removes all new uploads');
    $r=journal_case(); cleanup_check($r['success']!=='' && count($r['files'])===3,'Successful journal retains its three images');
} finally {
    foreach (glob($GLOBALS['fixture_root'].DIRECTORY_SEPARATOR.'*') ?: [] as $file) unlink($file);
    rmdir($GLOBALS['fixture_root']);unlink($original);
}
echo "$checks journal upload cleanup checks passed.\n";
