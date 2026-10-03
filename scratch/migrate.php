<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
$c = db();
// add requested_by to evaluations
$r = $c->query("SHOW COLUMNS FROM evaluations LIKE 'requested_by'");
if ($r->num_rows === 0) {
    $c->query("ALTER TABLE evaluations ADD COLUMN requested_by INT NULL AFTER evaluator_id");
    echo "evaluations.requested_by added\n";
}
$r = $c->query("SHOW COLUMNS FROM companies LIKE 'cert_template'");
if ($r->num_rows === 0) {
    $c->query("ALTER TABLE companies ADD COLUMN cert_template TEXT NULL");
    echo "companies.cert_template added\n";
}
echo "done\n";
