-- OJTrack normalized migration 004: requirements.
-- Additive only. Legacy requirement_templates and ojt_requirements are untouched.

CREATE TABLE IF NOT EXISTS requirement_definitions (
  id INT NOT NULL AUTO_INCREMENT,
  created_by INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_requirement_definitions_title (title),
  CONSTRAINT fk_requirement_definitions_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirement_definition_versions (
  id INT NOT NULL AUTO_INCREMENT,
  requirement_definition_id INT NOT NULL,
  version_no INT NOT NULL,
  description TEXT DEFAULT NULL,
  instructions TEXT DEFAULT NULL,
  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  published_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requirement_definition_version (requirement_definition_id, version_no),
  CONSTRAINT fk_requirement_definition_versions_definition
    FOREIGN KEY (requirement_definition_id) REFERENCES requirement_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirement_assignments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  requirement_definition_version_id INT NOT NULL,
  ojt_enrollment_id INT NOT NULL,
  assigned_by INT DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  assignment_note TEXT DEFAULT NULL,
  status ENUM('assigned','closed','waived') NOT NULL DEFAULT 'assigned',
  assigned_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_requirement_assignments_enrollment (ojt_enrollment_id, status),
  KEY idx_requirement_assignments_version (requirement_definition_version_id),
  CONSTRAINT fk_requirement_assignments_version
    FOREIGN KEY (requirement_definition_version_id) REFERENCES requirement_definition_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_assignments_enrollment
    FOREIGN KEY (ojt_enrollment_id) REFERENCES ojt_enrollments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_assignments_assigner
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirement_submissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  requirement_assignment_id BIGINT NOT NULL,
  version_no INT NOT NULL,
  submitted_by INT NOT NULL,
  status ENUM('submitted','returned','approved') NOT NULL DEFAULT 'submitted',
  submitted_at TIMESTAMP NOT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requirement_submission_version (requirement_assignment_id, version_no),
  CONSTRAINT fk_requirement_submissions_assignment
    FOREIGN KEY (requirement_assignment_id) REFERENCES requirement_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_submissions_submitter
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_submissions_reviewer
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirement_submission_attachments (
  requirement_submission_id BIGINT NOT NULL,
  attachment_id BIGINT NOT NULL,
  PRIMARY KEY (requirement_submission_id, attachment_id),
  CONSTRAINT fk_requirement_submission_attachments_submission
    FOREIGN KEY (requirement_submission_id) REFERENCES requirement_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_requirement_submission_attachments_attachment
    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_requirement_migration_map (
  legacy_requirement_id INT NOT NULL,
  requirement_assignment_id BIGINT NOT NULL,
  requirement_submission_id BIGINT DEFAULT NULL,
  PRIMARY KEY (legacy_requirement_id),
  UNIQUE KEY uq_legacy_requirement_assignment (requirement_assignment_id),
  CONSTRAINT fk_legacy_requirement_map_source
    FOREIGN KEY (legacy_requirement_id) REFERENCES ojt_requirements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_requirement_map_assignment
    FOREIGN KEY (requirement_assignment_id) REFERENCES requirement_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_requirement_map_submission
    FOREIGN KEY (requirement_submission_id) REFERENCES requirement_submissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_requirement_template_migration_map (
  legacy_template_id INT NOT NULL,
  requirement_definition_id INT NOT NULL,
  requirement_definition_version_id INT NOT NULL,
  PRIMARY KEY (legacy_template_id),
  UNIQUE KEY uq_legacy_requirement_template_definition (requirement_definition_id),
  CONSTRAINT fk_legacy_requirement_template_map_source
    FOREIGN KEY (legacy_template_id) REFERENCES requirement_templates(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_requirement_template_map_definition
    FOREIGN KEY (requirement_definition_id) REFERENCES requirement_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_requirement_template_map_version
    FOREIGN KEY (requirement_definition_version_id) REFERENCES requirement_definition_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
