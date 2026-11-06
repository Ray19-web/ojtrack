CREATE TABLE IF NOT EXISTS journal_additional_attachments (
 journal_revision_id BIGINT UNSIGNED NOT NULL,
 attachment_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (journal_revision_id, attachment_id)
);
CREATE OR REPLACE VIEW journal_revision_attachments AS
 SELECT id AS journal_revision_id, attachment_id FROM journal_revisions WHERE attachment_id IS NOT NULL
 UNION SELECT journal_revision_id, attachment_id FROM journal_additional_attachments;
