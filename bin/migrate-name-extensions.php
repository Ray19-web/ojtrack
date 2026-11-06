<?php
// Additive CLI migration; never alter the database during web requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';

$options = getopt('', ['apply', 'dry-run']);
$version = '015_name_extensions';
foreach (['users', 'companies', 'schema_migrations'] as $table) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's')) {
        fwrite(STDERR, "Missing prerequisite table: $table\n"); exit(1);
    }
}

$columns = [
    'users' => [
        'name_extension_type' => 'VARCHAR(30) DEFAULT NULL',
        'name_extension_value' => 'VARCHAR(50) DEFAULT NULL',
    ],
    'companies' => [
        'supervisor_name_extension_type' => 'VARCHAR(30) DEFAULT NULL',
        'supervisor_name_extension_value' => 'VARCHAR(50) DEFAULT NULL',
    ],
];
$present = [];
foreach ($columns as $table => $table_columns) {
    foreach ($table_columns as $column => $definition) {
        $present[$table][$column] = (bool)query_one(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column],
            'ss'
        );
    }
}
$before = [
    'users' => (int)(query_one('SELECT COUNT(*) c FROM users')['c'] ?? 0),
    'companies' => (int)(query_one('SELECT COUNT(*) c FROM companies')['c'] ?? 0),
];
$missing = [];
foreach ($present as $table => $table_columns) {
    foreach ($table_columns as $column => $exists) if (!$exists) $missing[] = "$table.$column";
}
echo $missing ? 'READY: add name extension columns: ' . implode(', ', $missing) . PHP_EOL : "Name extension columns already exist.\n";
if (!isset($options['apply']) || isset($options['dry-run'])) exit("Preview only. Apply with --apply.\n");

foreach ($columns as $table => $table_columns) {
    foreach ($table_columns as $column => $definition) {
        if (!$present[$table][$column]) query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
$after = [
    'users' => (int)(query_one('SELECT COUNT(*) c FROM users')['c'] ?? 0),
    'companies' => (int)(query_one('SELECT COUNT(*) c FROM companies')['c'] ?? 0),
];
if ($before !== $after) throw new RuntimeException('User or company row count changed during migration.');
foreach ($columns as $table => $table_columns) {
    foreach (array_keys($table_columns) as $column) {
        if (!query_one('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column], 'ss')) {
            throw new RuntimeException("Name extension column missing after migration: $table.$column");
        }
    }
}
query('INSERT INTO schema_migrations(version,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)',
    [$version, 'Store name extension types and values for people'], 'ss');
echo "Name extension columns verified. All user and company rows preserved.\n";
