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

query("INSERT INTO attendance
 (id,student_id,date,time_in,time_out,morning_in,morning_out,afternoon_in,afternoon_out,hours_rendered,remarks,status)
 VALUES
 (101,1,'2026-01-05','08:00:00','17:00:00',NULL,NULL,NULL,NULL,9.00,'Generic legacy day','present'),
 (102,2,'2026-01-06','08:00:00','17:00:00','08:00:00','12:00:00','13:00:00','17:00:00',8.00,'Split sessions','present'),
 (103,3,'2026-01-07',NULL,NULL,'08:15:00',NULL,NULL,NULL,0.00,'Open punch','present'),
 (104,1,'2026-01-08',NULL,NULL,NULL,NULL,NULL,NULL,0.00,'Excused absence','excused')");

echo "Synthetic phase 2 attendance fixtures created.\n";
