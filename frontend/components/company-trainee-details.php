<?php $pct = (int)$selected['required_hours'] > 0 ? min(100, round(((float)$selected['rendered_hours'] / (int)$selected['required_hours']) * 100)) : 0; ?>
<div class="company-trainee-modal-record">
  <section class="card card-body company-trainee-modal-summary" aria-label="Trainee summary">
    <div class="company-trainee-modal-profile">
      <span class="company-trainee-avatar"><?= user_avatar_content($selected['avatar'] ?? null, $selected['name']) ?></span>
      <div class="company-trainee-copy">
        <strong><?= e($selected['name']) ?></strong>
        <span><?= e($selected['email'] ?? 'Email not provided') ?> · <?= e($selected['student_id_no'] ?? 'No student ID') ?></span>
        <span><?= e($selected['program'] ?? 'Program not set') ?> · <?= e($selected['year_level'] ?? 'Year level not set') ?></span>
      </div>
    </div>
    <div class="company-trainee-modal-actions">
      <a href="/ojtrack/company/evaluation.php?student=<?= (int)$selected['id'] ?>" class="btn btn-primary btn-sm">Evaluate trainee</a>
      <a href="/ojtrack/company/messages.php?student=<?= (int)$selected['user_id'] ?>" class="btn btn-secondary btn-sm">Message</a>
    </div>
    <div class="company-trainee-modal-hours">
      <div class="flex-between"><strong>OJT hours rendered</strong><span><?= number_format((float)$selected['rendered_hours'], 1) ?> / <?= (int)$selected['required_hours'] ?>h · <?= $pct ?>%</span></div>
      <div class="company-trainee-progress" role="progressbar" aria-label="OJT hours completed" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div>
    </div>
    <dl class="company-trainee-modal-facts">
      <div><dt>OJT start</dt><dd><?= !empty($selected['ojt_start_date']) ? format_date($selected['ojt_start_date']) : 'Not set' ?></dd></div>
      <div><dt>OJT end</dt><dd><?= !empty($selected['ojt_end_date']) ? format_date($selected['ojt_end_date']) : 'Not set' ?></dd></div>
      <div><dt>Journal logs</dt><dd><?= (int)$journal_cnt ?> entries</dd></div>
      <div><dt>Midterm / Final</dt><dd><?= $midterm_eval ? 'Midterm: ' . number_format((float)$midterm_eval['overall_score'], 1) : 'Midterm: Pending' ?> · <?= $final_eval ? 'Final: ' . number_format((float)$final_eval['overall_score'], 1) : 'Final: Pending' ?></dd></div>
    </dl>
  </section>

  <section class="card company-trainee-modal-section" aria-labelledby="companyTraineeAttendanceTitle">
    <div class="card-header flex-between">
      <h3 class="card-title" id="companyTraineeAttendanceTitle">Daily time record</h3>
      <a href="/ojtrack/company/attendance.php?student=<?= (int)$selected['id'] ?>" class="btn btn-secondary btn-sm">View full attendance</a>
    </div>
    <div class="table-wrap company-trainee-modal-table-wrap" tabindex="0" role="region" aria-label="Recent attendance for <?= e($selected['name']) ?>">
      <table data-skip-table-tools="true">
        <thead><tr><th>Date</th><th>AM In</th><th>AM Out</th><th>PM In</th><th>PM Out</th><th>Hours</th><th>Status</th><th>Remarks</th></tr></thead>
        <tbody>
          <?php foreach ($att_records as $attendance): ?>
          <tr>
            <td class="td-mono"><?= !empty($attendance['date']) ? date('M d, Y · D', strtotime($attendance['date'])) : '—' ?></td>
            <?php foreach (['morning_in', 'morning_out', 'afternoon_in', 'afternoon_out'] as $punch): ?>
            <td class="td-mono"><?= !empty($attendance[$punch]) ? format_time($attendance[$punch]) : '—' ?></td>
            <?php endforeach; ?>            <td class="font-mono font-bold"><?= !empty($attendance['hours_rendered']) ? number_format((float)$attendance['hours_rendered'], 2) . 'h' : '—' ?></td>
            <td><?= status_badge($attendance['status']) ?></td>
            <td class="text-xs text-muted"><?= !empty($attendance['remarks']) ? e($attendance['remarks']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($att_records)): ?><tr><td colspan="8" class="text-center text-muted py-6">No attendance records logged yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card company-trainee-modal-section" aria-labelledby="companyTraineeReportsTitle">
    <div class="card-header"><h3 class="card-title" id="companyTraineeReportsTitle">Submitted reports</h3></div>
    <div class="table-wrap company-trainee-modal-table-wrap" tabindex="0" role="region" aria-label="Submitted reports for <?= e($selected['name']) ?>">
      <table data-skip-table-tools="true">
        <thead><tr><th>Report title</th><th>Type</th><th>Submitted</th><th>Status</th><th>Document</th></tr></thead>
        <tbody>
          <?php foreach ($reports as $report): ?>
          <tr>
            <td class="font-bold text-sm"><?= e($report['report_name']) ?></td>
            <td><span class="badge" style="background:var(--bg);color:var(--text-700)"><?= e(ucfirst($report['report_type'])) ?></span></td>
            <td class="td-mono text-sm"><?= !empty($report['submitted_at']) ? date('M d, Y', strtotime($report['submitted_at'])) : '—' ?></td>
            <td><?= status_badge($report['status']) ?></td>
            <td>
              <?php if (!empty($report['file_path'])): ?>
                <a href="/ojtrack/download.php?file=<?= rawurlencode($report['file_path']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Open file</a>
              <?php else: ?><span class="text-xs text-muted">No file</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($reports)): ?><tr><td colspan="5" class="text-center text-muted py-6">No submitted reports yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
