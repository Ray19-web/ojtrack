<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';

// 1. Ensure users.status includes 'archived'
query("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','archived') DEFAULT 'active'");
echo "Updated users.status enum to include 'archived'.\n";

// 2. Add is_archived to students table if not present
$cols = array_column(query('DESCRIBE students'), 'Field');
if (!in_array('is_archived', $cols)) {
    query("ALTER TABLE students ADD COLUMN is_archived TINYINT(1) DEFAULT 0 AFTER ojt_status");
    echo "Added is_archived column to students.\n";
} else {
    echo "students.is_archived already exists.\n";
}

echo "Database archiving support verified.\n";
