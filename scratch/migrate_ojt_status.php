<?php
/**
 * Extends students.ojt_status for stop/problem cases and adds status_notes.
 * Run once: php scratch/migrate_ojt_status.php
 */
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';

query("ALTER TABLE students MODIFY COLUMN ojt_status
    ENUM('pending','not_started','ongoing','completed','on_hold','withdrawn')
    DEFAULT 'pending'");
echo "Updated students.ojt_status enum (added on_hold, withdrawn).\n";

$cols = array_column(query('DESCRIBE students') ?: [], 'Field');
if (!in_array('status_notes', $cols, true)) {
    query("ALTER TABLE students ADD COLUMN status_notes TEXT NULL AFTER ojt_status");
    echo "Added students.status_notes.\n";
} else {
    echo "students.status_notes already exists.\n";
}

echo "OJT status migration complete.\n";
