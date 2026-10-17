<?php
// Pure scoring checks against the transcribed sheet; no database access.
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../config/normalized_evaluations.php';
$sheet=json_decode(file_get_contents(__DIR__.'/../database/bsit-ojt-evaluation.json'),true,512,JSON_THROW_ON_ERROR);
$criteria=[]; $scores=[];
foreach($sheet['sections'] as $section) foreach($section['criteria'] as $criterion) {
    $id=count($criteria)+1;
    $criteria[]=['id'=>$id,'section_title'=>$section['title'],'label'=>$criterion['label'],'weight'=>$criterion['points']];
    $scores['criterion_'.$id]=$criterion['points'];
}
$rules=[['score_min'=>91,'score_max'=>100,'equivalent'=>null,'description'=>'PASSED'],['score_min'=>0,'score_max'=>90.99,'equivalent'=>null,'description'=>'NEEDS IMPROVEMENT']];
$checks=0;
function bsit_check($actual,$expected,string $label) {
    global $checks;
    if ($actual!==$expected) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
bsit_check(array_sum(array_column($criteria,'weight')),100,'sheet totals 100 points');
bsit_check(array_map(fn($s)=>array_sum(array_column($s['criteria'],'points')),$sheet['sections']),[15,45,20,10,5,5],'printed category totals preserved');
bsit_check(count($criteria),17,'all criteria represented without invented point splits');
$result=normalized_eval_calculate_scores($criteria,$scores,'points',$rules);
bsit_check($result['overall'],100.0,'perfect score adds to 100');
bsit_check($result['result'],'PASSED','perfect score passes');
$scores['criterion_6']=1; // Ten-point item loses nine points.
$result=normalized_eval_calculate_scores($criteria,$scores,'points',$rules);
bsit_check($result['overall'],91.0,'91-point boundary');
bsit_check($result['result'],'PASSED','91 is passed');
$scores['criterion_6']=0;
$result=normalized_eval_calculate_scores($criteria,$scores,'points',$rules);
bsit_check($result['overall'],90.0,'90-point boundary');
bsit_check($result['result'],'NEEDS IMPROVEMENT','below 91 needs improvement');
$zero=array_fill_keys(array_keys($scores),0);
bsit_check(normalized_eval_calculate_scores($criteria,$zero,'points',$rules)['overall'],0.0,'zero is a valid score');
foreach (['',null,-1,6,2.5,'5abc',[]] as $bad) {
    $invalid=$scores; $invalid['criterion_1']=$bad;
    try { normalized_eval_calculate_scores($criteria,$invalid,'points',$rules); throw new RuntimeException('Invalid score accepted'); }
    catch (DomainException $error) { bsit_check(true,true,'invalid or excessive points rejected'); }
}
$old=[['id'=>1,'section_title'=>'Legacy','label'=>'A','weight'=>1],['id'=>2,'section_title'=>'Legacy','label'=>'B','weight'=>1]];
$legacy=normalized_eval_calculate_scores($old,['criterion_1'=>80,'criterion_2'=>90],'percentage',[['score_min'=>85,'score_max'=>100,'equivalent'=>1.25,'description'=>'Good']]);
bsit_check($legacy['overall'],85.0,'existing percentage arithmetic mean unchanged');
bsit_check($legacy['equivalent'],1.25,'existing numeric equivalent unchanged');
$old[0]['weight']=5; $old[1]['weight']=10;
bsit_check(normalized_eval_calculate_scores($old,['criterion_1'=>0,'criterion_2'=>100],'percentage')['overall'],50.0,'legacy percentage forms do not change to weighted scoring');
echo "$checks BSIT evaluation scoring checks passed.\n";
