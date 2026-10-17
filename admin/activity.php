<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
require_once __DIR__ . '/../config/activity-log.php';
$filters = activity_log_filters($_GET);
$search = $filters['search'];
$date = $filters['date'];
$role_filter = $filters['role'];
$where = $filters['where'];
$params = $filters['params'];
$types = $filters['types'];

$logs = query("SELECT al.*, u.name AS actor, u.avatar, u.role AS actor_role FROM activity_log al LEFT JOIN users u ON u.id=al.user_id WHERE $where ORDER BY al.created_at DESC, al.id DESC LIMIT 200",
    $params, $types);

$page_title = 'Activity Log';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Activity Log</div>
    <div class="page-sub">Recorded user actions</div>
  </div>

</div>

<?php if ($filters['error'] !== ''): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($filters['error']) ?></p></div></div><?php endif; ?>
<?php require __DIR__ . '/../includes/activity-filters.php'; ?>

<div class="card">
  <div class="card-header">
    <div class="card-title">Activity Log</div>
    <div class="text-sm text-muted"><?= count($logs) ?> entries shown (max 200)</div>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead><tr><th>Timestamp</th><th>User</th><th>Role</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td class="td-mono" style="font-size:11px;white-space:nowrap"><?= date('M d, Y h:i:s A', strtotime($log['created_at'])) ?></td>
          <td>
            <div class="table-person">
              <div style="width:26px;height:26px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:var(--primary)"><?= user_avatar_content($log['avatar'] ?? null, $log['actor'] ?? 'System') ?></div>
              <span class="text-sm font-bold"><?= e($log['actor'] ?? 'System') ?></span>
            </div>
          </td>
          <td><span class="badge badge-role badge-role-<?= e($log['actor_role'] ?? 'admin') ?>"><?= ucfirst($log['actor_role'] ?? 'system') ?></span></td>
          <td class="text-sm font-bold"><?= e($log['action']) ?></td>
          <td class="text-sm text-muted"><?= $log['details'] ? e($log['details']) : '—' ?></td>

        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="5" class="text-center text-muted py-6">No activity logs found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
