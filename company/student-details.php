<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company'], true);

header('Content-Type: text/html; charset=utf-8');

$user = current_user();
$company = query_one("SELECT id FROM companies WHERE user_id=?", [(int)$user['id']], 'i');
$studentId = (int)($_GET['student'] ?? 0);
$selected = null;

if ($company && $studentId > 0) {
    foreach (normalized_students_for_company((int)$company['id']) as $student) {
        if ((int)$student['id'] === $studentId) {
            $selected = $student;
            break;
        }
    }
}

if (!$selected) {
    http_response_code(404);
    ?><div class="empty-state"><p>This trainee is no longer assigned to your company.</p></div><?php
    exit;
}

$att_records = normalized_attendance_rows_for_student($studentId, '', 500);
$journal_cnt = count(normalized_journal_rows_for_student($studentId, 1000));
$reports = array_slice(array_reverse(normalized_report_rows_for_student($studentId)), 0, 5);
$student_evals = normalized_eval_requests_for_student($studentId);
$midterm_eval = null;
$final_eval = null;
foreach ($student_evals as $evaluation) {
    if ($evaluation['evaluation_kind'] === 'midterm' && $evaluation['status'] === 'completed') $midterm_eval = $evaluation;
    if ($evaluation['evaluation_kind'] === 'final' && $evaluation['status'] === 'completed') $final_eval = $evaluation;
}

require __DIR__ . '/../includes/company-trainee-details.php';
