<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
$c = db();
$c->query("ALTER TABLE reports MODIFY report_type ENUM('initial','midterm','final','monthly') NOT NULL");
echo $c->error ?: "ok\n";
