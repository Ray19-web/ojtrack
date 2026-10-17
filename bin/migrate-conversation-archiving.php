<?php
// Additive CLI migration; never run schema changes during web requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';
$options = getopt('', ['apply', 'dry-run']);
$version = '012_conversation_archiving';
foreach (['thread_members', 'messages', 'message_threads', 'schema_migrations'] as $table) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's')) {
        fwrite(STDERR, "Missing prerequisite table: $table\n"); exit(1);
    }
}
$column = query_one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='thread_members' AND COLUMN_NAME='archived_at'");
$index = query_one("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='thread_members' AND INDEX_NAME='idx_thread_members_archive'");
$counts = fn() => array_map(fn($table) => (int)query_one("SELECT COUNT(*) c FROM $table")['c'], ['thread_members', 'messages', 'message_threads']);
$before = $counts();
echo ($column ? 'Archive column already present.' : 'READY: add nullable per-member archive state; existing conversations remain in Inbox.') . PHP_EOL;
if (!isset($options['apply']) || isset($options['dry-run'])) exit("Preview only. Apply with --apply after isolated migration tests pass.\n");
if (!$column && !$index) {
    query(file_get_contents(__DIR__ . '/../database/migrations/012_conversation_archiving.sql'));
} else {
    if (!$column) query('ALTER TABLE thread_members ADD COLUMN archived_at DATETIME NULL DEFAULT NULL');
    if (!$index) query('ALTER TABLE thread_members ADD KEY idx_thread_members_archive (user_id, archived_at)');
}
if ($before !== $counts()) throw new RuntimeException('Conversation row counts changed during migration.');
query('INSERT INTO schema_migrations(version,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)', [$version, 'Per-user conversation archive and restore'], 'ss');
echo "Archive schema verified. All conversation, membership and message rows preserved.\n";
