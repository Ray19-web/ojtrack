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
    } elseif ($action === 'bulk_restore_users') {
        require_csrf();
        $raw_user_ids = is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : [];
        $user_ids = array_values(array_unique(array_filter(array_map(
            static fn($id): int => is_scalar($id) && filter_var((string)$id, FILTER_VALIDATE_INT) !== false ? (int)$id : 0,
            $raw_user_ids
        ), static fn(int $id): bool => $id > 0)));

        if (!$user_ids) {
            $error = 'Select at least one archived account first.';
        } else {
            $marks = implode(',', array_fill(0, count($user_ids), '?'));
            $selected_users = query(
                "SELECT id, role, name FROM users WHERE status='archived' AND id IN ($marks)",
                $user_ids,
                str_repeat('i', count($user_ids))
            ) ?: [];
            if (!$selected_users) {
                $error = 'The selected archived accounts could not be found.';
            } else {
                try {
                    db()->begin_transaction();
                    foreach ($selected_users as $account) {
                        $account_id = (int)$account['id'];
                        query("UPDATE users SET status='active' WHERE id=? AND status='archived'", [$account_id], 'i');
                        if ($account['role'] === 'student') query('UPDATE students SET is_archived=0 WHERE user_id=?', [$account_id], 'i');
                        elseif ($account['role'] === 'company') query("UPDATE companies SET status='active' WHERE user_id=?", [$account_id], 'i');
                        log_activity($user['id'], 'User Restored', "User ID: {$account_id} ({$account['name']} / {$account['role']})");
                    }
                    db()->commit();
                    $success = count($selected_users) . ' archived account' . (count($selected_users) === 1 ? '' : 's') . ' restored to active status.';
                } catch (Throwable $exception) {
                    db()->rollback();
                    error_log('OJTrack bulk account restore failed (code ' . $exception->getCode() . ').');
                    $error = 'The selected accounts could not be restored. Please try again.';
                }
            }
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
      'clear'=>'/ojtrack/admin/archived-users.php',
  ];
  require __DIR__.'/../includes/record-filters.php';
  ?>
  <form id="bulkArchivedUserForm" method="POST" hidden>
    <?= csrf_field() ?><input type="hidden" name="action" value="bulk_restore_users">
  </form>
  <div class="user-bulk-toolbar" data-archived-user-bulk-toolbar hidden>
    <div class="user-bulk-selection"><strong><span data-archived-user-selected-count>0</span> selected</strong><span>Restore these accounts to active status.</span></div>
    <div class="user-bulk-actions">
      <button type="submit" form="bulkArchivedUserForm" class="btn btn-secondary btn-sm" data-archived-user-restore>Restore selected</button>
      <button type="button" class="user-selection-clear" data-archived-user-clear>Clear</button>
    </div>
  </div>

  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table data-archived-user-multi-select>
      <thead>
        <tr>
          <th class="user-select-cell"><input type="checkbox" class="user-select-checkbox" data-archived-user-select-all aria-label="Select archived accounts on this page"></th>
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
          <td class="user-select-cell"><input type="checkbox" class="user-select-checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" form="bulkArchivedUserForm" aria-label="Select archived account for <?= e($u['name']) ?>"></td>
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
            <td colspan="7" class="text-center text-muted py-6">
              No archived accounts<?= $role_filter || $search ? ' matching your filters' : '' ?>.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
