<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$search = trim($_GET['q'] ?? '');
$date   = $_GET['date'] ?? '';
$role   = $_GET['role'] ?? '';

$where  = '1=1'; $params = []; $types = '';
if ($search) { $where .= " AND (al.action LIKE ? OR al.details LIKE ? OR u.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $types .= 'sss'; }
if ($date)   { $where .= " AND DATE(al.created_at)=?"; $params[] = $date; $types .= 's'; }
if ($role)   { $where .= " AND u.role=?"; $params[] = $role; $types .= 's'; }

$logs = query("SELECT al.*, u.name AS actor, u.role AS actor_role FROM activity_log al LEFT JOIN users u ON u.id=al.user_id WHERE $where ORDER BY al.created_at DESC LIMIT 200",
    $params, $types);

$page_title = 'Activity Log';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">System Activity Log</div>
    <div class="page-sub">Audit trail of all user actions across the system</div>
  </div>
  <form method="GET" style="display:flex;gap:8px">
    <div class="search-wrap" style="width:220px">
      <input type="text" name="q" class="form-control search-input" placeholder="Search actions..." value="<?= e($search) ?>">
    </div>
    <select name="role" class="form-control" style="width:160px" onchange="this.form.submit()">
      <option value="">All Roles</option>
      <option value="student" <?= $role==='student'?'selected':'' ?>>Student</option>
      <option value="coordinator" <?= $role==='coordinator'?'selected':'' ?>>Coordinator</option>
      <option value="company" <?= $role==='company'?'selected':'' ?>>Company</option>
      <option value="admin" <?= $role==='admin'?'selected':'' ?>>Admin</option>
    </select>
    <input type="date" name="date" value="<?= e($date) ?>" class="form-control" style="width:180px" onchange="this.form.submit()">
    <?php if ($search||$role||$date): ?><a href="/ojtrack/admin/activity.php" class="btn btn-ghost">Clear</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title">Activity Log</div>
    <div class="text-sm text-muted"><?= count($logs) ?> entries shown (max 200)</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Timestamp</th><th>User</th><th>Role</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td class="td-mono" style="font-size:11px;white-space:nowrap"><?= date('M d, Y h:i:s A', strtotime($log['created_at'])) ?></td>
          <td>
            <div style="display:flex;gap:8px;align-items:center">
              <div style="width:26px;height:26px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:var(--primary)"><?= strtoupper(substr($log['actor'] ?? 'S', 0, 2)) ?></div>
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
