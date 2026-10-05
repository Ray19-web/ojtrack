<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
$user = current_user();
$file = $_GET['file'] ?? '';
if (!is_string($file) || !preg_match('~^(requirements|reports|journal_proofs)/[a-zA-Z0-9_.-]+$~D', $file)) request_error(404, 'Document not found.');
$requirement_refs = query(
    "SELECT s.user_id,ec.coordinator_id,NULL company_id,'requirement' kind
     FROM requirement_submission_attachments rsa
     JOIN attachments a ON a.id=rsa.attachment_id
     JOIN requirement_submissions rs ON rs.id=rsa.requirement_submission_id
     JOIN requirement_assignments ra ON ra.id=rs.requirement_assignment_id
     JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
     JOIN students s ON s.id=oe.student_id
     LEFT JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
     WHERE a.storage_key=?",
    [$file],
    's'
) ?: [];
$report_refs = query(
    "SELECT s.user_id,ec.coordinator_id,p.company_id,'report' kind
     FROM report_submission_attachments rsa
     JOIN attachments a ON a.id=rsa.attachment_id
     JOIN report_submissions rs ON rs.id=rsa.report_submission_id
     JOIN report_assignments ra ON ra.id=rs.report_assignment_id
     JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
     JOIN students s ON s.id=oe.student_id
     LEFT JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
     LEFT JOIN placements p ON p.ojt_enrollment_id=oe.id
     WHERE a.storage_key=?",
    [$file],
    's'
) ?: [];
$journal_refs = query(
    "SELECT s.user_id,ec.coordinator_id,p.company_id,'journal' kind
     FROM journal_revision_attachments jra
     JOIN attachments a ON a.id=jra.attachment_id
     JOIN journal_revisions jr ON jr.id=jra.journal_revision_id
     JOIN journal_days jd ON jd.id=jr.journal_day_id
     JOIN placements p ON p.id=jd.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     JOIN students s ON s.id=oe.student_id
     LEFT JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
     WHERE a.storage_key=?",
    [$file],
    's'
) ?: [];
$refs = array_merge($requirement_refs, $report_refs, $journal_refs);
$allowed = false;
foreach ($refs as $ref) {
    if ($user['role'] === 'admin' || ($user['role'] === 'student' && (int)$ref['user_id'] === (int)$user['id'])) $allowed = true;
    if ($user['role'] === 'coordinator' && query_one("SELECT id FROM coordinators WHERE id=? AND user_id=?", [$ref['coordinator_id'],$user['id']], 'ii')) $allowed = true;
    // Companies already review trainee reports; pre-OJT private requirements remain academic-only.
    if ($user['role'] === 'company' && $ref['kind'] === 'report' && query_one("SELECT id FROM companies WHERE id=? AND user_id=?", [$ref['company_id'],$user['id']], 'ii')) $allowed = true;
}
if (!$allowed) request_error(404, 'Document not found.');
try {
    $path = resolve_private_document($file);
} catch (RuntimeException $error) {
    error_log('OJTrack private download storage unavailable.');
    request_error(503, 'Document storage is temporarily unavailable.');
}
if (!$path) request_error(404, 'Document not found.');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$preview = ($_GET['preview'] ?? '') === '1' && in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true);
header('Content-Type: ' . ($preview ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
