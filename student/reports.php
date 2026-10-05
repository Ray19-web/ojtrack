<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$uid     = (int)$user['id'];
$student = normalized_student_context_by_user($uid);
$sid     = (int)($student['id'] ?? 0);

$success = ''; $error = '';

// Monthly compilation submission from normalized DTR + journal evidence.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_monthly') {
    $month = $_POST['month'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $error = 'Please choose a valid month.';
    } elseif ($month >= date('Y-m')) {
        $error = 'You can only submit a monthly report after that month has fully ended.';
    } else {
        try {
            normalized_report_monthly_submit($sid, $uid, $month);
            $enrollment=normalized_enrollment_for_student($sid);
            $coord=$enrollment ? normalized_current_coordinator_for_enrollment((int)$enrollment['id']) : null;
            if ($coord) {
                create_notification(
                    (int)$coord['user_id'],
                    "{$user['name']} submitted a monthly OJT report (normalized journal + DTR snapshot).",
                    'info',
                    '/ojtrack/coordinator/reports.php'
                );
            }
            log_activity($uid, 'Monthly Report Submitted', "Month: $month");
            $success = 'Monthly report submitted. Its journal and DTR evidence snapshot is now frozen for review.';
        } catch (DomainException $exception) {
            $error=$exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    $rep_id = (int)(($_POST['rep_id'] ?? 0) ?: ($_POST['report_type'] ?? 0));
    $notes  = trim($_POST['notes'] ?? '');

    if (!$rep_id) {
        $error = 'Please select a report type to submit.';
    } else {
        $assignment=normalized_report_get($rep_id,$sid);
        if (!$assignment) request_error(404,'Submission assignment not found.');
        if ($assignment['status']==='approved') request_error(409,'Approved submissions cannot be replaced.');

        $file_path=null;
        $original_name=null;
        if (isset($_FILES['report_file']) && ($_FILES['report_file']['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK) {
            $original_name=$_FILES['report_file']['name'];
            $ext=strtolower(pathinfo($original_name,PATHINFO_EXTENSION));
            if (!in_array($ext,['pdf','doc','docx'],true)) {
                $error='Invalid format. Please submit PDF or Word document (.doc, .docx).';
            } else {
                $new_filename='report_'.$sid.'_'.$rep_id.'_'.bin2hex(random_bytes(16)).'.'.$ext;
                if (store_private_upload($_FILES['report_file']['tmp_name'],'reports',$new_filename)) {
                    $file_path='reports/'.$new_filename;
                } else {
                    $error='Failed to upload report file. Please try again.';
                }
            }
        }

        if (!$error) {
            try {
                normalized_report_submit(
                    $rep_id,$sid,$uid,$notes ?: 'Submitted for coordinator review',
                    $file_path,$original_name
                );
                $enrollment=normalized_enrollment_for_student($sid);
                $coord=$enrollment ? normalized_current_coordinator_for_enrollment((int)$enrollment['id']) : null;
                if ($coord) {
                    create_notification(
                        (int)$coord['user_id'],
                        "{$user['name']} submitted a narrative report for review.",
                        'info',
                        '/ojtrack/coordinator/reports.php'
                    );
                }
                log_activity($uid,'Report Submitted',"Assignment ID $rep_id");
                $success='Report submitted successfully. Awaiting coordinator review.';
            } catch (DomainException $exception) {
                $error=$exception->getMessage();
            }
        }
    }
}

$reports = normalized_report_rows_for_student($sid);

$page_title = 'OJT Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">OJT Narrative Reports</div>
    <div class="page-sub">Submit and monitor your milestone reports for academic clearance</div>
  </div>
  <button class="btn btn-primary" onclick="openNewReport()">+ Submit Report</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card mb-5">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Report Name</th>
          <th>Type</th>
          <th>Deadline</th>
          <th>Submitted On</th>
          <th>Status</th>
          <th>Remarks</th>
          <th>File</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reports as $r): ?>
          <tr>
            <td><strong class="text-800 text-sm"><?= e($r['report_name']) ?></strong></td>
            <td>
              <span class="badge badge-secondary"><?= ucfirst($r['report_type']) ?></span>
            </td>
            <td class="td-mono text-sm"><?= format_date($r['deadline']) ?></td>
            <td class="td-mono text-sm"><?= $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="text-xs text-muted" style="max-width:200px">
              <?= $r['remarks'] ? e($r['remarks']) : '—' ?>
            </td>
            <td>
              <?php if (!empty($r['file_path'])): ?>
                <a href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="View attached report">
                  View
                </a>
              <?php else: ?>
                <span class="text-xs text-muted">No file</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="table-actions">
                <?php if ($r['status'] === 'rejected' || !$r['submitted_at']): ?>
                  <button type="button" class="table-action-icon is-primary"
                          title="<?= $r['status'] === 'rejected' ? 'Resubmit report' : 'Upload report' ?>"
                          aria-label="<?= $r['status'] === 'rejected' ? 'Resubmit report' : 'Upload report' ?>"
                          onclick="openSubmit(<?= $r['id'] ?>, '<?= e(addslashes($r['report_name'])) ?>')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
                  </button>
                <?php elseif ($r['status'] === 'for_review'): ?>
                  <button type="button" class="table-action-icon is-primary" title="Update report" aria-label="Update report"
                          onclick="openSubmit(<?= $r['id'] ?>, '<?= e(addslashes($r['report_name'])) ?>')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.4-2.6L20 9M4 15l2.5 2.6A7 7 0 0 0 17.9 15"/></svg>
                  </button>
                <?php else: ?>
                  <span class="text-xs text-success font-bold">Approved</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($reports)): ?>
          <tr><td colspan="8" class="text-center text-muted py-6">No reports assigned.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Monthly Compilation -->
<?php
$compile_month = $_GET['cm'] ?? '';
$can_compile = $compile_month && preg_match('/^\d{4}-\d{2}$/', $compile_month) && $compile_month < date('Y-m');
$cm_att_days = $cm_att_hours = $cm_journal = 0;
if ($can_compile) {
    $cm_attendance = normalized_attendance_rows_for_student((int)$sid, $compile_month, 500);
    $cm_att_days = count(array_filter($cm_attendance, fn($row) => ($row['status'] ?? '') === 'present'));
    $cm_att_hours = array_sum(array_map(fn($row) => (float)($row['hours_rendered'] ?? 0), $cm_attendance));
    $cm_journals = normalized_journal_rows_for_student((int)$sid, 500);
    $cm_journal = count(array_filter($cm_journals, fn($row) => str_starts_with((string)($row['entry_date'] ?? ''), $compile_month . '-')));
}
?>
<div class="card card-body mb-4">
  <div class="section-title mb-3">Monthly OJT Report (Journal + DTR Compilation)</div>
  <p class="text-xs text-muted mb-3">Submit your monthly report once the month is complete. It compiles that month's DTR records and journal entries.</p>
  <form method="GET" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label">Month</label>
      <input type="month" name="cm" class="form-control" value="<?= e($compile_month) ?>" max="<?= date('Y-m', strtotime('first day of last month')) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Preview Compilation</button>
  </form>

  <?php if ($compile_month): ?>
    <?php if (!$can_compile): ?>
      <div class="alert alert-warn mt-3"><div class="alert-body"><p>You can only compile a month that has already ended.</p></div></div>
    <?php else: ?>
      <div style="margin-top:14px;display:flex;gap:24px;flex-wrap:wrap">
        <div><div class="font-mono font-bold" style="font-size:22px"><?= $cm_att_days ?></div><div class="text-xs text-muted">DTR present days</div></div>
        <div><div class="font-mono font-bold" style="font-size:22px"><?= $cm_journal ?></div><div class="text-xs text-muted">Journal entries</div></div>
        <div><div class="font-mono font-bold" style="font-size:22px"><?= number_format($cm_att_hours, 1) ?></div><div class="text-xs text-muted">DTR hours</div></div>
      </div>
      <form method="POST" style="margin-top:12px"><?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_monthly">
        <input type="hidden" name="month" value="<?= e($compile_month) ?>">
        <button type="submit" class="btn btn-primary">Compile &amp; Submit Report</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card card-body">
  <div class="section-title mb-3">Narrative Report Guidelines</div>
  <div class="space-y-2 text-sm text-600 leading-relaxed">
    <p>â€¢ All reports must be accompanied by the signed endorsement of your company supervisor and department chair.</p>
    <p>â€¢ The <strong>Acceptance Report</strong> confirms your company placement and planned training scope.</p>
    <p>â€¢ The <strong>Mid-Term Report</strong> summarizes hours completed, technologies utilized, and tasks undertaken up to 240 hours.</p>
    <p>â€¢ The <strong>Final Narrative Report</strong> is the comprehensive compilation of your 486-hour training portfolio, including learnings, reflections, and supervisor evaluation summary.</p>
  </div>
</div>

<!-- Submit Report Modal -->
<div class="modal-overlay" id="reportModal">
  <div class="modal modal-lg">
    <div class="modal-title">Submit OJT Report</div>
    <p class="modal-sub" id="reportModalSub">Attach your completed narrative report</p>

    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_report">
      <input type="hidden" name="rep_id" id="repId" value="">

      <div class="form-group" id="repSelectGroup">
        <label class="form-label">Report <span class="text-danger">*</span></label>
        <select name="report_type" id="repTypeSelect" class="form-control" onchange="document.getElementById('repId').value = this.value">
          <option value="">— Select report —</option>
          <?php foreach ($reports as $r): ?>
            <?php if ($r['status'] !== 'approved'): ?>
              <option value="<?= $r['id'] ?>"><?= e($r['report_name']) ?> (<?= ucfirst($r['status']) ?>)</option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Report Document (PDF preferred) <span class="text-danger">*</span></label>
        <div class="upload-area">
          <input type="file" name="report_file" accept=".pdf,.doc,.docx" style="display:none" required>
          <div class="upload-title">Click to browse or drop file here</div>
          <div class="upload-sub">PDF, DOC, DOCX Â· Max 20MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Student Notes (Optional)</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Provide any comments or context for your coordinator..."></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('reportModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Report</button>
      </div>
    </form>
  </div>
</div>

<script>
function openNewReport() {
  document.getElementById('repId').value = '';
  document.getElementById('repSelectGroup').style.display = 'block';
  document.getElementById('reportModalSub').textContent = 'Select report and upload document';
  openModal('reportModal');
}

function openSubmit(id, name) {
  document.getElementById('repId').value = id;
  const sel = document.getElementById('repTypeSelect');
  if (sel) sel.value = id;
  document.getElementById('reportModalSub').textContent = 'Submit: ' + name;
  openModal('reportModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
