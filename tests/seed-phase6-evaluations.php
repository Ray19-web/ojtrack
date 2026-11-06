<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='005_reports'")) {
    exit("Migration 005 must be applied first.\n");
}

query("UPDATE evaluation_forms
       SET description='Published synthetic evaluation',score_mode='percentage',rating_max=100,
           published_at='2026-09-01 09:00:00'
       WHERE id=1");
query("UPDATE evaluation_forms
       SET description='Draft synthetic evaluation',score_mode='percentage',rating_max=100
       WHERE id=2");
query("UPDATE evaluation_forms
       SET description='Other coordinator draft',score_mode='percentage',rating_max=100
       WHERE id=3");

query("INSERT INTO evaluation_forms
 (id,created_by,title,description,criteria,status,version,parent_id,score_mode,rating_max,published_at)
 VALUES
 (4,2,'Legacy JSON Criteria','Older JSON-only draft','[\"Communication\",\"Initiative\"]','draft',1,0,'percentage',100,NULL)");

query("INSERT INTO eval_rating_rules(id,form_id,score_min,score_max,equivalent,description)
       VALUES
       (1,4,90,100,1.25,'Excellent'),
       (2,4,80,89,1.50,'Very Good')");

query("UPDATE eval_submissions
       SET status='completed',overall_score=92.50,overall_equivalent=1.25,
           comments='Strong synthetic performance',
           submitted_at='2026-09-15 10:00:00',
           created_at='2026-09-10 09:00:00'
       WHERE id=1");
query("UPDATE eval_submissions
       SET status='pending',created_at='2026-09-10 09:05:00'
       WHERE id=2");

query("INSERT INTO eval_answers(id,submission_id,section_title,criterion_label,score,equivalent)
       VALUES
       (1,1,'Skills','Quality',95.0,1.25),
       (2,1,'Skills','Timeliness',90.0,1.25)");

query("INSERT INTO evaluations
 (id,student_id,company_id,evaluator_id,requested_by,evaluation_type,
  technical_skills,work_ethic,communication,teamwork,initiative,adaptability,
  overall_score,comments,status,evaluated_at)
 VALUES
 (1,1,1,3,2,'midterm',88,92,85,90,87,91,88.83,'Legacy fixed completed','completed','2026-09-20 09:00:00'),
 (2,3,1,3,2,'final',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'pending',NULL)");

echo "Synthetic phase 6 evaluation fixtures created.\n";
