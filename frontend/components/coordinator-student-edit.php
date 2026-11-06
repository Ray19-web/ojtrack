<!-- Edit Student Modal -->
<div class="modal-overlay" id="editStudentModal">
  <div class="modal">
    <div class="modal-title">Edit Student OJT Assignment</div>
    <p class="modal-sub"><?= e($detail['name']) ?> (<?= e($detail['student_id_no'] ?? '') ?>)</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="update_student">
      <input type="hidden" name="student_id" value="<?= $detail['id'] ?>">
      <div class="form-group">
        <label class="form-label">Assigned Partner Company</label>
        <select name="company_id" class="form-control">
          <option value="">-- No Company Assigned --</option>
          <?php foreach ($companies_list as $co): ?>
            <option value="<?= $co['id'] ?>" <?= (int)$detail['company_id'] === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">OJT Status</label>
          <select name="ojt_status" id="editOjtStatus" class="form-control" onchange="toggleStatusNotes(this.value)">
            <?php foreach ($status_labels as $val => $label): ?>
              <option value="<?= $val ?>" <?= $detail['ojt_status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-xs text-muted mt-1">Use <strong>On Hold</strong> for temporary stop (illness, company issue). Use <strong>Withdrawn</strong> if the student stopped OJT.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Required Hours</label>
          <input type="number" name="required_hours" class="form-control" value="<?= e($detail['required_hours']) ?>" min="1" required>
        </div>
      </div>
      <div class="form-group" id="statusNotesGroup">
        <label class="form-label">Status Notes / Reason</label>
        <textarea name="status_notes" id="editStatusNotes" class="form-control" rows="2" placeholder="Reason for the status change"><?= e($detail['status_notes'] ?? '') ?></textarea>
        <div class="text-xs text-muted mt-1">Required for On Hold or Withdrawn.</div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">OJT Start Date</label>
          <input type="date" name="ojt_start_date" class="form-control" value="<?= e($detail['ojt_start_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">OJT End Date</label>
          <input type="date" name="ojt_end_date" class="form-control" value="<?= e($detail['ojt_end_date'] ?? '') ?>">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleStatusNotes(status) {
  const group = document.getElementById('statusNotesGroup');
  const notes = document.getElementById('editStatusNotes');
  const needs = (status === 'on_hold' || status === 'withdrawn');
  if (group) group.style.outline = needs ? '1px solid #fed7aa' : 'none';
  if (notes) notes.required = needs;
}
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('editOjtStatus');
  if (sel) toggleStatusNotes(sel.value);
});
</script>

