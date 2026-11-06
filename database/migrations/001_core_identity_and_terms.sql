-- OJTrack normalized migration 001: core identity, academic terms and placements
-- Additive only. Safe to load before backfill. Existing legacy tables/columns are untouched.

CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  version VARCHAR(50) NOT NULL,
  description VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_terms (
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

CREATE TABLE IF NOT EXISTS company_users (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  position_title VARCHAR(100) DEFAULT NULL,
  company_role ENUM('supervisor','hr','manager','other') NOT NULL DEFAULT 'supervisor',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_users_user (user_id),
  KEY idx_company_users_company_status (company_id, status),
  CONSTRAINT fk_company_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_company_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ojt_enrollments (
  id INT NOT NULL AUTO_INCREMENT,
  student_id INT NOT NULL,
  academic_term_id INT NOT NULL,
  program_id INT NOT NULL,
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
  CONSTRAINT fk_ojt_enrollments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ojt_enrollments_term FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ojt_enrollments_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollment_coordinators (
  id INT NOT NULL AUTO_INCREMENT,
  ojt_enrollment_id INT NOT NULL,
  coordinator_id INT NOT NULL,
  assigned_by INT DEFAULT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_enrollment_coordinators_enrollment (ojt_enrollment_id, ended_at),
  KEY idx_enrollment_coordinators_coordinator (coordinator_id, ended_at),
  CONSTRAINT fk_enrollment_coordinators_enrollment FOREIGN KEY (ojt_enrollment_id) REFERENCES ojt_enrollments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_coordinators_coordinator FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_coordinators_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS placements (
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

CREATE TABLE IF NOT EXISTS placement_supervisors (
  id INT NOT NULL AUTO_INCREMENT,
  placement_id INT NOT NULL,
  company_user_id INT NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_placement_supervisors_placement (placement_id, ended_at),
  KEY idx_placement_supervisors_user (company_user_id, ended_at),
  CONSTRAINT fk_placement_supervisors_placement FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_placement_supervisors_company_user FOREIGN KEY (company_user_id) REFERENCES company_users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
