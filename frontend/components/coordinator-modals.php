<!-- Add Coordinator Modal -->
<div class="modal-overlay" id="addCoordModal">
  <div class="modal modal-name-form">
    <div class="modal-title">Add New OJT Coordinator</div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_coord">
      <div class="user-form-section-heading">Coordinator profile</div>
      <?php $person_name_prefix = 'newCoordinator'; $person_name_values = []; $person_name_required = true; require __DIR__ . '/person-name-fields.php'; ?>
      <?php $name_extension_prefix = 'newCoordinator'; $name_extension_entries = null; require __DIR__ . '/name-extension-fields.php'; ?>
      <div class="user-form-section-heading">Work and account details</div>
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="newCoordinatorEmail">Email Address <span style="color:red">*</span></label><input type="email" id="newCoordinatorEmail" name="email" class="form-control" placeholder="name@ustp.edu.ph" autocomplete="email" required></div>
        <div class="form-group">
          <label class="form-label" for="newCoordinatorId">Coordinator ID <span style="color:red">*</span></label>
          <input type="text" id="newCoordinatorId" name="coordinator_id_no" class="form-control" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,49}" placeholder="Enter coordinator ID" autocomplete="off" required>
          <div class="text-xs text-muted mt-1">Use the official ID. Letters, numbers and hyphens only.</div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="newCoordinatorPhone">Phone / Contact Number</label><input type="text" id="newCoordinatorPhone" name="contact_number" class="form-control" maxlength="30" placeholder="e.g. 0917-123-4567" autocomplete="tel"></div>
        <div class="form-group">
          <label class="form-label" for="newCoordinatorDept">Assigned Academic Program <span style="color:red">*</span></label>
          <select id="newCoordinatorDept" name="department" class="form-control" required>
            <option value="">-- Select Academic Program --</option>
            <?php foreach ($programs_list as $pr): ?>
              <option value="<?= e($pr['code']) ?>"><?= e($pr['code']) ?> - <?= e($pr['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-xs text-muted mt-1" style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <span>Retrieved from Program Management</span>
            <a href="/ojtrack/admin/programs.php" target="_blank" rel="noopener" style="color:var(--primary);font-weight:600">Manage Programs</a>
          </div>
        </div>
      </div>
      <div class="user-form-section-heading">Sign-in security</div>
      <div class="form-group"><label class="form-label">Initial Password</label><input type="password" name="password" class="form-control" value="" required></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addCoordModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Coordinator</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Coordinator Details Modal -->
<div class="modal-overlay" id="editCoordModal">
  <div class="modal modal-name-form">
    <div class="modal-title">Coordinator Details</div>
    <p class="modal-sub" id="editCoordSubName"></p>
    <div id="editCoordStats" class="coordinator-edit-stats" aria-label="Coordinator workload summary" hidden></div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_coord">
      <input type="hidden" name="coord_id" id="editCoordId">
      <div class="user-form-section-heading">Coordinator profile</div>
      <?php $person_name_prefix = 'editCoordinator'; $person_name_values = []; $person_name_required = false; require __DIR__ . '/person-name-fields.php'; ?>
      <div class="text-xs text-muted mb-3">For older records without saved name parts, leave all three fields blank to keep the current name.</div>
      <?php $name_extension_prefix = 'editCoordinator'; $name_extension_entries = []; require __DIR__ . '/name-extension-fields.php'; ?>
      <div class="user-form-section-heading">Work and account details</div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="editCoordEmail">Email Address <span style="color:red">*</span></label>
          <input type="email" name="email" id="editCoordEmail" class="form-control" maxlength="150" autocomplete="email" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="editCoordIdNo">Coordinator ID <span style="color:red">*</span></label>
          <input type="text" name="coordinator_id_no" id="editCoordIdNo" class="form-control" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,49}" autocomplete="off" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="editCoordPhone">Phone / Contact Number</label>
          <input type="text" name="contact_number" id="editCoordPhone" class="form-control" maxlength="30" placeholder="e.g. 0917-123-4567" autocomplete="tel">
        </div>
        <div class="form-group">
          <label class="form-label" for="editCoordDept">Assigned Academic Program <span style="color:red">*</span></label>
          <select name="department" id="editCoordDept" class="form-control" required>
            <option value="">-- Select Academic Program --</option>
            <?php foreach ($programs_list as $pr): ?>
              <option value="<?= e($pr['code']) ?>"><?= e($pr['code']) ?> - <?= e($pr['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-xs text-muted mt-1" style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
            <span>Retrieved from Program Management</span>
            <a href="/ojtrack/admin/programs.php" target="_blank" rel="noopener" style="color:var(--primary);font-weight:600">Manage Programs</a>
          </div>
        </div>
      </div>
      <div class="user-form-section-heading">Sign-in security</div>
      <div class="form-group">
        <label class="form-label" for="editCoordPassword">New Password</label>
        <input type="password" name="password" id="editCoordPassword" class="form-control" minlength="12" autocomplete="new-password" placeholder="Leave blank to keep current password">
        <div class="text-xs text-muted mt-1">Leave blank to keep the current password. A new password must be at least 12 characters.</div>
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
    'marine engineering technology': 'BSMET',
    'naval architecture and marine engineering': 'BSNAME',
    'energy systems management': 'BSESM',
    'electrical technology': 'BSET',
    'technology communication management': 'BSTCM',
    'tcm': 'BSTCM'
  };
  const mapped = aliases[raw.toLowerCase()];
  if (mapped) {
    for (let i = 0; i < PROGRAM_OPTIONS.length; i++) {
      if (String(PROGRAM_OPTIONS[i].code).toUpperCase() === mapped.toUpperCase()) return PROGRAM_OPTIONS[i].code;
    }
  }
  return raw;
}

function openEditCoord(c) {
  document.getElementById('editCoordId').value = c.id;
  document.getElementById('editCoordSubName').textContent = c.name + (c.email ? ' · ' + c.email : '');
  document.getElementById('editCoordinatorFirstName').value = c.first_name || '';
  document.getElementById('editCoordinatorMiddleName').value = c.middle_name || '';
  document.getElementById('editCoordinatorLastName').value = c.last_name || '';
  setNameExtensionFields('editCoordinator', c.name_extensions || []);
  document.getElementById('editCoordEmail').value = c.email || '';
  document.getElementById('editCoordIdNo').value = c.coordinator_id_no || '—';
  document.getElementById('editCoordPhone').value = c.contact_number || '';
  document.getElementById('editCoordDept').value = resolveProgramCode(c.department || '');
  document.getElementById('editCoordPassword').value = '';

  const stats = document.getElementById('editCoordStats');
  if (stats) {
    stats.hidden = false;
    stats.innerHTML =
      '<div class="coordinator-edit-stat coordinator-edit-stat-assigned">' +
        '<span class="coordinator-edit-stat-label">Assigned trainees</span>' +
        '<strong>' + (c.student_count ?? 0) + '</strong>' +
      '</div>' +
      '<div class="coordinator-edit-stat coordinator-edit-stat-active">' +
        '<span class="coordinator-edit-stat-label">Active in OJT</span>' +
        '<strong>' + (c.active_students ?? 0) + '</strong>' +
      '</div>';
  }
  openModal('editCoordModal');
}
</script>

