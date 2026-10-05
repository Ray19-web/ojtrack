<?php
// Synthetic legacy attendance fixtures for migration 002.
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='001_core_identity_and_terms'")) {
    exit("Migration 001 must be applied first.\n");
}
if ((int)query_one("SELECT COUNT(*) n FROM attendance")['n'] !== 0) {
    exit("Phase 2 seed requires empty legacy attendance.\n");
}

query("INSERT INTO users(id,name,email,password,role) VALUES(9,'Legacy No Placement','legacy-noplacement@example.invalid','x','student')");
query("INSERT INTO students(id,user_id,student_id_no,program_id,coordinator_id,company_id,ojt_status,required_hours)
       VALUES(4,9,'LEGACY4',1,1,NULL,'completed',486)");
$term=(int)query_one("SELECT id FROM academic_terms WHERE academic_year_start=2026 AND academic_year_end=2027 AND semester='1st'")['id'];
insert("INSERT INTO ojt_enrollments(student_id,academic_term_id,program_id,year_level,required_hours,status)
        VALUES(4,?,1,'4th Year',486,'completed')",[$term],'i');
$enrollment=(int)query_one("SELECT id FROM ojt_enrollments WHERE student_id=4 AND academic_term_id=?",[$term],'i')['id'];
query("INSERT INTO enrollment_coordinators(ojt_enrollment_id,coordinator_id) VALUES($enrollment,1)");

query("INSERT INTO attendance
 (id,student_id,date,time_in,time_out,morning_in,morning_out,afternoon_in,afternoon_out,hours_rendered,remarks,status)
 VALUES
 (101,1,'2026-01-05','08:00:00','17:00:00',NULL,NULL,NULL,NULL,9.00,'Generic legacy day','present'),
 (102,2,'2026-01-06','08:00:00','17:00:00','08:00:00','12:00:00','13:00:00','17:00:00',8.00,'Split sessions','present'),
 (103,3,'2026-01-07',NULL,NULL,'08:15:00',NULL,NULL,NULL,0.00,'Open punch','present'),
 (104,1,'2026-01-08',NULL,NULL,NULL,NULL,NULL,NULL,0.00,'Excused absence','excused'),
 (105,4,'2026-01-09',NULL,NULL,'10:00:00','12:00:00',NULL,NULL,2.00,'Legacy row needing placement resolution','present')");

echo "Synthetic phase 2 attendance fixtures created.\n";
