<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p6check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p6count(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    return (int)($r['n'] ?? 0);
}

p6check(p6count("SELECT COUNT(*) n FROM evaluation_forms")===4,'legacy dynamic forms preserved');
p6check(p6count("SELECT COUNT(*) n FROM eval_submissions")===2,'legacy dynamic requests preserved');
p6check(p6count("SELECT COUNT(*) n FROM eval_answers")===2,'legacy dynamic answers preserved');
p6check(p6count("SELECT COUNT(*) n FROM evaluations")===2,'legacy fixed evaluations preserved');
p6check(p6count("SELECT COUNT(*) n FROM schema_migrations WHERE version='006_evaluations'")===1,'phase 6 recorded');

p6check(p6count("SELECT COUNT(*) n FROM legacy_evaluation_form_migration_map")===4,'all dynamic forms mapped');
p6check(p6count("SELECT COUNT(*) n FROM legacy_eval_submission_migration_map")===2,'all dynamic requests mapped');
p6check(p6count("SELECT COUNT(*) n FROM legacy_fixed_evaluation_migration_map")===2,'all fixed evaluations mapped');

p6check(p6count("SELECT COUNT(*) n FROM evaluation_definitions")===5,'dynamic plus fixed definitions created');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_definition_versions")===5,'one version per migrated definition');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_version_sections")===5,'structured fallback and fixed sections created');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_version_criteria")===12,'all structured fallback and fixed criteria created');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_version_rating_rules")===2,'rating rules preserved');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_requests")===4,'all dynamic and fixed requests created');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_submissions")===2,'only completed evaluations create submissions');
p6check(p6count("SELECT COUNT(*) n FROM evaluation_answers")===8,'dynamic plus six fixed answers created');

$fallback=p6count(
    "SELECT COUNT(*) n
     FROM legacy_evaluation_form_migration_map m
     JOIN evaluation_version_sections s ON s.evaluation_definition_version_id=m.evaluation_definition_version_id
     JOIN evaluation_version_criteria c ON c.evaluation_version_section_id=s.id
     WHERE m.legacy_form_id=4 AND s.title='Criteria'
       AND c.label IN ('Communication','Initiative')"
);
p6check($fallback===2,'JSON-only legacy criteria preserved');

$dynamic=query_one(
    "SELECT er.status request_status,es.overall_score,es.overall_equivalent,es.comments,es.submitted_at
     FROM legacy_eval_submission_migration_map m
     JOIN evaluation_requests er ON er.id=m.evaluation_request_id
     JOIN evaluation_submissions es ON es.id=m.evaluation_submission_id
     WHERE m.legacy_submission_id=1"
);
p6check($dynamic && $dynamic['request_status']==='submitted','completed dynamic request marked submitted');
p6check($dynamic && abs((float)$dynamic['overall_score']-92.50)<0.001,'dynamic overall score preserved');
p6check($dynamic && abs((float)$dynamic['overall_equivalent']-1.25)<0.001,'dynamic equivalent preserved');
p6check($dynamic && $dynamic['comments']==='Strong synthetic performance','dynamic comments preserved');
p6check($dynamic && $dynamic['submitted_at']==='2026-09-15 10:00:00','dynamic submitted timestamp preserved');

$dynamicAnswers=query(
    "SELECT ea.section_title_snapshot,ea.criterion_label_snapshot,ea.score,ea.equivalent
     FROM legacy_eval_submission_migration_map m
     JOIN evaluation_answers ea ON ea.evaluation_submission_id=m.evaluation_submission_id
     WHERE m.legacy_submission_id=1
     ORDER BY ea.criterion_label_snapshot"
) ?: [];
p6check(count($dynamicAnswers)===2,'dynamic answer count preserved');
p6check($dynamicAnswers[0]['section_title_snapshot']==='Skills','dynamic section label snapshot preserved');
p6check(in_array($dynamicAnswers[0]['criterion_label_snapshot'],['Quality','Timeliness'],true),'dynamic criterion label snapshot preserved');

$pending=query_one(
    "SELECT er.status,m.evaluation_submission_id
     FROM legacy_eval_submission_migration_map m
     JOIN evaluation_requests er ON er.id=m.evaluation_request_id
     WHERE m.legacy_submission_id=2"
);
p6check($pending && $pending['status']==='pending','pending dynamic request stays pending');
p6check($pending && $pending['evaluation_submission_id']===null,'pending dynamic request gets no fake submission');

$fixed=query_one(
    "SELECT er.evaluation_kind,er.status request_status,es.submitted_by,es.overall_score,es.comments,es.submitted_at
     FROM legacy_fixed_evaluation_migration_map m
     JOIN evaluation_requests er ON er.id=m.evaluation_request_id
     JOIN evaluation_submissions es ON es.id=m.evaluation_submission_id
     WHERE m.legacy_evaluation_id=1"
);
p6check($fixed && $fixed['evaluation_kind']==='midterm','fixed evaluation kind preserved');
p6check($fixed && $fixed['request_status']==='submitted','completed fixed request marked submitted');
p6check($fixed && (int)$fixed['submitted_by']===3,'fixed evaluator user preserved');
p6check($fixed && abs((float)$fixed['overall_score']-88.83)<0.001,'fixed overall score preserved');
p6check($fixed && $fixed['comments']==='Legacy fixed completed','fixed comments preserved');
p6check($fixed && $fixed['submitted_at']==='2026-09-20 09:00:00','fixed evaluated timestamp preserved');

$fixedAnswers=query(
    "SELECT ea.criterion_label_snapshot,ea.score
     FROM legacy_fixed_evaluation_migration_map m
     JOIN evaluation_answers ea ON ea.evaluation_submission_id=m.evaluation_submission_id
     WHERE m.legacy_evaluation_id=1
     ORDER BY ea.id"
) ?: [];
p6check(count($fixedAnswers)===6,'six fixed criterion answers created');
p6check($fixedAnswers[0]['criterion_label_snapshot']==='Technical Skills' && abs((float)$fixedAnswers[0]['score']-88)<0.001,'fixed technical score preserved');
p6check($fixedAnswers[5]['criterion_label_snapshot']==='Adaptability' && abs((float)$fixedAnswers[5]['score']-91)<0.001,'fixed adaptability score preserved');

$fixedPending=query_one(
    "SELECT er.evaluation_kind,er.status,m.evaluation_submission_id
     FROM legacy_fixed_evaluation_migration_map m
     JOIN evaluation_requests er ON er.id=m.evaluation_request_id
     WHERE m.legacy_evaluation_id=2"
);
p6check($fixedPending && $fixedPending['evaluation_kind']==='final','pending fixed kind preserved');
p6check($fixedPending && $fixedPending['status']==='pending','pending fixed request stays pending');
p6check($fixedPending && $fixedPending['evaluation_submission_id']===null,'pending fixed gets no fake submission');

p6check(
    p6count("SELECT COUNT(*) n FROM eval_submissions WHERE id=1 AND status='completed' AND overall_score=92.50")===1,
    'legacy dynamic submission unchanged'
);
p6check(
    p6count("SELECT COUNT(*) n FROM evaluations WHERE id=1 AND status='completed' AND technical_skills=88")===1,
    'legacy fixed evaluation unchanged'
);

echo json_encode(['phase6_evaluation_checks_passed'=>$checks,'result'=>'PASS']) . PHP_EOL;
