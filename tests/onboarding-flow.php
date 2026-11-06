<?php
// Exercise the real page controller with synthetic in-memory records only.
if (PHP_SAPI !== 'cli') exit;
class OnboardingTestRedirect extends RuntimeException {}
function require_login($roles) {}
function current_user() { return ['id'=>1, 'name'=>'Synthetic Student']; }
function normalized_student_context_by_user($uid) { return ['id'=>1,'student_id_no'=>'SYNTHETIC','program_id'=>1,'program'=>'BSIT']; }
function normalized_requirement_rows_for_student($sid) { return $GLOBALS['test_rows']; }
function normalized_requirement_get($id,$sid) { foreach ($GLOBALS['test_rows'] as $row) if ($row['id']===$id) return $row; return null; }
function student_onboarding_complete($sid) {
    foreach ($GLOBALS['test_rows'] as $row) if ($row['status']!=='approved' || $row['assignment_status']!=='closed') return false;
    return count($GLOBALS['test_rows'])>0;
}
function redirect($path) { throw new OnboardingTestRedirect($path); }
function store_private_upload($tmp,$category,$filename) { $GLOBALS['saved']++; return true; }
function normalized_requirement_submit($id,$sid,$uid,$key,$name) {
    $GLOBALS['submitted_ids'][]=$id;
    foreach ($GLOBALS['test_rows'] as &$row) if ($row['id']===$id) $row['submitted_at']='2026-10-11 07:00:00';
}
function normalized_enrollment_for_student($sid) { return null; }
function log_activity($uid,$action,$details) {}
function create_notification(...$args) { throw new RuntimeException('No coordinator in fixture.'); }
$source=file_get_contents(__DIR__.'/../student/onboarding.php');
$controller=substr($source,5,strpos($source,'?>')-5);
$controller=preg_replace("~require_once __DIR__ \. '/../config/(?:db|auth)\.php';~", '', $controller);
$controller=str_replace("define('OJTRACK', true);", '', $controller);
function run_onboarding_case(string $method,array $rows,array $post=[],array $files=[],array $session=[]): array {
    global $controller;
    $GLOBALS['test_rows']=$rows; $GLOBALS['saved']=0; $GLOBALS['submitted_ids']=[];
    $_SERVER['REQUEST_METHOD']=$method; $_POST=$post; $_FILES=$files; $_SESSION=$session;
    http_response_code(200);
    $redirected=null;
    try { eval($controller); } catch (OnboardingTestRedirect $redirect) { $redirected=$redirect->getMessage(); }
    return ['ready'=>$ready ?? null,'error'=>$error ?? null,'success'=>$success ?? null,'redirect'=>$redirected,'code'=>http_response_code(),'session'=>$_SESSION,'saved'=>$GLOBALS['saved'],'ids'=>$GLOBALS['submitted_ids']];
}
$checks=0;
function onboarding_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
$pending=['id'=>1,'status'=>'pending','assignment_status'=>'open','document_name'=>'Synthetic document','submitted_at'=>null,'deadline'=>null];
$approved=array_replace($pending,['status'=>'approved','assignment_status'=>'closed','submitted_at'=>'2026-10-10 10:00:00']);
$r=run_onboarding_case('POST',[$approved],['action'=>'upload_all']);
onboarding_check($r['redirect']==='student/onboarding.php' && $r['code']===303 && $r['saved']===0 && empty($r['error']), 'completed empty POST is harmless');
$r=run_onboarding_case('GET',[$approved],[],[],['onboarding_feedback'=>['error'=>'Please attach at least one document before submitting.']]);
onboarding_check($r['ready']===true && $r['error']==='' && !isset($r['session']['onboarding_feedback']), 'completed GET clears stale upload feedback');
$r=run_onboarding_case('POST',[$pending],['action'=>'upload_all']);
onboarding_check($r['code']===303 && $r['session']['onboarding_feedback']['error']!=='' && $r['saved']===0, 'incomplete empty POST preserves validation across redirect');
$flash=$r['session']; $r=run_onboarding_case('GET',[$pending],[],[],$flash);
onboarding_check($r['ready']===false && $r['error']!=='' && !isset($r['session']['onboarding_feedback']), 'feedback consumed once');
$r=run_onboarding_case('GET',[$pending]);
onboarding_check($r['error']==='' && !$r['ready'], 'refresh clears stale error and cannot upload');
$files=['documents'=>['name'=>[1=>'synthetic.pdf'],'error'=>[1=>UPLOAD_ERR_OK],'size'=>[1=>20],'tmp_name'=>[1=>'synthetic-only']]];
$r=run_onboarding_case('POST',[$pending],['action'=>'upload_all'],$files);
onboarding_check($r['code']===303 && $r['saved']===1 && $r['ids']===[1] && $r['session']['onboarding_feedback']['success']!=='' && $r['session']['onboarding_feedback']['error']==='', 'valid submission redirects with success');
$r=run_onboarding_case('GET',$GLOBALS['test_rows'],[],[],$r['session']);
onboarding_check($r['saved']===0 && !$r['ready'] && $r['success']!=='' && $r['error']==='', 'submitted document waits for approval and GET does not resubmit');
$r=run_onboarding_case('POST',[$approved],['action'=>'upload_all'],$files);
onboarding_check($r['saved']===0 && $r['ids']===[] && $r['code']===303, 'stale completed form never replaces an approved file');
$r=run_onboarding_case('GET',[]);
onboarding_check(!$r['ready'], 'no assigned requirements cannot complete setup');
$inconsistent=array_replace($approved,['assignment_status'=>'open']);
$r=run_onboarding_case('GET',[$inconsistent]);
onboarding_check(!$r['ready'], 'completion card follows server completion rules');
echo json_encode(['onboarding_flow_checks_passed'=>$checks,'database_access'=>false,'result'=>'PASS']).PHP_EOL;
