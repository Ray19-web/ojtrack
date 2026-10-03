<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
$c = db();
$cols = $c->query("SHOW COLUMNS FROM journal_entries")->fetch_all(MYSQLI_ASSOC);
$have = array_column($cols, 'Field');
if (!in_array('proof_image', $have)) {
    $c->query("ALTER TABLE journal_entries ADD COLUMN proof_image VARCHAR(255) NULL");
    echo "added\n";
}
echo $c->error ?: "ok\n";
