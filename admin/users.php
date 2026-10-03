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

        if (!$name || !$email || !$role || !$pass) {
            $error = 'Name, email, role, and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } elseif ($role === 'student' && empty($_POST['program_id'])) {
            $error = 'Academic Program is required for students.';
        } else {
            $uid = insert("INSERT INTO users (name, email, role, password, status) VALUES (?,?,?,?,'active')",
                [$name, $email, $role, password_hash($pass, PASSWORD_DEFAULT)], 'ssss');

            $assigned_id = '';
            if ($role === 'student') {
                $student_id_no = generate_student_id();
                $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
                $prog_code  = 'BSIT';
                if ($program_id) {
                    $pr = query_one("SELECT code FROM programs WHERE id=?", [$program_id], 'i');
                    if ($pr) $prog_code = $pr['code'];
                }
                $year    = trim($_POST['year_level'] ?? '4th Year');
                $req_h   = (int)($_POST['required_hours'] ?? 486);
                insert("INSERT INTO students (user_id, student_id_no, program_id, program, year_level, required_hours, ojt_status) VALUES (?,?,?,?,?,?,'pending')",
                    [$uid, $student_id_no, $program_id, $prog_code, $year, $req_h], 'isisss');
                $assigned_id = $student_id_no;
            } elseif ($role === 'coordinator') {
                $coord_id_no = generate_coordinator_id();
                $phone       = trim($_POST['coord_contact_number'] ?? '');
                $dept        = trim($_POST['department'] ?? 'Information Technology');
                insert("INSERT INTO coordinators (user_id, coordinator_id_no, department, contact_number) VALUES (?,?,?,?)",
                    [$uid, $coord_id_no, $dept, $phone], 'isss');
                $assigned_id = $coord_id_no;
            } elseif ($role === 'company') {
                $sup  = trim($_POST['supervisor_name'] ?? '');
                $loc  = trim($_POST['location'] ?? '');
                $phone = trim($_POST['contact_number'] ?? '');
                insert("INSERT INTO companies (user_id, company_name, supervisor_name, location, contact_number, status) VALUES (?,?,?,?,?,'active')",
                    [$uid, $name, $sup, $loc, $phone], 'issss');
            }

            log_activity($user['id'], 'User Created', "$name ($role)" . ($assigned_id ? " ID: $assigned_id" : ''));
            $success = "User $name ($role) created successfully." . ($assigned_id ? " Assigned ID: $assigned_id." : '');
        }
    } elseif ($action === 'edit_user') {
        $uid   = (int)$_POST['user_id'];
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? '';
        $stat  = $_POST['status'] ?? 'active';
        $pass  = trim($_POST['new_password'] ?? '');

        if (!$name || !$email || !$role) {
            $error = 'Name, email, and role cannot be empty.';
        } else {
            $conflict = query_one("SELECT id FROM users WHERE email=? AND id!=?", [$email, $uid], 'si');
            if ($conflict) {
                $error = 'That email address is already in use by another account.';
            } else {
                if ($pass) {
                    query("UPDATE users SET name=?, email=?, role=?, status=?, password=? WHERE id=?",
                        [$name, $email, $role, $stat, password_hash($pass, PASSWORD_DEFAULT), $uid], 'sssssi');
                } else {
                    query("UPDATE users SET name=?, email=?, role=?, status=? WHERE id=?",
                        [$name, $email, $role, $stat, $uid], 'ssssi');
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

$role_filter   = $_GET['role'] ?? '';
$search        = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

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

$page_title = 'User Accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">User Accounts</div>
    <div class="page-sub">Manage system accounts, access privileges, and security across all user roles</div>
  </div>
  <div class="page-heading-actions">
    <a href="/ojtrack/admin/archived-users.php" class="btn btn-secondary">Archived Accounts (<?= $archived_count ?>)</a>
    <button class="btn btn-primary" onclick="openModal('addUserModal')">+ Add New User</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form method="GET" style="display:flex;gap:8px">
      <?php if ($role_filter): ?><input type="hidden" name="role" value="<?= e($role_filter) ?>"><?php endif; ?>
      <div class="search-wrap" style="width:240px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search name or email..." value="<?= e($search) ?>">
      </div>
      <select name="status" class="form-control" style="width:170px" onchange="this.form.submit()">
        <option value="" <?= $status_filter===''?'selected':'' ?>>Active &amp; Inactive</option>
        <option value="active" <?= $status_filter==='active'?'selected':'' ?>>Active Only</option>
        <option value="inactive" <?= $status_filter==='inactive'?'selected':'' ?>>Inactive Only</option>
        <option value="all" <?= $status_filter==='all'?'selected':'' ?>>All (excl. Archived)</option>
      </select>
      <button type="submit" class="btn btn-secondary">Filter</button>
      <?php if ($role_filter || $search || $status_filter): ?><a href="/ojtrack/admin/users.php" class="btn btn-ghost">Clear</a><?php endif; ?>
      <a href="/ojtrack/admin/archived-users.php" class="btn btn-ghost btn-xs" style="align-self:center">View archive →</a>
    </form>
    <div class="text-sm text-muted"><?= count($users) ?> users found</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th>Account Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr class="row-clickable" onclick='openEditModal(<?= htmlspecialchars(json_encode($u, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
          <td>
            <div style="display:flex;gap:10px;align-items:center">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= strtoupper(substr($u['name'],0,2)) ?></div>
              <div>
                <span class="font-bold text-sm"><?= e($u['name']) ?></span>
                <?php if ($u['status'] === 'archived'): ?>
                  <span class="badge badge-archived" style="font-size:10px;margin-left:4px">Archived</span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="text-sm text-muted"><?= e($u['email']) ?></td>
          <td><span class="badge badge-role badge-role-<?= e($u['role']) ?>"><?= ucfirst($u['role']) ?></span></td>
          <td class="td-mono text-sm"><?= isset($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?></td>
          <td><?= status_badge($u['status'] ?? 'active') ?></td>
          <td onclick="stopRowClick(event)">
            <div style="display:flex;gap:4px">
              <button type="button" class="btn btn-secondary btn-xs" onclick='openEditModal(<?= htmlspecialchars(json_encode($u, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>Edit</button>
              <form method="POST" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-xs"><?= ($u['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <?php if ((int)$u['id'] !== (int)$user['id']): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Archive this <?= e($u['role']) ?> account? They will lose login access, but all records will be preserved.')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="archive_user">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-warning btn-xs">Archive</button>
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
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="form-group"><label class="form-label">Full Name / Display Name <span style="color:red">*</span></label><input type="text" name="name" class="form-control" placeholder="e.g. Juan Dela Cruz" required></div>
      <div class="form-group"><label class="form-label">Email Address <span style="color:red">*</span></label><input type="email" name="email" class="form-control" placeholder="user@ustp.edu.ph" required></div>
      <div class="form-group"><label class="form-label">System Role <span style="color:red">*</span></label>
        <select name="role" id="addRoleSelect" class="form-control" onchange="toggleRoleFields(this.value)" required>
          <option value="">Select role...</option>
          <option value="student">Student</option>
          <option value="coordinator">Coordinator</option>
          <option value="company">Partner Company</option>
          <option value="admin">Administrator</option>
        </select>
      </div>

      <!-- Student Specific Fields -->
      <div id="studentFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="form-group">
          <label class="form-label">Student ID Number</label>
          <input type="text" class="form-control" value="<?= e($next_student_id) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;font-family:var(--font-mono);font-weight:700">
          <div class="text-xs text-muted mt-1">Auto-generated (format: S + year + sequence). Confirmed on save.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Academic Program <span style="color:red">*</span></label>
          <select name="program_id" class="form-control">
            <option value="">Select Academic Program...</option>
            <?php foreach ($programs_list as $pl): ?>
              <option value="<?= $pl['id'] ?>"><?= e($pl['code']) ?> - <?= e($pl['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-xs text-muted mt-1" style="display:flex;justify-content:space-between">
            <span>Retrieved from Program Management</span>
            <a href="/ojtrack/admin/programs.php" target="_blank" style="color:var(--primary);font-weight:600">+ Manage Programs</a>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Year Level</label><input type="text" name="year_level" class="form-control" value="4th Year"></div>
          <div class="form-group"><label class="form-label">Required Hours</label><input type="number" name="required_hours" class="form-control" value="486"></div>
        </div>
      </div>

      <!-- Coordinator Specific Fields -->
      <div id="coordinatorFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="form-group">
          <label class="form-label">Coordinator ID</label>
          <input type="text" class="form-control" value="<?= e($next_coord_id) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;font-family:var(--font-mono);font-weight:700">
          <div class="text-xs text-muted mt-1">Auto-generated (format: C + year + sequence). Confirmed on save.</div>
        </div>
        <div class="form-group"><label class="form-label">Phone / Contact Number</label><input type="text" name="coord_contact_number" class="form-control" placeholder="e.g. 0917-123-4567"></div>
        <div class="form-group"><label class="form-label">Assigned Department</label><input type="text" name="department" class="form-control" value="Information Technology"></div>
      </div>

      <!-- Company Specific Fields -->
      <div id="companyFields" style="display:none;padding:12px;background:var(--bg);border-radius:var(--radius);margin-bottom:14px">
        <div class="form-group"><label class="form-label">Supervisor / Contact Person</label><input type="text" name="supervisor_name" class="form-control" placeholder="e.g. Engr. Maria Santos"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-control" placeholder="Cagayan de Oro City"></div>
          <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="0917-123-4567"></div>
        </div>
      </div>

      <div class="form-group"><label class="form-label">Initial Password <span style="color:red">*</span></label><input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required></div>
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
        <input type="password" name="new_password" class="form-control" placeholder="Enter new password to change">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleRoleFields(role) {
  document.getElementById('studentFields').style.display = role === 'student' ? 'block' : 'none';
  document.getElementById('coordinatorFields').style.display = role === 'coordinator' ? 'block' : 'none';
  document.getElementById('companyFields').style.display = role === 'company' ? 'block' : 'none';
}
function openEditModal(u) {
  document.getElementById('editUserId').value = u.id;
  document.getElementById('editName').value = u.name;
  document.getElementById('editEmail').value = u.email;
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
