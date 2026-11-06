<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_login(['coordinator'], true);

$user = current_user();
$studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if (!$studentId) request_error(400, 'Choose a valid student.', true);

$coordinator = query_one('SELECT id FROM coordinators WHERE user_id=?', [(int)$user['id']], 'i');
if (!$coordinator) request_error(403, 'Coordinator profile not found.', true);

try {
    $rows = normalized_requirement_rows_for_coordinator((int)$coordinator['id'], '', '', $studentId);
    $items = [];
    foreach ($rows as $row) {
        // This view is an archive of submitted files; unsubmitted assignments have nothing to view or download.
        if (empty($row['submitted_at']) || empty($row['attachments'])) continue;
        foreach ($row['attachments'] as $attachment) {
            $items[] = [
                'requirement' => (string)$row['document_name'],
                'submitted_at' => date('M j, Y', strtotime((string)$row['submitted_at'])),
                'status' => (string)$row['status'],
                'file_name' => (string)$attachment['attachment_name'],
                'file_path' => (string)$attachment['file_path'],
                'size_bytes' => (int)($attachment['size_bytes'] ?? 0),
            ];
        }
    }
    echo json_encode(['ok'=>true, 'items'=>$items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('OJTrack coordinator requirement list failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Could not load this student’s requirements. Please try again.']);
}
