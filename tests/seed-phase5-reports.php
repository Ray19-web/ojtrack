<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='004_requirements'")) {
    exit("Migration 004 must be applied first.\n");
}

query("INSERT INTO reports
 (id,student_id,report_name,report_type,file_path,deadline,submitted_at,status,remarks,reviewed_by,reviewed_at)
 VALUES
 (1,1,'Acceptance Report','initial','reports/acceptance.pdf','2026-09-10','2026-09-09 18:00:00','for_review','Student acceptance note',NULL,NULL),
 (2,1,'Mid-Term Narrative Report','midterm','reports/midterm.pdf','2026-09-20','2026-09-19 18:00:00','rejected','Please revise the conclusion',2,'2026-09-20 09:00:00'),
 (3,1,'Final Narrative Report','final',NULL,'2026-10-30',NULL,'pending',NULL,NULL,NULL),
 (4,1,'Monthly OJT Report — September 2026','monthly',NULL,'2026-09-01','2026-10-01 08:00:00','for_review','Monthly compilation: current September evidence. ',NULL,NULL),
 (5,1,'Legacy Manual Final','final',NULL,'2026-10-31',NULL,'approved','Legacy manual approval',2,'2026-10-01 09:00:00'),
 (6,1,'Approved Narrative','final',NULL,'2026-10-15','2026-10-14 18:00:00','approved','Approved after review',2,'2026-10-15 09:00:00'),
 (7,2,'Acceptance Report','initial',NULL,'2026-09-10',NULL,'pending',NULL,NULL,NULL)");

file_put_contents(__DIR__.'/../uploads/reports/acceptance.pdf',"%PDF-1.4\nSynthetic acceptance report\n%%EOF\n");
file_put_contents(__DIR__.'/../uploads/reports/midterm.pdf',"%PDF-1.4\nSynthetic midterm report\n%%EOF\n");

echo "Synthetic phase 5 report fixtures created.\n";
