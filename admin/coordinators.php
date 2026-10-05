<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_coord') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $dept     = trim($_POST['department'] ?? '');
        $phone    = trim($_POST['contact_number'] ?? '');
        $pass     = trim($_POST['password'] ?? 'coord123');

        $prog = $dept !== '' ? query_one("SELECT code, name FROM programs WHERE code=? AND status='active'", [$dept], 's') : null;

        if (!$name || !$email) {
            $error = 'Name and email are required.';
        } elseif (!$prog) {
            $error = 'Please select a valid academic program for Assigned Department.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already in use by another account.';
        } else {
            $dept = $prog['code'];
            $coord_id = generate_coordinator_id();
            $uid = insert("INSERT INTO users (name,email,role,password,status) VALUES (?,?,'coordinator',?,'active')",
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT)], 'sss');
            insert("INSERT INTO coordinators (user_id, coordinator_id_no, department, contact_number) VALUES (?,?,?,?)",
                [$uid, $coord_id, $dept, $phone], 'isss');
            log_activity($user['id'], 'Coordinator Added', "$name ({$prog['code']} - {$prog['name']} - $coord_id)");
            $success = "Coordinator $name created successfully. Assigned ID: $coord_id.";
        }
    } elseif ($action === 'edit_coord') {
        $cid      = (int)$_POST['coord_id'];
        $dept     = trim($_POST['department'] ?? '');
        $phone    = trim($_POST['contact_number'] ?? '');

        $prog = $dept !== '' ? query_one("SELECT code, name FROM programs WHERE code=? AND status='active'", [$dept], 's') : null;
        if (!$prog) {
            $error = 'Please select a valid academic program for Assigned Department.';
        } else {
            $dept = $prog['code'];
            // coordinator_id_no is system-generated and not editable
            query("UPDATE coordinators SET department=?, contact_number=? WHERE id=?",
                [$dept, $phone, $cid], 'ssi');
            log_activity($user['id'], 'Coordinator Details Updated', "Coord ID: $cid → {$prog['code']}");
            $success = 'Coordinator details updated successfully.';
        }
    } elseif ($action === 'assign_student') {
        $coord_id   = (int)$_POST['coord_id'];
        $student_id = (int)$_POST['student_id'];
        query("UPDATE students SET coordinator_id=? WHERE id=?", [$coord_id, $student_id], 'ii');
        log_activity($user['id'], 'Student Assigned to Coordinator', "Student ID: $student_id to Coord ID: $coord_id");
        $success = 'Student assigned to coordinator successfully.';
    }
}

$coordinators = query("SELECT c.*, u.name, u.email, u.status,
    (SELECT COUNT(*) FROM students s JOIN users su ON su.id=s.user_id WHERE s.coordinator_id=c.id AND s.is_archived=0 AND su.status!='archived') AS student_count,
    (SELECT COUNT(*) FROM students s JOIN users su ON su.id=s.user_id WHERE s.coordinator_id=c.id AND s.ojt_status='ongoing' AND s.is_archived=0 AND su.status!='archived') AS active_students
    FROM coordinators c JOIN users u ON u.id=c.user_id ORDER BY u.name", [], '');

$unassigned = query("SELECT s.id, u.name, s.student_id_no FROM students s JOIN users u ON u.id=s.user_id WHERE s.coordinator_id IS NULL AND s.is_archived=0 AND u.status!='archived' ORDER BY u.name", [], '');
$next_coord_id = generate_coordinator_id();
$programs_list = query("SELECT id, code, name FROM programs WHERE status='active' ORDER BY code ASC", [], '') ?: [];

// Map program codes → labels for nicer card display (also resolve legacy free-text departments)
$program_label_by_code = [];
foreach ($programs_list as $pr) {
    $program_label_by_code[strtoupper($pr['code'])] = $pr['code'] . ' - ' . $pr['name'];
}

$page_title = 'Coordinators';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Coordinator Management</div>
    <div class="page-sub">Manage OJT Coordinators, faculty ID numbers, contact details, and department allocations</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addCoordModal')">+ Add New Coordinator</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if (!empty($unassigned)): ?>
<div class="alert alert-warn mb-4">
  <div class="alert-body">
    <p><strong><?= count($unassigned) ?> active student(s)</strong> currently have no assigned OJT Coordinator.</p>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-3 gap-4 mb-5 coordinator-grid">
  <?php foreach ($coordinators as $c): ?>
  <?php
    $dept_raw = trim((string)($c['department'] ?? ''));
    $dept_label = $program_label_by_code[strtoupper($dept_raw)] ?? ($dept_raw !== '' ? $dept_raw : 'No Program Assigned');

    // Build meaningful initials while ignoring common professional titles.
    $coord_words = preg_split('/\s+/', trim((string)$c['name'])) ?: [];
    $coord_prefixes = ['prof', 'prof.', 'dr', 'dr.', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'engr', 'engr.'];
    $coord_words = array_values(array_filter($coord_words, function ($word) use ($coord_prefixes) {
        return !in_array(strtolower(trim($word)), $coord_prefixes, true);
    }));
    if (count($coord_words) >= 2) {
        $coord_initials = strtoupper(substr($coord_words[0], 0, 1) . substr($coord_words[count($coord_words) - 1], 0, 1));
    } elseif (count($coord_words) === 1) {
        $coord_initials = strtoupper(substr($coord_words[0], 0, 2));
    } else {
        $coord_initials = 'OC';
    }
  ?>
  <div class="card coordinator-card card-clickable"
       onclick='openEditCoord(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'
       title="Click to view &amp; edit">
    <div class="coordinator-card-head">
      <div class="coordinator-identity">
        <div class="coordinator-avatar" aria-hidden="true"><?= e($coord_initials) ?></div>
        <div class="coordinator-identity-copy">
          <div class="coordinator-name"><?= e($c['name']) ?></div>
          <div class="coordinator-email" title="<?= e($c['email']) ?>"><?= e($c['email']) ?></div>
          <div class="coordinator-program" title="<?= e($dept_label) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M3 10.5 12 5l9 5.5L12 16l-9-5.5Z"/>
              <path d="M7 13.2V17c2.9 2 7.1 2 10 0v-3.8"/>
            </svg>
            <span><?= e($dept_label) ?></span>
          </div>
        </div>
      </div>
      <div class="coordinator-status"><?= status_badge($c['status']) ?></div>
    </div>

    <div class="coordinator-meta">
      <div class="coordinator-meta-row">
        <span class="coordinator-meta-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h4M7 13h6M16 10h2M16 14h2"/></svg>
        </span>
        <span class="coordinator-meta-copy">
          <span class="coordinator-meta-label">Coordinator ID</span>
          <strong class="coordinator-meta-value td-mono"><?= e($c['coordinator_id_no'] ?: 'Not Set') ?></strong>
        </span>
      </div>
      <div class="coordinator-meta-row">
        <span class="coordinator-meta-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/></svg>
        </span>
        <span class="coordinator-meta-copy">
          <span class="coordinator-meta-label">Phone Number</span>
          <strong class="coordinator-meta-value"><?= e($c['contact_number'] ?: 'Not Set') ?></strong>
        </span>
      </div>
    </div>

    <div class="coordinator-stats">
      <div class="coordinator-stat coordinator-stat-assigned">
        <span class="coordinator-stat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>
        </span>
        <span class="coordinator-stat-copy">
          <strong><?= (int)$c['student_count'] ?></strong>
          <span>Assigned Trainees</span>
        </span>
      </div>
      <div class="coordinator-stat coordinator-stat-active">
        <span class="coordinator-stat-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/><circle cx="12" cy="12" r="9"/></svg>
        </span>
        <span class="coordinator-stat-copy">
          <strong><?= (int)$c['active_students'] ?></strong>
          <span>Active in OJT</span>
        </span>
      </div>
    </div>

    <?php if (!empty($unassigned)): ?>
    <div class="coordinator-assignment" onclick="stopRowClick(event)">
      <div class="coordinator-assignment-label">Quick student assignment</div>
      <form method="POST" class="coordinator-assignment-form">
        <input type="hidden" name="action" value="assign_student">
        <input type="hidden" name="coord_id" value="<?= $c['id'] ?>">
        <select name="student_id" class="form-control" required>
          <option value="">Select unassigned student...</option>
          <?php foreach ($unassigned as $us): ?>
            <option value="<?= $us['id'] ?>"><?= e($us['name']) ?> (<?= e($us['student_id_no'] ?? '') ?>)</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Assign</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="coordinator-card-footer">
      <button type="button"
              class="coordinator-edit-btn"
              onclick='stopRowClick(event); openEditCoord(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>
        <span class="coordinator-edit-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </span>
        <span>View &amp; Edit Details</span>
        <svg class="coordinator-edit-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
      </button>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (empty($coordinators)): ?>
  <div class="card card-body coordinator-empty">
    <div class="empty-state"><p>No coordinators registered in the system yet.</p></div>
  </div>
  <?php endif; ?>
</div>

<!-- Add Coordinator Modal -->
<div class="modal-overlay" id="addCoordModal">
  <div class="modal">
    <div class="modal-title">Add New OJT Coordinator</div>
    <form method="POST">
      <input type="hidden" name="action" value="add_coord">
      <div class="form-group"><label class="form-label">Full Name <span style="color:red">*</span></label><input type="text" name="name" class="form-control" placeholder="e.g. Dr. Jocelyn Rivera" required></div>
      <div class="form-group"><label class="form-label">Email Address <span style="color:red">*</span></label><input type="email" name="email" class="form-control" placeholder="jocelyn.rivera@ustp.edu.ph" required></div>
      <div class="form-group">
        <label class="form-label">Coordinator ID</label>
        <input type="text" class="form-control" value="<?= e($next_coord_id) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;font-family:var(--font-mono);font-weight:700">
        <div class="text-xs text-muted mt-1">Auto-generated (format: C + year + sequence). Confirmed on save.</div>
      </div>
      <div class="form-group"><label class="form-label">Phone / Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="e.g. 0917-123-4567"></div>
      <div class="form-group">
        <label class="form-label">Assigned Department <span style="color:red">*</span></label>
        <select name="department" class="form-control" required>
          <option value="">-- Select Academic Program --</option>
          <?php foreach ($programs_list as $pr): ?>
            <option value="<?= e($pr['code']) ?>"><?= e($pr['code']) ?> - <?= e($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1" style="display:flex;justify-content:space-between">
          <span>Retrieved from Program Management</span>
          <a href="/ojtrack/admin/programs.php" target="_blank" style="color:var(--primary);font-weight:600">+ Manage Programs</a>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Initial Password</label><input type="password" name="password" class="form-control" value="coord123" required></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addCoordModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Coordinator</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Coordinator Details Modal -->
<div class="modal-overlay" id="editCoordModal">
  <div class="modal">
    <div class="modal-title">Coordinator Details</div>
    <p class="modal-sub" id="editCoordSubName"></p>
    <div id="editCoordStats" style="background:var(--bg);padding:10px 12px;border-radius:var(--radius);font-size:12px;margin-bottom:14px;display:none"></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_coord">
      <input type="hidden" name="coord_id" id="editCoordId">
      <div class="form-group">
        <label class="form-label">Coordinator ID</label>
        <input type="text" id="editCoordIdNo" class="form-control" readonly style="background:#f1f5f9;cursor:not-allowed;font-family:var(--font-mono);font-weight:700">
        <div class="text-xs text-muted mt-1">System-generated ID cannot be changed.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Phone / Contact Number</label>
        <input type="text" name="contact_number" id="editCoordPhone" class="form-control" placeholder="e.g. 0917-123-4567">
      </div>
      <div class="form-group">
        <label class="form-label">Assigned Department <span style="color:red">*</span></label>
        <select name="department" id="editCoordDept" class="form-control" required>
          <option value="">-- Select Academic Program --</option>
          <?php foreach ($programs_list as $pr): ?>
            <option value="<?= e($pr['code']) ?>"><?= e($pr['code']) ?> - <?= e($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1" style="display:flex;justify-content:space-between">
          <span>Retrieved from Program Management</span>
          <a href="/ojtrack/admin/programs.php" target="_blank" style="color:var(--primary);font-weight:600">+ Manage Programs</a>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editCoordModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
const PROGRAM_OPTIONS = <?= json_encode(array_map(function ($pr) {
    return ['code' => $pr['code'], 'name' => $pr['name']];
}, $programs_list), JSON_UNESCAPED_UNICODE) ?>;

function resolveProgramCode(department) {
  const raw = (department || '').trim();
  if (!raw) return '';
  const upper = raw.toUpperCase();
  for (let i = 0; i < PROGRAM_OPTIONS.length; i++) {
    const p = PROGRAM_OPTIONS[i];
    if (String(p.code).toUpperCase() === upper) return p.code;
    if (String(p.name).toLowerCase() === raw.toLowerCase()) return p.code;
  }
  // Legacy free-text departments (e.g. "Information Technology") → best program match
  const aliases = {
    'information technology': 'BSIT',
    'it': 'BSIT',
    'computer science': 'BSCS',
    'cs': 'BSCS',
    'education': 'BSEd',
    'mechanical engineering technology': 'BSMET',
    'electrical technology': 'BSET',
    'technology communication management': 'BSTCM',
    'tcm': 'BSTCM'
  };
  const mapped = aliases[raw.toLowerCase()];
  if (mapped) {
    for (let i = 0; i < PROGRAM_OPTIONS.length; i++) {
      if (String(PROGRAM_OPTIONS[i].code).toUpperCase() === mapped) return PROGRAM_OPTIONS[i].code;
    }
  }
  return raw;
}

function openEditCoord(c) {
  document.getElementById('editCoordId').value = c.id;
  document.getElementById('editCoordSubName').textContent = c.name + ' (' + c.email + ')';
  document.getElementById('editCoordIdNo').value = c.coordinator_id_no || '—';
  document.getElementById('editCoordPhone').value = c.contact_number || '';
  document.getElementById('editCoordDept').value = resolveProgramCode(c.department || '');

  const stats = document.getElementById('editCoordStats');
  if (stats) {
    stats.style.display = 'block';
    stats.innerHTML =
      '<div style="display:flex;justify-content:space-between;gap:12px"><span class="text-muted">Assigned Trainees</span><span class="font-bold">' + (c.student_count ?? 0) + '</span></div>' +
      '<div style="display:flex;justify-content:space-between;gap:12px;margin-top:6px"><span class="text-muted">Active in OJT</span><span class="font-bold" style="color:#16a34a">' + (c.active_students ?? 0) + '</span></div>';
  }
  openModal('editCoordModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
