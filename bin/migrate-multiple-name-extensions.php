<?php
// Additive CLI migration; never alter the database during web requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';

$options = getopt('', ['apply', 'dry-run']);
$version = '016_multiple_name_extensions';
foreach (['users', 'companies', 'schema_migrations'] as $table) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's')) {
        fwrite(STDERR, "Missing prerequisite table: $table\n"); exit(1);
    }
}
foreach ([['users','name_extension_type'],['users','name_extension_value'],['companies','supervisor_name_extension_type'],['companies','supervisor_name_extension_value']] as [$table,$column]) {
    if (!query_one('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table,$column], 'ss')) {
        fwrite(STDERR, "Run migration 015 before migration 016; missing $table.$column\n"); exit(1);
    }
}
$before = [
    'users' => (int)(query_one('SELECT COUNT(*) c FROM users')['c'] ?? 0),
    'companies' => (int)(query_one('SELECT COUNT(*) c FROM companies')['c'] ?? 0),
];
$existing = [
    'user_name_extensions' => (bool)query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['user_name_extensions'], 's'),
    'company_supervisor_name_extensions' => (bool)query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['company_supervisor_name_extensions'], 's'),
];
$pendingUsers = (int)(query_one(
    $existing['user_name_extensions']
        ? "SELECT COUNT(*) c FROM users u WHERE u.name_extension_type IS NOT NULL AND u.name_extension_type<>'' AND u.name_extension_value IS NOT NULL AND u.name_extension_value<>'' AND NOT EXISTS (SELECT 1 FROM user_name_extensions e WHERE e.user_id=u.id)"
        : "SELECT COUNT(*) c FROM users u WHERE u.name_extension_type IS NOT NULL AND u.name_extension_type<>'' AND u.name_extension_value IS NOT NULL AND u.name_extension_value<>''"
)['c'] ?? 0);
$pendingCompanies = (int)(query_one(
    $existing['company_supervisor_name_extensions']
        ? "SELECT COUNT(*) c FROM companies c WHERE c.supervisor_name_extension_type IS NOT NULL AND c.supervisor_name_extension_type<>'' AND c.supervisor_name_extension_value IS NOT NULL AND c.supervisor_name_extension_value<>'' AND NOT EXISTS (SELECT 1 FROM company_supervisor_name_extensions e WHERE e.company_id=c.id)"
        : "SELECT COUNT(*) c FROM companies c WHERE c.supervisor_name_extension_type IS NOT NULL AND c.supervisor_name_extension_type<>'' AND c.supervisor_name_extension_value IS NOT NULL AND c.supervisor_name_extension_value<>''"
)['c'] ?? 0);
echo 'READY: create extension tables and migrate up to ' . ($pendingUsers + $pendingCompanies) . " legacy extension entry/entries.\n";
if (!isset($options['apply']) || isset($options['dry-run'])) exit("Preview only. Apply with --apply.\n");

$sql = file_get_contents(__DIR__ . '/../database/migrations/016_multiple_name_extensions.sql');
if (!is_string($sql)) throw new RuntimeException('Unable to read migration SQL.');
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) query($statement);
$after = [
    'users' => (int)(query_one('SELECT COUNT(*) c FROM users')['c'] ?? 0),
    'companies' => (int)(query_one('SELECT COUNT(*) c FROM companies')['c'] ?? 0),
];
if ($before !== $after) throw new RuntimeException('User or company row count changed during migration.');
foreach (array_keys($existing) as $table) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's')) throw new RuntimeException("Extension table missing after migration: $table");
}
query('INSERT INTO schema_migrations(version,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)', [$version,'Store multiple ordered name extensions for people'],'ss');
echo "Extension tables verified. All user and company rows preserved.\n";
