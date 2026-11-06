<?php
// Additive CLI migration; never alter the database during web requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';

$options = getopt('', ['apply', 'dry-run']);
$version = '014_student_name_parts';
foreach (['students', 'schema_migrations'] as $table) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's')) {
        fwrite(STDERR, "Missing prerequisite table: $table\n"); exit(1);
    }
}

$columns = ['first_name' => 'VARCHAR(60) DEFAULT NULL', 'middle_name' => 'VARCHAR(60) DEFAULT NULL', 'last_name' => 'VARCHAR(60) DEFAULT NULL'];
$present = [];
foreach ($columns as $column => $definition) {
    $present[$column] = (bool)query_one(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
        ['students', $column],
        'ss'
    );
}
$before = (int)(query_one('SELECT COUNT(*) c FROM students')['c'] ?? 0);
$missing = array_keys(array_filter($present, static fn($exists) => !$exists));
echo $missing ? 'READY: add student name columns: ' . implode(', ', $missing) . PHP_EOL : "Student name columns already exist.\n";
if (!isset($options['apply']) || isset($options['dry-run'])) exit("Preview only. Apply with --apply.\n");

foreach ($columns as $column => $definition) {
    if (!$present[$column]) {
        query("ALTER TABLE students ADD COLUMN `$column` $definition");
    }
}
$after = (int)(query_one('SELECT COUNT(*) c FROM students')['c'] ?? 0);
if ($before !== $after) throw new RuntimeException('Student row count changed during migration.');
foreach (array_keys($columns) as $column) {
    if (!query_one(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
        ['students', $column],
        'ss'
    )) throw new RuntimeException("Student name column missing after migration: $column");
}
query('INSERT INTO schema_migrations(version,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)',
    [$version, 'Store student first, middle and last names separately'], 'ss');
echo "Student name columns verified. All student rows preserved.\n";
