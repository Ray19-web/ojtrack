<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$uid  = $user['id'];

$student = query_one("SELECT s.*, u.name, u.email, co.company_name, cu.name AS supervisor_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN companies co ON co.id = s.company_id
    LEFT JOIN users cu ON cu.id = co.user_id
    WHERE s.user_id = ?", [$uid], 'i');

$pct = $student['required_hours'] > 0
    ? min(100, round(($student['rendered_hours'] / $student['required_hours']) * 100))
    : 0;

$req_total    = query_one("SELECT COUNT(*) AS c FROM ojt_requirements WHERE student_id=?", [$student['id']], 'i')['c'];
$req_approved = query_one("SELECT COUNT(*) AS c FROM ojt_requirements WHERE student_id=? AND status='approved'", [$student['id']], 'i')['c'];
$req_pending  = $req_total - $req_approved;

$notifications = query("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5", [$uid], 'i');

$recent_reqs = query("SELECT * FROM ojt_requirements WHERE student_id=? ORDER BY FIELD(status,'rejected','pending','approved') LIMIT 5", [$student['id']], 'i');

$announcements = query("SELECT a.*, u.name AS author FROM announcements a JOIN users u ON u.id=a.created_by WHERE " . announcement_scope($user) . " ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 3");

$page_title = 'Student Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">My OJT Overview</div>
    <div class="page-sub">Welcome back, <?= e($student['name']) ?></div>
  </div>
</div>

<!-- OJT Banner -->
<div class="ojt-banner">
  <div>
    <p style="font-size:12px;opacity:.7;margin-bottom:4px">Current OJT Status</p>
    <div class="ojt-banner-title"><?= ucfirst(e($student['ojt_status'])) ?></div>
    <div class="ojt-banner-sub"><?= e($student['company_name'] ?? 'No company assigned yet') ?></div>
  </div>
  <div class="ojt-banner-right">
    
    <div class="ojt-banner-progress" data-pct="<?= $pct ?>"><div class="fill"></div></div>
    <div style="font-size:11px;opacity:.7;margin-top:4px"><?= $pct ?>% complete</div>
  </div>
</div>

<!-- Quick Time In Button -->
<?php
$today = date('Y-m-d');
$today_attendance = query_one("SELECT * FROM attendance WHERE student_id=? AND date=?", [$student['id'], $today], 'is');
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
<div class="stat-grid stat-grid-4 mb-5">
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
      <div class="stat-sub">On track</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon violet">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= max(0, $student['required_hours'] - $student['rendered_hours']) ?>h</span>Remaining</div>
      <div class="stat-sub">Based on official attendance</div>
    </div>
  </div>
</div>

<div class="dashboard-grid">
  <!-- Requirements summary -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Requirements Status</div>
      </div>
      <a href="/ojtrack/student/requirements.php" class="btn btn-ghost btn-sm">View All →</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Document</th><th>Submitted</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recent_reqs as $r): ?>
          <tr>
            <td><strong><?= e($r['document_name']) ?></strong></td>
            <td class="td-mono"><?= $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—' ?></td>
            <td><?= status_badge($r['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Progress + Notifications -->
  <div class="dashboard-side">
    <div class="card card-body">
      <div class="section-header"><div class="section-title">OJT Progress</div></div>
      <div class="space-y-4">
        <div class="progress-wrap">
          <div class="progress-label"><span class="text">Rendered Hours</span><span class="pct"><?= $pct ?>%</span></div>
          <div class="progress-track"><div class="progress-fill blue" data-pct="<?= $pct ?>"></div></div>
        </div>
        <div class="progress-wrap">
          <div class="progress-label"><span class="text">Requirements</span><span class="pct"><?= $req_total > 0 ? round(($req_approved/$req_total)*100) : 0 ?>%</span></div>
          <div class="progress-track"><div class="progress-fill green" data-pct="<?= $req_total>0?round(($req_approved/$req_total)*100):0 ?>"></div></div>
        </div>
      </div>
    </div>

    <div class="card card-body">
      <div class="section-header"><div class="section-title">Notifications</div></div>
      <div class="space-y-3">
        <?php foreach ($notifications as $n): ?>
        <div class="alert alert-<?= $n['notif_type'] === 'error' ? 'error' : ($n['notif_type'] === 'warning' ? 'warn' : 'info') ?>">
          <div class="alert-body">
            <p><?= e($n['message']) ?></p>
            <small><?= date('M d, Y', strtotime($n['created_at'])) ?></small>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?><p class="text-muted text-sm">No notifications.</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Announcements -->
<?php if (!empty($announcements)): ?>
<div class="card mt-5">
  <div class="card-header">
    <div class="card-title">Latest Announcements</div>
    <a href="/ojtrack/student/announcements.php" class="btn btn-ghost btn-sm">View All →</a>
  </div>
  <div class="card-body announcement-grid">
    <?php foreach ($announcements as $a): ?>
    <div class="announcement-card">
      <span class="announcement-tag"><?= e($a['tag']) ?></span>
      <div class="announcement-title"><?= e($a['title']) ?></div>
      <div class="announcement-body"><?= e(substr($a['body'], 0, 140)) ?>...</div>
      <div class="announcement-date"><?= date('F d, Y', strtotime($a['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/student-progress.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
