<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';
$year_level_options = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate', 'Other'];
$student_term_enrollment_created = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'edit_user') request_error(410, 'User account editing is not available from this page.');
    if (in_array($action, ['archive_user','toggle_status'], true)) {
        $target = query_one("SELECT id,role,status FROM users WHERE id=?", [(int)($_POST['user_id'] ?? 0)], 'i');
        if (!$target) request_error(404, 'Account not found.');
        if ((int)$target['id'] === (int)$user['id']) request_error(422, 'You cannot deactivate or archive your own administrator account.');
    }
    if ($action === 'add_user' && !in_array($_POST['role'] ?? '', ['admin','student','coordinator','company'], true)) request_error(422, 'Invalid account role.');


    if ($action === 'add_user') {
        $role    = $_POST['role'] ?? '';
        $coord_first_name = normalize_person_name_part($_POST['coordinator_first_name'] ?? '');
        $coord_middle_name = normalize_person_name_part($_POST['coordinator_middle_name'] ?? '');
        $coord_last_name = normalize_person_name_part($_POST['coordinator_last_name'] ?? '');
        $coord_has_name_parts = $coord_first_name !== '' || $coord_middle_name !== '' || $coord_last_name !== '';
        $base_name = $role === 'coordinator'
            ? person_name_from_parts($coord_first_name, $coord_middle_name, $coord_last_name)
            : normalize_person_name($_POST['name'] ?? '');
        $extension_result = name_extensions_from_post();
        $name_extensions = $extension_result['extensions'];
        $extension_error = $extension_result['error'];
        $name    = format_person_name($base_name, $name_extensions);
        $email   = trim($_POST['email'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $student_id_no = is_string($_POST['student_id_no'] ?? '') ? trim($_POST['student_id_no'] ?? '') : '';
        $coord_id_no = is_string($_POST['coordinator_id_no'] ?? '') ? trim($_POST['coordinator_id_no'] ?? '') : '';
        $supervisor_first_name = normalize_person_name_part($_POST['supervisor_first_name'] ?? '');
        $supervisor_middle_name = normalize_person_name_part($_POST['supervisor_middle_name'] ?? '');
        $supervisor_last_name = normalize_person_name_part($_POST['supervisor_last_name'] ?? '');
        $supervisor_has_name_parts = $supervisor_first_name !== '' || $supervisor_middle_name !== '' || $supervisor_last_name !== '';
        $supervisor_extension_result = name_extensions_from_post('supervisor_name_extension_type', 'supervisor_name_extension_value');
        $supervisor_extensions = $supervisor_extension_result['extensions'];
        $supervisor_extension_error = $supervisor_extension_result['error'];

        if (!$base_name || !$role || ($role === 'coordinator' && (!$coord_first_name || !$coord_last_name || mb_strlen($coord_first_name) > 60 || mb_strlen($coord_middle_name) > 60 || mb_strlen($coord_last_name) > 60)) || ($role !== 'student' && (!$email || !$pass))) {
            $error = 'Name and role are required. Other roles also require email and password.';
        } elseif ($role === 'company' ? ($name_extensions || $extension_error || $supervisor_extension_error || ($supervisor_has_name_parts && (!$supervisor_first_name || !$supervisor_last_name)) || mb_strlen($supervisor_first_name) > 60 || mb_strlen($supervisor_middle_name) > 60 || mb_strlen($supervisor_last_name) > 60 || (!$supervisor_has_name_parts && $supervisor_extensions)) : (bool)$extension_error) {
            $error = $role === 'company'
                ? ($extension_error ?: ($supervisor_extension_error ?: (($supervisor_has_name_parts && (!$supervisor_first_name || !$supervisor_last_name)) ? 'Enter both the supervisor first name and last name.' : (($supervisor_has_name_parts && (mb_strlen($supervisor_first_name) > 60 || mb_strlen($supervisor_middle_name) > 60 || mb_strlen($supervisor_last_name) > 60)) ? 'Each supervisor name part must be 60 characters or fewer.' : 'Enter a supervisor name before adding its extension.'))))
                : $extension_error;
        } elseif (mb_strlen($name) > 100) {
            $error = 'Name must be 100 characters or fewer, including its extension.';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } elseif ($role === 'student' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,19}$/D', $student_id_no)) {
            $error = 'Enter a student ID of up to 20 letters, numbers or hyphens.';
        } elseif ($role === 'student' && query_one("SELECT id FROM students WHERE student_id_no=?", [$student_id_no], 's')) {
            $error = 'That student ID is already assigned, including archived accounts.';
        } elseif ($role === 'coordinator' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,49}$/D', $coord_id_no)) {
            $error = 'Enter a coordinator ID using up to 50 letters, numbers or hyphens.';
        } elseif ($role === 'coordinator' && query_one("SELECT id FROM coordinators WHERE coordinator_id_no=?", [$coord_id_no], 's')) {
            $error = 'That coordinator ID is already assigned.';
        } elseif ($role === 'student' && empty($_POST['program_id'])) {
            $error = 'Academic Program is required for students.';
        } elseif ($role === 'student' && !query_one("SELECT id FROM programs WHERE id=? AND status='active'", [(int)$_POST['program_id']], 'i')) {
            $error = 'Please select a valid active academic program.';
        } else {
            db()->begin_transaction();
            try {
                $student_id_no = $role === 'student' ? $student_id_no : null;
                if ($role === 'student') $pass = $student_id_no;
                $first_extension = $name_extensions[0] ?? null;
                $uid = insert("INSERT INTO users (name,name_extension_type,name_extension_value,email,role,password,status) VALUES (?,?,?,?,?,?,'active')",
                    [$name, $first_extension['type'] ?? null, $first_extension['value'] ?? null, $email ?: null, $role, password_hash($pass, PASSWORD_DEFAULT)], 'ssssss');
                save_user_name_extensions((int)$uid, $name_extensions);

                $assigned_id = '';
                if ($role === 'student') {
                    $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
                    $pr = query_one("SELECT code, name FROM programs WHERE id=?", [$program_id], 'i');
                    $prog_code = $pr['code'];
                    $coord_id = find_coordinator_for_program($pr);
                    $year    = trim($_POST['year_level'] ?? '4th Year');
                    $req_h   = (int)($_POST['required_hours'] ?? 486);
                    $student_id = (int)insert("INSERT INTO students (user_id, student_id_no, program_id, program, coordinator_id, year_level, required_hours, ojt_status) VALUES (?,?,?,?,?,?,?,'pending')",
                        [$uid, $student_id_no, $program_id, $prog_code, $coord_id, $year, $req_h], 'isisiss');
                    $student_term_enrollment_created = (bool)normalized_create_active_term_enrollment($student_id, (int)$user['id'], (int)$program_id, $year, $req_h, $coord_id ? (int)$coord_id : null);
                    $assigned_id = $student_id_no;
                } elseif ($role === 'coordinator') {
                    $phone       = trim($_POST['coord_contact_number'] ?? '');
                    $dept        = trim($_POST['department'] ?? 'Information Technology');
                    insert("INSERT INTO coordinators (user_id, coordinator_id_no, first_name, middle_name, last_name, department, contact_number) VALUES (?,?,?,?,?,?,?)",
                        [$uid, $coord_id_no, $coord_first_name, $coord_middle_name ?: null, $coord_last_name, $dept, $phone], 'issssss');
                    $pr = query_one("SELECT id, code, name FROM programs WHERE code=? OR name=? LIMIT 1", [$dept, $dept], 'ss');
                    if ($pr) assign_unassigned_students_for_program($pr, (int)$user['id']);
                    $assigned_id = $coord_id_no;
                } elseif ($role === 'company') {
                    $sup  = person_name_from_parts($supervisor_first_name, $supervisor_middle_name, $supervisor_last_name);
                    $sup = format_person_name($sup, $supervisor_extensions);
                    $loc  = trim($_POST['location'] ?? '');
                    $phone = trim($_POST['contact_number'] ?? '');
                    $company_id = insert("INSERT INTO companies (user_id, company_name, supervisor_name, supervisor_first_name, supervisor_middle_name, supervisor_last_name, supervisor_name_extension_type, supervisor_name_extension_value, location, contact_number, status) VALUES (?,?,?,?,?,?,?,?,?,?,'active')",
                        [$uid, $name, $sup, $supervisor_has_name_parts ? $supervisor_first_name : null, $supervisor_has_name_parts ? ($supervisor_middle_name ?: null) : null, $supervisor_has_name_parts ? $supervisor_last_name : null, $supervisor_extensions[0]['type'] ?? null, $supervisor_extensions[0]['value'] ?? null, $loc, $phone], 'isssssssss');
                    save_company_supervisor_name_extensions((int)$company_id, $supervisor_extensions);
                }

                log_activity($user['id'], 'User Created', "$name ($role)" . ($assigned_id ? " ID: $assigned_id" : ''));
                db()->commit();
                $success = "User $name ($role) created successfully." . ($assigned_id ? " Assigned ID: $assigned_id." : '')
                    . ($role === 'student' ? ($student_term_enrollment_created ? ' Enrolled in the active academic term.' : ' No active-term enrollment was created; activate a term before assigning OJT.') : '');
            } catch (Throwable $exception) {
                db()->rollback();
                if ($exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062) {
                    $error = 'The email or assigned ID is already in use. Please choose a unique value.';
                } else {
                    error_log('OJTrack account creation failed (code ' . $exception->getCode() . ').');
                    $error = 'Unable to create the account. Please try again.';
                }
            }
        }
    } elseif ($action === 'archive_user') {
        $uid = (int)$_POST['user_id'];
        $u_row = query_one("SELECT role, name FROM users WHERE id=?", [$uid], 'i');
        if ($uid === (int)$user['id']) {
            $error = 'Cannot archive your own administrator account.';
        } elseif ($u_row) {
            query("UPDATE users SET status='archived' WHERE id=?", [$uid], 'i');
            if ($u_row['role'] === 'student') {
                query("UPDATE students SET is_archived=1 WHERE user_id=?", [$uid], 'i');
            } elseif ($u_row['role'] === 'company') {
                query("UPDATE companies SET status='inactive' WHERE user_id=?", [$uid], 'i');
            }
            log_activity($user['id'], 'User Archived', "User ID: $uid ({$u_row['name']} / {$u_row['role']})");
            $success = ucfirst($u_row['role']) . " {$u_row['name']} archived successfully. All related records are preserved.";
        }
    } elseif ($action === 'restore_user') {
        $uid = (int)$_POST['user_id'];
        $u_row = query_one("SELECT role, name FROM users WHERE id=?", [$uid], 'i');
        if ($u_row) {
            query("UPDATE users SET status='active' WHERE id=?", [$uid], 'i');
            if ($u_row['role'] === 'student') {
                query("UPDATE students SET is_archived=0 WHERE user_id=?", [$uid], 'i');
            } elseif ($u_row['role'] === 'company') {
                query("UPDATE companies SET status='active' WHERE user_id=?", [$uid], 'i');
            }
            log_activity($user['id'], 'User Restored', "User ID: $uid ({$u_row['name']} / {$u_row['role']})");
            $success = ucfirst($u_row['role']) . " {$u_row['name']} restored to active status successfully.";
        }
    } elseif ($action === 'toggle_status') {
        $uid     = (int)$_POST['user_id'];
        $u_row   = query_one("SELECT status, role FROM users WHERE id=?", [$uid], 'i');
        if ($u_row && $u_row['status'] === 'archived') {
            $error = 'Archived accounts cannot be activated/deactivated. Use Restore instead.';
        } elseif ($u_row) {
            $new_st = ($u_row['status'] === 'active') ? 'inactive' : 'active';
            query("UPDATE users SET status=? WHERE id=?", [$new_st, $uid], 'si');
            log_activity($user['id'], 'User Status Changed', "User ID: $uid set to $new_st");
            $success = "User status updated to $new_st.";
        }
    } elseif ($action === 'bulk_user_action') {
        $bulk_action = is_string($_POST['bulk_action'] ?? null) ? $_POST['bulk_action'] : '';
        $raw_user_ids = is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : [];
        $user_ids = array_values(array_unique(array_filter(array_map(
            static fn($id): int => is_scalar($id) && filter_var((string)$id, FILTER_VALIDATE_INT) !== false ? (int)$id : 0,
            $raw_user_ids
        ), static fn(int $id): bool => $id > 0)));
        $allowed_bulk_actions = ['activate', 'deactivate', 'archive'];

        if (!in_array($bulk_action, $allowed_bulk_actions, true)) {
            $error = 'Choose an action for the selected accounts.';
        } elseif (!$user_ids) {
            $error = 'Select at least one account first.';
        } else {
            $marks = implode(',', array_fill(0, count($user_ids), '?'));
            $selected_users = query(
                "SELECT id, name, role, status FROM users WHERE id IN ($marks) AND status!='archived'",
                $user_ids,
                str_repeat('i', count($user_ids))
            ) ?: [];
            $self_selected = in_array((int)$user['id'], array_map(static fn(array $account): int => (int)$account['id'], $selected_users), true);

            if ($self_selected && in_array($bulk_action, ['deactivate', 'archive'], true)) {
                $error = 'Your administrator account cannot be deactivated or archived.';
            } elseif (!$selected_users) {
                $error = 'The selected accounts could not be found.';
            } else {
                $targets = array_values(array_filter($selected_users, static function(array $account) use ($bulk_action): bool {
                    if ($bulk_action === 'activate') return $account['status'] !== 'active';
                    if ($bulk_action === 'deactivate') return $account['status'] !== 'inactive';
                    return true;
                }));
                try {
                    db()->begin_transaction();
                    if ($bulk_action === 'activate' || $bulk_action === 'deactivate') {
                        $target_status = $bulk_action === 'activate' ? 'active' : 'inactive';
                        if ($targets) {
                            $target_ids = array_map(static fn(array $account): int => (int)$account['id'], $targets);
                            $target_marks = implode(',', array_fill(0, count($target_ids), '?'));
                            query("UPDATE users SET status=? WHERE id IN ($target_marks)", array_merge([$target_status], $target_ids), 's' . str_repeat('i', count($target_ids)));
                            log_activity($user['id'], 'User Status Changed', 'Set ' . count($targets) . ' selected accounts to ' . $target_status . ': ' . implode(', ', array_column($targets, 'name')));
                        }
                        db()->commit();
                        $success = count($targets) . ' account' . (count($targets) === 1 ? '' : 's') . ' ' . ($target_status === 'active' ? 'activated' : 'deactivated') . '.';
                    } else {
                        foreach ($targets as $account) {
                            $target_id = (int)$account['id'];
                            query("UPDATE users SET status='archived' WHERE id=?", [$target_id], 'i');
                            if ($account['role'] === 'student') query('UPDATE students SET is_archived=1 WHERE user_id=?', [$target_id], 'i');
                            elseif ($account['role'] === 'company') query("UPDATE companies SET status='inactive' WHERE user_id=?", [$target_id], 'i');
                            log_activity($user['id'], 'User Archived', "User ID: {$target_id} ({$account['name']} / {$account['role']})");
                        }
                        db()->commit();
                        $success = count($targets) . ' account' . (count($targets) === 1 ? '' : 's') . ' archived. Related records are preserved.';
                    }
                } catch (Throwable $exception) {
                    db()->rollback();
                    error_log('OJTrack bulk account action failed (code ' . $exception->getCode() . ').');
                    $error = 'The selected account changes could not be completed. Please try again.';
                }
            }
        }
    }
}

$role_filter = is_string($_GET['role'] ?? null) && in_array($_GET['role'], ['student','coordinator','company','admin'], true) ? $_GET['role'] : '';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$status_filter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active','inactive','all'], true) ? $_GET['status'] : '';

$where = '1=1';
$params = []; $types = '';
if ($role_filter) { $where .= " AND role=?"; $params[] = $role_filter; $types .= 's'; }

if ($status_filter === 'active') {
    $where .= " AND status='active'";
} elseif ($status_filter === 'inactive') {
    $where .= " AND status='inactive'";
} elseif ($status_filter === 'all') {
    // Active + inactive only (archived has its own page)
    $where .= " AND status != 'archived'";
} else {
    // Default: exclude archived records
    $where .= " AND status != 'archived'";
}

if ($search) {
    $where .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

$users = query("SELECT * FROM users WHERE $where ORDER BY role, name", $params, $types);

$counts = [];
foreach (['student', 'coordinator', 'company', 'admin'] as $r) {
    $counts[$r] = query_one("SELECT COUNT(*) AS c FROM users WHERE role=? AND status != 'archived'", [$r], 's')['c'];
}
$archived_count = (int)(query_one("SELECT COUNT(*) AS c FROM users WHERE status='archived'")['c'] ?? 0);

$programs_list = query("SELECT id, code, name FROM programs WHERE status='active' ORDER BY code ASC");
$add_user_failed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user' && $error !== '');
$add_values = $add_user_failed ? array_filter($_POST, 'is_scalar') : [];
$add_values['name_extension_type'] = $add_user_failed && is_array($_POST['name_extension_type'] ?? null) ? $_POST['name_extension_type'] : [];
$add_values['name_extension_value'] = $add_user_failed && is_array($_POST['name_extension_value'] ?? null) ? $_POST['name_extension_value'] : [];
$add_values['supervisor_name_extension_type'] = $add_user_failed && is_array($_POST['supervisor_name_extension_type'] ?? null) ? $_POST['supervisor_name_extension_type'] : [];
$add_values['supervisor_name_extension_value'] = $add_user_failed && is_array($_POST['supervisor_name_extension_value'] ?? null) ? $_POST['supervisor_name_extension_value'] : [];
$add_role = $add_values['role'] ?? (in_array($role_filter, ['student','coordinator','company','admin'], true) ? $role_filter : '');

$page_title = 'User Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">User Accounts</div>
    <div class="page-sub">Accounts and access</div>
  </div>
  <div class="page-heading-actions">
    <a href="/ojtrack/admin/archived-users.php" class="btn btn-secondary">Archived Accounts (<?= $archived_count ?>)</a>
    <button class="btn btn-primary" onclick="openModal('addUserModal')">+ Add New User</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <?php
  $record_filter_ui=[
      'label'=>'Search accounts','placeholder'=>'Search name or email...','search'=>$search,
      'fields'=>[
          ['name'=>'role','label'=>'Role','value'=>$role_filter,'options'=>[''=>'All Roles','student'=>'Student','coordinator'=>'Coordinator','company'=>'Company','admin'=>'Admin']],
          ['name'=>'status','label'=>'Account Status','value'=>$status_filter==='all' ? '' : $status_filter,'options'=>[''=>'Active & Inactive','active'=>'Active Only','inactive'=>'Inactive Only']],
      ],
      'active'=>$search!=='' || $role_filter!=='' || $status_filter!=='',
      'clear'=>'/ojtrack/admin/users.php',
  ];
  require __DIR__.'/../includes/record-filters.php';
  ?>
  <form id="bulkUserForm" method="POST" hidden>
    <?= csrf_field() ?><input type="hidden" name="action" value="bulk_user_action">
  </form>
  <div class="user-bulk-toolbar" data-user-bulk-toolbar hidden>
    <div class="user-bulk-selection"><strong><span data-user-selected-count>0</span> selected</strong><span>Apply an action to these accounts.</span></div>
    <div class="user-bulk-actions">
      <button type="submit" form="bulkUserForm" name="bulk_action" value="activate" class="btn btn-secondary btn-sm" data-user-bulk-status="active">Activate selected</button>
      <button type="submit" form="bulkUserForm" name="bulk_action" value="deactivate" class="btn btn-danger btn-sm" data-user-bulk-status="inactive">Deactivate selected</button>
      <button type="submit" form="bulkUserForm" name="bulk_action" value="archive" class="btn btn-warning btn-sm" data-user-bulk-archive>Archive selected</button>
      <button type="button" class="user-selection-clear" data-user-clear-selection>Clear</button>
    </div>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table data-user-multi-select>
      <thead><tr><th class="user-select-cell"><input type="checkbox" class="user-select-checkbox" data-user-select-all aria-label="Select accounts on this page"></th><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Account Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td class="user-select-cell"><input type="checkbox" class="user-select-checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" data-user-status="<?= e($u['status']) ?>" form="bulkUserForm" aria-label="Select <?= e($u['name']) ?>" <?= (int)$u['id'] === (int)$user['id'] ? 'disabled title="Your administrator account is protected"' : '' ?>></td>
          <td>
            <div class="table-person">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= user_avatar_content($u['avatar'] ?? null, $u['name']) ?></div>
              <div>
                <span class="font-bold text-sm"><?= e($u['name']) ?></span>
                <?php if ($u['status'] === 'archived'): ?>
                  <span class="badge badge-archived" style="font-size:10px;margin-left:4px">Archived</span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-sm text-muted"><?= e($u['email'] ?: 'Not provided') ?></td>
          <td><span class="badge badge-role badge-role-<?= e($u['role']) ?>"><?= ucfirst($u['role']) ?></span></td>
          <td class="td-mono text-sm"><?= isset($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?></td>
          <td><?= status_badge($u['status'] ?? 'active') ?></td>
          <td>
            <div class="table-actions">
              <form method="POST"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="table-action-icon <?= ($u['status'] ?? 'active') === 'active' ? 'is-danger' : 'is-success' ?>"
                        title="<?= ($u['status'] ?? 'active') === 'active' ? 'Deactivate user' : 'Activate user' ?>"
                        aria-label="<?= ($u['status'] ?? 'active') === 'active' ? 'Deactivate user' : 'Activate user' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/>
                    <?php if (($u['status'] ?? 'active') === 'active'): ?>
                      <path d="M17 10h6"/>
                    <?php else: ?>
                      <path d="m17 10 2 2 4-4"/>
                    <?php endif; ?>
                  </svg>
                </button>
              </form>
              <?php if ((int)$u['id'] !== (int)$user['id']): ?>
              <form method="POST" data-confirm-message="Archive this <?= e($u['role']) ?> account? They will lose login access, but all records will be preserved."><?= csrf_field() ?>
                <input type="hidden" name="action" value="archive_user">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="table-action-icon is-warning" title="Archive user" aria-label="Archive user">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9M9 13h6"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?><tr><td colspan="7" class="text-center text-muted py-6">No users found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
  <div class="modal modal-name-form">
    <div class="modal-title">Add New User Account</div>
    <?php if ($add_user_failed): ?><div class="alert alert-error mb-4" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="user-form-section-heading">Account details</div>
      <div class="form-group" id="addUserFullNameGroup"><label class="form-label">Full Name <span style="color:red">*</span></label><input type="text" name="name" id="addUserFullName" class="form-control" placeholder="e.g. Juan Dela Cruz" value="<?= e($add_values['name'] ?? '') ?>" data-uppercase-name required></div>
      <div id="addUserNameExtensionFields"><?php $name_extension_prefix = 'addUser'; $name_extension_type_field = 'name_extension_type'; $name_extension_value_field = 'name_extension_value'; $name_extension_entries = null; $name_extension_selected = $add_values['name_extension_type'] ?? []; $name_extension_value = $add_values['name_extension_value'] ?? []; require __DIR__ . '/../includes/name-extension-fields.php'; ?></div>
      <div class="form-group"><label class="form-label">Email Address <span id="addEmailRequired" style="color:red">*</span></label><input type="email" name="email" id="addEmail" class="form-control" placeholder="user@ustp.edu.ph" value="<?= e($add_values['email'] ?? '') ?>" autocomplete="off" required></div>
      <div class="form-group"><label class="form-label">System Role <span style="color:red">*</span></label>
        <select name="role" id="addRoleSelect" class="form-control" onchange="toggleRoleFields(this.value)" required>
          <option value="">Select role...</option>
          <option value="student"<?= $add_role === 'student' ? ' selected' : '' ?>>Student</option>
          <option value="coordinator"<?= $add_role === 'coordinator' ? ' selected' : '' ?>>Coordinator</option>
          <option value="company"<?= $add_role === 'company' ? ' selected' : '' ?>>Partner Company</option>
          <option value="admin"<?= $add_role === 'admin' ? ' selected' : '' ?>>Administrator</option>
        </select>
      </div>

      <!-- Student Specific Fields -->
      <div id="studentFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="user-form-section-heading">Student details</div>
        <div class="form-group">
          <label class="form-label" for="addStudentId">Student ID Number <span style="color:red">*</span></label>
          <input type="text" name="student_id_no" id="addStudentId" class="form-control" value="<?= e($add_values['student_id_no'] ?? '') ?>" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,19}" placeholder="Enter official student ID" autocomplete="off" aria-describedby="addStudentIdHelp">
          <div id="addStudentIdHelp" class="text-xs text-muted mt-1">Use the student's official ID. Up to 20 letters, numbers or hyphens.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Academic Program <span style="color:red">*</span></label>
          <select name="program_id" id="addStudentProgram" class="form-control">
            <option value="">Select Academic Program...</option>
            <?php foreach ($programs_list as $pl): ?>
              <option value="<?= $pl['id'] ?>"<?= (int)($add_values['program_id'] ?? 0) === (int)$pl['id'] ? ' selected' : '' ?>><?= e($pl['code']) ?> - <?= e($pl['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-xs text-muted mt-1" style="display:flex;justify-content:space-between">
            <span>Retrieved from Program Management</span>
            <a href="/ojtrack/admin/programs.php" target="_blank" style="color:var(--primary);font-weight:600">+ Manage Programs</a>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Year Level</label><?php $new_year = $add_values['year_level'] ?? '4th Year'; ?><select name="year_level" class="form-control"><?php if ($new_year !== '' && !in_array($new_year, $year_level_options, true)): ?><option value="<?= e($new_year) ?>" selected><?= e($new_year) ?></option><?php endif; foreach ($year_level_options as $year_option): ?><option value="<?= e($year_option) ?>" <?= $new_year === $year_option ? 'selected' : '' ?>><?= e($year_option) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">Required Hours</label><input type="number" name="required_hours" class="form-control" value="<?= e($add_values['required_hours'] ?? '486') ?>"></div>
        </div>
      </div>

      <!-- Coordinator Specific Fields -->
      <div id="coordinatorFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="user-form-section-heading">Coordinator details</div>
        <div id="addUserCoordinatorNameFields"><?php $person_name_prefix = 'addUserCoordinator'; $person_name_fields = ['first' => 'coordinator_first_name', 'middle' => 'coordinator_middle_name', 'last' => 'coordinator_last_name']; $person_name_values = ['first' => $add_values['coordinator_first_name'] ?? '', 'middle' => $add_values['coordinator_middle_name'] ?? '', 'last' => $add_values['coordinator_last_name'] ?? '']; $person_name_required = true; require __DIR__ . '/../includes/person-name-fields.php'; ?></div>
        <div class="form-group">
          <label class="form-label" for="addCoordinatorId">Coordinator ID <span style="color:red">*</span></label>
          <input type="text" name="coordinator_id_no" id="addCoordinatorId" class="form-control" value="<?= e($add_values['coordinator_id_no'] ?? '') ?>" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,49}" placeholder="Enter coordinator ID" autocomplete="off">
          <div class="text-xs text-muted mt-1">Enter the coordinator's official ID. Letters, numbers and hyphens only.</div>
        </div>
        <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="coord_contact_number" class="form-control" placeholder="e.g. 0917-123-4567"></div>
        <div class="form-group"><label class="form-label">Assigned Department</label><input type="text" name="department" class="form-control" value="Information Technology"></div>
      </div>

      <!-- Company Specific Fields -->
      <div id="companyFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="user-form-section-heading">Company and supervisor details</div>
        <?php $person_name_prefix = 'addUserSupervisor'; $person_name_fields = ['first' => 'supervisor_first_name', 'middle' => 'supervisor_middle_name', 'last' => 'supervisor_last_name']; $person_name_values = []; $person_name_required = false; require __DIR__ . '/../includes/person-name-fields.php'; ?>
        <div id="addUserSupervisorNameExtensionFields"><?php $name_extension_prefix = 'addUserSupervisor'; $name_extension_type_field = 'supervisor_name_extension_type'; $name_extension_value_field = 'supervisor_name_extension_value'; $name_extension_entries = null; $name_extension_selected = $add_values['supervisor_name_extension_type'] ?? []; $name_extension_value = $add_values['supervisor_name_extension_value'] ?? []; require __DIR__ . '/../includes/name-extension-fields.php'; ?></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-control" placeholder="Cagayan de Oro City"></div>
          <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="0917-123-4567"></div>
        </div>
      </div>

      <p id="studentLoginHelp" class="text-muted" style="display:none">Student login and initial password: student ID.</p>
      <div class="user-form-section-heading">Sign-in security</div>
      <div class="form-group" id="addPasswordGroup"><label class="form-label">Initial Password <span style="color:red">*</span></label><input type="password" name="password" id="addPassword" class="form-control" placeholder="Min. 6 characters" required></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User Account</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleStudentIdField() {
  const student = document.getElementById('addRoleSelect').value === 'student';
  const input = document.getElementById('addStudentId');
  input.required = student;
  input.disabled = !student;
}
function toggleRoleFields(role) {
  const student = role === 'student';
  const coordinator = role === 'coordinator';
  const fullName = document.getElementById('addUserFullName');
  fullName.required = !coordinator;
  fullName.disabled = coordinator;
  document.getElementById('addUserFullNameGroup').style.display = coordinator ? 'none' : '';
  const coordinatorId = document.getElementById('addCoordinatorId');
  coordinatorId.required = coordinator;
  coordinatorId.disabled = !coordinator;
  document.getElementById('addStudentProgram').required = student;
  document.getElementById('addStudentProgram').disabled = !student;
  toggleStudentIdField();
  document.getElementById('addEmail').required = !student;
  document.getElementById('addEmailRequired').style.display = student ? 'none' : '';
  document.getElementById('addPassword').required = !student;
  document.getElementById('addPassword').disabled = student;
  document.getElementById('addPasswordGroup').style.display = student ? 'none' : '';
  document.getElementById('studentLoginHelp').style.display = student ? '' : 'none';
  document.getElementById('studentFields').style.display = role === 'student' ? 'block' : 'none';
  document.getElementById('coordinatorFields').style.display = coordinator ? 'block' : 'none';
  document.getElementById('companyFields').style.display = role === 'company' ? 'block' : 'none';
  const coordinatorNameFields = document.getElementById('addUserCoordinatorNameFields');
  coordinatorNameFields.querySelectorAll('input').forEach(function (field) { field.disabled = !coordinator; });
  document.getElementById('addUserCoordinatorFirstName').required = coordinator;
  document.getElementById('addUserCoordinatorLastName').required = coordinator;
  const nameExtensionFields = document.getElementById('addUserNameExtensionFields');
  nameExtensionFields.querySelectorAll('select,input').forEach(function (field) { field.disabled = role === 'company'; });
  document.getElementById('addUserSupervisorNameExtensionFields').querySelectorAll('select,input').forEach(function (field) { field.disabled = role !== 'company'; });
}
document.addEventListener('DOMContentLoaded', function () {
  toggleRoleFields(document.getElementById('addRoleSelect').value);
  <?php if ($add_user_failed): ?>openModal('addUserModal');<?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
