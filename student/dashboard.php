<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$uid  = $user['id'];

$student = normalized_student_context_by_user((int)$uid);
if (!$student) request_error(404, 'Student profile not found.');
$student['supervisor_name'] = null;
if (!empty($student['company_id'])) {
    $supervisor = query_one(
        "SELECT supervisor_name AS name FROM companies WHERE id=? LIMIT 1",
        [(int)$student['company_id']],
        'i'
    );
    $student['supervisor_name'] = $supervisor['name'] ?? null;
}

$pct = $student['required_hours'] > 0
    ? min(100, round(($student['rendered_hours'] / $student['required_hours']) * 100))
    : 0;
$remaining_hours = max(0.0, (float)$student['required_hours'] - (float)$student['rendered_hours']);
$remaining_hours_display = rtrim(rtrim(number_format($remaining_hours, 1), '0'), '.');
$ojt_progress_tone = ($student['ojt_status'] ?? '') === 'completed' || $pct >= 100
    ? 'complete'
    : ($pct >= 25 ? 'progress' : 'starting');

$requirement_rows = normalized_requirement_rows_for_student((int)$student['id']);
$req_total = count($requirement_rows);
$req_approved = count(array_filter($requirement_rows, fn($row) => ($row['status'] ?? '') === 'approved'));
$req_pending = $req_total - $req_approved;

$attendance_series = [];
if (!empty($student['_enrollment_id'])) {
    $attendance_series = query("SELECT ad.attendance_date chart_date, ROUND(SUM(ad.credited_minutes)/60, 1) chart_value FROM attendance_days ad JOIN placements pl ON pl.id=ad.placement_id WHERE pl.ojt_enrollment_id=? AND ad.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND ad.attendance_date <= CURDATE() GROUP BY ad.attendance_date ORDER BY ad.attendance_date", [(int)$student['_enrollment_id']], 'i') ?: [];
}
$requirement_counts = ['approved'=>0,'pending'=>0,'rejected'=>0];
foreach ($requirement_rows as $row) { $status = (string)($row['status'] ?? 'pending'); if (isset($requirement_counts[$status])) $requirement_counts[$status]++; }
$dashboard_insights = [
    'title'=>'Attendance hours','description'=>'Officially credited attendance for the past seven days',
    'unit'=>'hours','total_label'=>'hours this week','segments_title'=>'Requirements','series_rows'=>$attendance_series,
    'segments'=>[
        ['label'=>'Approved','color'=>'green','value'=>$requirement_counts['approved']],
        ['label'=>'Pending','color'=>'amber','value'=>$requirement_counts['pending']],
        ['label'=>'Needs revision','color'=>'red','value'=>$requirement_counts['rejected']],
    ],
];

$page_title = 'Student Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- OJT Banner -->
<div class="ojt-banner ojt-banner-<?= e($ojt_progress_tone) ?>">
  <div>
    <p style="font-size:12px;opacity:.7;margin-bottom:4px">Current OJT Status</p>
    <div class="ojt-banner-title"><?= ucfirst(e($student['ojt_status'])) ?></div>
    <div class="ojt-banner-sub"><?= e($student['company_name'] ?? 'No company assigned yet') ?></div>
  </div>
  <div class="ojt-banner-right">

    <div class="ojt-banner-progress" data-pct="<?= $pct ?>"><div class="fill"></div></div>
    <div class="ojt-banner-progress-label"><?= $pct ?>% complete</div>
  </div>
</div>

<!-- Quick Time In Button -->
<?php
$today = date('Y-m-d');
$today_attendance = normalized_attendance_for_student_date((int)$student['id'], $today);
$all_recorded = $today_attendance && $today_attendance['morning_in'] && $today_attendance['morning_out'] && $today_attendance['afternoon_in'] && $today_attendance['afternoon_out'];
?>
<?php if (!$all_recorded): ?>
<?php else: ?>
<div class="card card-body mb-4" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;padding:20px 24px;background:#ecfdf5;border-color:#a7f3d0">
  <div>
    <div class="font-bold text-base mb-1" style="color:#059669">All Time Records Complete</div>
    <div class="text-sm text-muted">You have recorded all time slots for today. Great job!</div>
  </div>
  <a href="/ojtrack/student/attendance.php" class="btn btn-secondary">View Attendance</a>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="stat-grid stat-grid-4 dashboard-stats mb-5">
  <div class="stat-card">
    <div class="stat-icon blue">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= number_format($student['rendered_hours']) ?>h</span>Rendered Hours</div>
      <div class="stat-sub">of <?= number_format($student['required_hours']) ?> required</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $req_approved ?>/<?= $req_total ?></span>Requirements</div>
      <div class="stat-sub"><?= $req_pending ?> pending</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon amber">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $pct ?>%</span>OJT Progress</div>

    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon violet">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $remaining_hours_display ?>h</span>Remaining</div>
      <div class="stat-sub">Based on official attendance</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/dashboard-insights.php'; ?>

<?php require __DIR__ . '/../includes/student-progress.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
