<?php
// PDF fixtures and structural regression checks. No database or real user data.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../config/normalized_evaluations.php';
require_once __DIR__.'/../includes/evaluation-pdf.php';
$student=['name'=>'SYNTHETIC STUDENT','student_id_no'=>'000007','program'=>'BSIT','required_hours'=>486];
$rules=[['score_min'=>91,'score_max'=>100,'description'=>'PASSED','equivalent'=>null],['score_min'=>0,'score_max'=>90,'description'=>'NEEDS IMPROVEMENT','equivalent'=>null]];
$answers=[];
foreach (json_decode(file_get_contents(__DIR__.'/../database/bsit-ojt-evaluation.json'), true)['sections'] as $section) {
    foreach ($section['criteria'] as $criterion) $answers[]=['section_title'=>$section['title'],'criterion_label'=>$criterion['label'],'score'=>$criterion['points'],'maximum_points'=>$criterion['points'],'equivalent'=>null];
}
$answers[0]['score']=1; // The synthetic scores now sum to the recorded overall 96.
$record=['form_title'=>'USTP OJT EVALUATION SHEET (BSIT)','status'=>'completed','overall_score'=>96,'overall_equivalent'=>null,'score_mode'=>'points','company_name'=>'Example Training Company','evaluator_name'=>'Synthetic Supervisor','evaluated_at'=>'2026-11-01','comments'=>'Shows initiative, communicates clearly, and applies feedback.','answers'=>$answers,'rules'=>$rules];
$pdf=student_evaluations_pdf($student,[$record]);
$checks=0;
$check=static function($value,$label) use (&$checks) { if (!$value) throw new RuntimeException('FAIL: '.$label); $checks++; };
$check(str_starts_with($pdf,'%PDF-1.4') && str_ends_with($pdf,'%%EOF'),'PDF envelope');
$check(str_contains($pdf,'5 / 5') && str_contains($pdf,'96.00 / 100'),'Actual point maxima');
$check(str_contains($pdf,'PASSED') && str_contains($pdf,'Ability to write reports'),'Saved answers and rating descriptions');
$check(!str_contains($pdf,'OJTRACK - Page'),'No unwanted footer');
$check(str_contains($pdf,'FM-USTP-ReO-014') && str_contains($pdf,'Perfect Score') && str_contains($pdf,"Trainee's Signature") && str_contains($pdf,"Trainer's Signature"),'Paper form header, columns, and blank signature fields');
$check(str_contains($pdf,'486 hours') && str_contains($pdf,'GROOMING AND APPEARANCE'),'Full six-section form and actual training hours');
$list=student_evaluations_pdf($student,[$record,array_replace($record,['status'=>'pending','answers'=>[],'comments'=>'PRIVATE PENDING COMMENT'])]);
$check(str_contains($list,'Evaluation overview') && str_contains($list,'Pending'),'List export includes pending overview');
$check(!str_contains($list,'PRIVATE PENDING COMMENT'),'Pending details are excluded');
$percentage=array_replace($record,['score_mode'=>'percentage','overall_score'=>87,'answers'=>[array_replace($answers[0],['score'=>87])]]);
$check(str_contains(student_evaluations_pdf($student,[$percentage]),'87.00%'),'Percentage form');
$missing=array_replace($record,['overall_score'=>null,'answers'=>[array_replace($answers[0],['score'=>null])]]);
$check(str_contains(student_evaluations_pdf($student,[$missing]),'Not recorded'),'Missing scores remain unavailable');
$stress=$record;
$stress['answers'][]=array_replace($answers[0],['criterion_label'=>str_repeat('Long question with careful workplace communication and review. ',100),'score'=>0]);
$stress['comments']=str_repeat('Long feedback remains readable across page breaks. ',150);
$long=student_evaluations_pdf($student,[$stress]);
preg_match('~/Type /Pages /Kids \[.*?\] /Count (\d+)~s',$long,$count);
$check((int)($count[1] ?? 0)>2,'Long rows and comments paginate');
preg_match('/startxref\n(\d+)\n%%EOF$/',$long,$xref);
$check(substr($long,(int)$xref[1],4)==='xref','Cross-reference offset');
if (!empty($argv[1])) {
    if (!is_dir($argv[1])) throw new RuntimeException('Fixture output directory must already exist.');
    foreach (['evaluation'=>$pdf,'evaluations'=>$list,'evaluation-long'=>$long] as $name=>$bytes) file_put_contents($argv[1].DIRECTORY_SEPARATOR.$name.'.pdf',$bytes);
}
echo "$checks evaluation PDF checks passed.\n";
