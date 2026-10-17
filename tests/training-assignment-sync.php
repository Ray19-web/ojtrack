<?php
// Real assignment writer on temporary tables; no application records are changed.
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../config/db.php';
foreach (['users','students','companies','programs','academic_terms','ojt_enrollments','placements','coordinators','attendance_days','schema_migrations'] as $table) {
    $create=query_one("SHOW CREATE TABLE $table")['Create Table'];
    $create=preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $create);
    $create=preg_replace('/^\s*CONSTRAINT .*\n?/m', '', $create);
    $create=preg_replace('/,\s*\)/', ')', $create);
    db()->query($create);
}
db()->query("CREATE TEMPORARY TABLE enrollment_coordinators (id INT,ojt_enrollment_id INT,coordinator_id INT,ended_at DATETIME)");
db()->query("CREATE TEMPORARY TABLE company_users (id INT,user_id INT,company_id INT,status VARCHAR(20),is_primary INT)");
db()->query("CREATE TEMPORARY TABLE placement_supervisors (id INT AUTO_INCREMENT PRIMARY KEY,placement_id INT,company_user_id INT,ended_at DATETIME)");
if (in_array('--lean',$argv,true)) {
    query("INSERT INTO schema_migrations(version,description) VALUES ('010_lean_schema','Synthetic lean schema fixture')");
}
query("INSERT INTO users(id,name,email,password,role,status) VALUES (1,'Student','student@example.invalid','test','student','active'),(2,'Coordinator','coord@example.invalid','test','coordinator','active'),(3,'Company A','a@example.invalid','test','company','active'),(4,'Company B','b@example.invalid','test','company','active')");
query("INSERT INTO programs(id,code,name) VALUES (1,'BSIT','Information Technology')");
query("INSERT INTO academic_terms(id,academic_year_start,academic_year_end,semester,status) VALUES (1,2026,2027,'1st','active')");
query("INSERT INTO coordinators(id,user_id,department,coordinator_id_no) VALUES (1,2,'BSIT','TEST-C1')");
query("INSERT INTO companies(id,user_id,company_name,status) VALUES (1,3,'Company A','active'),(2,4,'Company B','active')");
query("INSERT INTO company_users VALUES (1,3,1,'active',1),(2,4,2,'active',1)");
query("INSERT INTO students(id,user_id,student_id_no,program_id,program,coordinator_id,required_hours,is_archived) VALUES (1,1,'TEST-S1',1,'BSIT',1,486,0)");
query("INSERT INTO ojt_enrollments(id,student_id,academic_term_id,program_id,required_hours,status) VALUES (1,1,1,1,486,'pending')");
query("INSERT INTO enrollment_coordinators VALUES (1,1,1,NULL)");
$checks=0;
function assignment_sync_check($actual,$expected,$label) {
    global $checks;
    if ($actual!==$expected) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function save_assignment(?int $company,string $status='pending',int $hours=486): void {
    if (!normalized_update_training_assignment(1,2,1,$company,1,$status,$hours,'2026-10-01',null,'Test notes')) throw new RuntimeException('Assignment failed');
}
save_assignment(1);
$student=normalized_student_context(1);
assignment_sync_check($student['company_name'],'Company A','student dashboard reads assigned company');
assignment_sync_check((int)$student['company_id'],1,'dashboard company id');
assignment_sync_check((int)query_one('SELECT company_id FROM students WHERE id=1')['company_id'],1,'management record agrees');
assignment_sync_check(count(normalized_students_for_company(1)),1,'assigned trainee visible to company');
save_assignment(1,'ongoing',600);
assignment_sync_check((int)query_one('SELECT COUNT(*) n FROM placements')['n'],1,'repeat save does not duplicate placement');
$student=normalized_student_context(1);
assignment_sync_check($student['ojt_status'],'ongoing','dashboard status updates');
assignment_sync_check((int)$student['required_hours'],600,'dashboard hours update');
assignment_sync_check(query_one('SELECT ojt_status FROM students WHERE id=1')['ojt_status'],'ongoing','management status agrees');
save_assignment(2,'ongoing');
assignment_sync_check(normalized_student_context(1)['company_name'],'Company B','reassignment updates dashboard');
assignment_sync_check(query_one('SELECT status FROM placements WHERE company_id=1')['status'],'transferred','old placement retained as history');
assignment_sync_check(count(normalized_students_for_company(1)),0,'old company no longer has active trainee');
assignment_sync_check(count(normalized_students_for_company(2)),1,'new company has trainee');
save_assignment(null);
assignment_sync_check(normalized_student_context(1)['company_id'],null,'unassignment removes dashboard company');
assignment_sync_check(query_one('SELECT company_id FROM students WHERE id=1')['company_id'],null,'unassignment clears management record');
assignment_sync_check((int)query_one('SELECT COUNT(*) n FROM placements')['n'],2,'unassignment preserves placement history');
save_assignment(2,'completed');
save_assignment(null,'completed');
assignment_sync_check(normalized_student_context(1)['company_id'],null,'unassigning completed placement does not expose old company');
save_assignment(1);
query("UPDATE companies SET status='inactive' WHERE id=2");
try { save_assignment(2); throw new RuntimeException('Inactive company accepted'); }
catch (DomainException $error) { assignment_sync_check(normalized_student_context(1)['company_name'],'Company A','invalid company leaves dashboard unchanged'); }
assignment_sync_check((int)query_one('SELECT company_id FROM students WHERE id=1')['company_id'],1,'failed save leaves management unchanged');
query('UPDATE students SET is_archived=1 WHERE id=1');
try { save_assignment(2); throw new RuntimeException('Archived student accepted'); }
catch (DomainException $error) { assignment_sync_check(true,true,'archived student rejected'); }
echo "$checks training assignment synchronization checks passed.\n";
