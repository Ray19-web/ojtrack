<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';
$newReportCreated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $report_id = (int)($_POST['report_id'] ?? 0);
    $remarks   = trim($_POST['remarks'] ?? '');

    try {
        if ($action === 'create_report_assignment') {
            $createdReport = normalized_report_create_and_assign(
                (int)$coord['id'],
                (int)$user['id'],
                (string)($_POST['title'] ?? ''),
                (string)($_POST['report_type'] ?? ''),
                (string)($_POST['instructions'] ?? ''),
                is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : [],
                !empty($_POST['deadline']) ? (string)$_POST['deadline'] : null
            );
            foreach ($createdReport['students'] as $student) {
                create_notification(
                    (int)$student['user_id'],
                    "New report assigned: '{$createdReport['title']}'. Check the report instructions and due date.",
                    'info',
                    '/ojtrack/student/reports.php'
                );
            }
            log_activity($user['id'], 'Report Created and Assigned', "{$createdReport['title']} assigned to " . count($createdReport['students']) . ' student(s)');
            $newReportCreated = true;
            $success = "{$createdReport['title']} was assigned to " . count($createdReport['students']) . ' student(s).';
        } elseif ($action === 'bulk_review_reports') {
            $sourceStatus = (string)($_POST['source_status'] ?? '');
            $decision = (string)($_POST['status'] ?? '');
            $allowedDecisions = [
                'for_review' => ['approved', 'returned'],
                'approved' => ['returned'],
                'rejected' => ['approved'],
            ];
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['report_ids'] ?? [])), static fn($id) => $id > 0)));
            $reviewable = isset($allowedDecisions[$sourceStatus]) && in_array($decision, $allowedDecisions[$sourceStatus], true)
                ? normalized_report_rows_for_coordinator((int)$coord['id'], $sourceStatus)
                : [];
            $reviewableIds = array_fill_keys(array_map(static fn($report) => (int)$report['id'], $reviewable), true);
            $selectedIds = array_values(array_filter($selectedIds, static fn($id) => isset($reviewableIds[$id])));

            if (!$selectedIds) {
                $error = 'Select at least one report in the current status.';
            } elseif (!isset($allowedDecisions[$sourceStatus]) || !in_array($decision, $allowedDecisions[$sourceStatus], true)) {
                $error = 'That bulk action is not available for the selected report status.';
            } elseif ($decision === 'returned' && $remarks === '') {
                $error = 'Add feedback before returning the selected reports.';
            } else {
                $reviewedCount = 0;
                foreach ($selectedIds as $selectedId) {
                    $rep = normalized_report_review($selectedId, (int)$coord['id'], (int)$user['id'], $decision, $decision === 'approved' ? '' : $remarks);
                    if (!$rep) continue;
                    $reviewedCount++;
                    log_activity($user['id'], 'Report ' . ucfirst($decision), "Assignment ID: $selectedId ({$rep['report_name']}) for {$rep['student_name']}");
                    create_notification(
                        (int)$rep['user_id'],
                        "Your narrative report '{$rep['report_name']}' " . ($decision === 'approved' ? 'has been approved.' : 'was returned with feedback.'),
                        'info',
                        '/ojtrack/student/reports.php'
                    );
                }
                if ($reviewedCount > 0) {
                    $success = $decision === 'approved'
                        ? "$reviewedCount report " . ($reviewedCount === 1 ? 'was' : 'were') . ' approved.'
                        : "$reviewedCount report " . ($reviewedCount === 1 ? 'was' : 'were') . ' returned.';
                } else {
                    $error = 'The selected reports could not be reviewed. Refresh and try again.';
                }
            }
        } elseif ($action === 'approve_report') {
            $rep=normalized_report_review(
                $report_id,(int)$coord['id'],(int)$user['id'],'approved',$remarks
            );
            if (!$rep) {
                $error='Report not found.';
            } else {
                log_activity($user['id'],'Report Approved',"Assignment ID: $report_id ({$rep['report_name']}) for {$rep['student_name']}");
                create_notification((int)$rep['user_id'],"Your narrative report '{$rep['report_name']}' has been approved.",'info','/ojtrack/student/reports.php');
                $success='Report approved successfully.';
            }
        } elseif ($action === 'reject_report') {
            if ($remarks==='') {
                $error='Explain what the student needs to revise.';
            } else {
                $rep=normalized_report_review(
                    $report_id,(int)$coord['id'],(int)$user['id'],'returned',$remarks
                );
                if (!$rep) {
                    $error='Report not found.';
                } else {
                    log_activity($user['id'],'Report Returned',"Assignment ID: $report_id ({$rep['report_name']}) for {$rep['student_name']}");
                    create_notification((int)$rep['user_id'],"Your narrative report '{$rep['report_name']}' was returned with remarks: $remarks",'info','/ojtrack/student/reports.php');
                    $success='Report returned with remarks.';
                }
            }
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

$tab = $newReportCreated ? 'pending' : ($_GET['tab'] ?? 'for_review');
$search = $newReportCreated ? '' : trim($_GET['q'] ?? '');

if ($tab === 'evaluations') {
    $evaluations = normalized_eval_completed_for_coordinator((int)$coord['id'], $search);
    $reports = [];
} else {
    if (!in_array($tab,['for_review','approved','rejected','pending'],true)) $tab='for_review';
    $reports = normalized_report_rows_for_coordinator((int)$coord['id'], $tab, $search);
    $evaluations = [];
}

$allReports=normalized_report_rows_for_coordinator((int)$coord['id'], '', '');
$counts=[];
foreach (['for_review','approved','rejected','pending'] as $state) {
    $counts[$state]=count(array_filter($allReports,fn($r)=>$r['status']===$state));
}
$eval_count=count(normalized_eval_completed_for_coordinator((int)$coord['id']));
$reportStudents = $tab==='evaluations' ? [] : normalized_students_for_coordinator((int)$coord['id']);

$page_title = 'Reports & Evaluations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Reports &amp; Evaluations</div>
    <div class="page-sub">Review reports and company evaluations</div>
  </div>
  <?php if ($tab==='evaluations'): ?>
    <form class="filter-toolbar" method="GET" data-live-filter style="display:flex;gap:8px">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="search-wrap" style="width:240px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search student or report..." value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-secondary">Search</button>
      <?php if ($search): ?><a href="?tab=<?= $tab ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
  <?php else: ?>
    <div class="report-toolbar-actions">
      <button type="button" class="btn btn-primary" onclick="openCreateReportModal()">+ Create &amp; Assign Report</button>
      <form class="filter-toolbar" method="GET" data-live-filter style="display:flex;gap:8px">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="search-wrap" style="width:240px">
          <input type="text" name="q" class="form-control search-input" placeholder="Search student or report..." value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn btn-secondary">Search</button>
        <?php if ($search): ?><a href="?tab=<?= $tab ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="tabs mb-5">
  <a href="?tab=for_review"  class="tab-btn <?= $tab==='for_review'?'active':'' ?>">For Review <span class="tab-count"><?= $counts['for_review'] ?></span></a>
  <a href="?tab=approved"    class="tab-btn <?= $tab==='approved'?'active':'' ?>">Approved <span class="tab-count"><?= $counts['approved'] ?></span></a>
  <a href="?tab=rejected"    class="tab-btn <?= $tab==='rejected'?'active':'' ?>">Returned <span class="tab-count"><?= $counts['rejected'] ?></span></a>
  <a href="?tab=pending"     class="tab-btn <?= $tab==='pending'?'active':'' ?>">Not Submitted <span class="tab-count"><?= $counts['pending'] ?></span></a>
  <a href="?tab=evaluations" class="tab-btn <?= $tab==='evaluations'?'active':'' ?>">Company Evaluations <span class="tab-count"><?= $eval_count ?></span></a>
</div>

<?php if ($tab === 'evaluations'): ?>
<div class="card">
  <div class="card-header flex-between">
    <div class="card-title">Supervisor Evaluations</div>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Company</th>
          <th>Type</th>
          <th>Score</th>
          <th>Status</th>
          <th>Evaluated At</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($evaluations as $ev): ?>
        <tr class="evaluation-clickable-row" tabindex="0" data-evaluation-row
            data-evaluation-info="<?= e(json_encode($ev, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
            aria-label="View evaluation details for <?= e($ev['student_name']) ?>">
          <td>
            <a href="/ojtrack/coordinator/students.php?id=<?= $ev['student_id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
              <?= e($ev['student_name']) ?>
            </a>
            <div class="text-xs text-muted"><?= e($ev['student_id_no'] ?? '') ?></div>
          </td>
          <td>
            <div class="text-sm font-bold"><?= e($ev['company_name']) ?></div>
            <div class="text-xs text-muted"><?= e($ev['evaluator_name'] ?? 'Supervisor') ?></div>
          </td>
          <td><span class="badge" style="background:var(--bg);color:var(--text-700)"><?= ucfirst($ev['evaluation_type']) ?></span></td>
          <td>
            <span class="font-mono font-bold text-base text-primary"><?= number_format($ev['overall_score'],1) ?></span><span class="text-xs text-muted">/100</span>
          </td>
          <td><?= status_badge($ev['status'] ?? 'completed') ?></td>
          <td class="td-mono text-xs"><?= $ev['evaluated_at'] ? date('M d, Y h:i A', strtotime($ev['evaluated_at'])) : '—' ?></td>
          <td>
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary" title="View evaluation details" aria-label="View evaluation details"
                      onclick='viewEvaluation(<?= e(json_encode($ev, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)'>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($evaluations)): ?><tr><td colspan="7" class="text-center text-muted py-6">No company evaluations recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Evaluation Detail Modal -->
<div class="modal-overlay" id="evalDetailModal">
  <div class="modal modal-lg">
    <div class="modal-title" id="evalModalTitle">Evaluation Details</div>
    <p class="modal-sub" id="evalModalSub"></p>
    <div id="evalModalBody" style="margin-top:14px"></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('evalDetailModal')">Close</button>
    </div>
  </div>
</div>

<script>
function viewEvaluation(ev) {
  if (!ev) return;
  document.getElementById('evalModalTitle').textContent = ev.form_title || (String(ev.evaluation_type || 'Company').toUpperCase() + ' Evaluation');
  document.getElementById('evalModalSub').textContent = [ev.student_name, ev.company_name ? 'Evaluated by ' + ev.company_name : ''].filter(Boolean).join(' · ');

  const answers = Array.isArray(ev.answers) ? ev.answers : [];
  const groups = new Map();
  answers.forEach(answer => {
    const section = String(answer.section_title || 'Evaluation');
    if (!groups.has(section)) groups.set(section, []);
    groups.get(section).push(answer);
  });

  let html = '<div class="evaluation-result-answers">';
  if (!answers.length) {
    html += '<div class="empty-state"><p>No saved criterion answers were found for this evaluation.</p></div>';
  } else {
    groups.forEach((items, section) => {
      html += '<section class="evaluation-review-section"><h3>' + escapeHtml(section) + '</h3><div class="evaluation-review-table-wrap"><table class="evaluation-review-table"><thead><tr><th>Question</th><th>Score</th></tr></thead><tbody>';
      items.forEach(answer => {
        const score = Number(answer.score);
        const max = ev.score_mode === 'points' ? Number(answer.maximum_points) : 100;
        const scoreText = Number.isFinite(score) ? escapeHtml(String(score)) + ' / ' + escapeHtml(String(max)) + (ev.score_mode === 'points' ? ' pts' : '') : '—';
        html += '<tr><td>' + escapeHtml(answer.criterion_label || 'Question') + '</td><td>' + scoreText + '</td></tr>';
      });
      html += '</tbody></table></div></section>';
    });
  }
  html += '</div>';

  const overallScore = Number(ev.overall_score);
  html += '<div class="evaluation-result-summary"><span>Overall score</span><strong>' + (Number.isFinite(overallScore) ? escapeHtml(overallScore.toFixed(1)) + ' / 100' : '—') + '</strong>' +
    (ev.score_mode === 'points' && ev.overall_equivalent !== null && ev.overall_equivalent !== '' ? '<span>Equivalent: ' + escapeHtml(String(ev.overall_equivalent)) + '</span>' : '') +
  '</div>';

  if (ev.comments) {
    html += '<div class="card card-body" style="background:#fff;border:1px solid var(--border)">' +
      '<div class="text-xs font-bold text-muted mb-2">SUPERVISOR COMMENTS & FEEDBACK</div>' +
      '<p style="font-size:13px;line-height:1.6;color:var(--text-800)">' + escapeHtml(ev.comments).replace(/\n/g, '<br>') + '</p>' +
    '</div>';
  }

  document.getElementById('evalModalBody').innerHTML = html;
  openModal('evalDetailModal');
}

function evaluationFromRow(row) {
  try { return JSON.parse(row.dataset.evaluationInfo || '{}'); } catch (_) { return null; }
}

document.addEventListener('click', event => {
  const row = event.target.closest('[data-evaluation-row]');
  if (row && !event.target.closest('a,button,input,select,textarea,label')) {
    viewEvaluation(evaluationFromRow(row));
  }
});
document.addEventListener('keydown', event => {
  const row = event.target.closest('[data-evaluation-row]');
  if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault();
    viewEvaluation(evaluationFromRow(row));
  }
});
</script>

<?php else: ?>

<div class="card">
  <?php $canSelectReports = in_array($tab, ['for_review', 'approved', 'rejected'], true); ?>
  <div class="report-selection-toolbar" id="reportSelectionToolbar" hidden>
    <span><strong id="reportSelectedCount">0</strong> selected</span>
    <div class="report-selection-actions" id="reportSelectionActions" hidden>
      <?php if ($tab === 'for_review' || $tab === 'rejected'): ?><button type="button" class="btn btn-success btn-sm" id="approveSelectedReports">Approve selected</button><?php endif; ?>
      <?php if ($tab === 'for_review' || $tab === 'approved'): ?><button type="button" class="btn btn-danger btn-sm" id="returnSelectedReports">Return selected</button><?php endif; ?>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="clearReportSelection">Clear selection</button>
  </div>
  <?php if ($canSelectReports): ?>
  <form method="POST" id="bulkReportReviewForm" hidden><?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_review_reports">
    <input type="hidden" name="status" id="bulkReportStatus" value="approved">
    <input type="hidden" name="source_status" value="<?= e($tab) ?>">
    <input type="hidden" name="remarks" id="bulkReportRemarks" value="">
    <div id="bulkReportIds"></div>
  </form>
  <?php endif; ?>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead>
        <tr>
          <?php if ($canSelectReports): ?><th class="report-select-column"><input type="checkbox" id="reportSelectAll" aria-label="Select all reports on this page"></th><?php endif; ?>
          <th>Student</th>
          <th>Program</th>
          <th>Report Type</th>
          <th>Report Title</th>
          <th>Attached File</th>
          <th>Deadline</th>
          <th>Submitted</th>
          <th>Status</th>
          <th>Remarks</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reports as $r):
          $reportInfo = [
            'id' => (int)$r['id'], 'student_name' => $r['student_name'], 'student_id_no' => $r['student_id_no'] ?? '',
            'program' => $r['program'], 'report_type' => $r['report_type'], 'report_name' => $r['report_name'],
            'instructions' => $r['instructions'] ?? '',
            'deadline' => $r['deadline'] ? format_date($r['deadline']) : '—',
            'submitted_at' => $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—',
            'status' => $r['status'], 'remarks' => $r['review_notes'] ?? '', 'student_note' => $r['student_note'] ?? '',
            'file_path' => $r['file_path'] ?? '', 'period_start' => $r['period_start'] ?? '', 'period_end' => $r['period_end'] ?? '',
            'attachments' => array_map(static fn($file) => ['file_path'=>(string)$file['file_path'],'attachment_name'=>(string)$file['attachment_name']], $r['attachments'] ?? []),
          ];
        ?>
        <tr class="report-clickable-row" tabindex="0" data-report-row data-report-info="<?= e(json_encode($reportInfo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>" aria-label="View report details for <?= e($r['student_name']) ?>: <?= e($r['report_name']) ?>">
          <?php if ($canSelectReports): ?><td class="report-select-column"><input type="checkbox" class="report-row-select" value="<?= (int)$r['id'] ?>" aria-label="Select <?= e($r['report_name']) ?> for <?= e($r['student_name']) ?>"></td><?php endif; ?>
          <td>
            <a href="/ojtrack/coordinator/students.php?id=<?= $r['student_id'] ?>" style="font-weight:700;color:var(--primary);text-decoration:none">
              <?= e($r['student_name']) ?>
            </a>
            <div class="text-xs text-muted"><?= e($r['student_id_no'] ?? '') ?></div>
          </td>
          <td class="text-sm text-muted"><?= e($r['program']) ?></td>
          <td><span class="badge" style="background:var(--bg);color:var(--text-700)"><?= ucfirst($r['report_type']) ?></span></td>
          <td class="font-bold text-sm"><?= e($r['report_name']) ?></td>
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
          <td class="td-mono text-sm"><?= format_date($r['deadline']) ?></td>
          <td class="td-mono text-sm"><?= $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—' ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-xs text-muted" style="max-width:180px"><?= $r['remarks'] ? e($r['remarks']) : '—' ?></td>
          <td>
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary report-action-toggle" data-report-action-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?= e($r['report_name']) ?>" title="Actions"><span class="coordinator-actions-dots" aria-hidden="true">•••</span></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($reports)): ?><tr><td colspan="<?= $canSelectReports ? 11 : 10 ?>" class="text-center text-muted py-6">No <?= str_replace('_',' ',$tab) ?> reports found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create a reusable report template and assign its first published version. -->
<div class="modal-overlay" id="createReportModal">
  <div class="modal modal-lg create-report-modal" role="dialog" aria-modal="true" aria-labelledby="createReportTitle">
    <div class="report-detail-header">
      <div><span class="report-detail-eyebrow">Report setup</span><h2 class="modal-title" id="createReportTitle">Create &amp; Assign Report</h2><p class="modal-sub">Create the report instructions students will follow, then assign it to your students.</p></div>
      <button type="button" class="requirement-detail-close" onclick="closeModal('createReportModal')" aria-label="Close">&times;</button>
    </div>
    <form method="POST" id="createReportForm"><?= csrf_field() ?>
      <input type="hidden" name="action" value="create_report_assignment">
      <div class="create-report-form-body">
        <div class="form-group">
          <label class="form-label" for="createReportName">Report title <span class="text-danger">*</span></label>
          <input class="form-control" id="createReportName" name="title" maxlength="200" required placeholder="For example: Workplace Safety Reflection">
        </div>
        <div class="form-group">
          <label class="form-label" for="createReportType">Category <span class="text-danger">*</span></label>
          <select class="form-control" id="createReportType" name="report_type" required>
            <option value="">Choose a category</option>
            <option value="initial">Initial</option>
            <option value="midterm">Mid-Term</option>
            <option value="final">Final</option>
            <option value="custom">Custom</option>
          </select>
          <small class="text-muted">Monthly DTR and journal compilations are generated separately by students.</small>
        </div>
        <div class="form-group">
          <label class="form-label" for="createReportInstructions">Instructions for students</label>
          <textarea class="form-control" id="createReportInstructions" name="instructions" rows="4" maxlength="10000" placeholder="Explain what the student should include in the report."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label" id="reportStudentsLabel">Assign to students <span class="text-danger">*</span></label>
          <div class="report-student-picker" id="reportStudentPicker" role="group" aria-labelledby="reportStudentsLabel">
            <div class="report-student-picker-tools">
              <input type="search" class="form-control" id="reportStudentSearch" placeholder="Search your students..." aria-label="Search students to assign this report">
              <label><input type="checkbox" id="reportStudentSelectAll"> <span>Select all shown</span></label>
            </div>
            <div class="report-student-list" id="reportStudentList">
              <?php foreach ($reportStudents as $student): ?>
              <label class="report-student-choice" data-search="<?= e(strtolower($student['name'].' '.$student['student_id_no'].' '.($student['company_name'] ?? ''))) ?>">
                <input type="checkbox" class="report-student-check" name="student_ids[]" value="<?= (int)$student['id'] ?>">
                <span><strong><?= e($student['name']) ?></strong><small><?= e($student['student_id_no']) ?><?= !empty($student['company_name']) ? ' · '.e($student['company_name']) : '' ?></small></span>
              </label>
              <?php endforeach; ?>
              <?php if (!$reportStudents): ?><p class="text-sm text-muted">No students are currently assigned to you.</p><?php endif; ?>
            </div>
            <p class="report-student-empty" id="reportStudentNoResults" hidden>No students match your search.</p>
            <p class="report-student-selection" id="reportStudentSelectionCount">No students selected</p>
          </div>
          <p class="form-error" id="reportStudentSelectionError" role="alert" hidden>Select at least one student.</p>
        </div>
        <div class="form-group">
          <label class="form-label" for="createReportDeadline">Due date <span class="text-muted">(optional)</span></label>
          <input class="form-control" type="date" id="createReportDeadline" name="deadline">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createReportModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" <?= !$reportStudents ? 'disabled' : '' ?>>Create &amp; Assign</button>
      </div>
    </form>
  </div>
</div>

<div class="coordinator-action-menu" id="reportActionMenu" role="menu" aria-label="Report actions" hidden></div>

<!-- Report details modal -->
<div class="modal-overlay" id="reportDetailModal">
  <div class="modal modal-lg report-detail-modal" role="dialog" aria-modal="true" aria-labelledby="reportDetailTitle">
    <div class="report-detail-header">
      <div><span class="report-detail-eyebrow">Student report</span><h2 class="modal-title" id="reportDetailTitle">Report details</h2><p class="modal-sub" id="reportDetailStudent"></p></div>
      <button type="button" class="requirement-detail-close" onclick="closeModal('reportDetailModal')" aria-label="Close">&times;</button>
    </div>
    <div class="report-detail-body">
      <div class="report-detail-summary"><span id="reportDetailType"></span><span id="reportDetailStatus"></span></div>
      <dl class="report-detail-grid">
        <div><dt>Program</dt><dd id="reportDetailProgram"></dd></div>
        <div><dt>Deadline</dt><dd id="reportDetailDeadline"></dd></div>
        <div><dt>Submitted</dt><dd id="reportDetailSubmitted"></dd></div>
        <div id="reportDetailPeriodRow" hidden><dt>Reporting period</dt><dd id="reportDetailPeriod"></dd></div>
      </dl>
      <section class="report-detail-section" id="reportDetailInstructionsSection" hidden><h3>Coordinator instructions</h3><p id="reportDetailInstructions"></p></section>
      <section class="report-detail-section" id="reportDetailNoteSection" hidden><h3>Student note</h3><p id="reportDetailNote"></p></section>
      <section class="report-detail-section" id="reportDetailRemarksSection" hidden><h3>Review remarks</h3><p id="reportDetailRemarks"></p></section>
      <section class="report-detail-file" id="reportDetailFileSection" hidden><span class="report-detail-file-icon" id="reportDetailFileIcon" aria-hidden="true">PDF</span><span><strong id="reportDetailFileTitle">Submitted report</strong><small id="reportDetailFileName"></small></span><div id="reportDetailFileLinks" class="submission-file-list"></div></section>
    </div>
    <div class="report-detail-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('reportDetailModal')">Close</button><div id="reportDetailActions" class="report-detail-actions"></div></div>
  </div>
</div>

<div class="modal-overlay" id="bulkReportReturnModal">
  <div class="modal bulk-report-return-modal" role="dialog" aria-modal="true" aria-labelledby="bulkReportReturnTitle">
    <h2 class="modal-title" id="bulkReportReturnTitle">Return selected reports</h2>
    <p class="modal-sub">Add feedback for the selected reports. Students will receive this note.</p>
    <label class="form-label" for="bulkReportReturnFeedback">Feedback <span class="text-danger">*</span></label>
    <textarea class="form-control" id="bulkReportReturnFeedback" rows="4" required placeholder="Explain what needs to be revised…"></textarea>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('bulkReportReturnModal')">Cancel</button><button type="button" class="btn btn-danger" id="confirmBulkReportReturn">Return selected</button></div>
  </div>
</div>

<!-- Review confirmation modal -->
<div class="modal-overlay" id="repModal">
  <div class="modal">
    <div class="modal-title" id="repModalTitle">Review Report</div>
    <p class="modal-sub" id="repModalSub"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="report_id" id="repId">
      <input type="hidden" name="action" id="repAction">
      <div class="form-group">
        <label class="form-label">Feedback / Remarks for Student</label>
        <textarea name="remarks" id="repRemarks" class="form-control" rows="4" placeholder="Feedback for the student"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('repModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="repSubmitBtn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRep(id, action, label, remarks) {
  document.getElementById('repId').value = id;
  document.getElementById('repAction').value = action;
  document.getElementById('repModalSub').textContent = label;
  document.getElementById('repRemarks').value = remarks || '';
  const btn = document.getElementById('repSubmitBtn');
  if (action === 'approve_report') {
    document.getElementById('repModalTitle').textContent = 'Approve Report';
    btn.className = 'btn btn-success'; btn.textContent = 'Approve Report';
  } else {
    document.getElementById('repModalTitle').textContent = 'Return Report';
    btn.className = 'btn btn-danger'; btn.textContent = 'Return with Remarks';
  }
  openModal('repModal');
}

function reportFromRow(row) {
  try { return JSON.parse(row.dataset.reportInfo || '{}'); } catch (_) { return null; }
}

function openReportDetails(report) {
  if (!report || !report.id) return;
  const setText = (id, value) => { document.getElementById(id).textContent = value || '—'; };
  const label = `${report.student_name || 'Student'}${report.student_id_no ? ` · ${report.student_id_no}` : ''}`;
  document.getElementById('reportDetailTitle').textContent = report.report_name || 'Report details';
  setText('reportDetailStudent', label);
  setText('reportDetailType', (report.report_type || 'Report').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()));
  setText('reportDetailProgram', report.program);
  setText('reportDetailDeadline', report.deadline);
  setText('reportDetailSubmitted', report.submitted_at);
  const instructionSection = document.getElementById('reportDetailInstructionsSection');
  instructionSection.hidden = !report.instructions;
  if (report.instructions) setText('reportDetailInstructions', report.instructions);
  const statusLabels = {for_review: 'For Review', approved: 'Approved', rejected: 'Returned', pending: 'Not Submitted'};
  const status = document.getElementById('reportDetailStatus');
  const statusClasses = {for_review: 'badge-review', approved: 'badge-approved', rejected: 'badge-rejected', pending: 'badge-pending'};
  status.className = `badge ${statusClasses[report.status] || 'badge-pending'}`;
  status.textContent = statusLabels[report.status] || report.status || '—';

  const periodRow = document.getElementById('reportDetailPeriodRow');
  const hasPeriod = Boolean(report.period_start && report.period_end);
  periodRow.hidden = !hasPeriod;
  if (hasPeriod) setText('reportDetailPeriod', `${report.period_start} – ${report.period_end}`);
  const noteSection = document.getElementById('reportDetailNoteSection');
  noteSection.hidden = !report.student_note;
  if (report.student_note) setText('reportDetailNote', report.student_note);
  const remarksSection = document.getElementById('reportDetailRemarksSection');
  remarksSection.hidden = !report.remarks;
  if (report.remarks) setText('reportDetailRemarks', report.remarks);

  const fileSection = document.getElementById('reportDetailFileSection');
  const fileIcon = document.getElementById('reportDetailFileIcon');
  const fileTitle = document.getElementById('reportDetailFileTitle');
  const fileLinks = document.getElementById('reportDetailFileLinks');
  const attachments = Array.isArray(report.attachments) && report.attachments.length
    ? report.attachments
    : (report.file_path ? [{file_path: report.file_path, attachment_name: String(report.file_path).split('/').pop()}] : []);
  const hasFile = attachments.length > 0;
  fileSection.hidden = false;
  fileSection.classList.toggle('is-missing', !hasFile);
  fileLinks.replaceChildren();
  if (hasFile) {
    fileIcon.textContent = 'PDF';
    fileTitle.textContent = attachments.length === 1 ? 'Submitted report' : `${attachments.length} submitted files`;
    setText('reportDetailFileName', '');
    attachments.forEach((attachment, index) => {
      const fileLink = document.createElement('a');
      fileLink.className = 'btn btn-secondary btn-sm';
      fileLink.target = '_blank';
      fileLink.rel = 'noopener';
      fileLink.href = '/ojtrack/download.php?file=' + encodeURIComponent(attachment.file_path);
      fileLink.textContent = attachment.attachment_name || `Open file ${index + 1}`;
      fileLinks.appendChild(fileLink);
    });
  } else {
    fileIcon.textContent = '!';
    fileTitle.textContent = 'No file attached';
    setText('reportDetailFileName', 'No report file was uploaded.');
  }

  const actions = document.getElementById('reportDetailActions');
  actions.replaceChildren();
  const addAction = (text, action, tone) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `btn ${tone}`;
    button.textContent = text;
    button.addEventListener('click', () => {
      const reviewAction = action === 'approve' ? 'approve_report' : 'reject_report';
      closeModal('reportDetailModal');
      openRep(report.id, reviewAction, `${report.student_name} – ${report.report_name}`, report.remarks || '');
    });
    actions.appendChild(button);
  };
  if (report.status === 'for_review') {
    addAction('Return report', 'return', 'btn-danger');
    addAction('Approve report', 'approve', 'btn-success');
  } else if (report.status === 'approved') {
    addAction('Re-evaluate', 'return', 'btn-danger');
  } else if (report.status === 'rejected') {
    addAction('Re-evaluate', 'approve', 'btn-success');
  }
  openModal('reportDetailModal');
}

document.addEventListener('click', event => {
  const row = event.target.closest('[data-report-row]');
  if (row && !event.target.closest('a,button,input,select,textarea,label')) {
    openReportDetails(reportFromRow(row));
  }
});
document.addEventListener('keydown', event => {
  const row = event.target.closest('[data-report-row]');
  if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault();
    openReportDetails(reportFromRow(row));
  }
});

const reportActionMenu = document.getElementById('reportActionMenu');
let reportActionTrigger = null;
function closeReportActionMenu(restoreFocus = false) {
  if (!reportActionMenu || reportActionMenu.hidden) return;
  reportActionMenu.hidden = true;
  reportActionTrigger?.setAttribute('aria-expanded', 'false');
  if (restoreFocus) reportActionTrigger?.focus();
  reportActionTrigger = null;
}
function openReportActionMenu(trigger) {
  const report = reportFromRow(trigger.closest('[data-report-row]'));
  if (!report) return;
  closeReportActionMenu();
  reportActionTrigger = trigger;
  reportActionMenu.replaceChildren();
  const addMenuItem = (label, icon, callback, tone = '') => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `coordinator-action-menu-item ${tone}`;
    button.setAttribute('role', 'menuitem');
    const glyph = document.createElement('span'); glyph.className = 'coordinator-action-menu-icon'; glyph.setAttribute('aria-hidden', 'true'); glyph.textContent = icon;
    const copy = document.createElement('span'); copy.textContent = label;
    button.append(glyph, copy);
    button.addEventListener('click', () => { closeReportActionMenu(); callback(); });
    reportActionMenu.appendChild(button);
  };
  addMenuItem('View details', '◉', () => openReportDetails(report));
  if (report.status === 'for_review') {
    addMenuItem('Approve report', '✓', () => openRep(report.id, 'approve_report', `${report.student_name} – ${report.report_name}`, report.remarks || ''), 'is-success');
    addMenuItem('Return report', '↩', () => openRep(report.id, 'reject_report', `${report.student_name} – ${report.report_name}`, report.remarks || ''), 'is-danger');
  } else if (report.status === 'approved') {
    addMenuItem('Re-evaluate report', '↻', () => openRep(report.id, 'reject_report', `${report.student_name} – ${report.report_name}`, report.remarks || ''), 'is-danger');
  } else if (report.status === 'rejected') {
    addMenuItem('Re-evaluate report', '↻', () => openRep(report.id, 'approve_report', `${report.student_name} – ${report.report_name}`, report.remarks || ''), 'is-success');
  }
  document.body.appendChild(reportActionMenu);
  reportActionMenu.hidden = false;
  trigger.setAttribute('aria-expanded', 'true');
  const rect = trigger.getBoundingClientRect();
  const menuRect = reportActionMenu.getBoundingClientRect();
  const edge = 8, gap = 6;
  let left = Math.max(edge, Math.min(rect.right - menuRect.width, window.innerWidth - menuRect.width - edge));
  let top = rect.bottom + gap;
  if (top + menuRect.height > window.innerHeight - edge) top = rect.top - menuRect.height - gap;
  top = Math.max(edge, Math.min(top, window.innerHeight - menuRect.height - edge));
  reportActionMenu.style.left = `${left}px`;
  reportActionMenu.style.top = `${top}px`;
  reportActionMenu.querySelector('[role="menuitem"]')?.focus();
}
document.addEventListener('click', event => {
  const trigger = event.target.closest('[data-report-action-toggle]');
  if (trigger) {
    event.preventDefault();
    if (trigger === reportActionTrigger && !reportActionMenu.hidden) closeReportActionMenu();
    else openReportActionMenu(trigger);
    return;
  }
  if (reportActionMenu && !reportActionMenu.hidden && !reportActionMenu.contains(event.target)) closeReportActionMenu();
});
document.addEventListener('keydown', event => {
  if (reportActionMenu?.hidden) return;
  const items = [...reportActionMenu.querySelectorAll('[role="menuitem"]')];
  const index = items.indexOf(document.activeElement);
  if (event.key === 'Escape') { event.preventDefault(); closeReportActionMenu(true); }
  else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    const direction = event.key === 'ArrowDown' ? 1 : -1;
    items[(index + direction + items.length) % items.length]?.focus();
  }
});
document.addEventListener('scroll', () => closeReportActionMenu(), true);
window.addEventListener('resize', () => closeReportActionMenu());

const reportSelectAll = document.getElementById('reportSelectAll');
const reportSelectionToolbar = document.getElementById('reportSelectionToolbar');
function updateReportSelection() {
  const checks = [...document.querySelectorAll('.report-row-select')];
  const selected = checks.filter(check => check.checked);
  const count = document.getElementById('reportSelectedCount');
  const actions = document.getElementById('reportSelectionActions');
  if (count) count.textContent = String(selected.length);
  if (reportSelectionToolbar) reportSelectionToolbar.hidden = selected.length === 0;
  if (actions) actions.hidden = selected.length === 0 || !document.getElementById('bulkReportReviewForm');
  if (reportSelectAll) {
    reportSelectAll.checked = checks.length > 0 && checks.every(check => check.checked);
    reportSelectAll.indeterminate = selected.length > 0 && selected.length < checks.length;
  }
}
document.querySelectorAll('.report-row-select').forEach(check => check.addEventListener('change', updateReportSelection));
reportSelectAll?.addEventListener('change', () => {
  document.querySelectorAll('.report-row-select').forEach(check => { check.checked = reportSelectAll.checked; });
  updateReportSelection();
});
document.getElementById('clearReportSelection')?.addEventListener('click', () => {
  document.querySelectorAll('.report-row-select').forEach(check => { check.checked = false; });
  updateReportSelection();
});
function submitBulkReportReview(status, remarks = '') {
  const selectedIds = [...document.querySelectorAll('.report-row-select:checked')].map(check => check.value);
  if (!selectedIds.length) return;
  const idsContainer = document.getElementById('bulkReportIds');
  idsContainer.replaceChildren(...selectedIds.map(id => {
    const input = document.createElement('input'); input.type = 'hidden'; input.name = 'report_ids[]'; input.value = id; return input;
  }));
  document.getElementById('bulkReportStatus').value = status;
  document.getElementById('bulkReportRemarks').value = remarks;
  document.getElementById('bulkReportReviewForm').requestSubmit();
}
document.getElementById('approveSelectedReports')?.addEventListener('click', () => submitBulkReportReview('approved'));
document.getElementById('returnSelectedReports')?.addEventListener('click', () => {
  const feedback = document.getElementById('bulkReportReturnFeedback');
  feedback.value = '';
  openModal('bulkReportReturnModal');
  window.setTimeout(() => feedback.focus(), 0);
});
document.getElementById('confirmBulkReportReturn')?.addEventListener('click', () => {
  const feedback = document.getElementById('bulkReportReturnFeedback');
  const remarks = feedback.value.trim();
  if (!remarks) { feedback.setCustomValidity('Add feedback before returning reports.'); feedback.reportValidity(); return; }
  feedback.setCustomValidity('');
  submitBulkReportReview('returned', remarks);
});

const reportStudentPicker = document.getElementById('reportStudentPicker');
const reportStudentSearch = document.getElementById('reportStudentSearch');
const reportStudentSelectAll = document.getElementById('reportStudentSelectAll');
const reportStudentChecks = [...document.querySelectorAll('.report-student-check')];
function updateReportStudentPicker() {
  if (!reportStudentPicker) return;
  const visible = [...reportStudentPicker.querySelectorAll('.report-student-choice')].filter(option => !option.hidden);
  const selected = reportStudentChecks.filter(check => check.checked);
  const count = document.getElementById('reportStudentSelectionCount');
  const noResults = document.getElementById('reportStudentNoResults');
  const error = document.getElementById('reportStudentSelectionError');
  if (count) count.textContent = selected.length ? `${selected.length} student${selected.length === 1 ? '' : 's'} selected` : 'No students selected';
  if (noResults) noResults.hidden = visible.length > 0 || !reportStudentChecks.length;
  if (reportStudentSelectAll) {
    const selectedVisible = visible.filter(option => option.querySelector('.report-student-check')?.checked).length;
    reportStudentSelectAll.checked = visible.length > 0 && selectedVisible === visible.length;
    reportStudentSelectAll.indeterminate = selectedVisible > 0 && selectedVisible < visible.length;
  }
  if (error && selected.length) error.hidden = true;
}
function filterReportStudents() {
  const query = (reportStudentSearch?.value || '').trim().toLowerCase();
  reportStudentPicker?.querySelectorAll('.report-student-choice').forEach(option => {
    option.hidden = !String(option.dataset.search || '').includes(query);
  });
  updateReportStudentPicker();
}
reportStudentSearch?.addEventListener('input', filterReportStudents);
reportStudentSelectAll?.addEventListener('change', () => {
  reportStudentPicker?.querySelectorAll('.report-student-choice:not([hidden]) .report-student-check').forEach(check => { check.checked = reportStudentSelectAll.checked; });
  updateReportStudentPicker();
});
reportStudentChecks.forEach(check => check.addEventListener('change', updateReportStudentPicker));
document.getElementById('createReportForm')?.addEventListener('submit', event => {
  if (!reportStudentChecks.some(check => check.checked)) {
    event.preventDefault();
    const error = document.getElementById('reportStudentSelectionError');
    if (error) error.hidden = false;
    reportStudentPicker?.scrollIntoView({behavior:'smooth',block:'center'});
  }
});
function openCreateReportModal() {
  const form = document.getElementById('createReportForm');
  form?.reset();
  if (reportStudentSearch) reportStudentSearch.value = '';
  reportStudentPicker?.querySelectorAll('.report-student-choice').forEach(option => { option.hidden = false; });
  const error = document.getElementById('reportStudentSelectionError');
  if (error) error.hidden = true;
  updateReportStudentPicker();
  openModal('createReportModal');
}
updateReportSelection();
updateReportStudentPicker();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
