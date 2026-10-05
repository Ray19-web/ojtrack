<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['edit_company','toggle_company'], true)) {
        $account = query_one("SELECT u.status FROM companies c JOIN users u ON u.id=c.user_id WHERE c.id=?", [(int)($_POST['company_id'] ?? 0)], 'i');
        if (!$account || $account['status'] === 'archived') request_error(409, 'Restore the company account from Archived Accounts before editing it.');
    }


    if ($action === 'add_company') {
        $cname   = trim($_POST['company_name'] ?? '');
        $loc     = trim($_POST['location'] ?? '');
        $sup     = trim($_POST['supervisor_name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['contact_number'] ?? '');
        $pass    = trim($_POST['password'] ?? '');

        if (!$cname || !$email || strlen($pass) < 12 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Company name, a valid email and a password of at least 12 characters are required.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } else {
            $uid = insert("INSERT INTO users (name, email, role, password, status) VALUES (?,?,'company',?,'active')",
                [$cname, $email, password_hash($pass, PASSWORD_DEFAULT)], 'sss');
            insert("INSERT INTO companies (user_id, company_name, supervisor_name, location, contact_number, status) VALUES (?,?,?,?,?,'active')",
                [$uid, $cname, $sup, $loc, $phone], 'issss');
            log_activity($user['id'], 'Company Added', $cname);
            $success = "Company $cname registered successfully.";
        }
    } elseif ($action === 'edit_company') {
        $cid   = (int)$_POST['company_id'];
        $cname = trim($_POST['company_name'] ?? '');
        $loc   = trim($_POST['location'] ?? '');
        $sup   = trim($_POST['supervisor_name'] ?? '');
        $phone = trim($_POST['contact_number'] ?? '');
        $stat  = $_POST['status'] ?? 'active';

        if (!$cname) {
            $error = 'Company name cannot be empty.';
        } else {
            query("UPDATE companies SET company_name=?, supervisor_name=?, location=?, contact_number=?, status=? WHERE id=?",
                [$cname, $sup, $loc, $phone, $stat, $cid], 'sssssi');
            // Update associated user name as well
            $co = query_one("SELECT user_id FROM companies WHERE id=?", [$cid], 'i');
            if ($co) {
                query("UPDATE users SET name=?, status=? WHERE id=?", [$cname, $stat, $co['user_id']], 'ssi');
            }
            log_activity($user['id'], 'Company Updated', "Company ID: $cid ($cname)");
            $success = "Company $cname updated successfully.";
        }
    } elseif ($action === 'toggle_company') {
        $cid = (int)$_POST['company_id'];
        $cur = query_one("SELECT status, user_id FROM companies WHERE id=?", [$cid], 'i');
        if ($cur) {
            $new_st = ($cur['status'] === 'active') ? 'inactive' : 'active';
            query("UPDATE companies SET status=? WHERE id=?", [$new_st, $cid], 'si');
            query("UPDATE users SET status=? WHERE id=?", [$new_st, $cur['user_id']], 'si');
            log_activity($user['id'], 'Company Status Toggled', "Company ID: $cid set to $new_st");
            $success = "Company status updated to $new_st.";
        }
    }
}

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$where  = '1=1'; $params = []; $types = '';

if ($search) {
    $where .= " AND (co.company_name LIKE ? OR co.location LIKE ? OR co.supervisor_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}
if ($status) {
    $where .= " AND co.status=?";
    $params[] = $status;
    $types .= 's';
}

$companies = query("SELECT co.*, u.email, u.status AS user_status,
    (SELECT COUNT(*) FROM students s JOIN users su ON su.id=s.user_id
     WHERE s.company_id=co.id AND s.is_archived=0 AND su.status!='archived') AS trainee_count,
    (SELECT COUNT(*) FROM students s JOIN users su ON su.id=s.user_id
     WHERE s.company_id=co.id AND s.ojt_status='ongoing' AND s.is_archived=0 AND su.status!='archived') AS active_trainees
    FROM companies co LEFT JOIN users u ON u.id=co.user_id WHERE $where ORDER BY co.company_name",
    $params, $types);

$page_title = 'Company Registry';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Company Registry</div>
    <div class="page-sub">Manage accredited OJT partner industry hosts and supervisors</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addCompanyModal')">+ Register Partner Company</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form method="GET" style="display:flex;gap:8px">
      <div class="search-wrap" style="width:260px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search company or location..." value="<?= e($search) ?>">
      </div>
      <select name="status" class="form-control" style="width:140px" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
      <button type="submit" class="btn btn-secondary">Filter</button>
      <?php if ($search || $status): ?><a href="/ojtrack/admin/companies.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
    <div class="text-sm text-muted"><?= count($companies) ?> partner companies</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Company Name</th>
          <th>Location</th>
          <th>Supervisor / Contact</th>
          <th>Contact Number</th>
          <th>Email</th>
          <th>Trainees</th>
          <th>Active</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $c): ?>
        <tr class="row-clickable" onclick='openEditCompany(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
          <td>
            <div style="display:flex;gap:10px;align-items:center">
              <div style="width:36px;height:36px;border-radius:var(--radius);background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--primary);flex-shrink:0"><?= strtoupper(substr($c['company_name'],0,2)) ?></div>
              <span class="font-bold text-sm"><?= e($c['company_name']) ?></span>
            </div>
          </td>
          <td class="text-sm text-muted"><?= e($c['location'] ?? '—') ?></td>
          <td class="text-sm font-semibold"><?= e($c['supervisor_name'] ?? '—') ?></td>
          <td class="td-mono text-xs"><?= e($c['contact_number'] ?? '—') ?></td>
          <td class="text-sm text-muted"><?= e($c['email'] ?? '—') ?></td>
          <td class="text-center font-bold"><?= $c['trainee_count'] ?></td>
          <td class="text-center text-success font-bold"><?= $c['active_trainees'] ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td onclick="stopRowClick(event)">
            <div style="display:flex;gap:4px">
              <button type="button" class="btn btn-secondary btn-xs" onclick='openEditCompany(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>Edit</button>
              <form method="POST" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_company">
                <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-xs"><?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($companies)): ?><tr><td colspan="9" class="text-center text-muted py-6">No companies registered yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Company Modal -->
<div class="modal-overlay" id="addCompanyModal">
  <div class="modal modal-lg">
    <div class="modal-title">Register Partner Company</div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_company">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Company / Organization Name <span style="color:red">*</span></label><input type="text" name="company_name" class="form-control" placeholder="e.g. Innovatech Solutions" required></div>
        <div class="form-group"><label class="form-label">Workplace Location</label><input type="text" name="location" class="form-control" placeholder="e.g. Cagayan de Oro City"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Supervisor / Contact Person</label><input type="text" name="supervisor_name" class="form-control" placeholder="e.g. Engr. Roberto Diaz"></div>
        <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="0917-123-4567"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Account Email Address <span style="color:red">*</span></label><input type="email" name="email" class="form-control" placeholder="company@domain.com" required></div>
        <div class="form-group"><label class="form-label">Initial Password</label><input type="password" name="password" class="form-control" value="" required></div>
      </div>
      <div class="alert alert-info"><div class="alert-body"><small>An account will be created automatically for this partner company to log in, view trainees, and submit performance evaluations.</small></div></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addCompanyModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Register Company</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Company Modal -->
<div class="modal-overlay" id="editCompanyModal">
  <div class="modal modal-lg">
    <div class="modal-title">Company Details</div>
    <p class="modal-sub" id="editCompMeta"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_company">
      <input type="hidden" name="company_id" id="editCompId">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Company Name <span style="color:red">*</span></label><input type="text" name="company_name" id="editCompName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" id="editCompLoc" class="form-control"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Supervisor / Contact Person</label><input type="text" name="supervisor_name" id="editCompSup" class="form-control"></div>
        <div class="form-group"><label class="form-label">Contact Number</label><input type="text" name="contact_number" id="editCompPhone" class="form-control"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Account Email</label><input type="text" id="editCompEmail" class="form-control" readonly style="background:#f1f5f9;cursor:not-allowed"></div>
        <div class="form-group">
          <label class="form-label">Partnership Status</label>
          <select name="status" id="editCompStatus" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Total Trainees</label><input type="text" id="editCompTrainees" class="form-control" readonly style="background:#f1f5f9;cursor:not-allowed"></div>
        <div class="form-group"><label class="form-label">Active in OJT</label><input type="text" id="editCompActive" class="form-control" readonly style="background:#f1f5f9;cursor:not-allowed"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editCompanyModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditCompany(c) {
  document.getElementById('editCompId').value = c.id;
  document.getElementById('editCompName').value = c.company_name;
  document.getElementById('editCompLoc').value = c.location || '';
  document.getElementById('editCompSup').value = c.supervisor_name || '';
  document.getElementById('editCompPhone').value = c.contact_number || '';
  document.getElementById('editCompStatus').value = c.status || 'active';
  document.getElementById('editCompEmail').value = c.email || '—';
  document.getElementById('editCompTrainees').value = String(c.trainee_count ?? 0);
  document.getElementById('editCompActive').value = String(c.active_trainees ?? 0);
  document.getElementById('editCompMeta').textContent = (c.company_name || '') + (c.email ? ' · ' + c.email : '');
  openModal('editCompanyModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
