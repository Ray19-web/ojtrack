<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$stats = [
    'trainees'    => query_one("SELECT COUNT(*) AS c FROM students WHERE company_id=?", [$company['id']], 'i')['c'],
    'active'      => query_one("SELECT COUNT(*) AS c FROM students WHERE company_id=? AND ojt_status='ongoing'", [$company['id']], 'i')['c'],
    'completed'   => query_one("SELECT COUNT(*) AS c FROM students WHERE company_id=? AND ojt_status='completed'", [$company['id']], 'i')['c'],
    'pending_eval'=> query_one("SELECT COUNT(*) AS c FROM evaluations e JOIN students s ON s.id=e.student_id WHERE s.company_id=? AND e.status='pending'", [$company['id']], 'i')['c'],
];

$trainees = query("SELECT s.*, u.name, u.email FROM students s JOIN users u ON u.id=s.user_id WHERE s.company_id=? ORDER BY u.name", [$company['id']], 'i');
$today_att = query("SELECT a.*, u.name FROM attendance a JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id WHERE s.company_id=? AND a.date=CURDATE()", [$company['id']], 'i');
$announcements = query(
    "SELECT a.*, u.name AS author FROM announcements a
     LEFT JOIN users u ON u.id=a.created_by
     WHERE a.target_role IN ('all','company') AND a.is_active=1 AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
     ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 4"
);

$page_title = 'Company Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">Company Dashboard</div>
  <div class="page-sub">Welcome, <?= e($company['company_name']) ?> · <?= date('F d, Y') ?></div>
</div>

<div class="stat-grid stat-grid-4 mb-5">
  <div class="stat-card">
    <div class="stat-icon blue">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['trainees'] ?></span>Total Trainees</div>
      <div class="stat-sub">Assigned to your company</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['active'] ?></span>Active OJT</div>
      <div class="stat-sub">Currently ongoing training</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon amber">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 15l2 2 4-4"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['completed'] ?></span>Completed</div>
      <div class="stat-sub">Finished training programs</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon violet">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['pending_eval'] ?></span>Pending Evaluations</div>
      <div class="stat-sub">Awaiting your assessment</div>
    </div>
  </div>
</div>

<div class="dashboard-grid-2">
  <div>
    <div class="card mb-4">
      <div class="card-header">
        <div class="card-title">Today's Attendance</div>
        <span class="text-sm text-muted"><?= date('F d, Y') ?></span>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (empty($today_att)): ?>
              <tr><td colspan="5" class="text-center text-muted">No attendance logged today.</td></tr>
            <?php else: ?>
              <?php foreach ($today_att as $a): ?>
              <tr>
                <td class="font-bold"><?= e($a['name']) ?></td>
                <td class="td-mono"><?= $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '—' ?></td>
                <td class="td-mono"><?= $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '—' ?></td>
                <td class="font-mono font-bold"><?= $a['hours_rendered'] ? number_format($a['hours_rendered'],2) . 'h' : '—' ?></td>
                <td><?= status_badge($a['status'] === 'present' ? 'approved' : ($a['status'] === 'excused' ? 'pending' : 'rejected')) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card card-body">
      <div class="section-title mb-4">Trainees Progress</div>
      <?php foreach ($trainees as $t): $pct = $t['required_hours']>0?min(100,round(($t['rendered_hours']/$t['required_hours'])*100)):0; ?>
      <div class="progress-wrap mb-3">
        <div class="progress-label">
          <span class="text"><?= e($t['name']) ?></span>
          <span class="pct"><?= number_format($t['rendered_hours'],0) ?> / <?= $t['required_hours'] ?>h</span>
        </div>
        <div class="progress-track"><div class="progress-fill <?= $pct>=100?'green':($pct>=50?'blue':'amber') ?>" data-pct="<?= $pct ?>"></div></div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($trainees)): ?><p class="text-sm text-muted">No trainees assigned.</p><?php endif; ?>
    </div>
  </div>

  <div class="dashboard-side">
    <div class="card card-body">
      <div class="section-title mb-3">Announcements</div>
      <?php if (empty($announcements)): ?><p class="text-sm text-muted">No announcements.</p>
      <?php else: ?>
        <?php foreach ($announcements as $a): ?>
        <div class="side-list-item">
          <div class="side-list-title"><?= e($a['title']) ?></div>
          <div class="text-xs text-muted"><?= format_date($a['created_at']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <a href="/ojtrack/company/announcements.php" class="btn btn-secondary btn-sm mt-3 w-full">View All</a>
    </div>
    <div class="card card-body">
      <div class="section-title mb-3">Quick Actions</div>
      <div class="quick-access-list">
        <a href="/ojtrack/company/students.php" class="btn btn-secondary">View Trainees</a>
        <a href="/ojtrack/company/evaluation.php" class="btn btn-secondary">Submit Evaluation</a>
        <a href="/ojtrack/company/attendance.php" class="btn btn-secondary">Attendance Records</a>
        <a href="/ojtrack/company/messages.php" class="btn btn-primary">Messages</a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
