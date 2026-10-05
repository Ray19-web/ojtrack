<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$coord_students = normalized_students_for_coordinator((int)$coord['id']);
$pending_all = normalized_requirement_rows_for_coordinator((int)$coord['id'], 'pending');
$stats = [
    'total'   => count($coord_students),
    'active'  => count(array_filter($coord_students, fn($row) => ($row['ojt_status'] ?? '') === 'ongoing')),
    'on_hold' => count(array_filter($coord_students, fn($row) => in_array(($row['ojt_status'] ?? ''), ['on_hold','withdrawn'], true))),
    'pending' => count($pending_all),
];

$pending_reqs = array_slice($pending_all, 0, 8);

$students = $coord_students;
usort($students, fn($a,$b) => ((float)($b['rendered_hours'] ?? 0)) <=> ((float)($a['rendered_hours'] ?? 0)));
$students = array_slice($students, 0, 6);

$announcements = array_slice(normalized_announcements_for_user((int)$user['id']), 0, 4);

$page_title = 'Coordinator Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">Coordinator Dashboard</div>
  <div class="page-sub">Welcome back, <?= e($user['name']) ?> · <?= date('l, F d, Y') ?></div>
</div>

<div class="stat-grid stat-grid-4 mb-5">
  <div class="stat-card">
    <div class="stat-icon blue">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['total'] ?></span>Total Students</div>
      <div class="stat-sub">Assigned to your department</div>
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
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['on_hold'] ?></span>On Hold / Stopped</div>
      <div class="stat-sub">Requires attention</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon violet">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div>
      <div class="stat-label"><span class="stat-value"><?= $stats['pending'] ?></span>Pending Requirements</div>
      <div class="stat-sub">Awaiting your review</div>
    </div>
  </div>
</div>

<div class="dashboard-grid-2">
  <div>
    <div class="card mb-4">
      <div class="card-header">
        <div class="card-title">Pending Requirements for Review</div>
        <a href="/ojtrack/coordinator/requirements.php" class="btn btn-secondary btn-sm">View All</a>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Student</th><th>Document</th><th>Submitted</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($pending_reqs)): ?>
              <tr><td colspan="4" class="text-center text-muted">No pending requirements.</td></tr>
            <?php else: ?>
              <?php foreach ($pending_reqs as $r): ?>
              <tr>
                <td><strong><?= e($r['student_name']) ?></strong><div class="text-xs text-muted"><?= e($r['program']) ?></div></td>
                <td><?= e($r['document_name']) ?></td>
                <td class="td-mono"><?= format_date($r['submitted_at']) ?></td>
                <td>
                  <div class="table-actions">
                    <a href="/ojtrack/coordinator/requirements.php?review=<?= $r['id'] ?>"
                       class="table-action-icon is-primary" title="Review requirement" aria-label="Review requirement">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h4"/><path d="m14.5 17 1.5 1.5 3-3"/></svg>
                    </a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card card-body">
      <div class="section-title mb-4">Student OJT Hours Progress</div>
      <?php foreach ($students as $s): $pct = $s['required_hours'] > 0 ? min(100, round(($s['rendered_hours']/$s['required_hours'])*100)) : 0; ?>
      <div class="progress-wrap mb-3">
        <div class="progress-label">
          <span class="text"><?= e($s['name']) ?></span>
          <span class="pct"><?= number_format($s['rendered_hours'], 0) ?> / <?= $s['required_hours'] ?>h</span>
        </div>
        <div class="progress-track"><div class="progress-fill <?= $pct >= 100 ? 'green' : ($pct >= 50 ? 'blue' : 'amber') ?>" data-pct="<?= $pct ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="dashboard-side">
    <div class="card card-body">
      <div class="section-title mb-3">Announcements</div>
      <?php if (empty($announcements)): ?>
        <p class="text-sm text-muted">No announcements.</p>
      <?php else: ?>
        <?php foreach ($announcements as $a): ?>
        <div class="side-list-item">
          <?php if ($a['is_pinned']): ?><span class="pin-pill">PINNED</span><?php endif; ?>
          <div class="side-list-title"><?= e($a['title']) ?></div>
          <div class="text-xs text-muted"><?= format_date($a['created_at']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <a href="/ojtrack/coordinator/announcements.php" class="btn btn-secondary btn-sm mt-3 w-full">Manage Announcements</a>
    </div>

    <div class="card card-body">
      <div class="section-title mb-3">Quick Actions</div>
      <div class="quick-access-list">
        <a href="/ojtrack/coordinator/students.php" class="btn btn-secondary">View All Students</a>
        <a href="/ojtrack/coordinator/requirements.php" class="btn btn-secondary">Review Requirements</a>
        <a href="/ojtrack/coordinator/monitoring.php" class="btn btn-secondary">Monitor Progress</a>
        <a href="/ojtrack/coordinator/announcements.php?compose=1" class="btn btn-primary">+ Post Announcement</a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
