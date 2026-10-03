<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$uid     = (int)$user['id'];
$student = query_one("SELECT * FROM students WHERE user_id=?", [$uid], 'i');
$sid     = (int)($student['id'] ?? 0);

$success = ''; $error = '';

// Handle file upload / submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $req_id = (int)(($_POST['req_id'] ?? 0) ?: ($_POST['doc_type'] ?? 0));

    if (!$req_id) {
        $error = 'Please select a document type to submit.';
    } else {
        $assignment = query_one("SELECT * FROM ojt_requirements WHERE id=? AND student_id=?", [$req_id, $sid], 'ii');
        if (!$assignment) request_error(404, 'Submission assignment not found.');
        if ($assignment['status'] === 'approved') request_error(409, 'Approved submissions cannot be replaced.');
        if (($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && empty($assignment['file_path'])) {
            request_error(422, 'Choose a document before submitting this assignment.');
        }
        $file_path = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $orig_name = $_FILES['document']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

            if (!in_array($ext, $allowed)) {
                $error = 'Invalid file format. Please upload PDF, JPG, PNG, or DOC files.';
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
            if ($file_path) {
                query(
                    "UPDATE ojt_requirements
                     SET status='pending', submitted_at=NOW(), file_path=?, remarks='Submitted — awaiting coordinator review'
                     WHERE id=? AND student_id=?",
                    [$file_path, $req_id, $sid],
                    'sii'
                );
            } else {
                query(
                    "UPDATE ojt_requirements
                     SET status='pending', submitted_at=NOW(), remarks='Resubmitted — awaiting coordinator review'
                     WHERE id=? AND student_id=?",
                    [$req_id, $sid],
                    'ii'
                );
            }

            // Notify coordinator if assigned
            if (!empty($student['coordinator_id'])) {
                $coord_user = query_one("SELECT user_id FROM coordinators WHERE id=?", [$student['coordinator_id']], 'i');
                if ($coord_user) {
                    create_notification($coord_user['user_id'], "{$user['name']} submitted an OJT requirement for review.", 'info', '/ojtrack/coordinator/requirements.php');
                }
            }

            log_activity($uid, 'Requirement Submitted', "Requirement ID $req_id");
            $success = 'Document submitted successfully. Awaiting coordinator review.';
        }
    }
}

$requirements = query("SELECT * FROM ojt_requirements WHERE student_id=? ORDER BY deadline ASC", [$sid], 'i') ?: [];
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
                <a href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="View uploaded file">
                  View
                </a>
              <?php else: ?>
                <span class="text-xs text-muted">No file</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['status'] === 'rejected' || !$r['submitted_at']): ?>
                <button class="btn btn-primary btn-sm" onclick="openReupload(<?= $r['id'] ?>, '<?= e(addslashes($r['document_name'])) ?>')">
                  <?= $r['status'] === 'rejected' ? 'Resubmit' : 'Upload' ?>
                </button>
              <?php elseif ($r['status'] === 'pending'): ?>
                <button class="btn btn-secondary btn-sm" onclick="openReupload(<?= $r['id'] ?>, '<?= e(addslashes($r['document_name'])) ?>')">
                  Update
                </button>
              <?php else: ?>
                <span class="text-xs text-success font-bold">Verified</span>
              <?php endif; ?>
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
