<?php
$detail_required_hours = (int)($detail['required_hours'] ?? 0);
$detail_rendered_hours = (float)($detail['rendered_hours'] ?? 0);
$detail_pct = $detail_required_hours > 0 ? max(0, min(100, round($detail_rendered_hours / $detail_required_hours * 100))) : 0;
$academic_details = [
    'Program' => $detail['program'] ?? '—',
    'Year Level' => $detail['year_level'] ?: 'Not provided',
    'Email' => $detail['email'] ?: 'Not provided',
    'Contact Number' => $detail['contact_number'] ?: 'Not provided',
];
if (!empty($detail['department']) && strcasecmp(trim($detail['department']), trim($detail['program'] ?? '')) !== 0) {
    $academic_details = ['Program' => $academic_details['Program'], 'Department' => $detail['department']] + $academic_details;
}
$placement_details = [
    'Company' => $detail['company_name'] ?: 'Not assigned',
    'Supervisor' => $detail['supervisor'] ?: 'Not provided',
    'Start Date' => format_date($detail['ojt_start_date']),
    'End Date' => format_date($detail['ojt_end_date']),
];
?>
<div class="student-record-toolbar">
  <a href="/ojtrack/coordinator/students.php" class="btn btn-ghost">← Back to Students</a>
  <div class="student-record-actions">
    <button type="button" class="btn btn-secondary" onclick="openModal('editStudentModal')">Edit Assignment</button>
    <a href="/ojtrack/coordinator/monitoring.php?student=<?= (int)$detail['id'] ?>" class="btn btn-primary">OJT Monitoring</a>
  </div>
</div>

<section class="card card-body student-record-summary" aria-label="Student summary">
  <div class="student-record-identity">
    <div class="student-record-avatar" aria-hidden="true"><?= user_avatar_content($detail['avatar'] ?? null, $detail['name']) ?></div>
    <div class="student-record-name">
      <h2><?= e($detail['name']) ?></h2>
      <div class="student-record-meta"><span class="td-mono"><?= e($detail['student_id_no'] ?: '—') ?></span><?= status_badge($detail['ojt_status']) ?></div>
    </div>
  </div>
  <div class="student-record-progress">
    <div class="student-record-hours"><span>OJT Hours</span><strong><?= number_format($detail_rendered_hours, 1) ?> / <?= number_format($detail_required_hours) ?>h</strong></div>
    <progress class="student-record-progress-bar" value="<?= $detail_pct ?>" max="100" aria-label="OJT progress"><?= $detail_pct ?>%</progress>
    <div class="text-xs text-muted student-record-percent"><?= $detail_pct ?>% complete</div>
  </div>
</section>

<?php if (!empty($detail['status_notes']) && in_array($detail['ojt_status'], ['on_hold', 'withdrawn'], true)): ?>
  <div class="alert alert-warn mb-4"><div class="alert-body"><p><strong>Status notes:</strong> <?= e($detail['status_notes']) ?></p></div></div>
<?php endif; ?>

<div class="student-record-grid">
  <section class="card card-body" aria-label="Academic and contact details">
    <h3 class="section-title mb-3">Academic &amp; Contact Details</h3>
    <dl class="student-record-fields">
      <?php foreach ($academic_details as $label => $value): ?>
      <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>
  <section class="card card-body" aria-label="OJT placement">
    <h3 class="section-title mb-3">OJT Placement</h3>
    <dl class="student-record-fields">
      <?php foreach ($placement_details as $label => $value): ?>
      <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <div class="student-record-links">
      <a href="/ojtrack/coordinator/attendance.php?q=<?= rawurlencode($detail['name']) ?>" class="btn btn-secondary">Attendance</a>
      <a href="/ojtrack/coordinator/requirements.php?q=<?= rawurlencode($detail['name']) ?>" class="btn btn-secondary">Requirements</a>
    </div>
  </section>
</div>
