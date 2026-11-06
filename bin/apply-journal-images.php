<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
db()->multi_query(file_get_contents(__DIR__ . '/../database/migrations/011_journal_multiple_images.sql'));
do { if ($result = db()->store_result()) $result->free(); } while (db()->more_results() && db()->next_result());
echo "Journal image migration applied.\n";
