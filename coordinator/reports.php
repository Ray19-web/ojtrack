<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $report_id = (int)($_POST['report_id'] ?? 0);
    $remarks   = trim($_POST['remarks'] ?? '');

    $rep = query_one("SELECT r.*, s.user_id, u.name AS student_name FROM reports r
        JOIN students s ON s.id=r.student_id
        JOIN users u ON u.id=s.user_id
        WHERE r.id=? AND s.coordinator_id=?", [$report_id, $coord['id']], 'ii');

    if (!$rep) {
        $error = 'Report not found.';
    } elseif ($action === 'approve_report' && (empty($rep['submitted_at']))) {
        $error = 'A submission is required before approval.';
    } elseif (in_array($action, ['reject', 'reject_report'], true) && $remarks === '') {
        $error = 'Explain what the student needs to revise.';
    } elseif ($action === 'approve_report' && $report_id) {
        query("UPDATE reports SET status='approved', remarks=?, reviewed_at=NOW(), reviewed_by={$user['id']} WHERE id=?", [$remarks, $report_id], 'si');
        log_activity($user['id'], 'Report Approved', "Report ID: $report_id ({$rep['report_name']}) for {$rep['student_name']}");
        create_notification($rep['user_id'], "Your narrative report '{$rep['report_name']}' has been approved.", 'report', '/ojtrack/student/reports.php');
        $success = 'Report approved successfully.';
    } elseif ($action === 'reject_report' && $report_id) {
        query("UPDATE reports SET status='rejected', remarks=?, reviewed_at=NOW(), reviewed_by={$user['id']} WHERE id=?", [$remarks, $report_id], 'si');
        log_activity($user['id'], 'Report Rejected', "Report ID: $report_id ({$rep['report_name']}) for {$rep['student_name']}");
        create_notification($rep['user_id'], "Your narrative report '{$rep['report_name']}' was returned with remarks: $remarks", 'report', '/ojtrack/student/reports.php');
        $success = 'Report returned with remarks.';
    }
}

$tab = $_GET['tab'] ?? 'for_review';
$search = trim($_GET['q'] ?? '');

if ($tab === 'evaluations') {
    $where = "s.coordinator_id=?";
    $params = [$coord['id']];
    $types = 'i';
    if ($search) {
        $where .= " AND (u.name LIKE ? OR s.student_id_no LIKE ? OR co.company_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= 'sss';
    }
    $evaluations = query("SELECT e.*, u.name AS student_name, s.student_id_no, s.program, co.company_name, cu.name AS evaluator_name
        FROM evaluations e
        JOIN students s ON s.id=e.student_id
        JOIN users u ON u.id=s.user_id
        JOIN companies co ON co.id=e.company_id
        LEFT JOIN users cu ON cu.id=e.evaluator_id
        WHERE $where ORDER BY e.evaluated_at DESC", $params, $types);
} else {
    $where = "s.coordinator_id=? AND r.status=?";
    $params = [$coord['id'], $tab];
    $types = 'is';
    if ($search) {
        $where .= " AND (u.name LIKE ? OR r.report_name LIKE ? OR s.student_id_no LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= 'sss';
    }
    $reports = query("SELECT r.*, u.name AS student_name, s.student_id_no, s.program FROM reports r
        JOIN students s ON s.id=r.student_id JOIN users u ON u.id=s.user_id
        WHERE $where ORDER BY r.submitted_at DESC", $params, $types);
}

$counts = [];
foreach (['for_review', 'approved', 'rejected', 'pending'] as $st) {
    $counts[$st] = query_one("SELECT COUNT(*) AS c FROM reports r JOIN students s ON s.id=r.student_id WHERE s.coordinator_id=? AND r.status=?", [$coord['id'], $st], 'is')['c'];
}
$eval_count = query_one("SELECT COUNT(*) AS c FROM evaluations e JOIN students s ON s.id=e.student_id WHERE s.coordinator_id=?", [$coord['id']], 'i')['c'];

$page_title = 'Reports & Evaluations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Reports &amp; Evaluations Review</div>
    <div class="page-sub">Review student OJT narrative reports and company performance evaluations</div>
  </div>
  <form method="GET" style="display:flex;gap:8px">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="search-wrap" style="width:240px">
      <input type="text" name="q" class="form-control search-input" placeholder="Search student or report..." value="<?= e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if ($search): ?><a href="?tab=<?= $tab ?>" class="btn btn-ghost">Clear</a><?php endif; ?>
  </form>
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
    <span class="text-sm text-muted"><?= count($evaluations) ?> submitted</span>
  </div>
  <div class="table-wrap">
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
        <tr>
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
            <button class="btn btn-secondary btn-xs" onclick='viewEvaluation(<?= e(json_encode($ev, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>)'>View Details</button>
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
  document.getElementById('evalModalTitle').textContent = (ev.evaluation_type.toUpperCase()) + ' Evaluation Report';
  document.getElementById('evalModalSub').textContent = ev.student_name + ' · Evaluated by ' + ev.company_name;

  const criteria = [
    { label: 'Technical Skills & Competency', val: ev.technical_skills },
    { label: 'Work Ethic & Punctuality', val: ev.work_ethic },
    { label: 'Communication Skills', val: ev.communication },
    { label: 'Teamwork & Collaboration', val: ev.teamwork },
    { label: 'Initiative & Problem Solving', val: ev.initiative },
    { label: 'Adaptability & Learning', val: ev.adaptability }
  ];

  let html = '<div class="grid grid-2 gap-3 mb-4">';
  criteria.forEach(c => {
    html += '<div style="background:var(--bg);padding:10px 14px;border-radius:var(--radius);display:flex;justify-content:space-between;align-items:center">' +
      '<span class="text-xs font-bold">' + c.label + '</span>' +
      '<span class="font-mono font-bold text-primary">' + (c.val || 0) + '/100</span>' +
    '</div>';
  });
  html += '</div>';

  html += '<div style="text-align:center;padding:16px;background:var(--primary-light);border-radius:var(--radius);margin-bottom:16px">' +
    '<div class="text-xs text-muted mb-1">OVERALL PERFORMANCE RATING</div>' +
    '<div style="font-family:var(--font-display);font-size:36px;font-weight:800;color:var(--primary)">' + Number(ev.overall_score).toFixed(1) + ' / 100</div>' +
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
</script>

<?php else: ?>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
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
        <?php foreach ($reports as $r): ?>
        <tr>
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
            <?php if (!empty($r['file_path'])): ?>
              <a href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>" target="_blank" class="btn btn-secondary btn-xs" style="display:inline-flex;align-items:center;gap:4px">
                View Document
              </a>
            <?php else: ?>
              <span class="text-xs text-muted">No file</span>
            <?php endif; ?>
          </td>
          <td class="td-mono text-sm"><?= format_date($r['deadline']) ?></td>
          <td class="td-mono text-sm"><?= $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—' ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-xs text-muted" style="max-width:180px"><?= $r['remarks'] ? e($r['remarks']) : '—' ?></td>
          <td>
            <div style="display:flex;gap:4px">
              <?php if ($tab === 'for_review'): ?>
                <button class="btn btn-success btn-xs" onclick="openRep(<?= $r['id'] ?>,'approve_report','<?= e(addslashes($r['student_name'])) ?> – <?= e(addslashes($r['report_name'])) ?>','<?= e(addslashes($r['remarks'] ?? '')) ?>')">Approve</button>
                <button class="btn btn-danger btn-xs" onclick="openRep(<?= $r['id'] ?>,'reject_report','<?= e(addslashes($r['student_name'])) ?> – <?= e(addslashes($r['report_name'])) ?>','<?= e(addslashes($r['remarks'] ?? '')) ?>')">Return</button>
              <?php elseif ($r['status'] !== 'pending'): ?>
                <button class="btn btn-secondary btn-xs" onclick="openRep(<?= $r['id'] ?>,'<?= $r['status']==='approved'?'reject_report':'approve_report' ?>','<?= e(addslashes($r['student_name'])) ?> – <?= e(addslashes($r['report_name'])) ?>','<?= e(addslashes($r['remarks'] ?? '')) ?>')">Re-evaluate</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($reports)): ?><tr><td colspan="10" class="text-center text-muted py-6">No <?= str_replace('_',' ',$tab) ?> reports found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="repModal">
  <div class="modal">
    <div class="modal-title" id="repModalTitle">Review Report</div>
    <p class="modal-sub" id="repModalSub"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="report_id" id="repId">
      <input type="hidden" name="action" id="repAction">
      <div class="form-group">
        <label class="form-label">Feedback / Remarks for Student</label>
        <textarea name="remarks" id="repRemarks" class="form-control" rows="4" placeholder="Provide feedback or guidance for student..."></textarea>
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
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
