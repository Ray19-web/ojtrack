<?php
// Add collection tables for requirement/report attachments and preserve existing files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';

$options = getopt('', ['apply', 'dry-run']);
$version = '018_multiple_submission_files';
$required = [
    'attachments', 'requirement_submissions', 'report_submissions',
    'requirement_submission_attachments', 'report_submission_attachments', 'schema_migrations',
];
foreach ($required as $object) {
    if (!query_one('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$object], 's')) {
        fwrite(STDERR, "Missing prerequisite table or view: $object\n"); exit(1);
    }
}

$countRows = static fn(string $table): int => (int)(query_one("SELECT COUNT(*) c FROM `$table`")['c'] ?? 0);
$sourceCounts = [
    'requirements' => $countRows('requirement_submission_attachments'),
    'reports' => $countRows('report_submission_attachments'),
];
$applied = query_one('SELECT version FROM schema_migrations WHERE version=? LIMIT 1', [$version], 's');
echo "READY: preserve {$sourceCounts['requirements']} requirement file link(s) and {$sourceCounts['reports']} report file link(s); create or reuse two submission-file tables.\n";
if (!$applied && (!isset($options['apply']) || isset($options['dry-run']))) exit("Preview only. Apply with --apply.\n");

query("CREATE TABLE IF NOT EXISTS requirement_submission_files (
    requirement_submission_id BIGINT NOT NULL,
    attachment_id BIGINT NOT NULL,
    PRIMARY KEY (requirement_submission_id, attachment_id),
    KEY idx_requirement_submission_files_attachment (attachment_id),
    CONSTRAINT fk_requirement_submission_files_submission FOREIGN KEY (requirement_submission_id) REFERENCES requirement_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_requirement_submission_files_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
query("CREATE TABLE IF NOT EXISTS report_submission_files (
    report_submission_id BIGINT NOT NULL,
    attachment_id BIGINT NOT NULL,
    PRIMARY KEY (report_submission_id, attachment_id),
    KEY idx_report_submission_files_attachment (attachment_id),
    CONSTRAINT fk_report_submission_files_submission FOREIGN KEY (report_submission_id) REFERENCES report_submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_submission_files_attachment FOREIGN KEY (attachment_id) REFERENCES attachments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
query('INSERT IGNORE INTO requirement_submission_files(requirement_submission_id,attachment_id) SELECT requirement_submission_id,attachment_id FROM requirement_submission_attachments');
query('INSERT IGNORE INTO report_submission_files(report_submission_id,attachment_id) SELECT report_submission_id,attachment_id FROM report_submission_attachments');

$copiedCounts = [
    'requirements' => $countRows('requirement_submission_files'),
    'reports' => $countRows('report_submission_files'),
];
$missingRequirements=(int)(query_one(
    'SELECT COUNT(*) c FROM requirement_submission_attachments old
     LEFT JOIN requirement_submission_files new
       ON new.requirement_submission_id=old.requirement_submission_id AND new.attachment_id=old.attachment_id
     WHERE new.attachment_id IS NULL'
)['c'] ?? 0);
$missingReports=(int)(query_one(
    'SELECT COUNT(*) c FROM report_submission_attachments old
     LEFT JOIN report_submission_files new
       ON new.report_submission_id=old.report_submission_id AND new.attachment_id=old.attachment_id
     WHERE new.attachment_id IS NULL'
)['c'] ?? 0);
if ($missingRequirements>0 || $missingReports>0) {
    throw new RuntimeException('Not all existing submission attachments were preserved. The migration version was not recorded.');
}
query('INSERT INTO schema_migrations(version,description) VALUES(?,?) ON DUPLICATE KEY UPDATE description=VALUES(description)',
    [$version, 'Support multiple files on requirement and report submissions while preserving existing attachments'], 'ss');
echo "Verified: {$copiedCounts['requirements']} requirement file link(s), {$copiedCounts['reports']} report file link(s). Migration recorded.\n";
