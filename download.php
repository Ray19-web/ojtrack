<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
$user = current_user();
$file = $_GET['file'] ?? '';
if (!is_string($file) || !preg_match('~^(requirements|reports|journal_proofs)/[a-zA-Z0-9_.-]+$~D', $file)) request_error(404, 'Document not found.');
$refs = query("SELECT s.user_id,s.coordinator_id,s.company_id,'requirement' AS kind FROM ojt_requirements r JOIN students s ON s.id=r.student_id WHERE r.file_path=?
    UNION ALL SELECT s.user_id,s.coordinator_id,s.company_id,'report' FROM reports r JOIN students s ON s.id=r.student_id WHERE r.file_path=?
    UNION ALL SELECT s.user_id,s.coordinator_id,s.company_id,'journal' FROM journal_entries j JOIN students s ON s.id=j.student_id WHERE j.proof_image=?", [$file,$file,$file], 'sss');
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
