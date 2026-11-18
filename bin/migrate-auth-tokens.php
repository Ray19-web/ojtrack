<?php
// Additive migration for persistent login and password-reset token storage.
require_once __DIR__ . '/../config/db.php';

$version = '019_auth_tokens';
if (query_one('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1', [$version], 's')) {
    echo "ALREADY_APPLIED $version\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/../database/migrations/019_auth_tokens.sql');
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Migration SQL is missing.');

db()->multi_query($sql);
while (db()->more_results()) {
    db()->next_result();
    if ($result = db()->store_result()) $result->free();
}
query('INSERT INTO schema_migrations(version,description) VALUES(?,?)', [$version, 'Persistent login and password-reset tokens'], 'ss');
echo "PASS $version\n";
