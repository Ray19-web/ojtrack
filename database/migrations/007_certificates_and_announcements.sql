-- OJTrack normalized migration 007: certificate templates and announcements.
-- Additive only. Legacy companies.cert_template, announcements and public files remain untouched.

CREATE TABLE IF NOT EXISTS certificate_templates (
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
  CONSTRAINT fk_certificate_templates_company
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_certificate_templates_logo
    FOREIGN KEY (logo_attachment_id) REFERENCES attachments(id) ON DELETE SET NULL,
  CONSTRAINT fk_certificate_templates_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificates (
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
  CONSTRAINT fk_certificates_placement
    FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_certificates_template
    FOREIGN KEY (certificate_template_id) REFERENCES certificate_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_certificates_issuer
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_certificates_attachment
    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_posts (
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
  created_at TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  KEY idx_announcement_posts_active (is_active, expires_at, created_at),
  CONSTRAINT fk_announcement_posts_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_attachments (
  announcement_post_id BIGINT NOT NULL,
  attachment_id BIGINT NOT NULL,
  PRIMARY KEY (announcement_post_id, attachment_id),
  CONSTRAINT fk_announcement_attachments_post
    FOREIGN KEY (announcement_post_id) REFERENCES announcement_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_attachments_attachment
    FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_recipients (
  announcement_post_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  role_snapshot ENUM('student','coordinator','company') NOT NULL,
  snapshot_basis VARCHAR(120) NOT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (announcement_post_id, user_id),
  KEY idx_announcement_recipients_user (user_id, announcement_post_id),
  CONSTRAINT fk_announcement_recipients_post
    FOREIGN KEY (announcement_post_id) REFERENCES announcement_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_announcement_recipients_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_certificate_template_migration_map (
  company_id INT NOT NULL,
  certificate_template_id INT NOT NULL,
  PRIMARY KEY (company_id),
  UNIQUE KEY uq_legacy_certificate_template (certificate_template_id),
  CONSTRAINT fk_legacy_certificate_template_company
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_certificate_template_target
    FOREIGN KEY (certificate_template_id) REFERENCES certificate_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_announcement_migration_map (
  legacy_announcement_id INT NOT NULL,
  announcement_post_id BIGINT NOT NULL,
  PRIMARY KEY (legacy_announcement_id),
  UNIQUE KEY uq_legacy_announcement_post (announcement_post_id),
  CONSTRAINT fk_legacy_announcement_source
    FOREIGN KEY (legacy_announcement_id) REFERENCES announcements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_legacy_announcement_target
    FOREIGN KEY (announcement_post_id) REFERENCES announcement_posts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
