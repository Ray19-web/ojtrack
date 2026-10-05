<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$uid     = (int)$user['id'];
$student = normalized_student_context_by_user($uid);
$sid     = (int)($student['id'] ?? 0);

$month_filter = $_GET['month'] ?? '';
$records = normalized_attendance_rows_for_student($sid, $month_filter, 60);

$all_records = normalized_attendance_rows_for_student($sid, '', 500);
$totals = [
    'total_days' => count($all_records),
    'present' => count(array_filter($all_records, fn($r) => $r['status'] === 'present')),
    'absent' => count(array_filter($all_records, fn($r) => $r['status'] === 'absent')),
    'excused' => count(array_filter($all_records, fn($r) => in_array($r['status'], ['excused','leave'], true))),
    'total_hours' => array_sum(array_column($all_records, 'hours_rendered')),
];

$pct = ($student['required_hours'] ?? 0) > 0
    ? min(100, round((($student['rendered_hours'] ?? 0) / $student['required_hours']) * 100))
    : 0;

$page_title = 'Attendance DTR';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">Daily Time Record</div>
  <div class="page-sub">Time-in and time-out are recorded by your OJT company. You can only view your official record.</div>
</div>

<div class="card card-body mb-4" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
  <div>
    <div class="text-sm font-bold">Cumulative OJT Completion</div>
    <div class="text-xs text-muted"><?= number_format($student['rendered_hours'], 1) ?> / <?= number_format($student['required_hours'], 0) ?> hours</div>
  </div>
  <div>
    <div class="progress-track"><div class="progress-fill blue" data-pct="<?= $pct ?>"></div></div>
  </div>
</div>

<div class="card">
  <div class="card-header flex-between">
    <div class="card-title">DTR — Attendance Record</div>
    <form method="GET" class="flex-items-center gap-2">
      <input type="month" name="month" value="<?= e($month_filter) ?>" class="form-control text-sm" style="width:160px" onchange="this.form.submit()">
      <?php if ($month_filter): ?>
        <a href="/ojtrack/student/attendance.php" class="btn btn-ghost btn-sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table id="attTable">
      <thead>
        <tr>
          <th rowspan="2">Date</th>
          <th rowspan="2">Day</th>
          <th colspan="2" style="text-align:center">AM</th>
          <th colspan="2" style="text-align:center">PM</th>
          <th rowspan="2">Total Hours</th>
          <th rowspan="2">Status</th>
        </tr>
        <tr>
          <th>In</th>
          <th>Out</th>
          <th>In</th>
          <th>Out</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td class="td-mono text-sm font-bold"><?= date('M d, Y', strtotime($r['date'])) ?></td>
            <td class="text-sm text-muted"><?= date('D', strtotime($r['date'])) ?></td>
            <td class="td-mono text-sm"><?= $r['morning_in'] ? format_time($r['morning_in']) : '—' ?></td>
            <td class="td-mono text-sm"><?= $r['morning_out'] ? format_time($r['morning_out']) : '—' ?></td>
            <td class="td-mono text-sm"><?= $r['afternoon_in'] ? format_time($r['afternoon_in']) : '—' ?></td>
            <td class="td-mono text-sm"><?= $r['afternoon_out'] ? format_time($r['afternoon_out']) : '—' ?></td>
            <td class="font-mono text-sm font-bold <?= $r['hours_rendered'] > 0 ? 'text-success' : 'text-muted' ?>">
              <?= $r['hours_rendered'] > 0 ? number_format($r['hours_rendered'], 2) . 'h' : '—' ?>
            </td>
            <td><?= status_badge($r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?>
          <tr><td colspan="8" class="text-center text-muted py-6">No attendance records found.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700">
          <td colspan="6">Total this period</td>
          <td class="font-mono"><?= number_format(array_sum(array_column($records, 'hours_rendered')), 2) ?>h</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
