<?php
// Real submission workflow against temporary tables, including synthetic notifications.
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../config/db.php';
foreach(['users','students','companies','ojt_enrollments','placements','evaluation_definitions',
 'evaluation_definition_versions','evaluation_version_sections','evaluation_version_criteria',
 'evaluation_version_rating_rules','evaluation_requests','evaluation_submissions','evaluation_answers',
 'notifications','activity_log'] as $table) {
    $ddl=query_one("SHOW CREATE TABLE $table")['Create Table'];
    $ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*\n?/m','',$ddl);
    $ddl=preg_replace('/,\s*\)/',')',$ddl);
    query($ddl);
}
query("CREATE TEMPORARY TABLE company_users (id INT,company_id INT,user_id INT,status VARCHAR(20),is_primary INT)");
query("INSERT INTO users(id,name,email,password,role,status) VALUES (1,'Student','student@example.invalid','test','student','active'),(2,'Company','company@example.invalid','test','company','active'),(3,'Coordinator','coord@example.invalid','test','coordinator','active')");
query("INSERT INTO students(id,user_id,student_id_no,is_archived) VALUES (1,1,'POINTS-TEST',0)");
query("INSERT INTO companies(id,user_id,company_name,status) VALUES (1,2,'Test Company','active')");
query("INSERT INTO company_users VALUES (1,1,2,'active',1)");
query("INSERT INTO ojt_enrollments(id,student_id,academic_term_id,program_id,required_hours,status) VALUES (1,1,1,1,486,'ongoing')");
query("INSERT INTO placements(id,ojt_enrollment_id,company_id,status) VALUES (1,1,1,'active')");
$sheet=json_decode(file_get_contents(__DIR__.'/../database/bsit-ojt-evaluation.json'),true,512,JSON_THROW_ON_ERROR);
$version=normalized_eval_create_form(3,'Synthetic point sheet','Test only','points');
$scores=[];
foreach($sheet['sections'] as $section) {
    $sectionId=normalized_eval_add_section($version,3,$section['title']);
    foreach($section['criteria'] as $criterion) {
        $criterionId=normalized_eval_add_criterion($version,3,$sectionId,$criterion['label'],$criterion['points']);
        $scores['criterion_'.$criterionId]=$criterion['points'];
        if ($criterion['label']==='Works at desirable speed') $deductId=$criterionId;
    }
}
foreach([[91,100,'PASSED'],[0,90.99,'NEEDS IMPROVEMENT']] as [$min,$max,$label]) {
    insert('INSERT INTO evaluation_version_rating_rules(evaluation_definition_version_id,score_min,score_max,equivalent,description) VALUES(?,?,?,NULL,?)',[$version,$min,$max,$label],'idds');
}
normalized_eval_publish($version,3);
$request=insert("INSERT INTO evaluation_requests(evaluation_definition_version_id,placement_id,evaluator_company_user_id,requested_by,evaluation_kind,status) VALUES(?,1,1,3,'custom','pending')",[$version],'i');
$checks=0;
function point_submission_check($actual,$expected,$label) {
    global $checks;
    if ($actual!==$expected) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
$bad=$scores; $bad['criterion_'.$deductId]=11;
try { normalized_submit_evaluation(1,$request,$bad,'Test',2); throw new RuntimeException('Excessive score accepted'); }
catch(DomainException $error) { point_submission_check((int)query_one('SELECT COUNT(*) n FROM evaluation_submissions')['n'],0,'invalid score creates no submission'); }
point_submission_check((int)query_one('SELECT COUNT(*) n FROM notifications')['n'],0,'invalid score sends no notifications');
$scores['criterion_'.$deductId]=1;
normalized_submit_evaluation(1,$request,$scores,'Synthetic feedback',2);
$submission=query_one('SELECT * FROM evaluation_submissions WHERE evaluation_request_id=?',[$request],'i');
point_submission_check((float)$submission['overall_score'],91.0,'stored score is point sum, not an average');
point_submission_check(query_one('SELECT status FROM evaluation_requests WHERE id=?',[$request],'i')['status'],'submitted','request completed');
$answers=normalized_eval_answers_for_request($request);
point_submission_check(count($answers),17,'all scored entries saved');
point_submission_check(array_sum(array_map(fn($answer)=>(float)$answer['score'],$answers)),91.0,'raw awarded points preserved');
point_submission_check(array_sum(array_map(fn($answer)=>(float)$answer['maximum_points'],$answers)),100.0,'answer maxima remain available for display');
point_submission_check(normalized_eval_score_rule(91,normalized_eval_rules($version),'points')['description'],'PASSED','company result shows correct pass boundary');
point_submission_check((int)query_one('SELECT COUNT(*) n FROM notifications')['n'],2,'only synthetic student and coordinator notified');
normalized_submit_evaluation(1,$request,$scores,'Duplicate',2);
point_submission_check((int)query_one('SELECT COUNT(*) n FROM evaluation_submissions')['n'],1,'resubmission does not duplicate result');
// Render the actual company form markup with the synthetic version.
function csrf_field() { return '<input type="hidden" name="csrf_token" value="synthetic-only">'; }
$source=file_get_contents(__DIR__.'/../company/evaluation.php');
$start=strpos($source,'  <form method="POST">');
$end=strpos($source,'</form>',$start)+strlen('</form>');
$sub=['id'=>$request,'score_mode'=>'points'];
$sub_sections=normalized_eval_sections($version); $sub_rules=normalized_eval_rules($version); $_POST=[];
ob_start(); eval('?>'.substr($source,$start,$end-$start)); $html=ob_get_clean();
$document=new DOMDocument(); @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html); $xpath=new DOMXPath($document);
$limits=[];
foreach($xpath->query('//input[@type="number"]') as $input) $limits[]=(int)$input->getAttribute('max');
point_submission_check(count($limits),17,'company form renders every scored entry');
point_submission_check(array_sum($limits),100,'company input maxima total 100');
point_submission_check(count(array_filter($limits,fn($limit)=>$limit===10)),3,'ten-point criteria remain ten-point inputs');
point_submission_check(str_contains($html,'Add the awarded points for a total out of 100.'),true,'company instructions describe point sum');
point_submission_check(str_contains($html,'PASSED') && str_contains($html,'NEEDS IMPROVEMENT'),true,'company form displays both outcome labels');
echo "$checks point-based evaluation submission checks passed.\n";
