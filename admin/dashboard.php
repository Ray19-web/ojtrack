<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();

$active_student_where = "s.is_archived = 0 AND u.status != 'archived'";

$stats = [
    'users'    => query_one("SELECT COUNT(*) AS c FROM users WHERE status != 'archived'", [], '')['c'],
    'students' => query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE $active_student_where", [], '')['c'],
    'companies'=> query_one("SELECT COUNT(*) AS c FROM companies", [], '')['c'],
    'coords'   => query_one("SELECT COUNT(*) AS c FROM coordinators", [], '')['c'],
];

$ojt_stats = [
    'not_started' => query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.ojt_status='not_started' AND $active_student_where", [], '')['c'],
    'ongoing'     => query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.ojt_status='ongoing' AND $active_student_where", [], '')['c'],
    'completed'   => query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.ojt_status='completed' AND $active_student_where", [], '')['c'],
];

$recent_activity = query("SELECT al.*, u.name AS actor FROM activity_log al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC LIMIT 15", [], '');
$pending_reqs    = query_one("SELECT COUNT(*) AS c FROM ojt_requirements WHERE status='pending'", [], '')['c'];
$pending_reports = query_one("SELECT COUNT(*) AS c FROM reports WHERE status='for_review'", [], '')['c'];

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-hero">
  <div class="admin-hero-copy">
    <div class="admin-hero-kicker">
      
      System Control Center
    </div>
    <h2 class="admin-hero-title">Welcome back, <?= e(explode(' ', $user['name'])[0]) ?></h2>
    <p class="admin-hero-sub">OJTRACK · USTP Jasaan · <?= date('l, F j, Y') ?></p>
  </div>
  <div class="admin-hero-actions">
    <a href="/ojtrack/admin/users.php" class="btn btn-ghost-light btn-sm">Manage Users</a>
    <a href="/ojtrack/admin/announcements.php" class="btn btn-accent btn-sm">+ Post Announcement</a>
  </div>
</div>

<div class="stat-grid stat-grid-4 mb-5">
  <a href="/ojtrack/admin/users.php" class="stat-card stat-card-link">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['users'] ?></span>Total Users</div>
      <div class="stat-sub"><?= $stats['students'] ?> students · <?= $stats['coords'] ?> coordinators</div>
    </div>
  </a>
  <a href="/ojtrack/admin/companies.php" class="stat-card stat-card-link">
    <div class="stat-icon amber">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['companies'] ?></span>Companies</div>
      <div class="stat-sub">Partner host organizations</div>
    </div>
  </a>
  <a href="/ojtrack/admin/students.php" class="stat-card stat-card-link">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $pending_reqs ?></span>Pending Requirements</div>
      <div class="stat-sub">Awaiting coordinator review</div>
    </div>
  </a>
  <a href="/ojtrack/admin/activity.php" class="stat-card stat-card-link">
    <div class="stat-icon violet">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $pending_reports ?></span>Reports for Review</div>
      <div class="stat-sub">Submitted student reports</div>
    </div>
  </a>
</div>

<div class="dashboard-grid">
  <div>
    <div class="card card-body mb-4">
      <div class="section-title mb-4">OJT Status Overview</div>
      <?php $total = $stats['students'] ?: 1;
      $bars = [
        'ongoing'     => ['label' => 'Ongoing',     'val' => $ojt_stats['ongoing'],     'color' => 'blue'],
        'completed'   => ['label' => 'Completed',   'val' => $ojt_stats['completed'],   'color' => 'green'],
        'not_started' => ['label' => 'Not Started', 'val' => $ojt_stats['not_started'], 'color' => 'amber'],
      ];
      foreach ($bars as $bar): $pct = round(($bar['val'] / $total) * 100); ?>
      <div class="progress-wrap mb-3">
        <div class="progress-label"><span class="text"><?= $bar['label'] ?></span><span class="pct"><?= $bar['val'] ?> students </span></div>
        <div class="progress-track"><div class="progress-fill <?= $bar['color'] ?>" data-pct="<?= $pct ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">Recent Activity</div>
        <a href="/ojtrack/admin/activity.php" class="btn btn-secondary btn-sm">View All</a>
      </div>
      <div>
        <?php foreach ($recent_activity as $act): ?>
        <div class="activity-row">
          <div class="activity-dot"></div>
          <div class="activity-body">
            <div class="text-sm font-bold"><?= e($act['action']) ?></div>
            <?php if ($act['details']): ?><div class="text-xs text-muted"><?= e($act['details']) ?></div><?php endif; ?>
            <div class="text-xs text-muted"><?= e($act['actor'] ?? 'System') ?> · <?= date('M d, h:i A', strtotime($act['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($recent_activity)): ?><div class="empty-state"><p>No activity logs.</p></div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="dashboard-side">
    <div class="card card-body">
      <div class="section-title mb-3">Administration</div>
      <p class="text-sm text-muted">Review assignments and account status regularly. Keep a tested backup of your database and uploaded documents.</p>
    </div>

    <div class="card card-body">
      <div class="section-title mb-3">Quick Access</div>
      <div class="quick-access-list">
        <a href="/ojtrack/admin/users.php" class="btn btn-secondary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Manage Users
        </a>
        <a href="/ojtrack/admin/companies.php" class="btn btn-secondary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
          Manage Companies
        </a>
        <a href="/ojtrack/admin/students.php" class="btn btn-secondary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
          View All Students
        </a>
        <a href="/ojtrack/admin/announcements.php" class="btn btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3z"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          Post Announcement
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
