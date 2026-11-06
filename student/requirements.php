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

// Handle versioned normalized requirement submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $req_id = (int)(($_POST['req_id'] ?? 0) ?: ($_POST['doc_type'] ?? 0));

    if (!$req_id) {
        $error = 'Please select a document type to submit.';
    } else {
        $assignment = normalized_requirement_get($req_id, $sid);
        if (!$assignment) request_error(404, 'Submission assignment not found.');
        if ($assignment['status'] === 'approved') request_error(409, 'Approved submissions cannot be replaced.');
        $uploadBatch = uploaded_file_batch('documents');
        if (!$uploadBatch && empty($assignment['attachments']) && empty($assignment['file_path'])) {
            request_error(422, 'Choose a document before submitting this assignment.');
        }

        $storedFiles = [];
        try {
            foreach ($uploadBatch as $upload) {
                $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
                $newFilename = 'req_' . $sid . '_' . $req_id . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
                if (!store_private_upload((string)$upload['tmp_name'], 'requirements', $newFilename)) {
                    throw new DomainException('Failed to save an uploaded file. Please try again.');
                }
                $storedFiles[] = ['storage_key'=>'requirements/' . $newFilename, 'original_filename'=>(string)$upload['name']];
            }
            normalized_requirement_submit($req_id, $sid, $uid, null, null, $storedFiles);
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                $storedPath = resolve_private_document($storedFile['storage_key']);
                if ($storedPath) @unlink($storedPath);
            }
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'Could not save the requirement submission. Please try again.';
        }
        if (!$error) {
            $enrollment = normalized_enrollment_for_student($sid);
            $coord = $enrollment ? normalized_current_coordinator_for_enrollment((int)$enrollment['id']) : null;
            if ($coord) {
                create_notification(
                    (int)$coord['user_id'],
                    "{$user['name']} submitted an OJT requirement for review.",
                    'info',
                    '/ojtrack/coordinator/requirements.php'
                );
            }
            log_activity($uid, 'Requirement Submitted', "Assignment ID $req_id");
            $success = 'Document submitted successfully. Awaiting coordinator review.';
        }
    }
}

$requirements = normalized_requirement_rows_for_student($sid);
$total    = count($requirements);
$batchLimitBytes = upload_batch_limit_bytes();
$batchUploadLimitMb = $batchLimitBytes > 0 ? (int)floor($batchLimitBytes / 1048576) : null;
$approved = count(array_filter($requirements, fn($r) => $r['status'] === 'approved'));
$rejected = count(array_filter($requirements, fn($r) => $r['status'] === 'rejected'));
$pending  = count(array_filter($requirements, fn($r) => $r['status'] === 'pending' && $r['submitted_at']));
$unsub    = count(array_filter($requirements, fn($r) => $r['status'] === 'pending' && !$r['submitted_at']));

$page_title = 'OJT Requirements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Requirements</div>
    <div class="page-sub">Upload documents and track approval</div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table id="reqTable">
      <thead>
        <tr>
          <th>Document Name</th>
          <th>Deadline</th>
          <th>Submitted On</th>
          <th>Status</th>
          <th>Coordinator Remarks</th>
          <th>File</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requirements as $r): ?>
          <tr <?php if ($r['status'] !== 'approved'): ?>class="requirement-submit-row" tabindex="0" data-requirement-id="<?= (int)$r['id'] ?>" data-requirement-name="<?= e($r['document_name']) ?>" aria-label="Submit <?= e($r['document_name']) ?>" aria-haspopup="dialog"<?php else: ?>tabindex="0" data-locked-feedback="This requirement is already approved. You can view its files, but it cannot be changed or resubmitted."<?php endif; ?>>
            <td>
              <strong class="text-800 text-sm"><?= e($r['document_name']) ?></strong>
            </td>
            <td class="td-mono text-sm"><?= format_date($r['deadline']) ?></td>
            <td class="td-mono text-sm"><?= $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td style="max-width:220px;font-size:12px;color:<?= $r['status']==='rejected' ? 'var(--danger)' : 'var(--text-600)' ?>">
              <?= $r['remarks'] ? e($r['remarks']) : '—' ?>
            </td>
            <td>
              <?php if (!empty($r['attachments'])): ?>
                <details class="requirement-attachments">
                  <summary><?= count($r['attachments']) ?> attachment<?= count($r['attachments']) === 1 ? '' : 's' ?></summary>
                <div class="submission-file-list">
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
                          title="<?= $r['status'] === 'rejected' ? 'Resubmit requirement' : 'Upload requirement' ?>"
                          aria-label="<?= $r['status'] === 'rejected' ? 'Resubmit requirement' : 'Upload requirement' ?>"
                          onclick="openReupload(<?= $r['id'] ?>, '<?= e(addslashes($r['document_name'])) ?>')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
                  </button>
                <?php elseif ($r['status'] === 'pending'): ?>
                  <button type="button" class="table-action-icon is-primary" title="Update requirement" aria-label="Update requirement"
                          onclick="openReupload(<?= $r['id'] ?>, '<?= e(addslashes($r['document_name'])) ?>')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.4-2.6L20 9M4 15l2.5 2.6A7 7 0 0 0 17.9 15"/></svg>
                  </button>
                <?php else: ?>
                  <span class="text-xs text-success font-bold">Verified</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($requirements)): ?>
          <tr><td colspan="7" class="text-center text-muted py-6">No requirements scheduled.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Upload / Submit Modal -->
<div class="modal-overlay" id="uploadModal">
  <div class="modal">
    <header class="requirement-upload-header"><div class="modal-title">Submit Requirement</div>
    <p class="modal-sub" id="uploadDocSub">Select document and attach file</p></header>

    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="req_id" id="uploadReqId" value="">
      <div class="requirement-upload-body">
      <div class="form-group" id="docTypeGroup">
        <label class="form-label">Document Type <span class="text-danger">*</span></label>
        <select name="doc_type" id="docTypeSelect" class="form-control" onchange="document.getElementById('uploadReqId').value = this.value">
          <option value="">— Select document —</option>
          <?php foreach ($requirements as $r): ?>
            <?php if ($r['status'] !== 'approved'): ?>
              <option value="<?= $r['id'] ?>"><?= e($r['document_name']) ?> (<?= ucfirst($r['status']) ?>)</option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Upload Files <span class="text-danger">*</span></label>
        <div class="upload-area">
          <input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="display:none" multiple required>
          <div class="upload-title">Click to browse or drag files here</div>
          <div class="upload-sub">PDF, PNG, JPG, DOC · Up to 20 files, 10 MB each<?= $batchUploadLimitMb ? ' · ' . $batchUploadLimitMb . ' MB total' : '' ?>. Resubmitting replaces the previous file set.</div>
        </div>
      </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary requirement-add-files" onclick="document.querySelector('#uploadModal input[type=file]').click()">+ Add files</button>
        <button type="button" class="btn btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Files</button>
      </div>
    </form>
  </div>
</div>

<script>
const requirementFileInput = document.querySelector('#uploadModal input[type="file"]');
let requirementSelectedFiles = [];
requirementFileInput.addEventListener('change', event => {
  if (event.isTrusted) {
    const combined = [...requirementSelectedFiles];
    Array.from(requirementFileInput.files).forEach(file => {
      if (!combined.some(existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified)) combined.push(file);
    });
    const transfer = new DataTransfer();
    combined.forEach(file => transfer.items.add(file));
    requirementFileInput.files = transfer.files;
  }
  requirementSelectedFiles = Array.from(requirementFileInput.files);
});
document.querySelectorAll('.requirement-submit-row').forEach(row => {
  const open = () => openReupload(Number(row.dataset.requirementId), row.dataset.requirementName);
  row.addEventListener('click', event => {
    if (event.target.closest('a, button, input, select, textarea, label, details')) return;
    if (window.getSelection()?.toString()) return;
    open();
  });
  row.addEventListener('keydown', event => {
    if (event.target !== row || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    open();
  });
});
function openNewUpload() {
  requirementSelectedFiles = [];
  window.clearAttachmentPreviewForInput(document.querySelector('#uploadModal input[type="file"]'), 'Click to browse or drag files here');
  document.getElementById('uploadReqId').value = '';
  document.getElementById('docTypeGroup').style.display = 'block';
  document.getElementById('uploadDocSub').textContent = 'Select document to submit';
  openModal('uploadModal');
}

function openReupload(id, name) {
  requirementSelectedFiles = [];
  window.clearAttachmentPreviewForInput(document.querySelector('#uploadModal input[type="file"]'), 'Click to browse or drag files here');
  document.getElementById('uploadReqId').value = id;
  const sel = document.getElementById('docTypeSelect');
  if (sel) sel.value = id;
  document.getElementById('uploadDocSub').textContent = 'Submit: ' + name;
  openModal('uploadModal');
}
</script>
<style>
.requirement-attachments { max-width:240px; }
.requirement-attachments summary { cursor:pointer; color:#174781; font-size:13px; font-weight:600; white-space:nowrap; }
.requirement-attachments .submission-file-list { margin-top:10px; padding:10px 12px; background:#f8faff; border:1px solid #e2e8f0; border-radius:9px; }
.requirement-attachments .submission-file-list a { display:block; padding:4px 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#uploadModal > .modal { display:flex; flex-direction:column; max-height:90dvh; padding:0; overflow:hidden; }
#uploadModal .requirement-upload-header { position:relative; flex:0 0 auto; padding:24px 28px 18px; border-bottom:1px solid #e2e8f0; background:#f8faff; }
#uploadModal .requirement-upload-header .modal-sub { margin-bottom:0; }
#uploadModal form { display:flex; flex-direction:column; flex:1 1 auto; min-height:0; overflow:hidden; padding:0; }
#uploadModal .requirement-upload-body { flex:1 1 auto; min-height:0; overflow-y:auto; padding:24px 28px; overscroll-behavior:contain; isolation:isolate; }
#uploadModal .modal-footer { position:relative; flex:0 0 auto; margin:0; padding:16px 28px; background:#fff; border-top:1px solid #e2e8f0; z-index:2; }
#uploadModal .requirement-add-files { margin-right:auto; }
#uploadModal .attachment-preview-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
@media(max-width:600px) { #uploadModal .requirement-upload-header,#uploadModal .requirement-upload-body { padding:18px; } #uploadModal .modal-footer { padding:14px 18px; } }
.requirement-submit-row { cursor:pointer; }
.requirement-submit-row:hover > td { background:#f1f5fb; }
.requirement-submit-row:focus-visible { outline:2px solid #174781; outline-offset:-2px; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
