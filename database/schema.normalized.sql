-- OJTrack canonical normalized target schema (3NF-oriented)
-- Fresh-install design only. Do NOT import over an existing OJTrack database.
-- Existing installations must be migrated in staged, verified steps.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE schema_migrations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  version VARCHAR(50) NOT NULL,
  description VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('student','coordinator','company','admin') NOT NULL,
  status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  avatar VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  department_name VARCHAR(150) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_programs_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_terms (
  id INT NOT NULL AUTO_INCREMENT,
  academic_year_start SMALLINT UNSIGNED NOT NULL,
  academic_year_end SMALLINT UNSIGNED NOT NULL,
  semester ENUM('1st','2nd','summer') NOT NULL,
  starts_on DATE DEFAULT NULL,
  ends_on DATE DEFAULT NULL,
  status ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_term (academic_year_start, academic_year_end, semester),
  CONSTRAINT fk_academic_terms_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  student_id_no VARCHAR(30) NOT NULL,
  contact_number VARCHAR(30) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_user (user_id),
  UNIQUE KEY uq_students_student_no (student_id_no),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coordinators (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  coordinator_id_no VARCHAR(50) DEFAULT NULL,
  department VARCHAR(100) DEFAULT NULL,
  contact_number VARCHAR(30) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coordinators_user (user_id),
  UNIQUE KEY uq_coordinators_id_no (coordinator_id_no),
  CONSTRAINT fk_coordinators_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE companies (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  company_name VARCHAR(150) NOT NULL,
  supervisor_name VARCHAR(150) DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  contact_number VARCHAR(30) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_user (user_id),
  KEY idx_companies_status (status),
  KEY idx_companies_name (company_name),
  CONSTRAINT fk_companies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE ojt_enrollments (
  id INT NOT NULL AUTO_INCREMENT,
  student_id INT NOT NULL,
  academic_term_id INT NOT NULL,
  program_id INT NOT NULL,
  coordinator_id INT DEFAULT NULL,
  coordinator_assigned_by INT DEFAULT NULL,
  coordinator_assigned_at TIMESTAMP NULL DEFAULT NULL,
  year_level VARCHAR(30) DEFAULT NULL,
  required_hours INT NOT NULL DEFAULT 486,
  status ENUM('pending','not_started','ongoing','on_hold','completed','withdrawn') NOT NULL DEFAULT 'pending',
  onboarding_completed_at TIMESTAMP NULL DEFAULT NULL,
  status_notes TEXT DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ojt_enrollment_student_term (student_id, academic_term_id),
  KEY idx_ojt_enrollments_term_status (academic_term_id, status),
  KEY idx_ojt_enrollments_program (program_id),
  KEY idx_ojt_enrollments_coordinator (coordinator_id),
  CONSTRAINT fk_ojt_enrollments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ojt_enrollments_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ojt_enrollments_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ojt_enrollments_coordinator FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE SET NULL,
  CONSTRAINT fk_ojt_enrollments_coordinator_assigner FOREIGN KEY (coordinator_assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE placements (
  id INT NOT NULL AUTO_INCREMENT,
  ojt_enrollment_id INT NOT NULL,
  company_id INT NOT NULL,
  status ENUM('planned','active','on_hold','completed','terminated','transferred') NOT NULL DEFAULT 'planned',
  starts_on DATE DEFAULT NULL,
  ends_on DATE DEFAULT NULL,
  assigned_by INT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_placements_enrollment_status (ojt_enrollment_id, status),
  KEY idx_placements_company_status (company_id, status),
  CONSTRAINT fk_placements_enrollment FOREIGN KEY (ojt_enrollment_id) REFERENCES ojt_enrollments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_placements_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_placements_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE attendance_days (
  id BIGINT NOT NULL AUTO_INCREMENT,
  placement_id INT NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','excused','leave') NOT NULL DEFAULT 'present',
  credited_minutes INT NOT NULL DEFAULT 0,
  remarks VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_day (placement_id, attendance_date),
  CONSTRAINT fk_attendance_days_placement FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  attendance_day_id BIGINT NOT NULL,
  time_in DATETIME DEFAULT NULL,
  time_out DATETIME DEFAULT NULL,
  credited_minutes INT NOT NULL DEFAULT 0,
  source ENUM('company','coordinator','migration','correction') NOT NULL DEFAULT 'company',
  recorded_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attendance_sessions_day (attendance_day_id),
  CONSTRAINT fk_attendance_sessions_day FOREIGN KEY (attendance_day_id) REFERENCES attendance_days(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_sessions_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE journal_days (
  id BIGINT NOT NULL AUTO_INCREMENT,
  placement_id INT NOT NULL,
  entry_date DATE NOT NULL,
  week_number INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_entry_day (placement_id, entry_date),
  CONSTRAINT fk_journal_days_placement FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_revisions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  journal_day_id BIGINT NOT NULL,
  revision_no INT NOT NULL,
  activities TEXT NOT NULL,
  learnings TEXT NOT NULL,
  challenges TEXT NOT NULL,
  claimed_minutes INT NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','returned','approved') NOT NULL DEFAULT 'draft',
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  attachment_id BIGINT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_revision (journal_day_id, revision_no),
  CONSTRAINT fk_journal_revisions_entry FOREIGN KEY (journal_day_id) REFERENCES journal_days(id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_revisions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_journal_revisions_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attachments (
  id BIGINT NOT NULL AUTO_INCREMENT,
  storage_key VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  detected_mime VARCHAR(150) NOT NULL,
  size_bytes BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  uploaded_by INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attachments_storage_key (storage_key),
  KEY idx_attachments_sha256 (sha256),
  CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE requirement_definitions (
  id INT NOT NULL AUTO_INCREMENT,
  created_by INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_requirement_definitions_title (title),
  CONSTRAINT fk_requirement_definitions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requirement_definition_versions (
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
  CONSTRAINT fk_requirement_definition_versions_definition FOREIGN KEY (requirement_definition_id) REFERENCES requirement_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requirement_assignments (
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
  CONSTRAINT fk_requirement_assignments_version FOREIGN KEY (requirement_definition_version_id) REFERENCES requirement_definition_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_assignments_enrollment FOREIGN KEY (ojt_enrollment_id) REFERENCES ojt_enrollments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_assignments_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requirement_submissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  requirement_assignment_id BIGINT NOT NULL,
  version_no INT NOT NULL,
  submitted_by INT NOT NULL,
  status ENUM('submitted','returned','approved') NOT NULL DEFAULT 'submitted',
  submitted_at TIMESTAMP NOT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  attachment_id BIGINT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requirement_submission_version (requirement_assignment_id, version_no),
  CONSTRAINT fk_requirement_submissions_assignment FOREIGN KEY (requirement_assignment_id) REFERENCES requirement_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_submissions_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_requirement_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_requirement_submissions_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE report_templates (
  id INT NOT NULL AUTO_INCREMENT,
  created_by INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_report_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_template_versions (
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
  CONSTRAINT fk_report_template_versions_template FOREIGN KEY (report_template_id) REFERENCES report_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_assignments (
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
  CONSTRAINT fk_report_assignments_version FOREIGN KEY (report_template_version_id) REFERENCES report_template_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_assignments_enrollment FOREIGN KEY (ojt_enrollment_id) REFERENCES ojt_enrollments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_assignments_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_submissions (
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
  attachment_id BIGINT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_submission_version (report_assignment_id, version_no),
  CONSTRAINT fk_report_submissions_assignment FOREIGN KEY (report_assignment_id) REFERENCES report_assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_submissions_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_report_submissions_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE evaluation_definitions (
  id INT NOT NULL AUTO_INCREMENT,
  created_by INT DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_evaluation_definitions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_definition_versions (
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
  CONSTRAINT fk_evaluation_definition_versions_definition FOREIGN KEY (evaluation_definition_id) REFERENCES evaluation_definitions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_version_sections (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_definition_version_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_evaluation_version_sections_order (evaluation_definition_version_id, sort_order),
  CONSTRAINT fk_evaluation_version_sections_version FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_version_criteria (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_version_section_id INT NOT NULL,
  criterion_code VARCHAR(80) NOT NULL,
  label VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_version_criterion_code (evaluation_version_section_id, criterion_code),
  CONSTRAINT fk_evaluation_version_criteria_section FOREIGN KEY (evaluation_version_section_id) REFERENCES evaluation_version_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_version_rating_rules (
  id INT NOT NULL AUTO_INCREMENT,
  evaluation_definition_version_id INT NOT NULL,
  score_min DECIMAL(6,2) NOT NULL,
  score_max DECIMAL(6,2) NOT NULL,
  equivalent DECIMAL(6,2) DEFAULT NULL,
  description VARCHAR(150) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_evaluation_version_rating_rules (evaluation_definition_version_id, score_min, score_max),
  CONSTRAINT fk_evaluation_version_rating_rules_version FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_requests (
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
  CONSTRAINT fk_evaluation_requests_version FOREIGN KEY (evaluation_definition_version_id) REFERENCES evaluation_definition_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_requests_placement FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_requests_evaluator FOREIGN KEY (evaluator_company_user_id) REFERENCES company_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_requests_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_submissions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  evaluation_request_id BIGINT NOT NULL,
  submitted_by INT NOT NULL,
  overall_score DECIMAL(7,2) DEFAULT NULL,
  overall_equivalent DECIMAL(7,2) DEFAULT NULL,
  comments TEXT DEFAULT NULL,
  submitted_at TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_submission_request (evaluation_request_id),
  CONSTRAINT fk_evaluation_submissions_request FOREIGN KEY (evaluation_request_id) REFERENCES evaluation_requests(id) ON DELETE RESTRICT,
  CONSTRAINT fk_evaluation_submissions_submitter FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE evaluation_answers (
  id BIGINT NOT NULL AUTO_INCREMENT,
  evaluation_submission_id BIGINT NOT NULL,
  evaluation_criterion_id INT NOT NULL,
  section_title_snapshot VARCHAR(200) DEFAULT NULL,
  criterion_label_snapshot VARCHAR(255) NOT NULL,
  score DECIMAL(7,2) NOT NULL,
  equivalent DECIMAL(7,2) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_answer_criterion (evaluation_submission_id, evaluation_criterion_id),
  CONSTRAINT fk_evaluation_answers_submission FOREIGN KEY (evaluation_submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_evaluation_answers_criterion FOREIGN KEY (evaluation_criterion_id) REFERENCES evaluation_version_criteria(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificate_templates (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  template_body MEDIUMTEXT NOT NULL,
  logo_attachment_id BIGINT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_certificate_templates_company_active (company_id, is_active),
  CONSTRAINT fk_certificate_templates_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_certificate_templates_logo FOREIGN KEY (logo_attachment_id) REFERENCES attachments(id) ON DELETE SET NULL,
  CONSTRAINT fk_certificate_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificates (
  id BIGINT NOT NULL AUTO_INCREMENT,
  placement_id INT NOT NULL,
  certificate_template_id INT DEFAULT NULL,
  certificate_no VARCHAR(100) NOT NULL,
  issued_by INT NOT NULL,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rendered_snapshot MEDIUMTEXT NOT NULL,
  attachment_id BIGINT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_certificates_no (certificate_no),
  UNIQUE KEY uq_certificates_placement (placement_id),
  CONSTRAINT fk_certificates_placement FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_certificates_template FOREIGN KEY (certificate_template_id) REFERENCES certificate_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_certificates_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_certificates_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_posts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  tag VARCHAR(50) DEFAULT 'General',
  target_role ENUM('all','student','coordinator','company','admin') NOT NULL DEFAULT 'all',
  created_by INT NOT NULL,
  author_role_snapshot ENUM('admin','coordinator','company','student') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  expires_at DATE DEFAULT NULL,
  attachment_id BIGINT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  KEY idx_announcement_posts_active (is_active, expires_at, created_at),
  CONSTRAINT fk_announcement_posts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE announcement_recipients (
  announcement_post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  role_snapshot ENUM('student','coordinator','company') NOT NULL,
  snapshot_basis VARCHAR(120) NOT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_post_id, user_id),
  KEY idx_announcement_recipients_user (user_id, announcement_post_id),
  CONSTRAINT fk_announcement_recipients_post FOREIGN KEY (announcement_post_id) REFERENCES announcement_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_recipients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_threads (
  id BIGINT NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) DEFAULT NULL,
  thread_type ENUM('group','direct') NOT NULL DEFAULT 'group',
  description VARCHAR(255) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_message_threads_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE thread_members (
  thread_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (thread_id, user_id),
  KEY idx_thread_members_user (user_id, left_at),
  CONSTRAINT fk_thread_members_thread FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_thread_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  thread_id BIGINT NOT NULL,
  sender_id INT NOT NULL,
  message TEXT NOT NULL,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_thread_sent (thread_id, sent_at),
  CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_reads (
  message_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, user_id),
  CONSTRAINT fk_message_reads_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  message TEXT NOT NULL,
  notif_type ENUM('info','warning','error','success') NOT NULL DEFAULT 'info',
  link VARCHAR(500) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read_created (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  actor_user_id INT DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) DEFAULT NULL,
  entity_id BIGINT DEFAULT NULL,
  before_data JSON DEFAULT NULL,
  after_data JSON DEFAULT NULL,
  details TEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_actor_created (actor_user_id, created_at),
  KEY idx_activity_entity (entity_type, entity_id),
  CONSTRAINT fk_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- Compatibility views retained after migration 010. These are views, not storage tables.
CREATE OR REPLACE VIEW company_users AS
SELECT
  c.user_id AS id,
  c.id AS company_id,
  c.user_id,
  c.supervisor_name AS position_title,
  'supervisor' AS company_role,
  1 AS is_primary,
  CASE WHEN c.status='active' AND u.status='active' THEN 'active' ELSE 'inactive' END AS status,
  c.created_at
FROM companies c
JOIN users u ON u.id=c.user_id;

CREATE OR REPLACE VIEW enrollment_coordinators AS
SELECT
  oe.id AS id,
  oe.id AS ojt_enrollment_id,
  oe.coordinator_id,
  oe.coordinator_assigned_by AS assigned_by,
  COALESCE(oe.coordinator_assigned_at,oe.created_at) AS assigned_at,
  NULL AS ended_at
FROM ojt_enrollments oe
WHERE oe.coordinator_id IS NOT NULL;

CREATE OR REPLACE VIEW journal_revision_attachments AS
SELECT id AS journal_revision_id, attachment_id
FROM journal_revisions WHERE attachment_id IS NOT NULL;

CREATE OR REPLACE VIEW requirement_submission_attachments AS
SELECT id AS requirement_submission_id, attachment_id
FROM requirement_submissions WHERE attachment_id IS NOT NULL;

CREATE OR REPLACE VIEW report_submission_attachments AS
SELECT id AS report_submission_id, attachment_id
FROM report_submissions WHERE attachment_id IS NOT NULL;

CREATE OR REPLACE VIEW announcement_attachments AS
SELECT id AS announcement_post_id, attachment_id
FROM announcement_posts WHERE attachment_id IS NOT NULL;
