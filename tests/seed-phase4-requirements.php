<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='003_journals_and_attachments'")) {
    exit("Migration 003 must be applied first.\n");
}

query("INSERT INTO requirement_templates(id,coordinator_id,name,description,created_at) VALUES
 (1,1,'Test file','Template instructions','2026-08-01 09:00:00'),
 (2,1,'Duplicate Requirement','Keep duplicate assignments distinct','2026-08-02 09:00:00'),
 (3,2,'Other Coordinator Requirement','Other scope','2026-08-03 09:00:00')");

query("UPDATE ojt_requirements
       SET status='pending',remarks='Awaiting review'
       WHERE id IN (1,2)");
query("UPDATE ojt_requirements
       SET status='pending',remarks='Not yet submitted'
       WHERE id=3");

query("INSERT INTO ojt_requirements
 (id,student_id,document_name,file_path,deadline,submitted_at,status,remarks,reviewed_by,reviewed_at)
 VALUES
 (4,1,'Returned file','requirements/returned.pdf','2026-09-10','2026-09-05 10:00:00','rejected','Replace this file',2,'2026-09-06 09:00:00'),
 (5,1,'Approved no file',NULL,'2026-09-11',NULL,'approved','Legacy manual approval',2,'2026-09-06 09:30:00'),
 (6,1,'Duplicate Requirement',NULL,'2026-09-12','2026-09-07 10:00:00','approved','Approved duplicate',2,'2026-09-08 09:00:00'),
 (7,1,'Duplicate Requirement',NULL,'2026-09-13',NULL,'pending','Second historical assignment',NULL,NULL)");

file_put_contents(__DIR__.'/../uploads/requirements/returned.pdf',"%PDF-1.4\nSynthetic requirement PDF\n%%EOF\n");

echo "Synthetic phase 4 requirement fixtures created.\n";
