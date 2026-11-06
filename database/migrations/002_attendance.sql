-- OJTrack normalized migration 002: attendance days, sessions and corrections
-- Additive only. Existing legacy attendance rows are never deleted or altered.

CREATE TABLE IF NOT EXISTS attendance_days (
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
  KEY idx_attendance_days_date_status (attendance_date, status),
  CONSTRAINT fk_attendance_days_placement
    FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_sessions (
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
  CONSTRAINT fk_attendance_sessions_day
    FOREIGN KEY (attendance_day_id) REFERENCES attendance_days(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_sessions_recorded_by
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_corrections (
  id BIGINT NOT NULL AUTO_INCREMENT,
  attendance_day_id BIGINT NOT NULL,
  requested_by INT NOT NULL,
  reason TEXT NOT NULL,
  before_state JSON DEFAULT NULL,
  requested_state JSON NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_attendance_corrections_day_status (attendance_day_id, status),
  CONSTRAINT fk_attendance_corrections_day
    FOREIGN KEY (attendance_day_id) REFERENCES attendance_days(id) ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_corrections_requested_by
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_corrections_reviewed_by
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
