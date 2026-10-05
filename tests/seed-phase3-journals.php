<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='002_attendance'")) {
    exit("Migration 002 must be applied first.\n");
}

query("INSERT INTO journal_entries
 (id,student_id,entry_date,week_number,activities,learnings,challenges,hours_rendered,status,coordinator_remarks,submitted_at,reviewed_at,proof_image)
 VALUES
 (2,1,'2026-09-02',35,'Approved work','Approved learning','Approved challenge',8.50,'approved','Good work','2026-09-02 18:00:00','2026-09-03 09:00:00',NULL),
 (3,2,'2026-09-03',35,'Pending work','Pending learning','Pending challenge',7.00,'pending',NULL,'2026-09-03 18:00:00',NULL,NULL)");

$root=getenv('OJTRACK_PRIVATE_UPLOAD_DIR');
if (!$root) exit("OJTRACK_PRIVATE_UPLOAD_DIR is required.\n");
$dir=$root . DIRECTORY_SEPARATOR . 'journal_proofs';
if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) exit("Cannot create test proof directory.\n");
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
$file=$dir . DIRECTORY_SEPARATOR . 'phase3-proof.png';
file_put_contents($file,$png);
chmod($file,0600);
query("UPDATE journal_entries
       SET proof_image='journal_proofs/phase3-proof.png',
           hours_rendered=0.00,
           submitted_at='2026-09-01 18:00:00',
           reviewed_at='2026-09-02 09:00:00'
       WHERE id=1");

echo "Synthetic phase 3 journal fixtures created.\n";
