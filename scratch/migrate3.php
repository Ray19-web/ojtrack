<?php
require 'C:/xampp/htdocs/ojtrack/config/db.php';
$c = db();
$c->query("CREATE TABLE IF NOT EXISTS requirement_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coordinator_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE CASCADE
)");
echo $c->error ?: "ok\n";
