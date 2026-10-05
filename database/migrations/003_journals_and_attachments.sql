-- OJTrack normalized migration 003: journal days, revisions and centralized attachment metadata.
-- Additive only. The legacy journal_entries table and existing files are left untouched.

CREATE TABLE IF NOT EXISTS journal_days (
  id BIGINT NOT NULL AUTO_INCREMENT,
  placement_id INT NOT NULL,
  entry_date DATE NOT NULL,
  week_number INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_day (placement_id, entry_date),
  KEY idx_journal_days_date (entry_date),
  CONSTRAINT fk_journal_days_placement
    FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_revisions (
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
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_revision (journal_day_id, revision_no),
  KEY idx_journal_revisions_status (status, submitted_at),
  CONSTRAINT fk_journal_revisions_day
    FOREIGN KEY (journal_day_id) REFERENCES journal_days(id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_revisions_reviewer
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
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
  CONSTRAINT fk_attachments_uploaded_by
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_revision_attachments (
  journal_revision_id BIGINT NOT NULL,
  attachment_id BIGINT NOT NULL,
  PRIMARY KEY (journal_revision_id, attachment_id),
  CONSTRAINT fk_jra_revision
    FOREIGN KEY (journal_revision_id) REFERENCES journal_revisions(id) ON DELETE CASCADE,
  CONSTRAINT fk_jra_attachment
    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
