<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$date   = $_GET['date'] ?? date('Y-m-d');
$search = trim($_GET['q'] ?? '');

$records = normalized_attendance_rows_for_coordinator_date((int)$coord['id'], $date, $search);

$totals = [
    'present' => 0, 'absent' => 0, 'excused' => 0, 'logged' => 0, 'total' => count($records)
];
foreach ($records as $r) {
    if ($r['att_status'] === 'present') $totals['present']++;
    elseif ($r['att_status'] === 'absent') $totals['absent']++;
    elseif ($r['att_status'] === 'excused') $totals['excused']++;
    if ($r['time_in']) $totals['logged']++;
}

$page_title = 'Attendance Monitoring';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Attendance Monitoring</div>
    <div class="page-sub">Track daily attendance, time-in/out logs, and hours rendered</div>
  </div>
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="date" name="date" value="<?= e($date) ?>" class="form-control" style="width:180px" onchange="this.form.submit()">
    <div class="search-wrap" style="width:200px">
      <input type="text" name="q" class="form-control search-input" placeholder="Search student/ID..." value="<?= e($search) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($search || $date !== date('Y-m-d')): ?><a href="/ojtrack/coordinator/attendance.php" class="btn btn-ghost">Today</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="card-header flex-between">
    <div class="card-title">Attendance Log · <?= date('l, F d, Y', strtotime($date)) ?></div>
    <span class="text-sm text-muted"><?= count($records) ?> records</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Student</th><th>Student ID</th><th>Company</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th><th>Remarks</th></tr></thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <td>
            <a href="/ojtrack/coordinator/students.php?id=<?= $r['id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
              <?= e($r['name']) ?>
            </a>
          </td>
          <td class="td-mono text-xs"><?= e($r['student_id_no'] ?? '—') ?></td>
          <td class="text-sm"><?= e($r['company_name'] ?? '—') ?></td>
          <td class="td-mono"><?= $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '<span class="text-muted">—</span>' ?></td>
          <td class="td-mono"><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '<span class="text-muted">—</span>' ?></td>
          <td class="td-mono font-bold"><?= $r['hours_rendered'] ? number_format($r['hours_rendered'],2) . 'h' : '—' ?></td>
          <td>
            <?php if (!empty($r['att_status'])): ?>
              <?= status_badge($r['att_status']) ?>
            <?php else: ?>
              <span class="badge" style="background:#f1f5f9;color:#64748b">Not Logged</span>
            <?php endif; ?>
          </td>
          <td class="text-xs text-muted"><?= $r['remarks'] ? e($r['remarks']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($records)): ?><tr><td colspan="8" class="text-center text-muted py-6">No students or attendance records found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
