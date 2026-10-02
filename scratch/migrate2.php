<?php
require 'C:/xampp/htdocs/ojtrack/config/db.php';
$c = db();
$c->query("CREATE TABLE IF NOT EXISTS evaluation_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_by INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    criteria TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
)");
$c->query("CREATE TABLE IF NOT EXISTS evaluation_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    student_id INT NOT NULL,
    company_id INT NOT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','completed') DEFAULT 'pending',
    answers TEXT,
    overall_score DECIMAL(5,2),
    comments TEXT,
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id)
)");
echo $c->error ?: "ok\n";
