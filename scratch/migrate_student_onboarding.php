<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * OJTRACK migration: student onboarding gate.
 *
 * Run once after deploying the onboarding code.
 * Existing student accounts created before the rollout are marked complete;
 * newly created student accounts keep onboarding_completed_at = NULL.
 */
require_once __DIR__ . '/../config/db.php';

$column = query_one(
    "SELECT COUNT(*) AS c
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE()
       AND TABLE_NAME='students'
       AND COLUMN_NAME='onboarding_completed_at'"
);

if ((int)($column['c'] ?? 0) === 0) {
    query(
        "ALTER TABLE students
         ADD COLUMN onboarding_completed_at TIMESTAMP NULL DEFAULT NULL
         AFTER ojt_status"
    );
}

query(
    "UPDATE students s
     JOIN users u ON u.id=s.user_id
     SET s.onboarding_completed_at=COALESCE(s.onboarding_completed_at, NOW())
     WHERE s.onboarding_completed_at IS NULL
       AND u.created_at < '2026-10-02 00:00:00'"
);

echo "Student onboarding migration completed.";
