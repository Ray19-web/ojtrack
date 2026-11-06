<?php
// Exercise the real editor with temporary tables, never application records.
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../config/db.php';
db()->query("CREATE TEMPORARY TABLE requirement_definitions (
 id INT PRIMARY KEY, created_by INT, title VARCHAR(200), status VARCHAR(20), created_at DATETIME)");
db()->query("CREATE TEMPORARY TABLE requirement_definition_versions (
 id INT AUTO_INCREMENT PRIMARY KEY, requirement_definition_id INT, version_no INT,
 description TEXT, status VARCHAR(20), published_at DATETIME, created_at DATETIME,
 UNIQUE(requirement_definition_id,version_no))");
db()->query("CREATE TEMPORARY TABLE requirement_assignments (
 id INT PRIMARY KEY, requirement_definition_version_id INT, assignment_note TEXT)");
db()->query("CREATE TEMPORARY TABLE requirement_submissions (
 id INT PRIMARY KEY, requirement_assignment_id INT, status VARCHAR(20), review_notes TEXT)");
db()->query("INSERT INTO requirement_definitions VALUES
 (1,10,'Old name','active',NOW()),(2,20,'Other owner','active',NOW()),(3,10,'Archived','archived',NOW())");
db()->query("INSERT INTO requirement_definition_versions VALUES
 (1,1,1,'Original instructions','published',NOW(),NOW()),
 (2,2,1,'Other instructions','published',NOW(),NOW()),
 (3,3,1,'Archived instructions','retired',NOW(),NOW())");
db()->query("INSERT INTO requirement_assignments VALUES (1,1,'Original instructions')");
db()->query("INSERT INTO requirement_submissions VALUES (1,1,'approved','Approved already')");
$checks=0;
function requirement_edit_check($actual,$expected,$label): void {
    global $checks;
    if ($actual!==$expected) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function requirement_edit_rejected(callable $action,string $label): void {
    try { $action(); } catch (DomainException $error) {
        requirement_edit_check(true,true,$label); return;
    }
    throw new RuntimeException('FAIL: '.$label);
}
$newId=normalized_requirement_edit_template(10,1,1,"  Parent's Consent  ","  Updated instructions  ");
requirement_edit_check($newId>3,true,'creates a new version');
requirement_edit_check(query_one('SELECT title FROM requirement_definitions WHERE id=1')['title'],"Parent's Consent",'name updates and trims safely');
requirement_edit_check(query_one('SELECT description FROM requirement_definition_versions WHERE id=1')['description'],'Original instructions','previous version remains intact');
requirement_edit_check((int)query_one('SELECT version_no FROM requirement_definition_versions WHERE id=?',[$newId],'i')['version_no'],2,'version increases');
requirement_edit_check(query_one('SELECT description FROM requirement_definition_versions WHERE id=?',[$newId],'i')['description'],'Updated instructions','new instructions saved');
$library=normalized_requirement_templates_for_coordinator(10);
requirement_edit_check(count($library),1,'library remains scoped to active owned requirements');
requirement_edit_check((int)$library[0]['version_id'],$newId,'send list uses edited version');
requirement_edit_check($library[0]['name'],"Parent's Consent",'send list shows new name');
requirement_edit_check(query_one('SELECT assignment_note FROM requirement_assignments WHERE id=1')['assignment_note'],'Original instructions','existing instructions preserved');
requirement_edit_check((int)query_one('SELECT requirement_definition_version_id FROM requirement_assignments WHERE id=1')['requirement_definition_version_id'],1,'existing assignment version preserved');
requirement_edit_check(query_one('SELECT status FROM requirement_submissions WHERE id=1')['status'],'approved','approved status preserved');
requirement_edit_check(query_one('SELECT review_notes FROM requirement_submissions WHERE id=1')['review_notes'],'Approved already','review feedback preserved');
requirement_edit_rejected(fn()=>normalized_requirement_edit_template(10,2,2,'Changed',''),'other owner blocked');
requirement_edit_rejected(fn()=>normalized_requirement_edit_template(10,3,3,'Changed',''),'archived requirement blocked');
requirement_edit_rejected(fn()=>normalized_requirement_edit_template(10,1,1,'Stale change',''),'stale edit blocked');
requirement_edit_check(query_one('SELECT title FROM requirement_definitions WHERE id=1')['title'],"Parent's Consent",'stale edit cannot overwrite name');
requirement_edit_rejected(fn()=>normalized_requirement_edit_template(10,1,$newId,'  ',''),'empty name rejected');
requirement_edit_rejected(fn()=>normalized_requirement_edit_template(10,1,$newId,str_repeat('x',201),''),'overlong name rejected');
requirement_edit_rejected(fn()=>normalized_requirement_edit_template(10,1,$newId,'Valid',str_repeat('x',60001)),'oversized instructions rejected');
requirement_edit_check(normalized_requirement_edit_template(10,1,$newId,"Parent's Consent",'Updated instructions'),$newId,'unchanged save does not create a duplicate version');
$nextId=normalized_requirement_edit_template(10,1,$newId,'Medical Certificate','');
requirement_edit_check(query_one('SELECT description FROM requirement_definition_versions WHERE id=?',[$nextId],'i')['description'],'','instructions may be cleared');
requirement_edit_check((int)query_one('SELECT COUNT(*) n FROM requirement_definition_versions WHERE requirement_definition_id=1')['n'],3,'only successful changes create versions');
echo "$checks requirement editing checks passed.\n";
