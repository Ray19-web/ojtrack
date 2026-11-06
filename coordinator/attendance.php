<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');
$coordinatorId = (int)($coord['id'] ?? 0);
$students = $coordinatorId > 0 ? normalized_students_for_coordinator($coordinatorId) : [];
$studentsById = [];
foreach ($students as $student) $studentsById[(int)$student['id']] = $student;

/** Return every saved DTR day and its AM/PM punches for one authorized enrollment. */
function coordinator_attendance_dtr_rows(int $enrollmentId): array
{
    if ($enrollmentId <= 0) return [];
    $days = query(
        "SELECT ad.*,p.company_id
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id=?
         ORDER BY ad.attendance_date DESC,ad.id DESC",
        [$enrollmentId],
        'i'
    ) ?: [];
    if (!$days) return [];

    $dayIds = array_map(static fn(array $day): int => (int)$day['id'], $days);
    $placeholders = implode(',', array_fill(0, count($dayIds), '?'));
    $sessions = query(
        "SELECT attendance_day_id,time_in,time_out,credited_minutes
         FROM attendance_sessions
         WHERE attendance_day_id IN ($placeholders)
         ORDER BY attendance_day_id,COALESCE(time_in,'9999-12-31 23:59:59'),id",
        $dayIds,
        str_repeat('i', count($dayIds))
    ) ?: [];
    $sessionsByDay = [];
    foreach ($sessions as $session) $sessionsByDay[(int)$session['attendance_day_id']][] = $session;

    $formatTime = static fn($value): ?string => $value ? date('h:i A', strtotime((string)$value)) : null;
    $rows = [];
    foreach ($days as $day) {
        $daySessions = $sessionsByDay[(int)$day['id']] ?? [];
        $morning = [];
        $afternoon = [];
        foreach ($daySessions as $session) {
            if (empty($session['time_in'])) continue;
            if ((int)date('G', strtotime((string)$session['time_in'])) < 12) $morning[] = $session;
            else $afternoon[] = $session;
        }
        $firstMorning = $morning[0] ?? null;
        $lastMorning = $morning ? $morning[count($morning) - 1] : null;
        $firstAfternoon = $afternoon[0] ?? null;
        $lastAfternoon = $afternoon ? $afternoon[count($afternoon) - 1] : null;
        $day['date'] = $day['attendance_date'];
        $day['hours_rendered'] = round((int)$day['credited_minutes'] / 60, 2);
        $day['morning_in'] = $formatTime($firstMorning['time_in'] ?? null);
        $day['morning_out'] = $formatTime($lastMorning['time_out'] ?? null);
        $day['afternoon_in'] = $formatTime($firstAfternoon['time_in'] ?? null);
        $day['afternoon_out'] = $formatTime($lastAfternoon['time_out'] ?? null);
        $rows[] = $day;
    }
    return $rows;
}

/** Build a small text-and-vector PDF without relying on browser canvas capture. */
require_once __DIR__ . '/../includes/dtr-pdf.php';

$requestedStudentId = filter_input(INPUT_GET, 'attendance_student', FILTER_VALIDATE_INT) ?: 0;
$pdfStudentId = filter_input(INPUT_GET, 'download_attendance_pdf', FILTER_VALIDATE_INT) ?: 0;
if ($pdfStudentId > 0) {
    $student = $studentsById[$pdfStudentId] ?? null;
    if (!$student) {
        http_response_code(404);
        exit('Student attendance record not found.');
    }
    $records = coordinator_attendance_dtr_rows((int)$student['_enrollment_id']);
    $pdf = coordinator_dtr_pdf([
        'name' => (string)$student['name'],
        'student_id_no' => (string)($student['student_id_no'] ?? ''),
        'program' => (string)($student['program'] ?? ''),
        'company_name' => (string)($student['company_name'] ?? ''),
    ], $records);
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($student['student_id_no'] ?: $student['name'])) ?: 'student';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="DTR_' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
if ($requestedStudentId > 0) {
    $student = $studentsById[$requestedStudentId] ?? null;
    if (!$student) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'Student attendance record not found.']));
    }

    $dtrRows = coordinator_attendance_dtr_rows((int)$student['_enrollment_id']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    echo json_encode([
        'ok' => true,
        'student' => [
            'id' => (int)$student['id'],
            'name' => (string)$student['name'],
            'student_id_no' => (string)($student['student_id_no'] ?? ''),
            'program' => (string)($student['program'] ?? ''),
            'company_name' => (string)($student['company_name'] ?? ''),
        ],
        'records' => array_map(static fn(array $day): array => [
            'date' => (string)$day['date'],
            'day' => date('l', strtotime($day['date'])),
            'morning_in' => $day['morning_in'],
            'morning_out' => $day['morning_out'],
            'afternoon_in' => $day['afternoon_in'],
            'afternoon_out' => $day['afternoon_out'],
            'hours_rendered' => (float)$day['hours_rendered'],
            'status' => (string)($day['status'] ?? ''),
            'remarks' => (string)($day['remarks'] ?? ''),
        ], $dtrRows),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

$enrollmentIds = array_values(array_unique(array_filter(array_map(
    static fn(array $student): int => (int)($student['_enrollment_id'] ?? 0),
    $students
))));
$dtrDayCounts = [];
if ($enrollmentIds) {
    $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
    $counts = query(
        "SELECT p.ojt_enrollment_id,COUNT(*) day_count
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id IN ($placeholders)
         GROUP BY p.ojt_enrollment_id",
        $enrollmentIds,
        str_repeat('i', count($enrollmentIds))
    ) ?: [];
    foreach ($counts as $count) $dtrDayCounts[(int)$count['ojt_enrollment_id']] = (int)$count['day_count'];
}

$page_title = 'Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">Attendance</div>
  <div class="page-sub">Review connected students and their date-by-date daily time records.</div>
</div>

<div class="card coordinator-attendance-card">
  <div class="table-wrap" tabindex="0" role="region" aria-label="Connected students attendance overview">
    <table class="coordinator-attendance-table">
      <thead><tr><th>Student</th><th>Student ID</th><th>Company</th><th>OJT Hours</th><th>DTR Days</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($students as $student):
          $enrollmentId = (int)($student['_enrollment_id'] ?? 0);
          $renderedHours = (float)($student['rendered_hours'] ?? 0);
          $requiredHours = (int)($student['required_hours'] ?? 0);
        ?>
        <tr class="row-clickable coordinator-attendance-student-row" tabindex="0" role="button" aria-haspopup="dialog" aria-label="View attendance for <?= e($student['name']) ?>" data-attendance-student="<?= (int)$student['id'] ?>" title="View student DTR">
          <td><strong class="coordinator-attendance-student-name"><?= e($student['name']) ?></strong></td>
          <td class="td-mono text-xs"><?= e($student['student_id_no'] ?? '—') ?></td>
          <td class="text-sm"><?= e($student['company_name'] ?? '—') ?></td>
          <td class="td-mono font-bold"><?= number_format($renderedHours, 1) ?> / <?= number_format($requiredHours) ?>h</td>
          <td><span class="coordinator-attendance-day-count"><?= (int)($dtrDayCounts[$enrollmentId] ?? 0) ?></span></td>
          <td><?= status_badge((string)($student['ojt_status'] ?? 'not_started')) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$students): ?><tr><td colspan="6" class="text-center text-muted py-6">No students are connected to your coordinator account in the active academic term.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/coordinator-attendance-modal.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
