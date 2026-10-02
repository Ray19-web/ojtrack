<?php
require 'C:/xampp/htdocs/ojtrack/config/db.php';
$c = db();

$c->query("CREATE TABLE IF NOT EXISTS eval_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE
)");
$c->query("CREATE TABLE IF NOT EXISTS eval_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    label VARCHAR(200) NOT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (section_id) REFERENCES eval_sections(id) ON DELETE CASCADE
)");
$c->query("CREATE TABLE IF NOT EXISTS eval_rating_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    score_min INT NOT NULL,
    score_max INT NOT NULL,
    equivalent DECIMAL(4,2) NOT NULL,
    description VARCHAR(100),
    FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE
)");
$c->query("CREATE TABLE IF NOT EXISTS eval_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    form_version INT DEFAULT 1,
    student_id INT NOT NULL,
    company_id INT NOT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','completed') DEFAULT 'pending',
    overall_score DECIMAL(5,2),
    overall_equivalent DECIMAL(4,2),
    comments TEXT,
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$c->query("CREATE TABLE IF NOT EXISTS eval_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    section_title VARCHAR(200),
    criterion_label VARCHAR(200),
    score DECIMAL(5,1),
    equivalent DECIMAL(4,2),
    FOREIGN KEY (submission_id) REFERENCES eval_submissions(id) ON DELETE CASCADE
)");

$cols = $c->query("SHOW COLUMNS FROM evaluation_forms")->fetch_all(MYSQLI_ASSOC);
$have = array_column($cols, 'Field');
if (!in_array('status', $have)) $c->query("ALTER TABLE evaluation_forms ADD COLUMN status VARCHAR(20) DEFAULT 'draft'");
if (!in_array('version', $have)) $c->query("ALTER TABLE evaluation_forms ADD COLUMN version INT DEFAULT 1");
if (!in_array('parent_id', $have)) $c->query("ALTER TABLE evaluation_forms ADD COLUMN parent_id INT DEFAULT 0");
if (!in_array('score_mode', $have)) $c->query("ALTER TABLE evaluation_forms ADD COLUMN score_mode VARCHAR(20) DEFAULT 'percentage'");
if (!in_array('rating_max', $have)) $c->query("ALTER TABLE evaluation_forms ADD COLUMN rating_max INT DEFAULT 100");
if (!in_array('published_at', $have)) $c->query("ALTER TABLE evaluation_forms ADD COLUMN published_at TIMESTAMP NULL");

echo $c->error ?: "ok\n";
