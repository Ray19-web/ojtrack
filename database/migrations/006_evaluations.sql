-- OJTrack normalized migration 006: evaluations.
-- Additive only. All legacy evaluation tables remain untouched.

CREATE TABLE IF NOT EXISTS evaluation_definitions (
  id INT NOT NULL AUTO_INCREMENT,
  created_by INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_evaluation_definitions_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_definition_versions (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_definition_id INT NOT NULL,
  version_no INT NOT NULL,
  score_mode ENUM('percentage','rating') NOT NULL DEFAULT 'percentage',
  rating_max INT NOT NULL DEFAULT 100,
  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  published_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_definition_version (evaluation_definition_id, version_no),
  CONSTRAINT fk_evaluation_definition_versions_definition
    FOREIGN KEY (evaluation_definition_id) REFERENCES evaluation_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_version_sections (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_definition_version_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_evaluation_version_sections_order (evaluation_definition_version_id, sort_order),
  CONSTRAINT fk_evaluation_version_sections_version
    FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_version_criteria (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_version_section_id INT NOT NULL,
  criterion_code VARCHAR(80) NOT NULL,
  label VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_version_criterion_code (evaluation_version_section_id, criterion_code),
  CONSTRAINT fk_evaluation_version_criteria_section
    FOREIGN KEY (evaluation_version_section_id) REFERENCES evaluation_version_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_version_rating_rules (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_definition_version_id INT NOT NULL,
  score_min DECIMAL(6,2) NOT NULL,
  score_max DECIMAL(6,2) NOT NULL,
  equivalent DECIMAL(6,2) DEFAULT NULL,
  description VARCHAR(150) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_evaluation_version_rating_rules (evaluation_definition_version_id, score_min, score_max),
  CONSTRAINT fk_evaluation_version_rating_rules_version
    FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_requests (
  id BIGINT NOT NULL AUTO_INCREMENT,
  evaluation_definition_version_id INT NOT NULL,
  placement_id INT NOT NULL,
  evaluator_company_user_id INT NOT NULL,
  requested_by INT DEFAULT NULL,
  evaluation_kind ENUM('midterm','final','custom') NOT NULL DEFAULT 'custom',
  due_date DATE DEFAULT NULL,
  status ENUM('pending','submitted','cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_evaluation_requests_evaluator_status (evaluator_company_user_id, status),
  KEY idx_evaluation_requests_placement (placement_id),
  CONSTRAINT fk_evaluation_requests_version
    FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_requests_placement
    FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_requests_evaluator
    FOREIGN KEY (evaluator_company_user_id) REFERENCES company_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_requests_requester
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_submissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  evaluation_request_id BIGINT NOT NULL,
  submitted_by INT NOT NULL,
  overall_score DECIMAL(7,2) DEFAULT NULL,
  overall_equivalent DECIMAL(7,2) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  submitted_at TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_submission_request (evaluation_request_id),
  CONSTRAINT fk_evaluation_submissions_request
    FOREIGN KEY (evaluation_request_id) REFERENCES evaluation_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_submissions_submitter
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_answers (
  id BIGINT NOT NULL AUTO_INCREMENT,
  evaluation_submission_id BIGINT NOT NULL,
  evaluation_criterion_id INT NOT NULL,
  section_title_snapshot VARCHAR(200) DEFAULT NULL,
  criterion_label_snapshot VARCHAR(255) NOT NULL,
  score DECIMAL(7,2) NOT NULL,
  equivalent DECIMAL(7,2) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_answer_criterion (evaluation_submission_id, evaluation_criterion_id),
  CONSTRAINT fk_evaluation_answers_submission
    FOREIGN KEY (evaluation_submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_evaluation_answers_criterion
    FOREIGN KEY (evaluation_criterion_id) REFERENCES evaluation_version_criteria(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_evaluation_form_migration_map (
  legacy_form_id INT NOT NULL,
  evaluation_definition_id INT NOT NULL,
  evaluation_definition_version_id INT NOT NULL,
  PRIMARY KEY (legacy_form_id),
  UNIQUE KEY uq_legacy_evaluation_form_version (evaluation_definition_version_id),
  CONSTRAINT fk_legacy_evaluation_form_source
    FOREIGN KEY (legacy_form_id) REFERENCES evaluation_forms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_evaluation_form_definition
    FOREIGN KEY (evaluation_definition_id) REFERENCES evaluation_definitions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_evaluation_form_version
    FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_eval_submission_migration_map (
  legacy_submission_id INT NOT NULL,
  evaluation_request_id BIGINT NOT NULL,
  evaluation_submission_id BIGINT DEFAULT NULL,
  PRIMARY KEY (legacy_submission_id),
  UNIQUE KEY uq_legacy_eval_submission_request (evaluation_request_id),
  CONSTRAINT fk_legacy_eval_submission_source
    FOREIGN KEY (legacy_submission_id) REFERENCES eval_submissions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_eval_submission_request
    FOREIGN KEY (evaluation_request_id) REFERENCES evaluation_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_eval_submission_submission
    FOREIGN KEY (evaluation_submission_id) REFERENCES evaluation_submissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_fixed_evaluation_migration_map (
  legacy_evaluation_id INT NOT NULL,
  evaluation_request_id BIGINT NOT NULL,
  evaluation_submission_id BIGINT DEFAULT NULL,
  PRIMARY KEY (legacy_evaluation_id),
  UNIQUE KEY uq_legacy_fixed_evaluation_request (evaluation_request_id),
  CONSTRAINT fk_legacy_fixed_evaluation_source
    FOREIGN KEY (legacy_evaluation_id) REFERENCES evaluations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_fixed_evaluation_request
    FOREIGN KEY (evaluation_request_id) REFERENCES evaluation_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_fixed_evaluation_submission
    FOREIGN KEY (evaluation_submission_id) REFERENCES evaluation_submissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
