<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$student_id = isset($_GET['student']) ? (int)$_GET['student'] : 0;
$month = $_GET['month'] ?? date('Y-m');

$students = normalized_students_for_company((int)$company['id']);
$records = normalized_attendance_rows_for_company((int)$company['id'], $student_id, $month);

$summary = [
    'present' => 0, 'absent' => 0, 'excused' => 0, 'total_hours' => 0
];
foreach ($records as $r) {
    if ($r['status'] === 'present') $summary['present']++;
    elseif ($r['status'] === 'absent') $summary['absent']++;
    elseif ($r['status'] === 'excused') $summary['excused']++;
    $summary['total_hours'] += (float)$r['hours_rendered'];
}

$page_title = 'Attendance Records';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Attendance Records</div>
    <div class="page-sub">View daily attendance logs, clock times, and rendered hours for your trainees</div>
  </div>
  <form method="GET" style="display:flex;gap:8px">
    <select name="student" class="form-control" style="width:200px" onchange="this.form.submit()">
      <option value="">All Trainees</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $s['id']==$student_id?'selected':'' ?>><?= e($s['name']) ?> (<?= e($s['student_id_no'] ?? '') ?>)</option>
      <?php endforeach; ?>
    </select>
    <input type="month" name="month" value="<?= e($month) ?>" class="form-control" style="width:180px" onchange="this.form.submit()">
    <?php if ($student_id || $month !== date('Y-m')): ?><a href="/ojtrack/company/attendance.php" class="btn btn-ghost">Reset</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="card-header flex-between">
    <div class="card-title"><?= date('F Y', strtotime($month . '-01')) ?> Attendance Logs</div>
    <span class="text-sm text-muted"><?= count($records) ?> records found</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php if (!$student_id): ?><th>Trainee</th><?php endif; ?>
          <th>Date</th>
          <th>Day</th>
          <th>Time In</th>
          <th>Time Out</th>
          <th>Hours</th>
          <th>Status</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <?php if (!$student_id): ?>
          <td>
            <a href="/ojtrack/company/students.php?id=<?= $r['student_id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
              <?= e($r['student_name'] ?? '') ?>
            </a>
            <div class="text-xs text-muted"><?= e($r['student_id_no'] ?? '') ?></div>
          </td>
          <?php endif; ?>
          <td class="td-mono"><?= date('M d, Y', strtotime($r['date'])) ?></td>
          <td class="text-muted text-sm"><?= date('l', strtotime($r['date'])) ?></td>
          <td class="td-mono"><?= $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—' ?></td>
          <td class="td-mono"><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
          <td class="font-mono font-bold text-primary"><?= $r['hours_rendered'] ? number_format($r['hours_rendered'],2) . 'h' : '—' ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-xs text-muted"><?= $r['remarks'] ? e($r['remarks']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?><tr><td colspan="<?= $student_id ? 7 : 8 ?>" class="text-center text-muted py-6">No attendance records found for this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
