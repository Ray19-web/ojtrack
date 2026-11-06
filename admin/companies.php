<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';
if (isset($_SESSION['company_created'])) {
    $success = $_SESSION['company_created'];
    unset($_SESSION['company_created']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'edit_company') {
        $account = query_one("SELECT u.status, c.user_id FROM companies c JOIN users u ON u.id=c.user_id WHERE c.id=?", [(int)($_POST['company_id'] ?? 0)], 'i');
        if (!$account || $account['status'] === 'archived') request_error(409, 'Restore the company account from Archived Accounts before editing it.');
    }


    if ($action === 'add_company') {
        $field = static fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $cname = normalize_person_name($field('company_name')); $loc = $field('location');
        $sup_first_name = normalize_person_name_part($_POST['supervisor_first_name'] ?? '');
        $sup_middle_name = normalize_person_name_part($_POST['supervisor_middle_name'] ?? '');
        $sup_last_name = normalize_person_name_part($_POST['supervisor_last_name'] ?? '');
        $sup_has_name_parts = $sup_first_name !== '' || $sup_middle_name !== '' || $sup_last_name !== '';
        $sup_base = person_name_from_parts($sup_first_name, $sup_middle_name, $sup_last_name);
        $sup_extension_result = name_extensions_from_post('supervisor_name_extension_type', 'supervisor_name_extension_value');
        $sup_extensions = $sup_extension_result['extensions'];
        $sup_first_extension = $sup_extensions[0] ?? null;
        $sup_error = $sup_extension_result['error'];
        $sup = format_person_name($sup_base, $sup_extensions);
        $email = $field('email'); $phone = $field('contact_number');
        $pass = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        if ($cname === '' || mb_strlen($cname) > 100) {
            $error = 'Enter a company name of up to 100 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $error = 'Enter a valid account email address.';
        } elseif (strlen($pass) < 12 || strlen($pass) > 72 || str_contains($pass, "\0")) {
            $error = 'Initial password must be between 12 and 72 characters.';
        } elseif (($sup_has_name_parts && (!$sup_first_name || !$sup_last_name)) || mb_strlen($sup_first_name) > 60 || mb_strlen($sup_middle_name) > 60 || mb_strlen($sup_last_name) > 60 || mb_strlen($loc) > 200 || mb_strlen($sup) > 100 || mb_strlen($phone) > 20) {
            $error = $sup_has_name_parts && (!$sup_first_name || !$sup_last_name)
                ? 'Enter both the supervisor first name and last name.'
                : 'Each supervisor name part must be 60 characters or fewer; the complete name, location and phone must also fit the allowed lengths.';
        } elseif ($sup_error || ($sup_base === '' && $sup_extensions)) {
            $error = $sup_error ?: 'Enter the supervisor name before adding its extension.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } else {
            db()->begin_transaction();
            try {
                $uid = insert("INSERT INTO users (name, email, role, password, status) VALUES (?,?,'company',?,'active')",
                    [$cname, $email, password_hash($pass, PASSWORD_DEFAULT)], 'sss');
                $company_id = insert("INSERT INTO companies (user_id, company_name, supervisor_name, supervisor_first_name, supervisor_middle_name, supervisor_last_name, supervisor_name_extension_type, supervisor_name_extension_value, location, contact_number, status) VALUES (?,?,?,?,?,?,?,?,?,?,'active')",
                    [$uid, $cname, $sup, $sup_has_name_parts ? $sup_first_name : null, $sup_has_name_parts ? ($sup_middle_name ?: null) : null, $sup_has_name_parts ? $sup_last_name : null, $sup_first_extension['type'] ?? null, $sup_first_extension['value'] ?? null, $loc, $phone], 'isssssssss');
                save_company_supervisor_name_extensions((int)$company_id, $sup_extensions);
                log_activity($user['id'], 'Company Added', $cname);
                db()->commit();
            } catch (Throwable $exception) {
                db()->rollback();
                $error = $exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062
                    ? 'Email is already registered in the system.'
                    : 'Unable to register the company. Please try again.';
                error_log('OJTrack company registration failed (code ' . $exception->getCode() . ').');
            }
            if ($error === '') {
                $_SESSION['company_created'] = "Company $cname registered successfully.";
                http_response_code(303);
                redirect('admin/companies.php');
            }
        }
    } elseif ($action === 'edit_company') {
        $cid   = (int)$_POST['company_id'];
        $cname = normalize_person_name($_POST['company_name'] ?? '');
        $loc   = trim($_POST['location'] ?? '');
        $sup_first_name = normalize_person_name_part($_POST['supervisor_first_name'] ?? '');
        $sup_middle_name = normalize_person_name_part($_POST['supervisor_middle_name'] ?? '');
        $sup_last_name = normalize_person_name_part($_POST['supervisor_last_name'] ?? '');
        $sup_has_name_parts = $sup_first_name !== '' || $sup_middle_name !== '' || $sup_last_name !== '';
        $sup_extension_result = name_extensions_from_post('supervisor_name_extension_type', 'supervisor_name_extension_value');
        $sup_extensions = $sup_extension_result['extensions'];
        $sup_error = $sup_extension_result['error'];
        $phone = trim($_POST['contact_number'] ?? '');
        $new_password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $company_record = query_one("SELECT supervisor_name, supervisor_first_name, supervisor_middle_name, supervisor_last_name FROM companies WHERE id=?", [$cid], 'i');
        $sup_base = $sup_has_name_parts
            ? person_name_from_parts($sup_first_name, $sup_middle_name, $sup_last_name)
            : ($company_record ? base_person_name($company_record['supervisor_name'] ?? '', company_supervisor_name_extensions($cid)) : '');
        $sup = format_person_name($sup_base, $sup_extensions);

        if (!$cname) {
            $error = 'Company name cannot be empty.';
        } elseif ($new_password !== '' && (strlen($new_password) < 12 || strlen($new_password) > 72 || str_contains($new_password, "\0"))) {
            $error = 'New password must be between 12 and 72 characters, or leave it blank to keep the current password.';
        } elseif (($sup_has_name_parts && (!$sup_first_name || !$sup_last_name)) || mb_strlen($sup_first_name) > 60 || mb_strlen($sup_middle_name) > 60 || mb_strlen($sup_last_name) > 60 || $sup_error || ($sup_base === '' && $sup_extensions) || mb_strlen($sup) > 100) {
            $error = $sup_error ?: ($sup_base === '' && $sup_extensions ? 'Enter the supervisor name before adding its extension.' : 'Supervisor name must be 100 characters or fewer.');
        } else {
            if ($sup_has_name_parts) {
                query("UPDATE companies SET company_name=?, supervisor_name=?, supervisor_first_name=?, supervisor_middle_name=?, supervisor_last_name=?, location=?, contact_number=? WHERE id=?",
                    [$cname, $sup, $sup_first_name, $sup_middle_name ?: null, $sup_last_name, $loc, $phone, $cid], 'sssssssi');
            } else {
                query("UPDATE companies SET company_name=?, supervisor_name=?, location=?, contact_number=? WHERE id=?",
                    [$cname, $sup, $loc, $phone, $cid], 'ssssi');
            }
            save_company_supervisor_name_extensions($cid, $sup_extensions);
            // Update the associated account name and change its password only when one was supplied.
            if ($account) {
                if ($new_password !== '') {
                    query("UPDATE users SET name=?, password=? WHERE id=?", [$cname, password_hash($new_password, PASSWORD_DEFAULT), $account['user_id']], 'ssi');
                } else {
                    query("UPDATE users SET name=? WHERE id=?", [$cname, $account['user_id']], 'si');
                }
            }
            log_activity($user['id'], 'Company Updated', "Company ID: $cid ($cname)");
            $success = "Company $cname updated successfully.";
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

$companies = query("SELECT co.*, u.email, u.avatar, u.status AS user_status,
    (SELECT COUNT(DISTINCT oe.id) FROM ojt_enrollments oe
     JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
     JOIN placements pl ON pl.ojt_enrollment_id=oe.id AND pl.company_id=co.id AND pl.status IN ('active','on_hold','planned','completed')
     JOIN students s ON s.id=oe.student_id JOIN users su ON su.id=s.user_id
     WHERE s.is_archived=0 AND su.status!='archived') AS trainee_count,
    (SELECT COUNT(DISTINCT oe.id) FROM ojt_enrollments oe
     JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
     JOIN placements pl ON pl.ojt_enrollment_id=oe.id AND pl.company_id=co.id AND pl.status IN ('active','on_hold','planned')
     JOIN students s ON s.id=oe.student_id JOIN users su ON su.id=s.user_id
     WHERE oe.status='ongoing' AND s.is_archived=0 AND su.status!='archived') AS active_trainees
    FROM companies co LEFT JOIN users u ON u.id=co.user_id WHERE $where ORDER BY co.company_name",
    $params, $types);

$add_company_failed = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_company' && $error !== '';
$add_values = $add_company_failed ? array_intersect_key(array_filter($_POST, 'is_scalar'), array_flip(['company_name','location','supervisor_name','supervisor_first_name','supervisor_middle_name','supervisor_last_name','email','contact_number'])) : [];
$add_values['supervisor_name_extension_type'] = $add_company_failed && is_array($_POST['supervisor_name_extension_type'] ?? null) ? $_POST['supervisor_name_extension_type'] : [];
$add_values['supervisor_name_extension_value'] = $add_company_failed && is_array($_POST['supervisor_name_extension_value'] ?? null) ? $_POST['supervisor_name_extension_value'] : [];
$page_title = 'Companies';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Companies</div>
    <div class="page-sub">Partner companies and supervisors</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addCompanyModal')">+ Register Partner Company</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form class="filter-toolbar" method="GET" data-live-filter style="display:flex;gap:8px">
      <div class="search-wrap" style="width:260px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search company or location..." value="<?= e($search) ?>">
      </div>
      <select name="status" class="form-control" style="width:140px">
        <option value="">All Statuses</option>
        <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
      <button type="submit" class="btn btn-secondary">Filter</button>
      <?php if ($search || $status): ?><a href="/ojtrack/admin/companies.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
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
        <?php foreach ($companies as $c): $c['supervisor_name_extensions'] = company_supervisor_name_extensions((int)$c['id']); $c['supervisor_name_base'] = base_person_name($c['supervisor_name'] ?? '', $c['supervisor_name_extensions']); ?>
        <tr class="row-clickable" onclick='openEditCompany(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
          <td>
            <div class="table-person">
              <div style="width:36px;height:36px;border-radius:var(--radius);background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--primary);flex-shrink:0"><?= user_avatar_content($c['avatar'] ?? null, $c['company_name']) ?></div>
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
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary"
                      title="Edit company" aria-label="Edit company"
                      onclick='openEditCompany(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
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
  <div class="modal modal-lg modal-name-form">
    <div class="modal-title">Register Partner Company</div>
    <?php if ($add_company_failed): ?><div class="alert alert-error" role="alert"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_company">
      <div class="user-form-section-heading">Company details</div>
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="newCompanyName">Company / Organization Name <span style="color:red">*</span></label><input type="text" name="company_name" id="newCompanyName" maxlength="100" value="<?= e($add_values['company_name'] ?? '') ?>" class="form-control" placeholder="e.g. Innovatech Solutions" data-uppercase-name required></div>
        <div class="form-group"><label class="form-label" for="newCompanyLocation">Workplace Location</label><input type="text" name="location" id="newCompanyLocation" maxlength="200" value="<?= e($add_values['location'] ?? '') ?>" class="form-control" placeholder="e.g. Cagayan de Oro City"></div>
      </div>
      <div class="user-form-section-heading">Supervisor / contact person</div>
      <?php $person_name_prefix = 'newCompanySupervisor'; $person_name_fields = ['first' => 'supervisor_first_name', 'middle' => 'supervisor_middle_name', 'last' => 'supervisor_last_name']; $person_name_values = ['first' => $add_values['supervisor_first_name'] ?? '', 'middle' => $add_values['supervisor_middle_name'] ?? '', 'last' => $add_values['supervisor_last_name'] ?? '']; $person_name_required = false; require __DIR__ . '/../includes/person-name-fields.php'; ?>
      <?php $name_extension_prefix = 'newCompanySupervisor'; $name_extension_type_field = 'supervisor_name_extension_type'; $name_extension_value_field = 'supervisor_name_extension_value'; $name_extension_selected = $add_values['supervisor_name_extension_type'] ?? []; $name_extension_value = $add_values['supervisor_name_extension_value'] ?? []; $name_extension_entries = null; require __DIR__ . '/../includes/name-extension-fields.php'; ?>
      <div class="form-group"><label class="form-label" for="newCompanyPhone">Contact Number</label><input type="text" name="contact_number" id="newCompanyPhone" maxlength="20" value="<?= e($add_values['contact_number'] ?? '') ?>" class="form-control" placeholder="0917-123-4567"></div>
      <div class="user-form-section-heading">Account access</div>
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="newCompanyEmail">Account Email Address <span style="color:red">*</span></label><input type="email" name="email" id="newCompanyEmail" maxlength="150" value="<?= e($add_values['email'] ?? '') ?>" class="form-control" placeholder="company@domain.com" required></div>
        <div class="form-group"><label class="form-label" for="newCompanyPassword">Initial Password <span style="color:red">*</span></label><input type="password" id="newCompanyPassword" name="password" class="form-control" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="companyPasswordHelp" required><div id="companyPasswordHelp" class="text-xs text-muted mt-1">Use 12–72 characters.</div></div>
      </div>
      <div class="alert alert-info"><div class="alert-body"><small>Creates a company login for trainee management and evaluations.</small></div></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addCompanyModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Register Company</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Company Modal -->
<div class="modal-overlay" id="editCompanyModal">
  <div class="modal modal-lg modal-name-form">
    <div class="modal-title">Company Details</div>
    <p class="modal-sub" id="editCompMeta"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_company">
      <input type="hidden" name="company_id" id="editCompId">
      <div class="user-form-section-heading">Company details</div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Company Name <span style="color:red">*</span></label><input type="text" name="company_name" id="editCompName" class="form-control" data-uppercase-name required></div>
        <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" id="editCompLoc" class="form-control"></div>
      </div>
      <div class="user-form-section-heading">Supervisor / contact person</div>
      <?php $person_name_prefix = 'editCompanySupervisor'; $person_name_fields = ['first' => 'supervisor_first_name', 'middle' => 'supervisor_middle_name', 'last' => 'supervisor_last_name']; $person_name_values = []; $person_name_required = false; require __DIR__ . '/../includes/person-name-fields.php'; ?>
        <div class="text-xs text-muted mb-3">For older records without saved name parts, leave all three fields blank to keep the current supervisor name.</div>
      <?php $name_extension_prefix = 'editCompanySupervisor'; $name_extension_type_field = 'supervisor_name_extension_type'; $name_extension_value_field = 'supervisor_name_extension_value'; $name_extension_entries = []; require __DIR__ . '/../includes/name-extension-fields.php'; ?>
      <div class="user-form-section-heading">Contact and account details</div>
      <div class="form-group"><label class="form-label" for="editCompPhone">Contact Number</label><input type="text" name="contact_number" id="editCompPhone" class="form-control"></div>
      <div class="form-group"><label class="form-label">Account Email</label><input type="text" id="editCompEmail" class="form-control" readonly style="background:#f1f5f9;cursor:not-allowed"></div>
      <div class="form-group"><label class="form-label" for="editCompPassword">New Password</label><input type="password" name="new_password" id="editCompPassword" class="form-control" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="editCompPasswordHelp"><div id="editCompPasswordHelp" class="text-xs text-muted mt-1">Leave blank to keep the current password. A new password must be at least 12 characters.</div></div>
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
<?php if ($add_company_failed): ?>
document.addEventListener('DOMContentLoaded', function () { openModal('addCompanyModal'); });
<?php endif; ?>
function openEditCompany(c) {
  document.getElementById('editCompId').value = c.id;
  document.getElementById('editCompPassword').value = '';
  document.getElementById('editCompName').value = c.company_name;
  document.getElementById('editCompLoc').value = c.location || '';
  document.getElementById('editCompanySupervisorFirstName').value = c.supervisor_first_name || '';
  document.getElementById('editCompanySupervisorMiddleName').value = c.supervisor_middle_name || '';
  document.getElementById('editCompanySupervisorLastName').value = c.supervisor_last_name || '';
  setNameExtensionFields('editCompanySupervisor', c.supervisor_name_extensions || []);
  document.getElementById('editCompPhone').value = c.contact_number || '';
  document.getElementById('editCompEmail').value = c.email || '—';
  document.getElementById('editCompTrainees').value = String(c.trainee_count ?? 0);
  document.getElementById('editCompActive').value = String(c.active_trainees ?? 0);
  document.getElementById('editCompMeta').textContent = (c.company_name || '') + (c.email ? ' · ' + c.email : '');
  openModal('editCompanyModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
