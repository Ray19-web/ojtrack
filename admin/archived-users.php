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

require_once __DIR__.'/../config/record-filters.php';
$filters=archived_account_filters($_GET);
$search=$filters['search']; $role_filter=$filters['role'];
$where=$filters['where']; $params=$filters['params']; $types=$filters['types'];

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
    <div class="page-sub">Records are preserved. Restore accounts here.</div>
  </div>
  <a href="/ojtrack/admin/users.php" class="btn btn-secondary">← Back to User Management</a>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <?php
  $record_filter_ui=[
      'label'=>'Search accounts','placeholder'=>'Search name or email...','search'=>$search,
      'fields'=>[
          ['name'=>'role','label'=>'Role','value'=>$role_filter,'options'=>[''=>'All Roles','student'=>'Student','coordinator'=>'Coordinator','company'=>'Company','admin'=>'Admin']],
          ['name'=>'created','label'=>'Created on','value'=>$filters['created']],
      ],
      'active'=>$search!=='' || $role_filter!=='' || $filters['created']!=='',
      'clear'=>'/ojtrack/admin/archived-users.php','count'=>count($archived_users).' of '.$total_archived.' archived account(s)',
  ];
  require __DIR__.'/../includes/record-filters.php';
  ?>

  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
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
            <div class="table-person">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)">
                <?= user_avatar_content($u['avatar'] ?? null, $u['name']) ?>
              </div>
              <div>
                <span class="font-bold text-sm"><?= e($u['name']) ?></span>
              </div>
            </div>
          </td>
          <td class="text-sm text-muted"><?= e($u['email']) ?></td>
          <td><span class="badge badge-role badge-role-<?= e($u['role']) ?>"><?= ucfirst($u['role']) ?></span></td>
          <td class="td-mono text-sm"><?= isset($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?></td>
          <td><?= status_badge('archived') ?></td>
          <td>
            <div class="table-actions">
              <form method="POST" data-confirm-message="Restore this <?= e($u['role']) ?> account to active status?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="restore_user">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="table-action-icon is-success" title="Restore account" aria-label="Restore account">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                </button>
              </form>
            </div>
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
