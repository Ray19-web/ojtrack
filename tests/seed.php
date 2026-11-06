<?php
// Synthetic fixtures only. Refuses application databases.
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') exit("Use isolated ojtrack_test database.\n");
require __DIR__ . '/../config/db.php';
if ((int)query_one("SELECT COUNT(*) AS n FROM users")['n']) exit("Seed requires an empty database.\n");
$roles = ['admin','coordinator','company','student','coordinator','company','student','student'];
foreach ($roles as $i=>$role) insert("INSERT INTO users(id,name,email,password,role) VALUES(?,?,?,?,?)",[$i+1,"Test $role ".($i+1),"test".($i+1)."@example.invalid",password_hash('Synthetic-test-pass-42',PASSWORD_DEFAULT),$role],'issss');
query("INSERT INTO coordinators(id,user_id,department) VALUES(1,2,'IT'),(2,5,'Other')");
query("INSERT INTO companies(id,user_id,company_name) VALUES(1,3,'Synthetic Company'),(2,6,'Other Company')");
query("INSERT INTO programs(id,code,name) VALUES(1,'TEST','Test Program')");
query("INSERT INTO students(id,user_id,student_id_no,program_id,coordinator_id,company_id,ojt_status,onboarding_completed_at,ojt_start_date,ojt_end_date) VALUES(1,4,'TEST1',1,1,1,'ongoing',NOW(),'2026-01-01','2027-12-31'),(2,7,'TEST2',1,2,2,'ongoing',NOW(),'2026-01-01','2027-12-31'),(3,8,'TEST3',1,1,1,'pending',NULL,'2026-01-01','2027-12-31')");
query("INSERT INTO evaluation_forms(id,created_by,title,status) VALUES(1,2,'Test published form','active'),(2,2,'Test draft','draft'),(3,5,'Other draft','draft')");
query("INSERT INTO eval_sections(id,form_id,title) VALUES(1,1,'Skills'),(2,2,'Draft skills'),(3,3,'Other skills')");
query("INSERT INTO eval_criteria(id,section_id,label) VALUES(1,1,'Quality'),(2,1,'Timeliness'),(3,2,'Draft criterion'),(4,3,'Other criterion')");
query("INSERT INTO eval_submissions(id,form_id,student_id,company_id,requested_by) VALUES(1,1,1,1,2),(2,1,1,1,2)");
query("INSERT INTO notifications(id,user_id,message,link) VALUES(1,4,'First','/ojtrack/student/journal.php'),(2,4,'Second',NULL),(3,7,'Other',NULL)");
query("INSERT INTO journal_entries(id,student_id,entry_date,week_number,activities,learnings,challenges,status,coordinator_remarks) VALUES(1,1,'2026-09-01',35,'Test','Test','Test','rejected','Please revise')");
query("INSERT INTO ojt_requirements(id,student_id,document_name,file_path,submitted_at) VALUES(1,1,'Test file','requirements/test.txt',NOW()),(2,3,'Onboarding file','requirements/onboarding.txt',NOW()),(3,1,'Not submitted',NULL,NULL)");
query("INSERT INTO announcements(title,body,created_by,target_role) VALUES('VISIBLE ASSIGNED','Test',2,'student'),('HIDDEN OTHER','Test',5,'student'),('VISIBLE ADMIN','Test',1,'all')");
file_put_contents(__DIR__.'/../uploads/requirements/test.txt','Synthetic private document');
file_put_contents(__DIR__.'/../uploads/requirements/onboarding.txt','Synthetic onboarding document');
echo "Synthetic fixtures created.\n";
