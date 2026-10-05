<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $req_id  = (int)($_POST['req_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    try {
        if ($action === 'add_templates') {
            $names = trim($_POST['names'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $names))));
            if (!$lines) {
                $error = 'Enter at least one requirement name (one per line).';
            } else {
                foreach ($lines as $name) normalized_requirement_add_template((int)$user['id'], $name, $desc);
                log_activity($user['id'], 'Requirements Added', count($lines) . ' normalized template(s)');
                $success = count($lines) . ' requirement(s) added to your requirement library.';
            }
        } elseif ($action === 'delete_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            if (!normalized_requirement_archive_template((int)$user['id'], $tid)) {
                $error = 'Requirement template not found.';
            } else {
                $success = 'Requirement archived from your active library.';
            }
        } elseif ($action === 'send_requirements') {
            $template_ids = $_POST['template_ids'] ?? [];
            $student_ids  = $_POST['student_ids'] ?? [];
            $deadline     = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
            if (!$template_ids || !$student_ids) {
                $error = 'Select at least one requirement and at least one student.';
            } else {
                $created = 0; $skipped = 0;
                foreach ($student_ids as $studentIdRaw) {
                    $studentId=(int)$studentIdRaw;
                    $student=normalized_student_context($studentId);
                    if (!$student) continue;
                    $enrollment=normalized_enrollment_for_student($studentId);
                    $assignedCoord=$enrollment ? normalized_current_coordinator_for_enrollment((int)$enrollment['id']) : null;
                    if (!$assignedCoord || (int)$assignedCoord['coordinator_id'] !== (int)$coord['id']) continue;

                    foreach ($template_ids as $templateIdRaw) {
                        $templateId=(int)$templateIdRaw;
                        $result=normalized_requirement_assign(
                            $templateId,$studentId,(int)$coord['id'],(int)$user['id'],$deadline
                        );
                        if ($result==='created') {
                            $tpl=query_one("SELECT title FROM requirement_definitions WHERE id=?",[$templateId],'i');
                            create_notification(
                                (int)$student['user_id'],
                                'New requirement: '.($tpl['title'] ?? 'OJT document').'. Please submit the document.',
                                'info',
                                '/ojtrack/student/requirements.php'
                            );
                            $created++;
                        } elseif ($result==='duplicate') {
                            $skipped++;
                        }
                    }
                }
                log_activity($user['id'], 'Requirements Sent', "$created normalized assignment(s)");
                $success = "$created requirement(s) assigned to the selected students.";
                if ($skipped>0) $success .= " $skipped duplicate(s) skipped because the student already has them.";
            }
        } elseif (in_array($action,['approve','reject'],true)) {
            if ($action==='reject' && $remarks==='') {
                $error='Explain what the student needs to revise.';
            } else {
                $row=normalized_requirement_review(
                    $req_id,
                    (int)$coord['id'],
                    (int)$user['id'],
                    $action==='approve' ? 'approved' : 'returned',
                    $remarks
                );
                if (!$row) {
                    $error='Requirement submission not found.';
                } else {
                    log_activity(
                        $user['id'],
                        $action==='approve' ? 'Requirement Approved' : 'Requirement Returned',
                        "Assignment ID: $req_id ({$row['document_name']}) for {$row['student_name']}"
                    );
                    create_notification(
                        (int)$row['user_id'],
                        $action==='approve'
                            ? "Your requirement document '{$row['document_name']}' has been approved."
                            : "Your requirement document '{$row['document_name']}' was returned with remarks: $remarks",
                        'info',
                        '/ojtrack/student/requirements.php'
                    );
                    $success=$action==='approve'
                        ? "Requirement '{$row['document_name']}' approved successfully."
                        : 'Requirement returned with remarks.';
                }
            }
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

$tab = $_GET['tab'] ?? 'pending';
$search = trim($_GET['q'] ?? '');
if (!in_array($tab,['pending','approved','rejected'],true)) $tab='pending';

$reqs = normalized_requirement_rows_for_coordinator((int)$coord['id'], $tab, $search);
$allRequirementRows = normalized_requirement_rows_for_coordinator((int)$coord['id'], '', '');
$counts = [
    'pending'  => count(array_filter($allRequirementRows, fn($r) => $r['status']==='pending')),
    'approved' => count(array_filter($allRequirementRows, fn($r) => $r['status']==='approved')),
    'rejected' => count(array_filter($allRequirementRows, fn($r) => $r['status']==='rejected')),
];

$page_title = 'Requirements Review';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Requirements Review</div>
    <div class="page-sub">Review and verify student OJT document submissions</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="btn btn-primary" onclick="openAddReqModal()">+ Add Requirement</button>
    <button class="btn btn-secondary" onclick="openModal('sendReqModal')">Send to Students</button>
    <form method="GET" style="display:flex;gap:8px">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="search-wrap" style="width:240px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search student or doc..." value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-secondary">Search</button>
      <?php if ($search): ?><a href="?tab=<?= $tab ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="tabs mb-5">
  <a href="?tab=pending" class="tab-btn <?= $tab==='pending'?'active':'' ?>">Pending Review <span class="tab-count"><?= $counts['pending'] ?></span></a>
  <a href="?tab=approved" class="tab-btn <?= $tab==='approved'?'active':'' ?>">Approved <span class="tab-count"><?= $counts['approved'] ?></span></a>
  <a href="?tab=rejected" class="tab-btn <?= $tab==='rejected'?'active':'' ?>">Returned <span class="tab-count"><?= $counts['rejected'] ?></span></a>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Program</th>
          <th>Document</th>
          <th>Attached File</th>
          <th>Date Submitted</th>
          <th>Status</th>
          <th>Remarks</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reqs as $r): ?>
        <tr>
          <td>
            <a href="/ojtrack/coordinator/students.php?id=<?= $r['student_id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
              <?= e($r['student_name']) ?>
            </a>
            <div class="text-xs text-muted"><?= e($r['student_id_no'] ?? '') ?></div>
          </td>
          <td class="text-sm text-muted"><?= e($r['program'] ?? 'BSIT') ?></td>
          <td><strong class="text-sm"><?= e($r['document_name']) ?></strong></td>
          <td>
            <?php if (!empty($r['file_path'])): ?>
              <div class="table-actions">
                <a href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>" target="_blank"
                   class="table-action-icon is-primary" title="View requirement document" aria-label="View requirement document">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                </a>
              </div>
            <?php else: ?>
              <span class="text-xs text-muted">No file</span>
            <?php endif; ?>
          </td>
          <td class="td-mono text-sm"><?= format_date($r['submitted_at']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-xs text-muted" style="max-width:200px"><?= $r['remarks'] ? e($r['remarks']) : '—' ?></td>
          <td>
            <div class="table-actions">
              <?php if ($tab === 'pending'): ?>
                <button type="button" class="table-action-icon is-success" title="Approve requirement" aria-label="Approve requirement"
                        onclick="openReview(<?= $r['id'] ?>,'approve','<?= e(addslashes($r['student_name'])) ?>','<?= e(addslashes($r['document_name'])) ?>','<?= e(addslashes($r['remarks'] ?? '')) ?>')">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                </button>
                <button type="button" class="table-action-icon is-danger" title="Return requirement" aria-label="Return requirement"
                        onclick="openReview(<?= $r['id'] ?>,'reject','<?= e(addslashes($r['student_name'])) ?>','<?= e(addslashes($r['document_name'])) ?>','<?= e(addslashes($r['remarks'] ?? '')) ?>')">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7 4 12l5 5"/><path d="M5 12h8a6 6 0 0 1 6 6"/></svg>
                </button>
              <?php else: ?>
                <button type="button" class="table-action-icon is-primary"
                        title="<?= $r['status']==='approved' ? 'Re-evaluate requirement' : 'Re-approve requirement' ?>"
                        aria-label="<?= $r['status']==='approved' ? 'Re-evaluate requirement' : 'Re-approve requirement' ?>"
                        onclick="openReview(<?= $r['id'] ?>,'<?= $r['status']==='approved'?'reject':'approve' ?>','<?= e(addslashes($r['student_name'])) ?>','<?= e(addslashes($r['document_name'])) ?>','<?= e(addslashes($r['remarks'] ?? '')) ?>')">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.4-2.6L20 9M4 15l2.5 2.6A7 7 0 0 0 17.9 15"/></svg>
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($reqs)): ?><tr><td colspan="8" class="text-center text-muted py-6">No <?= $tab ?> requirements found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Requirement Modal (requirement library) -->
<div class="modal-overlay" id="addReqModal">
  <div class="modal modal-lg">
    <div class="modal-title">Add Requirements</div>
    <p class="modal-sub">Add one or more requirements to your requirement library (one per line). You can send these to any students later.</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_templates">
      <div class="form-group">
        <label class="form-label">Requirement Names <span class="text-danger">*</span></label>
        <textarea name="names" class="form-control" rows="5" placeholder="Medical Certificate&#10;Barangay Clearance&#10;Parental Consent Form" required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Description / Notes</label>
        <textarea name="description" class="form-control" rows="2" placeholder="Optional instructions for students..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addReqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add to Library</button>
      </div>
    </form>

    <?php
    $templates = normalized_requirement_templates_for_coordinator((int)$user['id']);
    ?>
    <div style="margin-top:16px;border-top:1px solid var(--border-light);padding-top:14px">
      <div class="section-title mb-2" style="font-size:14px">Requirement Library (<?= count($templates) ?>)</div>
      <?php if (empty($templates)): ?>
        <p class="text-xs text-muted">No requirements added yet.</p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($templates as $t): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:var(--bg);border-radius:var(--radius)">
          <div>
            <strong class="text-sm"><?= e($t['name']) ?></strong>
            <?php if ($t['description']): ?><div class="text-xs text-muted"><?= e($t['description']) ?></div><?php endif; ?>
          </div>
          <form method="POST" onsubmit="return confirm('Remove this requirement from the library?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
            <button class="btn btn-danger btn-xs">Delete</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Send Requirements to Students Modal -->
<div class="modal-overlay" id="sendReqModal">
  <div class="modal modal-lg">
    <div class="modal-title">Send Requirements to Students</div>
    <p class="modal-sub">Select as many requirements and as many students as you want — the requirement will be assigned to each selected student. Students who already have a selected requirement are marked, and duplicates are skipped automatically.</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="send_requirements">
      <div class="form-group">
        <label class="form-label">Requirements <span class="text-danger">*</span></label>
        <div style="max-height:180px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius);padding:10px">
          <?php if (empty($templates)): ?>
            <p class="text-xs text-muted">No requirements in library yet. Add some first.</p>
          <?php else: ?>
            <?php foreach ($templates as $t): ?>
            <label style="display:flex;gap:8px;align-items:center;padding:4px 0">
              <input type="checkbox" class="tpl-check" name="template_ids[]" value="<?= $t['id'] ?>" data-name="<?= e($t['name']) ?>">
              <span class="text-sm"><?= e($t['name']) ?></span>
            </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Students <span class="text-danger">*</span></label>
        <div style="max-height:200px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius);padding:10px">
          <?php $students2 = normalized_students_for_coordinator((int)$coord['id']); ?>
          <?php
          $existing_docs = [];
          $existing_rows = normalized_requirement_rows_for_coordinator((int)$coord['id'], '', '');
          foreach ($existing_rows as $erow) {
              $existing_docs[$erow['student_id']][] = $erow['document_name'];
          }
          ?>
          <?php foreach ($students2 as $st): ?>
          <label class="student-row" style="display:flex;gap:8px;align-items:center;padding:4px 0">
            <input type="checkbox" class="student-check" name="student_ids[]" value="<?= $st['id'] ?>" data-docs='<?= json_encode($existing_docs[$st['id']] ?? []) ?>'>
            <span class="text-sm"><?= e($st['name']) ?> <span class="text-xs text-muted">(<?= e($st['student_id_no']) ?>)</span>
            <span class="student-dup-note text-xs text-muted" style="display:none;color:#b42318"></span></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Deadline (optional)</label>
        <input type="date" name="deadline" class="form-control">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('sendReqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send to Selected Students</button>
      </div>
    </form>
  </div>
</div>

<!-- View Requirement Modal -->
<div class="modal-overlay" id="viewReqModal">
  <div class="modal modal-lg">
    <div class="modal-title">Requirement Details</div>
    <p class="modal-sub" id="viewReqStudent"></p>
    <div class="card card-body mb-4">
      <div class="info-list">
        <div class="info-row"><span class="info-key">Document</span><span class="info-val font-bold" id="viewReqDoc"></span></div>
        <div class="info-row"><span class="info-key">Status</span><span class="info-val" id="viewReqStatus"></span></div>
        <div class="info-row"><span class="info-key">Date Submitted</span><span class="info-val" id="viewReqDate"></span></div>
        <div class="info-row"><span class="info-key">Remarks</span><span class="info-val" id="viewReqRemarks"></span></div>
      </div>
    </div>
    <div class="card card-body" id="viewReqFileCard">
      <div class="section-title mb-3">Attached File</div>
      <div id="viewReqFile"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('viewReqModal')">Close</button>
    </div>
  </div>
</div>

<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
  <div class="modal">
    <div class="modal-title" id="revModalTitle">Review Requirement</div>
    <p class="modal-sub" id="revModalSub"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="req_id" id="revReqId">
      <input type="hidden" name="action" id="revAction">
      <div class="form-group">
        <label class="form-label">Remarks / Feedback for Student</label>
        <textarea name="remarks" id="revRemarks" class="form-control" rows="4" placeholder="Provide feedback or guidance for the student..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('reviewModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="revSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
// Disable students who already have all the selected requirements
function refreshStudentAvailability() {
    var selected = [];
    document.querySelectorAll('.tpl-check:checked').forEach(function (cb) {
        selected.push(cb.dataset.name);
    });

    document.querySelectorAll('.student-check').forEach(function (cb) {
        var existing = [];
        try { existing = JSON.parse(cb.dataset.docs || '[]'); } catch (e) {}

        var matched = selected.filter(function (n) { return existing.indexOf(n) !== -1; });
        var row = cb.closest('.student-row');
        var note = row ? row.querySelector('.student-dup-note') : null;

        if (selected.length > 0 && matched.length === selected.length) {
            cb.checked = false;
            cb.disabled = true;
            if (note) { note.style.display = 'inline'; note.textContent = ' — already has all selected'; }
            if (row) row.style.opacity = '0.5';
        } else {
            cb.disabled = false;
            if (row) row.style.opacity = '1';
            if (note) {
                if (matched.length > 0) {
                    note.style.display = 'inline';
                    note.textContent = ' — already has: ' + matched.join(', ');
                } else {
                    note.style.display = 'none';
                    note.textContent = '';
                }
            }
        }
    });
}

document.querySelectorAll('.tpl-check').forEach(function (cb) {
    cb.addEventListener('change', refreshStudentAvailability);
});

function openAddReqModal() {
  const names = document.querySelector('#addReqModal textarea[name="names"]');
  const desc  = document.querySelector('#addReqModal textarea[name="description"]');
  if (names) names.value = '';
  if (desc) desc.value = '';
  openModal('addReqModal');
}

function viewReq(r, statusBadge) {
  document.getElementById('viewReqStudent').textContent = r.student_name + ' (' + (r.student_id_no || '') + ')';
  document.getElementById('viewReqDoc').textContent = r.document_name;
  document.getElementById('viewReqStatus').innerHTML = statusBadge;
  document.getElementById('viewReqDate').textContent = r.submitted_at ? new Date(r.submitted_at).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : '—';
  document.getElementById('viewReqRemarks').textContent = r.remarks || '—';
  const fileDiv = document.getElementById('viewReqFile');
  if (r.file_path) {
    fileDiv.innerHTML = '<a href="/ojtrack/download.php?file=' + encodeURIComponent(r.file_path) + '" target="_blank" class="btn btn-primary">View Document</a>';
  } else {
    fileDiv.innerHTML = '<span class="text-muted">No file attached</span>';
  }
  openModal('viewReqModal');
}

function openReview(id, action, student, doc, remarks) {
  document.getElementById('revReqId').value = id;
  document.getElementById('revAction').value = action;
  document.getElementById('revModalSub').textContent = student + ' — ' + doc;
  document.getElementById('revRemarks').value = remarks || '';
  const btn = document.getElementById('revSubmitBtn');
  if (action === 'approve') {
    document.getElementById('revModalTitle').textContent = 'Approve Requirement';
    btn.className = 'btn btn-success';
    btn.textContent = 'Approve Document';
  } else {
    document.getElementById('revModalTitle').textContent = 'Return Requirement';
    btn.className = 'btn btn-danger';
    btn.textContent = 'Return with Remarks';
  }
  openModal('reviewModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
