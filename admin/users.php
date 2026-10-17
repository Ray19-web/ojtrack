<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['edit_user','archive_user','toggle_status'], true)) {
        $target = query_one("SELECT id,role,status FROM users WHERE id=?", [(int)($_POST['user_id'] ?? 0)], 'i');
        if (!$target) request_error(404, 'Account not found.');
        if ($action === 'edit_user' && ($_POST['role'] ?? '') !== $target['role']) request_error(422, 'Account roles cannot be changed. Create the correct role account and reassign records explicitly.');
        if ((int)$target['id'] === (int)$user['id'] && ($action !== 'edit_user' || ($_POST['status'] ?? '') !== 'active')) request_error(422, 'You cannot deactivate or archive your own administrator account.');
        if ($action === 'edit_user' && !in_array($_POST['status'] ?? '', ['active','inactive','archived'], true)) request_error(422, 'Invalid account status.');
    }
    if ($action === 'add_user' && !in_array($_POST['role'] ?? '', ['admin','student','coordinator','company'], true)) request_error(422, 'Invalid account role.');


    if ($action === 'add_user') {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $role    = $_POST['role'] ?? '';
        $pass    = $_POST['password'] ?? '';
        $student_id_mode = $_POST['student_id_mode'] ?? 'manual';
        $student_id_no = is_string($_POST['student_id_no'] ?? '') ? trim($_POST['student_id_no'] ?? '') : '';

        if (!$name || !$role || ($role !== 'student' && (!$email || !$pass))) {
            $error = 'Name and role are required. Other roles also require email and password.';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } elseif ($role === 'student' && !in_array($student_id_mode, ['manual','auto'], true)) {
            $error = 'Choose how to assign the student ID.';
        } elseif ($role === 'student' && $student_id_mode === 'manual' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,19}$/D', $student_id_no)) {
            $error = 'Enter a student ID of up to 20 letters, numbers or hyphens.';
        } elseif ($role === 'student' && $student_id_mode === 'manual' && query_one("SELECT id FROM students WHERE student_id_no=?", [$student_id_no], 's')) {
            $error = 'That student ID is already assigned, including archived accounts.';
        } elseif ($role === 'student' && empty($_POST['program_id'])) {
            $error = 'Academic Program is required for students.';
        } elseif ($role === 'student' && !query_one("SELECT id FROM programs WHERE id=? AND status='active'", [(int)$_POST['program_id']], 'i')) {
            $error = 'Please select a valid active academic program.';
        } else {
            db()->begin_transaction();
            try {
                $student_id_no = $role === 'student' ? ($student_id_mode === 'auto' ? generate_student_id() : $student_id_no) : null;
                if ($role === 'student') $pass = $student_id_no;
                $uid = insert("INSERT INTO users (name, email, role, password, status) VALUES (?,?,?,?,'active')",
                    [$name, $email ?: null, $role, password_hash($pass, PASSWORD_DEFAULT)], 'ssss');

                $assigned_id = '';
                if ($role === 'student') {
                    $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
                    $pr = query_one("SELECT code, name FROM programs WHERE id=?", [$program_id], 'i');
                    $prog_code = $pr['code'];
                    $coord_id = find_coordinator_for_program($pr);
                    $year    = trim($_POST['year_level'] ?? '4th Year');
                    $req_h   = (int)($_POST['required_hours'] ?? 486);
                    insert("INSERT INTO students (user_id, student_id_no, program_id, program, coordinator_id, year_level, required_hours, ojt_status) VALUES (?,?,?,?,?,?,?,'pending')",
                        [$uid, $student_id_no, $program_id, $prog_code, $coord_id, $year, $req_h], 'isisiss');
                    $assigned_id = $student_id_no;
                } elseif ($role === 'coordinator') {
                    $coord_id_no = generate_coordinator_id();
                    $phone       = trim($_POST['coord_contact_number'] ?? '');
                    $dept        = trim($_POST['department'] ?? 'Information Technology');
                    insert("INSERT INTO coordinators (user_id, coordinator_id_no, department, contact_number) VALUES (?,?,?,?)",
                        [$uid, $coord_id_no, $dept, $phone], 'isss');
                    $pr = query_one("SELECT id, code, name FROM programs WHERE code=? OR name=? LIMIT 1", [$dept, $dept], 'ss');
                    if ($pr) assign_unassigned_students_for_program($pr);
                    $assigned_id = $coord_id_no;
                } elseif ($role === 'company') {
                    $sup  = trim($_POST['supervisor_name'] ?? '');
                    $loc  = trim($_POST['location'] ?? '');
                    $phone = trim($_POST['contact_number'] ?? '');
                    insert("INSERT INTO companies (user_id, company_name, supervisor_name, location, contact_number, status) VALUES (?,?,?,?,?,'active')",
                        [$uid, $name, $sup, $loc, $phone], 'issss');
                }

                log_activity($user['id'], 'User Created', "$name ($role)" . ($assigned_id ? " ID: $assigned_id" : ''));
                db()->commit();
                $success = "User $name ($role) created successfully." . ($assigned_id ? " Assigned ID: $assigned_id." : '');
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
    } elseif ($action === 'edit_user') {
        $uid   = (int)$_POST['user_id'];
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? '';
        $stat  = $_POST['status'] ?? 'active';
        $pass  = trim($_POST['new_password'] ?? '');

        if (!$name || !$role || ($role !== 'student' && !$email)) {
            $error = 'Name and role cannot be empty. Other roles also require email.';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            $conflict = query_one("SELECT id FROM users WHERE email=? AND id!=?", [$email, $uid], 'si');
            if ($conflict) {
                $error = 'That email address is already in use by another account.';
            } else {
                if ($pass) {
                    query("UPDATE users SET name=?, email=?, role=?, status=?, password=? WHERE id=?",
                        [$name, $email ?: null, $role, $stat, password_hash($pass, PASSWORD_DEFAULT), $uid], 'sssssi');
                } else {
                    query("UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?",
                        [$name, $email ?: null, $role, $stat, $uid], 'ssssi');
                }
                // Keep student archive flag in sync with account status
                if ($role === 'student') {
                    if ($stat === 'archived') {
                        query("UPDATE students SET is_archived=1 WHERE user_id=?", [$uid], 'i');
                    } else {
                        query("UPDATE students SET is_archived=0 WHERE user_id=?", [$uid], 'i');
                    }
                }
                log_activity($user['id'], 'User Updated', "User ID: $uid ($name)");
                $success = "User $name updated successfully.";
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
$next_student_id = generate_student_id();
$next_coord_id   = generate_coordinator_id();
$add_user_failed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user' && $error !== '');
$add_values = $add_user_failed ? array_filter($_POST, 'is_scalar') : [];
$add_role = $add_values['role'] ?? (in_array($role_filter, ['student','coordinator','company','admin'], true) ? $role_filter : '');
$add_id_mode = ($add_values['student_id_mode'] ?? '') === 'auto' ? 'auto' : 'manual';

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
      'clear'=>'/ojtrack/admin/users.php','count'=>count($users).' users found',
  ];
  require __DIR__.'/../includes/record-filters.php';
  ?>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Account Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr class="row-clickable" onclick='openEditModal(<?= htmlspecialchars(json_encode($u, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
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
          <td onclick="stopRowClick(event)">
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary"
                      title="Edit user" aria-label="Edit user"
                      onclick='openEditModal(<?= htmlspecialchars(json_encode($u, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
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
        <?php if (empty($users)): ?><tr><td colspan="6" class="text-center text-muted py-6">No users found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
  <div class="modal">
    <div class="modal-title">Add New User Account</div>
    <?php if ($add_user_failed): ?><div class="alert alert-error mb-4" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="form-group"><label class="form-label">Full Name <span style="color:red">*</span></label><input type="text" name="name" class="form-control" placeholder="e.g. Juan Dela Cruz" value="<?= e($add_values['name'] ?? '') ?>" required></div>
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
        <div class="form-group">
          <label class="form-label" for="addStudentIdMode">Student ID assignment</label>
          <select name="student_id_mode" id="addStudentIdMode" class="form-control" onchange="toggleStudentIdMode()">
            <option value="manual"<?= $add_id_mode === 'manual' ? ' selected' : '' ?>>Enter student ID</option>
            <option value="auto"<?= $add_id_mode === 'auto' ? ' selected' : '' ?>>Generate automatically</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="addStudentId">Student ID Number <span style="color:red">*</span></label>
          <input type="text" name="student_id_no" id="addStudentId" class="form-control" value="<?= e($add_values['student_id_no'] ?? '') ?>" data-generated-id="<?= e($next_student_id) ?>" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,19}" placeholder="Enter official student ID" autocomplete="off" aria-describedby="addStudentIdHelp">
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
          <div class="form-group"><label class="form-label">Year Level</label><input type="text" name="year_level" class="form-control" value="<?= e($add_values['year_level'] ?? '4th Year') ?>"></div>
          <div class="form-group"><label class="form-label">Required Hours</label><input type="number" name="required_hours" class="form-control" value="<?= e($add_values['required_hours'] ?? '486') ?>"></div>
        </div>
      </div>

      <!-- Coordinator Specific Fields -->
      <div id="coordinatorFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="form-group">
          <label class="form-label">Coordinator ID</label>
          <input type="text" class="form-control" value="<?= e($next_coord_id) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;font-family:var(--font-mono);font-weight:700">
          <div class="text-xs text-muted mt-1">Auto-generated (format: C + year + sequence). Confirmed on save.</div>
        </div>
        <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="coord_contact_number" class="form-control" placeholder="e.g. 0917-123-4567"></div>
        <div class="form-group"><label class="form-label">Assigned Department</label><input type="text" name="department" class="form-control" value="Information Technology"></div>
      </div>

      <!-- Company Specific Fields -->
      <div id="companyFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="form-group"><label class="form-label">Supervisor</label><input type="text" name="supervisor_name" class="form-control" placeholder="e.g. Engr. Maria Santos"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-control" placeholder="Cagayan de Oro City"></div>
          <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="0917-123-4567"></div>
        </div>
      </div>

      <p id="studentLoginHelp" class="text-muted" style="display:none">Student login and initial password: student ID.</p>
      <div class="form-group" id="addPasswordGroup"><label class="form-label">Initial Password <span style="color:red">*</span></label><input type="password" name="password" id="addPassword" class="form-control" placeholder="Min. 6 characters" required></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editUserModal">
  <div class="modal">
    <div class="modal-title">User Account Details</div>
    <p class="modal-sub" id="editUserMeta"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="user_id" id="editUserId">
      <div class="form-group"><label class="form-label">Full Name <span style="color:red">*</span></label><input type="text" name="name" id="editName" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Email Address <span style="color:red">*</span></label><input type="email" name="email" id="editEmail" class="form-control" required></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Role <span style="color:red">*</span></label>
          <select name="role" id="editRole" class="form-control" required>
            <option value="student">Student</option>
            <option value="coordinator">Coordinator</option>
            <option value="company">Company</option>
            <option value="admin">Administrator</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Status <span style="color:red">*</span></label>
          <select name="status" id="editStatus" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Reset Password (Leave blank to keep current)</label>
        <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current password">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleStudentIdMode() {
  const student = document.getElementById('addRoleSelect').value === 'student';
  const mode = document.getElementById('addStudentIdMode');
  const input = document.getElementById('addStudentId');
  if (mode.value === 'auto') {
    if (input.dataset.mode !== 'auto') input.dataset.manualValue = input.value;
    input.value = input.dataset.generatedId;
  } else if (input.dataset.mode === 'auto') {
    input.value = input.dataset.manualValue || '';
  }
  input.dataset.mode = mode.value;
  input.required = student && mode.value === 'manual';
  input.readOnly = mode.value === 'auto';
  input.disabled = !student || mode.value === 'auto';
  mode.disabled = !student;
  document.getElementById('addStudentIdHelp').textContent = mode.value === 'auto'
    ? 'Preview only. A unique ID is generated when the account is saved.'
    : "Use the student's official ID. Up to 20 letters, numbers or hyphens.";
}
function toggleRoleFields(role) {
  const student = role === 'student';
  document.getElementById('addStudentProgram').required = student;
  document.getElementById('addStudentProgram').disabled = !student;
  toggleStudentIdMode();
  document.getElementById('addEmail').required = !student;
  document.getElementById('addEmailRequired').style.display = student ? 'none' : '';
  document.getElementById('addPassword').required = !student;
  document.getElementById('addPassword').disabled = student;
  document.getElementById('addPasswordGroup').style.display = student ? 'none' : '';
  document.getElementById('studentLoginHelp').style.display = student ? '' : 'none';
  document.getElementById('studentFields').style.display = role === 'student' ? 'block' : 'none';
  document.getElementById('coordinatorFields').style.display = role === 'coordinator' ? 'block' : 'none';
  document.getElementById('companyFields').style.display = role === 'company' ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
  toggleRoleFields(document.getElementById('addRoleSelect').value);
  <?php if ($add_user_failed): ?>openModal('addUserModal');<?php endif; ?>
});
function openEditModal(u) {
  document.getElementById('editUserId').value = u.id;
  document.getElementById('editName').value = u.name;
  document.getElementById('editEmail').value = u.email || '';
  document.getElementById('editEmail').required = u.role !== 'student';
  document.getElementById('editRole').value = u.role;
  document.getElementById('editStatus').value = u.status || 'active';
  const created = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
  document.getElementById('editUserMeta').textContent =
    (u.role ? (u.role.charAt(0).toUpperCase() + u.role.slice(1)) : 'User') +
    (created ? ' · Created ' + created : '');
  openModal('editUserModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
