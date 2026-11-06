<?php
// Additive CLI migration. Existing full names are preserved, never guessed apart.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';

$options = getopt('', ['apply', 'dry-run']);
$version = '017_person_name_parts';
foreach (['coordinators', 'companies', 'schema_migrations'] as $table) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's')) {
        fwrite(STDERR, "Missing prerequisite table: $table\n"); exit(1);
    }
}

$columns = [
    'coordinators' => ['first_name' => 'VARCHAR(60) DEFAULT NULL', 'middle_name' => 'VARCHAR(60) DEFAULT NULL', 'last_name' => 'VARCHAR(60) DEFAULT NULL'],
    'companies' => ['supervisor_first_name' => 'VARCHAR(60) DEFAULT NULL', 'supervisor_middle_name' => 'VARCHAR(60) DEFAULT NULL', 'supervisor_last_name' => 'VARCHAR(60) DEFAULT NULL'],
];
$before = [
    'coordinators' => (int)(query_one('SELECT COUNT(*) c FROM coordinators')['c'] ?? 0),
    'companies' => (int)(query_one('SELECT COUNT(*) c FROM companies')['c'] ?? 0),
];
$pending = [];
foreach ($columns as $table => $definitions) {
    foreach ($definitions as $column => $definition) {
        $exists = query_one('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column], 'ss');
        if (!$exists) $pending[] = [$table, $column, $definition];
    }
}
echo 'READY: add ' . count($pending) . " name-part column(s); existing names will remain unchanged.\n";
if (!isset($options['apply']) || isset($options['dry-run'])) exit("Preview only. Apply with --apply.\n");

foreach ($pending as [$table, $column, $definition]) {
    query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
$after = [
    'coordinators' => (int)(query_one('SELECT COUNT(*) c FROM coordinators')['c'] ?? 0),
    'companies' => (int)(query_one('SELECT COUNT(*) c FROM companies')['c'] ?? 0),
];
if ($before !== $after) throw new RuntimeException('Coordinator or company row count changed during migration.');
query('INSERT INTO schema_migrations(version,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)', [$version, 'Store coordinator and company supervisor name parts'],'ss');
echo "Name-part columns verified. All coordinator and company rows preserved.\n";
