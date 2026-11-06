<?php
// Synthetic controller and result rendering checks. No database or account writes.
if (PHP_SAPI !== 'cli') exit;
define('OJTRACK', true);
require_once __DIR__.'/../config/normalized_evaluations.php';
function require_login($roles) { if ($roles !== ['student']) throw new RuntimeException('Wrong role boundary'); }
function current_user() { return ['id'=>42]; }
function normalized_student_context_by_user($id) { return ['id'=>7]; }
function normalized_enrollment_for_student($id) { return ['id'=>7]; }
function request_error($code, $message) { throw new DomainException((string)$code); }
function query($sql, $params=[], $types='') {
    if (str_contains($sql, 'FROM evaluation_requests er')) return $GLOBALS['fixture_requests'];
    $GLOBALS['fixture_reads'][] = $params[0] ?? null;
    return [];
}
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$source=file_get_contents(__DIR__.'/../student/evaluation.php');
$controller=substr($source,5,strpos($source,'if (isset($_GET[\'result\']))')-5);
$controller=preg_replace("~require_once __DIR__ \. '/../config/(db|auth)\.php';~", '', $controller);
$controller=str_replace("define('OJTRACK', true);", '', $controller);
$checks=0;
function check($condition,$label) { global $checks; if (!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; }
function select_evaluation($id) {
    global $controller;
    $_GET=['evaluation'=>$id];
    $GLOBALS['fixture_reads']=[];
    eval($controller);
    return $selectedEvaluation;
}
$GLOBALS['fixture_requests']=[['id'=>11,'status'=>'completed','form_id'=>100],['id'=>12,'status'=>'pending','form_id'=>100]];
check(select_evaluation(11)['id']===11,'Own completed evaluation is available');
check($GLOBALS['fixture_reads']===[11,100],'Only selected answers and rules are fetched');
foreach ([999=>404,12=>409] as $id=>$expected) {
    try { select_evaluation($id); check(false,'Unavailable result rejected'); }
    catch (DomainException $error) { check($error->getMessage()===(string)$expected,'Reject foreign or incomplete evaluation'); }
    check($GLOBALS['fixture_reads']===[],'Unavailable request cannot read answer data');
}
$student=['name'=>'Synthetic <Student>','student_id_no'=>'0007','program'=>'BSIT'];
$selectedEvaluation=['form_title'=>'Actual assigned form','score_mode'=>'points','overall_score'=>96,'overall_equivalent'=>null,'company_name'=>'Example Company','evaluator_name'=>'Supervisor','evaluated_at'=>'2026-10-23','comments'=>'Good <work>'];
$rules=[['score_min'=>91,'score_max'=>100,'description'=>'PASSED','equivalent'=>null]];
$answers=[['section_title'=>'Custom Skills (15 points)','criterion_label'=>'Actual saved question','score'=>5,'maximum_points'=>5,'equivalent'=>null]];
function render_result(): string {
    global $student,$selectedEvaluation,$answers,$rules;
    ob_start(); require __DIR__.'/../includes/student-evaluation-result.php'; return ob_get_clean();
}
$html=render_result();
check(str_contains($html,'Actual saved question') && str_contains($html,'Custom Skills (15 points)'),'Render saved labels and sections');
check(str_contains($html,'5 / 5') && str_contains($html,'96.00 / 100'),'Point scores use their actual maxima');
check(str_contains($html,'PASSED'),'Rating rules are applied');
check(str_contains($html,'Synthetic &lt;Student&gt;') && str_contains($html,'Good &lt;work&gt;'),'Escape student and feedback text');
$answers[0]['score']=null;
$selectedEvaluation['overall_score']=null;
$html=render_result();
check(str_contains($html,'Not recorded') && !str_contains($html,'0.00 / 100') && !str_contains($html,'PASSED'),'Missing scores are not displayed as zero or passed');
$selectedEvaluation['score_mode']='percentage'; $selectedEvaluation['overall_score']=95;
$answers[0]['score']=95;
$html=render_result();
check(str_contains($html,'95 / 100') && str_contains($html,'95.00%'),'Percentage forms retain percentage scores');
$answers=[];
check(str_contains(render_result(),'No saved question scores'),'Explain missing answer data');
echo "$checks student evaluation checks passed.\n";
