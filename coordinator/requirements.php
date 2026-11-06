<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';
$editDraft = null;

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
        } elseif ($action === 'edit_template') {
            $editDraft = [
                'id'=>(int)($_POST['template_id'] ?? 0),
                'version_id'=>(int)($_POST['version_id'] ?? 0),
                'name'=>trim($_POST['name'] ?? ''),
                'description'=>trim($_POST['description'] ?? ''),
            ];
            normalized_requirement_edit_template(
                (int)$user['id'],$editDraft['id'],$editDraft['version_id'],
                $editDraft['name'],$editDraft['description']
            );
            log_activity($user['id'],'Requirement Updated','Definition ID: '.$editDraft['id']);
            $success='Requirement updated. New instructions apply to future assignments; existing submissions are preserved.';
            $editDraft=null;
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
        } elseif ($action === 'bulk_review') {
            $bulkIds = $_POST['req_ids'] ?? [];
            $bulkDecision = $_POST['bulk_decision'] ?? '';
            if (!is_array($bulkIds) || !in_array($bulkDecision, ['approve','reject'], true)) {
                $error = 'Choose requirement rows and a valid action.';
            } elseif ($bulkDecision === 'reject' && $remarks === '') {
                $error = 'Add a remark before returning the selected requirements.';
            } else {
                $bulkIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $bulkIds), fn($id) => $id > 0))), 0, 500);
                if (!$bulkIds) {
                    $error = 'Select at least one requirement.';
                } else {
                    $reviewed = 0;
                    $skipped = 0;
                    foreach ($bulkIds as $bulkId) {
                        try {
                            $row = normalized_requirement_review(
                                $bulkId,
                                (int)$coord['id'],
                                (int)$user['id'],
                                $bulkDecision === 'approve' ? 'approved' : 'returned',
                                $remarks
                            );
                        } catch (DomainException $exception) {
                            $row = null;
                        }
                        if (!$row) { $skipped++; continue; }
                        log_activity(
                            $user['id'],
                            $bulkDecision === 'approve' ? 'Requirement Approved' : 'Requirement Returned',
                            "Assignment ID: $bulkId ({$row['document_name']}) for {$row['student_name']} (bulk review)"
                        );
                        create_notification(
                            (int)$row['user_id'],
                            $bulkDecision === 'approve'
                                ? "Your requirement document '{$row['document_name']}' has been approved."
                                : "Your requirement document '{$row['document_name']}' was returned with remarks: $remarks",
                            'info',
                            '/ojtrack/student/requirements.php'
                        );
                        $reviewed++;
                    }
                    if ($reviewed) {
                        $success = $bulkDecision === 'approve'
                            ? "$reviewed requirement(s) approved."
                            : "$reviewed requirement(s) returned with feedback.";
                    }
                    if ($skipped) $success .= ($success ? ' ' : '') . "$skipped item(s) were skipped because they were no longer available for review.";
                    if (!$reviewed && !$skipped) $error = 'No requirements were updated.';
                }
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
    <div class="page-sub">Review student documents</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="btn btn-primary" onclick="openAddReqModal()">+ Add Requirement</button>
    <button class="btn btn-secondary" onclick="openModal('sendReqModal')">Send to Students</button>
    <form class="filter-toolbar" method="GET" data-live-filter style="display:flex;gap:8px">
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
  <form id="bulkRequirementForm" method="POST" class="requirement-bulk-form"><?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_review">
    <input type="hidden" name="bulk_decision" id="bulkRequirementDecision" value="">
    <input type="hidden" name="remarks" id="bulkRequirementRemarks" value="">
  </form>
  <div class="requirement-bulk-toolbar" id="requirementBulkToolbar" hidden>
    <div class="requirement-bulk-selection"><strong id="requirementSelectedCount">0</strong><span>selected</span></div>
    <div class="requirement-bulk-actions">
      <?php if ($tab === 'pending'): ?>
        <button type="submit" form="bulkRequirementForm" class="btn btn-success btn-sm" data-bulk-decision="approve">Approve selected</button>
        <button type="button" class="btn btn-danger btn-sm" data-bulk-return>Return selected</button>
      <?php elseif ($tab === 'approved'): ?>
        <button type="button" class="btn btn-secondary btn-sm" data-bulk-return data-bulk-return-label="Re-evaluate selected">Re-evaluate selected</button>
      <?php else: ?>
        <button type="submit" form="bulkRequirementForm" class="btn btn-success btn-sm" data-bulk-decision="approve">Re-approve selected</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead>
        <tr>
          <th class="requirement-select-column"><input type="checkbox" id="selectAllRequirements" aria-label="Select all requirements on this page"></th>
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
        <?php $requirement_row_payload = [
            'id' => (int)$r['id'],
            'student_id' => (int)$r['student_id'],
            'student_name' => (string)$r['student_name'],
            'student_id_no' => (string)($r['student_id_no'] ?? ''),
            'program' => (string)($r['program'] ?? 'BSIT'),
            'document_name' => (string)$r['document_name'],
            'file_path' => (string)($r['file_path'] ?? ''),
            'attachments' => array_map(static fn($file) => ['file_path'=>(string)$file['file_path'],'attachment_name'=>(string)$file['attachment_name']], $r['attachments'] ?? []),
            'submitted_at' => format_date($r['submitted_at'] ?? null),
            'status' => (string)$r['status'],
            'remarks' => (string)($r['remarks'] ?? ''),
        ]; $requirement_row_json = json_encode($requirement_row_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>
        <tr class="row-clickable coordinator-requirement-row" tabindex="0" aria-haspopup="dialog" aria-label="View <?= e($r['document_name']) ?> for <?= e($r['student_name']) ?>" data-requirement-details="<?= e($requirement_row_json ?: '{}') ?>">
          <td class="requirement-select-column"><input type="checkbox" class="requirement-row-checkbox" name="req_ids[]" value="<?= (int)$r['id'] ?>" form="bulkRequirementForm" aria-label="Select <?= e($r['document_name']) ?> for <?= e($r['student_name']) ?>"></td>
          <td>
            <a href="/ojtrack/coordinator/students.php?id=<?= $r['student_id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
              <?= e($r['student_name']) ?>
            </a>
            <div class="text-xs text-muted"><?= e($r['student_id_no'] ?? '') ?></div>
          </td>
          <td class="text-sm text-muted"><?= e($r['program'] ?? 'BSIT') ?></td>
          <td><strong class="text-sm"><?= e($r['document_name']) ?></strong></td>
          <td>
            <?php if (!empty($r['attachments'])): ?>
              <div class="submission-file-list">
                <?php foreach ($r['attachments'] as $attachment): ?>
                  <a href="/ojtrack/download.php?file=<?= rawurlencode($attachment['file_path']) ?>" target="_blank" rel="noopener" title="View <?= e($attachment['attachment_name']) ?>"><?= e($attachment['attachment_name']) ?></a>
                <?php endforeach; ?>
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
              <button type="button" class="table-action-icon is-primary coordinator-action-toggle"
                      aria-label="Actions for <?= e($r['document_name']) ?> by <?= e($r['student_name']) ?>"
                      title="More actions" aria-haspopup="menu" aria-expanded="false" aria-controls="coordinatorRequirementActionMenu"
                      data-review-item="<?= e($requirement_row_json ?: '{}') ?>">
                <span class="coordinator-actions-dots" aria-hidden="true">&bull;&bull;&bull;</span>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($reqs)): ?><tr><td colspan="9" class="text-center text-muted py-6">No <?= $tab ?> requirements found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="coordinator-action-menu" id="coordinatorRequirementActionMenu" role="menu" aria-label="Requirement actions" hidden></div>

<div class="modal-overlay" id="bulkRequirementReturnModal">
  <div class="modal requirement-bulk-return-modal">
    <div class="modal-title" id="bulkRequirementReturnTitle">Return selected requirements</div>
    <p class="modal-sub">Add feedback for the selected student submissions.</p>
    <div class="form-group">
      <label for="bulkRequirementReturnNote" class="form-label">Feedback <span class="text-danger">*</span></label>
      <textarea id="bulkRequirementReturnNote" class="form-control" rows="4" required placeholder="Explain what needs to be updated..."></textarea>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('bulkRequirementReturnModal')">Cancel</button>
      <button type="button" class="btn btn-danger" id="bulkRequirementReturnSubmit">Return selected</button>
    </div>
  </div>
</div>

<!-- Add Requirement Modal (requirement library) -->
<div class="modal-overlay" id="addReqModal">
  <div class="modal modal-lg requirement-add-modal">
    <div class="modal-title">Add Requirements</div>
    <p class="modal-sub">Enter one requirement per line. Assign them to students later.</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_templates">
      <div class="form-group">
        <label class="form-label">Requirement Names <span class="text-danger">*</span></label>
        <textarea name="names" class="form-control" rows="5" placeholder="Medical Certificate&#10;Barangay Clearance&#10;Parental Consent Form" required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Description / Notes</label>
        <textarea name="description" class="form-control" rows="2" placeholder="Instructions (optional)"></textarea>
      </div>
      <div class="modal-footer requirement-add-actions">
        <button type="button" class="btn btn-secondary requirement-library-open" onclick="openModal('requirementLibraryModal')">Requirement Library</button>
        <div class="requirement-add-actions-right">
          <button type="button" class="btn btn-secondary" onclick="closeModal('addReqModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Add to Library</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php $templates = normalized_requirement_templates_for_coordinator((int)$user['id']); ?>
<!-- Requirement Library Modal -->
<div class="modal-overlay" id="requirementLibraryModal">
  <div class="modal requirement-library-modal">
    <div class="requirement-library-modal-header">
      <div>
        <div class="modal-title">Requirement Library</div>
        <p class="modal-sub">Manage the requirements available to assign to students.</p>
      </div>
      <button type="button" class="requirement-detail-close" onclick="closeModal('requirementLibraryModal')" aria-label="Close requirement library">&times;</button>
    </div>
    <section class="requirement-library-panel" aria-labelledby="requirementLibraryTitle">
      <div class="requirement-library-heading">
        <h2 class="section-title" id="requirementLibraryTitle">Saved requirements</h2>
        <span class="requirement-library-count"><?= count($templates) ?> <?= count($templates) === 1 ? 'saved item' : 'saved items' ?></span>
      </div>
      <?php if (empty($templates)): ?>
        <p class="requirement-library-empty">Your library is empty. Close this window and add requirements to get started.</p>
      <?php else: ?>
      <div class="requirement-library-list">
        <?php foreach ($templates as $t): ?>
        <div class="requirement-library-item">
          <div class="requirement-library-copy">
            <strong class="text-sm"><?= e($t['name']) ?></strong>
            <?php if ($t['description']): ?><div class="text-xs text-muted"><?= e($t['description']) ?></div><?php endif; ?>
          </div>
          <div class="requirement-library-actions">
          <button type="button" class="btn btn-secondary btn-xs" data-requirement="<?= e(json_encode($t)) ?>" onclick="openRequirementEditor(this)" aria-label="Edit <?= e($t['name']) ?>">Edit</button>
          <form method="POST" data-confirm-message="Remove this requirement from the library?"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="template_id" value="<?= $t['id'] ?>">
            <button class="btn btn-danger btn-xs">Delete</button>
          </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <div class="modal-footer requirement-library-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('requirementLibraryModal')">Close</button>
    </div>
  </div>
</div>

<!-- Send Requirements to Students Modal -->
<div class="modal-overlay" id="sendReqModal">
  <div class="modal modal-lg send-requirements-modal">
    <div class="modal-title">Send Requirements to Students</div>
    <p class="modal-sub">Each selected student receives the selected requirements. Existing assignments are skipped.</p>
    <form method="POST" class="send-requirements-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="send_requirements">
      <div class="form-group">
        <div class="send-requirements-group-heading">
          <div class="form-label" id="sendRequirementsLabel">Requirements <span class="text-danger">*</span></div>
          <span class="send-requirements-hint">Select the items students need to submit.</span>
        </div>
        <details class="send-multiselect" data-multi-select data-singular="requirement" data-plural="requirements" data-placeholder="Choose requirements">
          <summary class="send-multiselect-trigger">
            <span class="send-multiselect-summary">Choose requirements</span>
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
          </summary>
          <div class="send-multiselect-panel">
            <div class="send-multiselect-tools">
              <input type="search" class="form-control send-multiselect-search" placeholder="Search requirements..." aria-label="Search requirements">
              <label class="send-multiselect-select-all"><input type="checkbox" class="send-select-all"> <span>Select all matching</span></label>
            </div>
            <div class="send-selection-list send-requirements-list" role="group" aria-labelledby="sendRequirementsLabel">
          <?php if (empty($templates)): ?>
            <p class="send-selection-empty">No requirements in your library yet. Add requirements before sending them to students.</p>
          <?php else: ?>
            <?php foreach ($templates as $t): ?>
            <div class="send-requirement-option" data-search="<?= e($t['name']) ?>">
            <label class="send-option-label">
              <input type="checkbox" class="send-option-checkbox tpl-check" name="template_ids[]" value="<?= $t['id'] ?>" data-name="<?= e($t['name']) ?>">
              <span class="send-option-copy"><?= e($t['name']) ?></span>
            </label>
            <button type="button" class="btn btn-secondary btn-xs send-requirement-edit" data-requirement="<?= e(json_encode($t)) ?>" onclick="openRequirementEditor(this)" aria-label="Edit <?= e($t['name']) ?>">Edit</button>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
            </div>
            <p class="send-multiselect-no-results" hidden>No requirements match your search.</p>
          </div>
        </details>
      </div>
      <div class="form-group">
        <div class="send-requirements-group-heading">
          <div class="form-label" id="sendStudentsLabel">Students <span class="text-danger">*</span></div>
          <span class="send-requirements-hint">Select the students who will receive these requirements.</span>
        </div>
        <details class="send-multiselect" data-multi-select data-singular="student" data-plural="students" data-placeholder="Choose students">
          <summary class="send-multiselect-trigger">
            <span class="send-multiselect-summary">Choose students</span>
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
          </summary>
          <div class="send-multiselect-panel">
            <div class="send-multiselect-tools">
              <input type="search" class="form-control send-multiselect-search" placeholder="Search students..." aria-label="Search students">
              <label class="send-multiselect-select-all"><input type="checkbox" class="send-select-all"> <span>Select all matching</span></label>
            </div>
            <div class="send-selection-list send-students-list" role="group" aria-labelledby="sendStudentsLabel">
          <?php $students2 = normalized_students_for_coordinator((int)$coord['id']); ?>
          <?php
          $existing_docs = [];
          $existing_rows = normalized_requirement_rows_for_coordinator((int)$coord['id'], '', '');
          foreach ($existing_rows as $erow) {
              $existing_docs[$erow['student_id']][] = $erow['document_name'];
          }
          ?>
          <?php if (empty($students2)): ?><p class="send-selection-empty">No students are assigned to you yet.</p><?php endif; ?>
          <?php foreach ($students2 as $st): ?>
          <label class="student-row send-student-option" data-search="<?= e($st['name'] . ' ' . $st['student_id_no']) ?>">
            <input type="checkbox" class="send-option-checkbox student-check" name="student_ids[]" value="<?= $st['id'] ?>" data-docs='<?= e(json_encode($existing_docs[$st['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>'>
            <span class="send-option-copy">
              <span><?= e($st['name']) ?></span>
              <small><?= e($st['student_id_no']) ?></small>
              <span class="student-dup-note"></span>
            </span>
          </label>
          <?php endforeach; ?>
            </div>
            <p class="send-multiselect-no-results" hidden>No students match your search.</p>
          </div>
        </details>
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

<!-- Edit library requirement without changing earlier submission instructions. -->
<div class="modal-overlay" id="editReqModal">
  <div class="modal">
    <div class="modal-title">Edit Requirement</div>
    <p class="modal-sub">Names update wherever this requirement appears. Updated instructions apply when you send new assignments; existing assignments keep their original instructions.</p>
    <?php if ($editDraft && $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_template">
      <input type="hidden" name="template_id" id="editReqId">
      <input type="hidden" name="version_id" id="editReqVersion">
      <div class="form-group">
        <label class="form-label" for="editReqName">Requirement Name <span class="text-danger">*</span></label>
        <input name="name" id="editReqName" class="form-control" maxlength="200" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="editReqDescription">Description / Instructions</label>
        <textarea name="description" id="editReqDescription" class="form-control" rows="4"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeRequirementEditor()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- View Requirement Modal -->
<div class="modal-overlay" id="viewReqModal">
  <div class="modal modal-lg requirement-detail-modal">
    <div class="requirement-detail-header">
      <div>
        <span class="requirement-detail-eyebrow">Submitted requirement</span>
        <div class="modal-title" id="viewReqTitle">Requirement Details</div>
        <p class="modal-sub" id="viewReqStudent"></p>
      </div>
      <button type="button" class="requirement-detail-close" onclick="closeModal('viewReqModal')" aria-label="Close requirement details">&times;</button>
    </div>
    <div class="card card-body mb-4 requirement-detail-info">
      <div class="info-list">
        <div class="info-row"><span class="info-key">Document</span><span class="info-val font-bold" id="viewReqDoc"></span></div>
        <div class="info-row"><span class="info-key">Program</span><span class="info-val" id="viewReqProgram"></span></div>
        <div class="info-row"><span class="info-key">Status</span><span class="info-val" id="viewReqStatus"></span></div>
        <div class="info-row"><span class="info-key">Date Submitted</span><span class="info-val" id="viewReqDate"></span></div>
        <div class="info-row"><span class="info-key">Remarks</span><span class="info-val" id="viewReqRemarks"></span></div>
      </div>
    </div>
    <div class="card card-body requirement-detail-file" id="viewReqFileCard">
      <div class="section-title mb-3">Attached file</div>
      <div id="viewReqFile"></div>
    </div>
    <div class="modal-footer requirement-detail-actions">
      <button type="button" class="btn btn-success" id="viewReqApprove" onclick="reviewRequirementFromDetails('approve')">Approve</button>
      <button type="button" class="btn btn-danger" id="viewReqReturn" onclick="reviewRequirementFromDetails('reject')">Return</button>
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
        <textarea name="remarks" id="revRemarks" class="form-control" rows="4" placeholder="Feedback for the student"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('reviewModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="revSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
const coordinatorRequirementMenu = document.getElementById('coordinatorRequirementActionMenu');
let coordinatorRequirementMenuTrigger = null;

function closeCoordinatorRequirementMenu(returnFocus = false) {
    if (!coordinatorRequirementMenu) return;
    coordinatorRequirementMenu.hidden = true;
    coordinatorRequirementMenu.replaceChildren();
    if (coordinatorRequirementMenuTrigger) {
        coordinatorRequirementMenuTrigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) coordinatorRequirementMenuTrigger.focus();
    }
    coordinatorRequirementMenuTrigger = null;
}

function openCoordinatorRequirementMenu(trigger) {
    if (!coordinatorRequirementMenu || !trigger) return;
    if (coordinatorRequirementMenuTrigger === trigger && !coordinatorRequirementMenu.hidden) {
        closeCoordinatorRequirementMenu();
        return;
    }
    closeCoordinatorRequirementMenu();

    let item;
    try { item = JSON.parse(trigger.dataset.reviewItem || '{}'); } catch (_) { return; }
    if (!item.id) return;

    const actions = item.status === 'pending'
        ? [
            { action: 'approve', label: 'Approve requirement', tone: 'success', icon: '✓' },
            { action: 'reject', label: 'Return with remarks', tone: 'danger', icon: '↩' },
          ]
        : item.status === 'approved'
            ? [{ action: 'reject', label: 'Re-evaluate requirement', tone: 'primary', icon: '↻' }]
            : [{ action: 'approve', label: 'Re-approve requirement', tone: 'success', icon: '✓' }];

    actions.forEach(function (action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'coordinator-action-menu-item is-' + action.tone;
        button.setAttribute('role', 'menuitem');
        button.dataset.reviewAction = action.action;
        const icon = document.createElement('span');
        icon.className = 'coordinator-action-menu-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = action.icon;
        const label = document.createElement('span');
        label.textContent = action.label;
        button.append(icon, label);
        coordinatorRequirementMenu.appendChild(button);
    });

    document.body.appendChild(coordinatorRequirementMenu);
    coordinatorRequirementMenu.dataset.currentReview = JSON.stringify(item);
    coordinatorRequirementMenu.hidden = false;
    coordinatorRequirementMenuTrigger = trigger;
    trigger.setAttribute('aria-expanded', 'true');

    const rect = trigger.getBoundingClientRect();
    const menuRect = coordinatorRequirementMenu.getBoundingClientRect();
    const gap = 6;
    const edge = 8;
    let left = rect.right - menuRect.width;
    let top = rect.bottom + gap;
    if (top + menuRect.height > window.innerHeight - edge) top = rect.top - menuRect.height - gap;
    left = Math.max(edge, Math.min(left, window.innerWidth - menuRect.width - edge));
    top = Math.max(edge, Math.min(top, window.innerHeight - menuRect.height - edge));
    coordinatorRequirementMenu.style.left = left + 'px';
    coordinatorRequirementMenu.style.top = top + 'px';
    coordinatorRequirementMenu.querySelector('[role="menuitem"]')?.focus();
}

document.addEventListener('click', function (event) {
    const trigger = event.target.closest('.coordinator-action-toggle');
    if (trigger) {
        event.preventDefault();
        openCoordinatorRequirementMenu(trigger);
        return;
    }
    const actionButton = event.target.closest('#coordinatorRequirementActionMenu [data-review-action]');
    if (actionButton) {
        let item;
        try { item = JSON.parse(coordinatorRequirementMenu.dataset.currentReview || '{}'); } catch (_) { item = null; }
        const action = actionButton.dataset.reviewAction;
        closeCoordinatorRequirementMenu();
        if (item && action === 'approve') {
            selectedRequirementDetails = item;
            reviewRequirementFromDetails('approve');
        } else if (item) {
            openReview(item.id, action, item.student_name, item.document_name, item.remarks);
        }
        return;
    }
    if (coordinatorRequirementMenu && !coordinatorRequirementMenu.hidden && !coordinatorRequirementMenu.contains(event.target)) {
        closeCoordinatorRequirementMenu();
    }
});

document.addEventListener('keydown', function (event) {
    if (!coordinatorRequirementMenu || coordinatorRequirementMenu.hidden) return;
    const items = [...coordinatorRequirementMenu.querySelectorAll('[role="menuitem"]')];
    const index = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.preventDefault();
        closeCoordinatorRequirementMenu(true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        items[(index + direction + items.length) % items.length]?.focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        items[0]?.focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        items[items.length - 1]?.focus();
    }
});
document.addEventListener('scroll', function () { closeCoordinatorRequirementMenu(); }, true);
window.addEventListener('resize', function () { closeCoordinatorRequirementMenu(); });

let selectedRequirementDetails = null;
function viewReq(requirement) {
  if (!requirement || !requirement.id) return;
  selectedRequirementDetails = requirement;
  const studentLabel = (requirement.student_name || 'Student') + (requirement.student_id_no ? ' (' + requirement.student_id_no + ')' : '');
  document.getElementById('viewReqStudent').textContent = studentLabel;
  document.getElementById('viewReqDoc').textContent = requirement.document_name || '—';
  document.getElementById('viewReqProgram').textContent = requirement.program || '—';
  document.getElementById('viewReqDate').textContent = requirement.submitted_at || '—';
  document.getElementById('viewReqRemarks').textContent = requirement.remarks || '—';

  const statusLabels = {pending:'Pending', approved:'Approved', rejected:'Returned', returned:'Returned'};
  const statusClasses = {pending:'badge-pending', approved:'badge-approved', rejected:'badge-rejected', returned:'badge-rejected'};
  const status = String(requirement.status || 'pending').toLowerCase();
  const badge = document.createElement('span');
  badge.className = 'badge ' + (statusClasses[status] || 'badge-pending');
  badge.textContent = statusLabels[status] || status.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
  const statusContainer = document.getElementById('viewReqStatus');
  statusContainer.replaceChildren(badge);

  const fileContainer = document.getElementById('viewReqFile');
  fileContainer.replaceChildren();
  const attachments = Array.isArray(requirement.attachments) && requirement.attachments.length
    ? requirement.attachments
    : (requirement.file_path ? [{file_path: requirement.file_path, attachment_name: 'View attached file'}] : []);
  if (attachments.length) {
    attachments.forEach((attachment, index) => {
      const fileLink = document.createElement('a');
      fileLink.href = '/ojtrack/download.php?file=' + encodeURIComponent(attachment.file_path);
      fileLink.target = '_blank';
      fileLink.rel = 'noopener';
      fileLink.className = 'btn btn-secondary btn-sm';
      fileLink.textContent = attachment.attachment_name || `View attached file ${index + 1}`;
      fileContainer.appendChild(fileLink);
    });
  } else {
    const noFile = document.createElement('span');
    noFile.className = 'text-muted';
    noFile.textContent = 'No file attached';
    fileContainer.appendChild(noFile);
  }

  const approveButton = document.getElementById('viewReqApprove');
  const returnButton = document.getElementById('viewReqReturn');
  approveButton.hidden = status === 'approved';
  returnButton.hidden = status === 'rejected' || status === 'returned';
  approveButton.textContent = status === 'rejected' || status === 'returned' ? 'Re-approve' : 'Approve';
  returnButton.textContent = status === 'approved' ? 'Re-evaluate' : 'Return';
  openModal('viewReqModal');
}

function reviewRequirementFromDetails(action) {
  const requirement = selectedRequirementDetails;
  if (!requirement) return;
  closeModal('viewReqModal');
  if (action === 'approve') {
    document.getElementById('revReqId').value = requirement.id;
    document.getElementById('revAction').value = 'approve';
    document.getElementById('revRemarks').value = '';
    const reviewForm = document.querySelector('#reviewModal form');
    const approveButton = document.getElementById('revSubmitBtn');
    if (reviewForm && approveButton) reviewForm.requestSubmit(approveButton);
    return;
  }
  openReview(requirement.id, action, requirement.student_name, requirement.document_name, requirement.remarks || '');
}

const requirementChecks = [...document.querySelectorAll('.requirement-row-checkbox')];
const requirementSelectAll = document.getElementById('selectAllRequirements');
const requirementBulkToolbar = document.getElementById('requirementBulkToolbar');
const requirementRowIsVisible = input => {
  const row = input.closest('tr');
  return row && !row.hidden && row.style.display !== 'none' && !row.classList.contains('table-quick-search-hidden') && !row.classList.contains('table-page-hidden');
};
function updateRequirementSelection() {
  const selected = requirementChecks.filter(input => input.checked).length;
  const visible = requirementChecks.filter(requirementRowIsVisible);
  const visibleSelected = visible.filter(input => input.checked).length;
  requirementBulkToolbar.hidden = selected === 0;
  document.getElementById('requirementSelectedCount').textContent = selected;
  if (requirementSelectAll) {
    requirementSelectAll.checked = visible.length > 0 && visibleSelected === visible.length;
    requirementSelectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
  }
}
requirementChecks.forEach(input => input.addEventListener('change', updateRequirementSelection));
const requirementRowsObserver = new MutationObserver(updateRequirementSelection);
document.querySelectorAll('.coordinator-requirement-row').forEach(row => requirementRowsObserver.observe(row, {attributes: true, attributeFilter: ['hidden', 'style', 'class']}));
requirementSelectAll?.addEventListener('change', () => {
  requirementChecks.forEach(input => {
    if (requirementRowIsVisible(input)) input.checked = requirementSelectAll.checked;
  });
  updateRequirementSelection();
});
document.querySelectorAll('[data-bulk-decision]').forEach(button => {
  button.addEventListener('click', () => {
    document.getElementById('bulkRequirementDecision').value = button.dataset.bulkDecision;
  });
});
document.getElementById('bulkRequirementForm')?.addEventListener('submit', event => {
  if (!requirementChecks.some(input => input.checked)) {
    event.preventDefault();
    return;
  }
  if (!document.getElementById('bulkRequirementDecision').value) event.preventDefault();
});
document.querySelectorAll('[data-bulk-return]').forEach(button => {
  button.addEventListener('click', () => {
    const label = button.dataset.bulkReturnLabel || 'Return selected';
    document.getElementById('bulkRequirementReturnTitle').textContent = label;
    document.getElementById('bulkRequirementReturnSubmit').textContent = label;
    document.getElementById('bulkRequirementReturnNote').value = '';
    openModal('bulkRequirementReturnModal');
  });
});
document.getElementById('bulkRequirementReturnSubmit')?.addEventListener('click', () => {
  const note = document.getElementById('bulkRequirementReturnNote').value.trim();
  if (!note) {
    document.getElementById('bulkRequirementReturnNote').focus();
    return;
  }
  document.getElementById('bulkRequirementDecision').value = 'reject';
  document.getElementById('bulkRequirementRemarks').value = note;
  closeModal('bulkRequirementReturnModal');
  document.getElementById('bulkRequirementForm').requestSubmit();
});
updateRequirementSelection();

document.addEventListener('click', function (event) {
  const row = event.target.closest('tr.coordinator-requirement-row');
  if (!row || event.defaultPrevented || event.button !== 0 || event.target.closest('a, button, input, select, textarea, label, form')) return;
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || window.getSelection()?.toString()) return;
  let requirement;
  try { requirement = JSON.parse(row.dataset.requirementDetails || '{}'); } catch (_) { return; }
  viewReq(requirement);
});

document.addEventListener('keydown', function (event) {
  const row = event.target.closest('tr.coordinator-requirement-row');
  if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault();
    try { viewReq(JSON.parse(row.dataset.requirementDetails || '{}')); } catch (_) {}
  }
});

let requirementEditorOrigin = null;
function fillRequirementEditor(requirement) {
    document.getElementById('editReqId').value = requirement.id;
    document.getElementById('editReqVersion').value = requirement.version_id;
    document.getElementById('editReqName').value = requirement.name;
    document.getElementById('editReqDescription').value = requirement.description || '';
}
function openRequirementEditor(button) {
    requirementEditorOrigin = button.closest('.modal-overlay')?.id || null;
    fillRequirementEditor(JSON.parse(button.dataset.requirement));
    if (requirementEditorOrigin) closeModal(requirementEditorOrigin);
    openModal('editReqModal');
}
function closeRequirementEditor() {
    closeModal('editReqModal');
    if (requirementEditorOrigin) openModal(requirementEditorOrigin);
}
<?php if ($editDraft): ?>
document.addEventListener('DOMContentLoaded', function () {
    fillRequirementEditor(<?= json_encode($editDraft, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>);
    openModal('editReqModal');
});
<?php endif; ?>
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
            if (note) { note.style.display = 'block'; note.textContent = 'Already has all selected requirements'; }
            if (row) row.style.opacity = '0.5';
        } else {
            cb.disabled = false;
            if (row) row.style.opacity = '1';
            if (note) {
                if (matched.length > 0) {
                    note.style.display = 'block';
                    note.textContent = ' — already has: ' + matched.join(', ');
                } else {
                    note.style.display = 'none';
                    note.textContent = '';
                }
            }
        }
    });
    const studentPicker = document.querySelector('.send-multiselect[data-plural="students"]');
    if (studentPicker) syncSendMultiSelect(studentPicker);
}

document.querySelectorAll('.tpl-check').forEach(function (cb) {
    cb.addEventListener('change', refreshStudentAvailability);
});

function syncSendMultiSelect(dropdown) {
    if (!dropdown) return;
    const options = [...dropdown.querySelectorAll('.send-option-checkbox')];
    const visibleEnabled = options.filter(function (checkbox) {
        const option = checkbox.closest('.send-requirement-option, .send-student-option');
        return option && !option.hidden && !checkbox.disabled;
    });
    const selectedCount = options.filter(function (checkbox) { return checkbox.checked; }).length;
    const summary = dropdown.querySelector('.send-multiselect-summary');
    const selectAll = dropdown.querySelector('.send-select-all');
    const placeholder = dropdown.dataset.placeholder || 'Choose items';
    const singular = dropdown.dataset.singular || 'item';
    const plural = dropdown.dataset.plural || 'items';

    if (summary) {
        summary.textContent = selectedCount ? selectedCount + ' ' + (selectedCount === 1 ? singular : plural) + ' selected' : placeholder;
        summary.classList.toggle('has-selection', selectedCount > 0);
    }
    if (selectAll) {
        const checkedCount = visibleEnabled.filter(function (checkbox) { return checkbox.checked; }).length;
        selectAll.checked = visibleEnabled.length > 0 && checkedCount === visibleEnabled.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < visibleEnabled.length;
        selectAll.disabled = visibleEnabled.length === 0;
    }
}

document.querySelectorAll('.send-multiselect[data-multi-select]').forEach(function (dropdown) {
    const search = dropdown.querySelector('.send-multiselect-search');
    const selectAll = dropdown.querySelector('.send-select-all');
    const list = dropdown.querySelector('.send-selection-list');
    const noResults = dropdown.querySelector('.send-multiselect-no-results');
    const options = [...dropdown.querySelectorAll('.send-option-checkbox')];

    function applySearch() {
        const term = (search?.value || '').trim().toLocaleLowerCase();
        let visibleCount = 0;
        options.forEach(function (checkbox) {
            const option = checkbox.closest('.send-requirement-option, .send-student-option');
            const source = option?.dataset.search || option?.textContent || '';
            const matches = source.toLocaleLowerCase().includes(term);
            if (option) {
                option.hidden = !matches;
                if (matches) visibleCount++;
            }
        });
        if (noResults) noResults.hidden = visibleCount > 0 || options.length === 0;
        syncSendMultiSelect(dropdown);
    }

    search?.addEventListener('input', applySearch);
    search?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') event.preventDefault();
    });
    selectAll?.addEventListener('change', function () {
        const check = selectAll.checked;
        options.forEach(function (checkbox) {
            const option = checkbox.closest('.send-requirement-option, .send-student-option');
            if (option && !option.hidden && !checkbox.disabled) checkbox.checked = check;
        });
        if (dropdown.dataset.plural === 'requirements') refreshStudentAvailability();
        syncSendMultiSelect(dropdown);
    });
    options.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () { syncSendMultiSelect(dropdown); });
    });
    dropdown.addEventListener('toggle', function () {
        if (!dropdown.open) return;
        document.querySelectorAll('.send-multiselect[open]').forEach(function (other) {
            if (other !== dropdown) other.open = false;
        });
        applySearch();
        if (search) search.focus();
    });
    syncSendMultiSelect(dropdown);
});

document.addEventListener('click', function (event) {
    document.querySelectorAll('.send-multiselect[open]').forEach(function (dropdown) {
        if (!dropdown.contains(event.target)) dropdown.open = false;
    });
});
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    const dropdown = document.querySelector('.send-multiselect[open]');
    if (!dropdown) return;
    event.preventDefault();
    event.stopPropagation();
    dropdown.open = false;
    dropdown.querySelector('summary')?.focus();
}, true);
function openAddReqModal() {
  const names = document.querySelector('#addReqModal textarea[name="names"]');
  const desc  = document.querySelector('#addReqModal textarea[name="description"]');
  if (names) names.value = '';
  if (desc) desc.value = '';
  openModal('addReqModal');
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
