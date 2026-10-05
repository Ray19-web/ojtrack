-- OJTrack normalized migration 005: reports.
-- Additive only. Legacy reports and existing files are untouched.

CREATE TABLE IF NOT EXISTS report_templates (
  id INT NOT NULL AUTO_INCREMENT,
  created_by INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_report_templates_title (title),
  CONSTRAINT fk_report_templates_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_template_versions (
  id INT NOT NULL AUTO_INCREMENT,
  report_template_id INT NOT NULL,
  version_no INT NOT NULL,
  report_type ENUM('initial','midterm','final','monthly','custom') NOT NULL,
  instructions TEXT DEFAULT NULL,
  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  published_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_template_version (report_template_id, version_no),
  CONSTRAINT fk_report_template_versions_template
    FOREIGN KEY (report_template_id) REFERENCES report_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_assignments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  report_template_version_id INT NOT NULL,
  ojt_enrollment_id INT NOT NULL,
  assigned_by INT DEFAULT NULL,
  period_start DATE DEFAULT NULL,
  period_end DATE DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  status ENUM('assigned','closed','waived') NOT NULL DEFAULT 'assigned',
  assigned_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_report_assignments_enrollment (ojt_enrollment_id, status),
  KEY idx_report_assignments_version (report_template_version_id),
  CONSTRAINT fk_report_assignments_version
    FOREIGN KEY (report_template_version_id) REFERENCES report_template_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_assignments_enrollment
    FOREIGN KEY (ojt_enrollment_id) REFERENCES ojt_enrollments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_assignments_assigner
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_submissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  report_assignment_id BIGINT NOT NULL,
  version_no INT NOT NULL,
  submitted_by INT NOT NULL,
  status ENUM('submitted','returned','approved') NOT NULL DEFAULT 'submitted',
  submitted_at TIMESTAMP NOT NULL,
  student_note TEXT DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  evidence_snapshot JSON DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_submission_version (report_assignment_id, version_no),
  CONSTRAINT fk_report_submissions_assignment
    FOREIGN KEY (report_assignment_id) REFERENCES report_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_submissions_submitter
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_submissions_reviewer
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_submission_attachments (
  report_submission_id BIGINT NOT NULL,
  attachment_id BIGINT NOT NULL,
  PRIMARY KEY (report_submission_id, attachment_id),
  CONSTRAINT fk_report_submission_attachments_submission
    FOREIGN KEY (report_submission_id) REFERENCES report_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_submission_attachments_attachment
    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_report_migration_map (
  legacy_report_id INT NOT NULL,
  report_template_id INT NOT NULL,
  report_template_version_id INT NOT NULL,
  report_assignment_id BIGINT NOT NULL,
  report_submission_id BIGINT DEFAULT NULL,
  PRIMARY KEY (legacy_report_id),
  UNIQUE KEY uq_legacy_report_assignment (report_assignment_id),
  CONSTRAINT fk_legacy_report_map_source
    FOREIGN KEY (legacy_report_id) REFERENCES reports(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_report_map_template
    FOREIGN KEY (report_template_id) REFERENCES report_templates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_report_map_version
    FOREIGN KEY (report_template_version_id) REFERENCES report_template_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_report_map_assignment
    FOREIGN KEY (report_assignment_id) REFERENCES report_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_report_map_submission
    FOREIGN KEY (report_submission_id) REFERENCES report_submissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
