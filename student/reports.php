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
if (isset($_GET['monthly_pdf'])) {
    $month = (string)($_GET['cm'] ?? '');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m', $month);
    if (!$parsed || $parsed->format('Y-m') !== $month || $month >= date('Y-m')) request_error(422, 'Choose a month that has already ended.');
    $attendance = normalized_attendance_rows_for_student($sid, $month, 500);
    $journals = array_values(array_filter(normalized_journal_rows_for_student($sid, 1000), fn($row) => str_starts_with($row['entry_date'], $month.'-')));
    require_once __DIR__ . '/../includes/monthly-report-pdf.php';
    $pdf = monthly_report_pdf($student ?? [], $month, $attendance, $journals);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Monthly_OJT_Report_'.$month.'.pdf"');
    header('Cache-Control: private, no-store');
    header('Content-Length: '.strlen($pdf));
    echo $pdf; exit;
}

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
        } catch (Throwable $exception) {
            error_log('OJTrack monthly report failed: '.$exception->getMessage());
            $error='The monthly report could not be submitted. Please try again.';
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

        $uploadBatch=uploaded_file_batch('report_files');
        $storedFiles=[];
        try {
            foreach ($uploadBatch as $upload) {
                $ext=strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));
                $newFilename='report_'.$sid.'_'.$rep_id.'_'.bin2hex(random_bytes(16)).'.'.$ext;
                if (!store_private_upload((string)$upload['tmp_name'],'reports',$newFilename)) {
                    throw new DomainException('Failed to save an uploaded report file. Please try again.');
                }
                $storedFiles[]=['storage_key'=>'reports/'.$newFilename,'original_filename'=>(string)$upload['name']];
            }
            normalized_report_submit(
                $rep_id,$sid,$uid,$notes ?: 'Submitted for coordinator review',
                null,null,null,$storedFiles
            );
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                $storedPath=resolve_private_document($storedFile['storage_key']);
                if ($storedPath) @unlink($storedPath);
            }
            $error=$exception instanceof DomainException ? $exception->getMessage() : 'Could not save the report submission. Please try again.';
        }
        if (!$error) {
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
        }
    }
}

$reports = normalized_report_rows_for_student($sid);
$submittableReports = array_values(array_filter($reports, fn($report) => $report['status'] !== 'approved'));
$reportUploadLimitMb = (int)(upload_policy('report_file')[1] / 1048576);
$batchLimitBytes = upload_batch_limit_bytes();
$batchUploadLimitMb = $batchLimitBytes > 0 ? (int)floor($batchLimitBytes / 1048576) : null;
$requiredHours = max(0, (int)($student['required_hours'] ?? 0));

$page_title = 'OJT Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Reports</div>
    <div class="page-sub">Submit reports and track review</div>
  </div>
  <button type="button" class="btn btn-primary report-monthly-action" onclick="toggleMonthlyPanel(this)" aria-expanded="false" aria-controls="monthlyCompilationModal">Monthly compilation ▾</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card mb-5">
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
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
          <tr <?php if ($r['status'] === 'approved'): ?>tabindex="0" data-locked-feedback="This report is already approved. You can view its files, but it cannot be changed or resubmitted."<?php else: ?>class="report-submit-row" tabindex="0" data-report-id="<?= (int)$r['id'] ?>" data-report-name="<?= e($r['report_name']) ?>" aria-haspopup="dialog"<?php endif; ?>>
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
              <?php if (!empty($r['attachments'])): ?>
                <details class="report-attachments"><summary><?= count($r['attachments']) ?> attachment<?= count($r['attachments']) === 1 ? '' : 's' ?></summary><div class="submission-file-list">
                  <?php foreach ($r['attachments'] as $attachment): ?>
                    <a href="/ojtrack/download.php?file=<?= rawurlencode($attachment['file_path']) ?>" target="_blank" rel="noopener" title="View <?= e($attachment['attachment_name']) ?>"><?= e($attachment['attachment_name']) ?></a>
                  <?php endforeach; ?>
                </div></details>
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
          <tr><td colspan="8" class="text-center text-muted py-6">No narrative reports assigned yet.<br><span class="text-xs">Your coordinator must assign a report before you can upload it. Monthly reports use the compilation section below.</span></td></tr>
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
<div id="monthlyCompilationModal" class="monthly-filter-panel" hidden>
<div class="modal" style="width:min(620px,calc(100vw - 32px))">
  <div class="section-title mb-3">Monthly compilation</div>
  <p class="text-sm text-muted compilation-intro">Choose a completed month to review, download, or submit your attendance and journal report.</p>
  <form class="compilation-month-form" method="GET">
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="compilationMonth">Month</label>
      <input type="month" id="compilationMonth" name="cm" class="form-control" value="<?= e($compile_month) ?>" max="<?= date('Y-m', strtotime('first day of last month')) ?>" required>
    </div>
    <a href="/ojtrack/student/reports.php" class="btn btn-secondary btn-sm">Reset</a>
    <button type="submit" class="btn btn-primary btn-sm">Apply month</button>
  </form>

  <?php if ($compile_month): ?>
    <?php if (!$can_compile): ?>
      <div class="alert alert-warn mt-3"><div class="alert-body"><p>You can only compile a month that has already ended.</p></div></div>
    <?php else: ?>
      <section class="compilation-summary" aria-label="Selected month summary">
      <div class="compilation-summary-title"><?= e(date('F Y', strtotime($compile_month.'-01'))) ?> summary</div>
      <div class="compilation-stats">
        <div><div class="font-mono font-bold" style="font-size:22px"><?= $cm_att_days ?></div><div class="text-xs text-muted">DTR present days</div></div>
        <div><div class="font-mono font-bold" style="font-size:22px"><?= $cm_journal ?></div><div class="text-xs text-muted">Journal entries</div></div>
        <div><div class="font-mono font-bold" style="font-size:22px"><?= number_format($cm_att_hours, 1) ?></div><div class="text-xs text-muted">DTR hours</div></div>
      </div>
      </section>
      <div class="compilation-actions">
      <a class="btn btn-secondary" href="?<?= e(http_build_query(['cm'=>$compile_month,'monthly_pdf'=>1])) ?>">Download PDF</a>
      <form method="POST"><?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_monthly">
        <input type="hidden" name="month" value="<?= e($compile_month) ?>">
        <button type="submit" class="btn btn-primary">Compile &amp; Submit</button>
      </form>
      </div>
      <p class="compilation-note">Submitting sends the generated PDF to your coordinator for review.</p>
    <?php endif; ?>
  <?php endif; ?>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="toggleMonthlyPanel(document.querySelector('.report-monthly-action'), false)">Close</button></div>
</div>
</div>

<div class="card card-body">
  <details class="report-guidelines"><summary class="section-title">Narrative report guidelines</summary><div style="padding-top:16px">
  <ul class="space-y-2 text-sm text-600 leading-relaxed" style="margin:0;padding-left:20px;line-height:1.7">
    <li>Follow your coordinator's instructions for report content, required endorsements, and deadlines.</li>
    <li>The <strong>Acceptance Report</strong> describes your company placement and planned training scope, when assigned.</li>
    <li>The <strong>Mid-Term Report</strong> summarizes completed hours, work activities, and learning at the milestone specified by your coordinator.</li>
    <li>The <strong>Final Narrative Report</strong> summarizes your training activities, learning, and reflections. Your assigned training requirement is <strong><?= $requiredHours > 0 ? number_format($requiredHours) . ' hours' : 'set by your coordinator' ?></strong>. Include supporting documents requested by your coordinator.</li>
  </ul></div></details>
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
        <select name="report_type" id="repTypeSelect" class="form-control" onchange="document.getElementById('repId').value = this.value; updateReportInstructions()" required>
          <option value="">— Select report —</option>
          <?php foreach ($submittableReports as $r): ?>
              <option value="<?= $r['id'] ?>" data-instructions="<?= e($r['instructions'] ?? '') ?>"><?= e($r['report_name']) ?> (<?= ucfirst($r['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <section class="report-submission-instructions" id="reportSubmissionInstructions" hidden>
        <strong>Coordinator instructions</strong>
        <p id="reportSubmissionInstructionsText"></p>
      </section>

      <div class="form-group">
        <label class="form-label">Report Document (PDF preferred) <span class="text-danger">*</span></label>
        <div class="upload-area">
          <input type="file" name="report_files[]" accept=".pdf,.doc,.docx" style="display:none" multiple required>
          <div class="upload-title">Click to browse or drop files here</div>
          <div class="upload-sub">PDF, DOC, DOCX &middot; Up to 20 files, <?= $reportUploadLimitMb ?> MB each<?= $batchUploadLimitMb ? ' &middot; ' . $batchUploadLimitMb . ' MB total' : '' ?>. Resubmitting replaces the previous file set.</div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Student Notes (Optional)</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Notes for your coordinator (optional)"></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('reportModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Report</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleMonthlyPanel(button, force) {
  const panel = document.getElementById('monthlyCompilationModal');
  const open = force === undefined ? panel.hidden : force;
  panel.hidden = !open; button.setAttribute('aria-expanded', String(open));
  if (open) {
    const rect = button.getBoundingClientRect();
    panel.style.top = Math.min(rect.bottom + 8, window.innerHeight - 180) + 'px';
    panel.style.right = Math.max(12, window.innerWidth - rect.right) + 'px';
  }
}
<?php if ($compile_month): ?>
window.addEventListener('load', () => toggleMonthlyPanel(document.querySelector('.report-monthly-action'), true));
<?php endif; ?>
document.querySelectorAll('.report-submit-row').forEach(row => {
  const open = () => openSubmit(Number(row.dataset.reportId), row.dataset.reportName);
  row.addEventListener('click', event => {
    if (event.target.closest('a,button,input,select,textarea,details') || window.getSelection()?.toString()) return;
    open();
  });
  row.addEventListener('keydown', event => {
    if (event.target !== row || !['Enter',' '].includes(event.key)) return;
    event.preventDefault(); open();
  });
});
function openNewReport() {
  window.clearAttachmentPreviewForInput(document.querySelector('#reportModal input[type="file"]'), 'Click to browse or drop files here');
  document.getElementById('repId').value = '';
  const select = document.getElementById('repTypeSelect');
  if (select) select.value = '';
  updateReportInstructions();
  document.getElementById('repSelectGroup').style.display = 'block';
  document.getElementById('reportModalSub').textContent = 'Select report and upload document';
  openModal('reportModal');
}

function openSubmit(id, name) {
  window.clearAttachmentPreviewForInput(document.querySelector('#reportModal input[type="file"]'), 'Click to browse or drop files here');
  document.getElementById('repId').value = id;
  const sel = document.getElementById('repTypeSelect');
  if (sel) sel.value = id;
  updateReportInstructions();
  document.getElementById('reportModalSub').textContent = 'Submit: ' + name;
  openModal('reportModal');
}

function updateReportInstructions() {
  const select = document.getElementById('repTypeSelect');
  const block = document.getElementById('reportSubmissionInstructions');
  const text = document.getElementById('reportSubmissionInstructionsText');
  if (!select || !block || !text) return;
  const instructions = select.selectedOptions[0]?.dataset.instructions || '';
  text.textContent = instructions;
  block.hidden = !instructions.trim();
}
</script>

<style>
.compilation-intro { line-height:1.6; margin:8px 0 20px; }
.compilation-month-form { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:10px; align-items:end; padding-bottom:20px; border-bottom:1px solid #e5ebf3; }
.compilation-month-form input { width:100%; min-width:0; }
.compilation-summary { margin:20px 0; padding:16px; border:1px solid #e3eaf3; border-radius:10px; background:#f8faff; }
.compilation-summary-title { font-size:13px; font-weight:600; margin-bottom:16px; }
.compilation-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
.compilation-stats > div + div { border-left:1px solid #dfe7f1; padding-left:12px; }
.compilation-actions { display:flex; gap:12px; align-items:stretch; }
.compilation-actions > * { flex:1; min-width:0; margin:0; }
.compilation-actions .btn { width:100%; justify-content:center; }
.compilation-note { font-size:12px; color:#71829a; line-height:1.5; margin:12px 0 0; }
#monthlyCompilationModal .modal-footer { margin-top:20px; padding-top:16px; border-top:1px solid #e5ebf3; }
@media(max-width:520px) { .compilation-month-form { grid-template-columns:1fr 1fr; } .compilation-month-form .form-group { grid-column:1 / -1; } .compilation-actions { flex-direction:column; } }
.monthly-filter-panel { position:fixed; z-index:250; width:min(480px,calc(100vw - 24px)); max-height:75vh; overflow:auto; background:#fff; border:1px solid #dce5f0; border-radius:14px; box-shadow:0 14px 40px #172b4930; }
.monthly-filter-panel[hidden] { display:none; }
.monthly-filter-panel > .modal { width:100%!important; padding:22px; margin:0; max-height:none; box-shadow:none; border:0; }
.report-monthly-action { order:100; margin-left:8px; flex-shrink:0; }
.report-submit-row { cursor:pointer; }
.report-submit-row:hover > td { background:#f2f6fc; }
.report-submit-row:focus-visible { outline:2px solid #174781; outline-offset:-2px; }
.report-attachments { position:relative; max-width:220px; }
.report-attachments summary { color:#174781; font-size:13px; cursor:pointer; white-space:nowrap; font-weight:600; }
.report-attachments .submission-file-list { margin-top:10px; padding:10px; border:1px solid #e2e8f0; border-radius:8px; background:#f8faff; }
.report-attachments a { display:block; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; padding:4px 0; }
.report-guidelines summary { cursor:pointer; font-size:16px; }
.monthly-report-panel { display:grid; grid-template-columns:minmax(0,1fr) auto; column-gap:24px; align-items:center; }
.monthly-report-panel > .section-title { margin:0 0 6px; }
.monthly-report-panel > p { grid-column:1; margin:0; max-width:600px; line-height:1.6; }
.monthly-report-panel > form:first-of-type { grid-column:2; grid-row:1 / 3; }
.monthly-report-panel > .alert { grid-column:1 / -1; }
@media(max-width:800px) { .monthly-report-panel { display:block; } .monthly-report-panel > form:first-of-type { margin-top:18px; } }
</style><?php require_once __DIR__ . '/../includes/footer.php'; ?>
