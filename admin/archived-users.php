<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restore_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $u_row = query_one("SELECT role, name FROM users WHERE id=? AND status='archived'", [$uid], 'i');
        if (!$u_row) {
            $error = 'Archived account not found.';
        } else {
            query("UPDATE users SET status='active' WHERE id=?", [$uid], 'i');
            if ($u_row['role'] === 'student') {
                query("UPDATE students SET is_archived=0 WHERE user_id=?", [$uid], 'i');
            } elseif ($u_row['role'] === 'company') {
                query("UPDATE companies SET status='active' WHERE user_id=?", [$uid], 'i');
            }
            log_activity($user['id'], 'User Restored', "User ID: $uid ({$u_row['name']} / {$u_row['role']})");
            $success = ucfirst($u_row['role']) . " {$u_row['name']} restored to active status successfully.";
        }
    }
}

$role_filter = $_GET['role'] ?? '';
$search      = trim($_GET['q'] ?? '');

$where  = "status='archived'";
$params = [];
$types  = '';

if ($role_filter !== '' && in_array($role_filter, ['student', 'coordinator', 'company', 'admin'], true)) {
    $where .= " AND role=?";
    $params[] = $role_filter;
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

$archived_users = query(
    "SELECT * FROM users WHERE $where ORDER BY role, name",
    $params,
    $types
) ?: [];

$role_counts = [];
foreach (['student', 'coordinator', 'company', 'admin'] as $r) {
    $role_counts[$r] = (int)(query_one("SELECT COUNT(*) AS c FROM users WHERE status='archived' AND role=?", [$r], 's')['c'] ?? 0);
}
$total_archived = array_sum($role_counts);

$page_title = 'Archived Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Archived Accounts</div>
    <div class="page-sub">Accounts removed from active use. Records are preserved and can be restored at any time.</div>
  </div>
  <a href="/ojtrack/admin/users.php" class="btn btn-secondary">← Back to User Management</a>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($role_filter): ?><input type="hidden" name="role" value="<?= e($role_filter) ?>"><?php endif; ?>
      <div class="search-wrap" style="width:240px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search name or email..." value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-secondary">Search</button>
      <?php if ($role_filter || $search): ?>
        <a href="/ojtrack/admin/archived-users.php" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>
    <div class="text-sm text-muted"><?= count($archived_users) ?> of <?= $total_archived ?> archived account(s)</div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Created</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($archived_users as $u): ?>
        <tr>
          <td>
            <div style="display:flex;gap:10px;align-items:center">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)">
                <?= strtoupper(substr($u['name'], 0, 2)) ?>
              </div>
              <div>
                <span class="font-bold text-sm"><?= e($u['name']) ?></span>
                <span class="badge badge-archived" style="font-size:10px;margin-left:4px">Archived</span>
              </div>
            </div>
          </td>
          <td class="text-sm text-muted"><?= e($u['email']) ?></td>
          <td><span class="badge badge-role badge-role-<?= e($u['role']) ?>"><?= ucfirst($u['role']) ?></span></td>
          <td class="td-mono text-sm"><?= isset($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?></td>
          <td><?= status_badge('archived') ?></td>
          <td>
            <form method="POST" style="display:inline" onsubmit="return confirm('Restore this <?= e($u['role']) ?> account to active status?')">
              <input type="hidden" name="action" value="restore_user">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-secondary btn-xs" style="color:var(--success);border-color:var(--success);font-weight:600">Restore</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($archived_users)): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-6">
              No archived accounts<?= $role_filter || $search ? ' matching your filters' : '' ?>.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
