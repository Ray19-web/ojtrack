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
        if (($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && empty($assignment['file_path'])) {
            request_error(422, 'Choose a document before submitting this assignment.');
        }

        $file_path = null;
        $original_name = null;
        if (isset($_FILES['document']) && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $original_name = $_FILES['document']['name'];
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

            if (!in_array($ext, $allowed, true)) {
                $error = 'Invalid file format. Please upload PDF, JPG, PNG, DOC, or DOCX.';
            } else {
                $new_filename = 'req_' . $sid . '_' . $req_id . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
                if (store_private_upload($_FILES['document']['tmp_name'], 'requirements', $new_filename)) {
                    $file_path = 'requirements/' . $new_filename;
                } else {
                    $error = 'Failed to save the uploaded file. Please try again.';
                }
            }
        }

        if (!$error) {
            try {
                normalized_requirement_submit($req_id, $sid, $uid, $file_path, $original_name);
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
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

$requirements = normalized_requirement_rows_for_student($sid);
$total    = count($requirements);
$approved = count(array_filter($requirements, fn($r) => $r['status'] === 'approved'));
$rejected = count(array_filter($requirements, fn($r) => $r['status'] === 'rejected'));
$pending  = count(array_filter($requirements, fn($r) => $r['status'] === 'pending' && $r['submitted_at']));
$unsub    = count(array_filter($requirements, fn($r) => $r['status'] === 'pending' && !$r['submitted_at']));

$page_title = 'OJT Requirements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">OJT Requirements Checklist</div>
    <div class="page-sub">Submit and monitor the verification status of your mandatory OJT credentials</div>
  </div>
  <button class="btn btn-primary" onclick="openNewUpload()">+ Submit Document</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header">
    <div class="card-title">Required Documents</div>
    <div class="search-wrap" style="width:260px">
      <input class="form-control search-input" id="reqSearch" placeholder="Search document..." oninput="filterTable('reqSearch','reqTable')">
    </div>
  </div>
  <div class="table-wrap">
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
          <tr>
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
              <?php if (!empty($r['file_path'])): ?>
                <div class="table-actions">
                  <a href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>" target="_blank"
                     class="table-action-icon is-primary" title="View uploaded requirement" aria-label="View uploaded requirement">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                  </a>
                </div>
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
    <div class="modal-title">Submit Requirement</div>
    <p class="modal-sub" id="uploadDocSub">Select document and attach file</p>

    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="req_id" id="uploadReqId" value="">

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
        <label class="form-label">Upload File <span class="text-danger">*</span></label>
        <div class="upload-area">
          <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="display:none" required>
          <div class="upload-title">Click to browse or drag file here</div>
          <div class="upload-sub">PDF, PNG, JPG, DOC · Max 10MB</div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit File</button>
      </div>
    </form>
  </div>
</div>

<script>
function openNewUpload() {
  document.getElementById('uploadReqId').value = '';
  document.getElementById('docTypeGroup').style.display = 'block';
  document.getElementById('uploadDocSub').textContent = 'Select document to submit';
  openModal('uploadModal');
}

function openReupload(id, name) {
  document.getElementById('uploadReqId').value = id;
  const sel = document.getElementById('docTypeSelect');
  if (sel) sel.value = id;
  document.getElementById('uploadDocSub').textContent = 'Submit: ' + name;
  openModal('uploadModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
