<!-- Add Coordinator Modal -->
<div class="modal-overlay" id="addCoordModal">
  <div class="modal">
    <div class="modal-title">Add New OJT Coordinator</div>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_coord">
      <div class="form-group"><label class="form-label">Full Name <span style="color:red">*</span></label><input type="text" name="name" class="form-control" placeholder="Full name" required></div>
      <div class="form-group"><label class="form-label">Email Address <span style="color:red">*</span></label><input type="email" name="email" class="form-control" placeholder="name@ustp.edu.ph" required></div>
      <div class="form-group">
        <label class="form-label">Coordinator ID</label>
        <input type="text" class="form-control" value="<?= e($next_coord_id) ?>" readonly style="background:#f1f5f9;cursor:not-allowed;font-family:var(--font-mono);font-weight:700">
        <div class="text-xs text-muted mt-1">Auto-generated (format: C + year + sequence). Confirmed on save.</div>
      </div>
      <div class="form-group"><label class="form-label">Phone / Contact Number</label><input type="text" name="contact_number" class="form-control" placeholder="e.g. 0917-123-4567"></div>
      <div class="form-group">
        <label class="form-label">Assigned Academic Program <span style="color:red">*</span></label>
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
  <div class="modal">
    <div class="modal-title">Coordinator Details</div>
    <p class="modal-sub" id="editCoordSubName"></p>
    <div id="editCoordStats" style="background:var(--bg);padding:10px 12px;border-radius:var(--radius);font-size:12px;margin-bottom:14px;display:none"></div>
    <form method="POST"><?= csrf_field() ?>
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
        <label class="form-label">Assigned Academic Program <span style="color:red">*</span></label>
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

