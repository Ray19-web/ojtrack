<?php
// Explicit, idempotent import of the user-supplied evaluation into Sir Jmvy's library.
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../config/db.php';
$apply=in_array('--apply',$argv,true);
$data=json_decode(file_get_contents(__DIR__.'/../database/bsit-ojt-evaluation.json'),true,512,JSON_THROW_ON_ERROR);
$owner=query_one("SELECT c.id,c.user_id,u.name FROM coordinators c JOIN users u ON u.id=c.user_id
 WHERE c.coordinator_id_no='C20260001' AND c.department='BSIT' AND u.role='coordinator' AND u.status='active'");
if (!$owner || stripos($owner['name'],'Jmvy')===false) throw new RuntimeException('Sir Jmvy BSIT coordinator account was not found.');
$totals=array_map(fn($section)=>array_sum(array_column($section['criteria'],'points')),$data['sections']);
if ($totals!==[15,45,20,10,5,5] || array_sum($totals)!==100) throw new RuntimeException('Source category totals do not match.');
$existing=query_one('SELECT id FROM evaluation_definitions WHERE created_by=? AND title=?',[(int)$owner['user_id'],$data['title']],'is');
if ($existing) {
    echo json_encode(['result'=>'Already imported','definition_id'=>(int)$existing['id']]).PHP_EOL; exit;
}
if (!$apply) {
    echo json_encode(['mode'=>'dry-run','owner'=>$owner['name'],'sections'=>6,'scored_entries'=>17,'category_points'=>$totals,'total'=>100,'status'=>'published, not sent']).PHP_EOL; exit;
}
$column=query_one("SHOW COLUMNS FROM evaluation_definition_versions LIKE 'score_mode'");
if (!str_contains($column['Type'],"'points'")) {
    $before=(int)query_one('SELECT COUNT(*) n FROM evaluation_definition_versions')['n'];
    query(file_get_contents(__DIR__.'/../database/migrations/013_evaluation_points.sql'));
    if ((int)query_one('SELECT COUNT(*) n FROM evaluation_definition_versions')['n']!==$before) throw new RuntimeException('Version count changed during migration.');
}
if (!query_one("SELECT id FROM schema_migrations WHERE version='013_evaluation_points'")) {
    insert("INSERT INTO schema_migrations(version,description) VALUES('013_evaluation_points','Add point-based evaluation scoring')");
}
db()->begin_transaction();
try {
    $definitionId=insert("INSERT INTO evaluation_definitions(created_by,title,description,status,created_at) VALUES(?,?,?,'active',NOW())",[(int)$owner['user_id'],$data['title'],$data['description']],'iss');
    $versionId=insert("INSERT INTO evaluation_definition_versions(evaluation_definition_id,version_no,score_mode,rating_max,status,created_at) VALUES(?,1,'points',100,'draft',NOW())",[$definitionId],'i');
    foreach($data['sections'] as $section) {
        $sectionId=normalized_eval_add_section($versionId,(int)$owner['user_id'],$section['title']);
        foreach($section['criteria'] as $criterion) normalized_eval_add_criterion($versionId,(int)$owner['user_id'],$sectionId,$criterion['label'],$criterion['points']);
    }
    foreach([[91,100,'PASSED'],[0,90.99,'NEEDS IMPROVEMENT']] as [$min,$max,$label]) {
        insert("INSERT INTO evaluation_version_rating_rules(evaluation_definition_version_id,score_min,score_max,equivalent,description) VALUES(?,?,?,NULL,?)",[$versionId,$min,$max,$label],'idds');
    }
    normalized_eval_publish($versionId,(int)$owner['user_id']);
    log_activity(null,'BSIT Evaluation Form Imported',"User-requested paper form added to coordinator {$owner['id']}; version $versionId; 100 points. No requests or scores created.");
    db()->commit();
    echo json_encode(['result'=>'Imported','owner'=>$owner['name'],'definition_id'=>$definitionId,'version_id'=>$versionId,'sections'=>6,'scored_entries'=>17,'total_points'=>100,'status'=>'active','sent'=>false]).PHP_EOL;
} catch(Throwable $error) { db()->rollback();throw $error; }
