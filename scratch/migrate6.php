<?php
require 'C:/xampp/htdocs/ojtrack/config/db.php';
$c = db();
$c->query("ALTER TABLE reports MODIFY report_type ENUM('initial','midterm','final','monthly') NOT NULL");
echo $c->error ?: "ok\n";
