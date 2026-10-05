<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';

// Review the latest normalized journal revision.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_journal') {
    $jid     = (int)$_POST['journal_id'];
    $status  = $_POST['status'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($status === 'rejected' && $remarks === '') {
        $error = 'Explain what the student needs to revise.';
    } elseif (in_array($status, ['approved', 'rejected'], true)) {
        $entry = normalized_journal_review(
            $jid,
            (int)$coord['id'],
            (int)$user['id'],
            $status === 'approved' ? 'approved' : 'returned',
            $remarks
        );
        if (!$entry) {
            $error = 'Journal entry not found.';
        } else {
            log_activity($user['id'], 'Journal ' . ucfirst($status), "Journal ID: $jid for {$entry['student_name']}");
            create_notification(
                $entry['user_id'],
                "Your journal entry for " . date('M d, Y', strtotime($entry['entry_date'])) . " was " . ($status === 'approved' ? 'approved' : 'returned with remarks') . ".",
                'info',
                '/ojtrack/student/journal.php'
            );
            $success = "Journal entry for " . date('M d, Y', strtotime($entry['entry_date'])) . " marked as " . $status . ".";
        }
    }
}

$student_id = isset($_GET['student']) ? (int)$_GET['student'] : 0;
$search = trim($_GET['q'] ?? '');

if ($student_id) {
    $sel_student = normalized_student_context($student_id);
    $enrollment = $sel_student ? normalized_enrollment_for_student($student_id) : null;
    $assignment = $enrollment ? normalized_current_coordinator_for_enrollment((int)$enrollment['id']) : null;
    if (!$sel_student || !$assignment || (int)$assignment['coordinator_id'] !== (int)$coord['id']) {
        $sel_student = null;
        $journals = [];
    } else {
        $journals = normalized_journal_rows_for_student($student_id);
    }
} else {
    $students = normalized_students_for_coordinator((int)$coord['id'], $search);
    foreach ($students as &$studentRow) {
        $journalRows = normalized_journal_rows_for_student((int)$studentRow['id']);
        $studentRow['pending_journals'] = count(array_filter($journalRows, fn($j) => $j['status'] === 'pending'));
        $studentRow['total_journals'] = count($journalRows);
    }
    unset($studentRow);
    usort($students, fn($a,$b) => ($b['pending_journals'] <=> $a['pending_journals']) ?: strcasecmp($a['name'],$b['name']));
}

$page_title = 'Student Monitoring';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Daily Journals</div>
    <div class="page-sub">Monitor student progress, journals, hours, and provide mentoring feedback</div>
  </div>
  <?php if ($student_id): ?>
    <a href="/ojtrack/coordinator/monitoring.php" class="btn btn-ghost btn-sm">← Back to All Students</a>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($student_id && isset($sel_student)): ?>
<div class="grid" style="grid-template-columns:300px 1fr;gap:20px">
  <div>
    <div class="card card-body mb-4">
      <div class="text-center mb-4">
        <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1d4ed8);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff"><?= strtoupper(substr($sel_student['name'],0,2)) ?></div>
        <div class="font-bold text-base"><?= e($sel_student['name']) ?></div>
        <div class="text-xs text-muted"><?= e($sel_student['student_id_no'] ?? '') ?></div>
        <div class="text-xs text-muted mb-2"><?= e($sel_student['company_name'] ?? 'No Company Assigned') ?></div>
        <?= status_badge($sel_student['ojt_status']) ?>
      </div>
      <?php $pct = $sel_student['required_hours']>0?min(100,round(($sel_student['rendered_hours']/$sel_student['required_hours'])*100)):0; ?>
      <div class="progress-wrap">
        <div class="progress-label"><span class="text">Rendered Hours</span><span class="pct"><?= $pct ?>%</span></div>
        <div class="progress-track"><div class="progress-fill <?= $pct>=100?'green':($pct>=50?'blue':'amber') ?>" data-pct="<?= $pct ?>"></div></div>
      </div>
      <div style="margin-top:10px;font-size:12px;color:var(--text-600);text-align:center"><?= number_format($sel_student['rendered_hours'],1) ?> / <?= $sel_student['required_hours'] ?>h completed</div>
    </div>
    <div class="card card-body">
      <div class="section-title mb-2">Actions</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <a href="/ojtrack/coordinator/students.php?id=<?= $sel_student['id'] ?>" class="btn btn-secondary btn-sm" style="width:100%;text-align:left">Manage Student Record</a>
        <a href="/ojtrack/coordinator/attendance.php?q=<?= urlencode($sel_student['name']) ?>" class="btn btn-secondary btn-sm" style="width:100%;text-align:left">View Attendance</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header flex-between">
      <div class="card-title">Daily Journal Entries (<?= count($journals) ?>)</div>
    </div>
    <?php if (empty($journals)): ?>
      <div class="text-center text-muted py-8"><p>No journal entries submitted by this student yet.</p></div>
    <?php else: ?>
      <?php foreach ($journals as $j): ?>
      <div style="padding:18px 20px;border-bottom:1px solid var(--border-light)">
        <div class="flex-between mb-2">
          <div>
            <span class="font-bold text-sm" style="color:var(--text-900)"><?= date('F d, Y · l', strtotime($j['entry_date'])) ?></span>
            <span class="badge" style="background:var(--bg);color:var(--text-700);margin-left:8px;font-size:11px">Week <?= $j['week_number'] ?></span>
            <span class="text-xs text-muted" style="margin-left:8px"><?= number_format($j['hours_rendered'],1) ?>h rendered</span>
          </div>
          <?= status_badge($j['status']) ?>
        </div>
        <div style="margin:10px 0;background:var(--bg);padding:12px 14px;border-radius:var(--radius);font-size:13px;line-height:1.6;color:var(--text-800)">
          <div class="text-xs font-bold text-muted mb-1 uppercase" style="letter-spacing:0.5px">Activities &amp; Learning</div>
          <?= nl2br(e($j['activities'])) ?>
        </div>

        <?php if ($j['status'] === 'pending'): ?>
        <form method="POST" style="margin-top:14px;padding-top:12px;border-top:1px dashed var(--border)"><?= csrf_field() ?>
          <input type="hidden" name="action" value="review_journal">
          <input type="hidden" name="journal_id" value="<?= $j['id'] ?>">
          <div class="form-group mb-2">
            <label class="form-label text-xs">Feedback / Mentor Notes (Optional)</label>
            <textarea name="remarks" class="form-control" rows="2" placeholder="Leave remarks or guidance for student..."></textarea>
          </div>
          <div style="display:flex;gap:8px">
            <button type="submit" name="status" value="approved" class="btn btn-success btn-xs">Approve Entry</button>
            <button type="submit" name="status" value="rejected" class="btn btn-danger btn-xs">Return Entry</button>
          </div>
        </form>
        <?php else: ?>
          <?php if (!empty($j['coordinator_remarks'])): ?>
          <div style="background:#f0fdf4;border-left:3px solid var(--success);padding:8px 12px;border-radius:0 var(--radius) var(--radius) 0;margin-top:8px">
            <div class="text-xs font-bold text-success mb-1">COORDINATOR REMARKS</div>
            <div style="font-size:12px;color:var(--text-800)"><?= nl2br(e($j['coordinator_remarks'])) ?></div>
            <?php if (!empty($j['reviewed_at'])): ?><div class="text-xs text-muted mt-1">Reviewed on <?= date('M d, Y h:i A', strtotime($j['reviewed_at'])) ?></div><?php endif; ?>
          </div>
          <?php endif; ?>
          <div style="margin-top:8px">
            <button type="button" class="btn btn-ghost btn-xs text-muted" onclick="toggleEditRemarks(<?= $j['id'] ?>)">Change Feedback / Status</button>
            <form id="editForm_<?= $j['id'] ?>" method="POST" style="display:none;margin-top:8px;padding-top:8px;border-top:1px dashed var(--border)"><?= csrf_field() ?>
              <input type="hidden" name="action" value="review_journal">
              <input type="hidden" name="journal_id" value="<?= $j['id'] ?>">
              <div class="form-group mb-2">
                <textarea name="remarks" class="form-control" rows="2"><?= e($j['coordinator_remarks'] ?? '') ?></textarea>
              </div>
              <div style="display:flex;gap:6px">
                <button type="submit" name="status" value="approved" class="btn btn-success btn-xs">Approve</button>
                <button type="submit" name="status" value="rejected" class="btn btn-danger btn-xs">Return</button>
                <button type="button" class="btn btn-secondary btn-xs" onclick="toggleEditRemarks(<?= $j['id'] ?>)">Cancel</button>
              </div>
            </form>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleEditRemarks(id) {
  const f = document.getElementById('editForm_' + id);
  if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php else: ?>

<div class="card mb-4">
  <div class="card-header flex-between">
    <form method="GET" style="display:flex;gap:8px">
      <div class="search-wrap" style="width:260px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search student or company..." value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-secondary">Filter</button>
      <?php if ($search): ?><a href="/ojtrack/coordinator/monitoring.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
    <div class="text-sm text-muted"><?= count($students) ?> students under supervision</div>
  </div>
</div>

<div class="grid grid-3 gap-4">
  <?php foreach ($students as $s): $pct = $s['required_hours']>0?min(100,round(($s['rendered_hours']/$s['required_hours'])*100)):0; ?>
  <div class="card card-body">
    <div class="flex-between mb-3">
      <div style="display:flex;gap:10px;align-items:center">
        <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff"><?= strtoupper(substr($s['name'],0,2)) ?></div>
        <div>
          <div class="font-bold text-sm"><?= e($s['name']) ?></div>
          <div class="text-xs text-muted"><?= e($s['company_name'] ?? 'No Company') ?></div>
        </div>
      </div>
      <?= status_badge($s['ojt_status']) ?>
    </div>
    <div class="progress-wrap mb-3">
      <div class="progress-label"><span class="text"><?= number_format($s['rendered_hours'],1) ?> / <?= $s['required_hours'] ?>h</span><span class="pct"><?= $pct ?>%</span></div>
      <div class="progress-track"><div class="progress-fill <?= $pct>=100?'green':($pct>=50?'blue':'amber') ?>" data-pct="<?= $pct ?>"></div></div>
    </div>
    <?php if ($s['pending_journals'] > 0): ?>
      <div class="alert alert-warn py-2 px-3 mb-3" style="font-size:12px;margin-bottom:12px">
        <strong><?= $s['pending_journals'] ?></strong> entry(ies) awaiting review
      </div>
    <?php else: ?>
      <div class="text-xs text-muted mb-3"><?= $s['total_journals'] ?> total journal entries logged</div>
    <?php endif; ?>
    <a href="?student=<?= $s['id'] ?>" class="btn btn-secondary btn-sm" style="width:100%;text-align:center">Review Journals →</a>
  </div>
  <?php endforeach; ?>
  <?php if (empty($students)): ?><div class="card card-body" style="grid-column:span 3"><div class="empty-state"><p>No students assigned under your supervision.</p></div></div><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
