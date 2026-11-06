<?php
// Read-only deployment verification. Never creates accounts or modifies schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$failed = false;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$label.PHP_EOL;
    if (!$ok) $failed = true;
};
$check(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP 8.1 or newer');
foreach (['mysqli','mbstring','fileinfo','gd','zip','iconv','dom'] as $extension) $check(extension_loaded($extension), 'PHP extension: '.$extension);
if (!extension_loaded('mysqli')) exit(1);
require_once __DIR__.'/../config/db.php';
$check(normalized_cutover_ready() && normalized_lean_schema_ready(), 'Current application schema markers');
foreach (['requirement_submission_files','report_submission_files','journal_additional_attachments','user_name_extensions','company_supervisor_name_extensions'] as $table) {
    $check((bool)query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table], 's'), 'Required schema object: '.$table);
}
$check(DB_USER !== 'root' && DB_PASS !== '', 'Dedicated database credentials configured (required on production)');
require_once __DIR__.'/../config/storage.php';
try { $root = private_storage_root(); $check(is_writable($root), 'Private document storage is configured and writable'); }
catch (Throwable $exception) { $check(false, 'Configure OJTRACK_PRIVATE_UPLOAD_DIR outside the public root'); }
$check((int)ini_get('max_file_uploads') >= 20, 'At least 20 multipart upload files allowed');
echo 'Also verify Apache deny/rewrite rules, HTTPS, role workflows, and backup restoration on the deployment host.'.PHP_EOL;
exit($failed ? 1 : 0);
