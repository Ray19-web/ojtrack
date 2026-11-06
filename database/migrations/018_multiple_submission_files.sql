CREATE TABLE IF NOT EXISTS requirement_submission_files (
  requirement_submission_id BIGINT NOT NULL,
  attachment_id BIGINT NOT NULL,
  PRIMARY KEY (requirement_submission_id, attachment_id),
  KEY idx_requirement_submission_files_attachment (attachment_id),
  CONSTRAINT fk_requirement_submission_files_submission FOREIGN KEY (requirement_submission_id) REFERENCES requirement_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_requirement_submission_files_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_submission_files (
  report_submission_id BIGINT NOT NULL,
  attachment_id BIGINT NOT NULL,
  PRIMARY KEY (report_submission_id, attachment_id),
  KEY idx_report_submission_files_attachment (attachment_id),
  CONSTRAINT fk_report_submission_files_submission FOREIGN KEY (report_submission_id) REFERENCES report_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_submission_files_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO requirement_submission_files(requirement_submission_id,attachment_id)
SELECT requirement_submission_id,attachment_id FROM requirement_submission_attachments;

INSERT IGNORE INTO report_submission_files(report_submission_id,attachment_id)
SELECT report_submission_id,attachment_id FROM report_submission_attachments;
