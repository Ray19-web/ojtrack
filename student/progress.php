<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$student = query_one("SELECT s.*, u.name, co.company_name, cu.name AS supervisor_name, co.location
    FROM students s JOIN users u ON u.id=s.user_id
    LEFT JOIN companies co ON co.id=s.company_id
    LEFT JOIN users cu ON cu.id=co.user_id
    WHERE s.user_id=?", [$user['id']], 'i');

$pct        = $student['required_hours'] > 0 ? min(100, round(($student['rendered_hours'] / $student['required_hours']) * 100)) : 0;
$req_total  = query_one("SELECT COUNT(*) AS c FROM ojt_requirements WHERE student_id=?", [$student['id']], 'i')['c'];
$req_app    = query_one("SELECT COUNT(*) AS c FROM ojt_requirements WHERE student_id=? AND status='approved'", [$student['id']], 'i')['c'];
$rep_total  = query_one("SELECT COUNT(*) AS c FROM reports WHERE student_id=?", [$student['id']], 'i')['c'];
$rep_sub    = query_one("SELECT COUNT(*) AS c FROM reports WHERE student_id=? AND submitted_at IS NOT NULL", [$student['id']], 'i')['c'];
$eval_done  = query_one("SELECT COUNT(*) AS c FROM evaluations WHERE student_id=? AND status='completed'", [$student['id']], 'i')['c'];

$milestones = [
  ['label' => 'Requirements Submitted & Approved',   'done' => $req_app >= 6,   'date' => 'September 7, 2025'],
  ['label' => 'OJT Started',                          'done' => $student['ojt_start_date'] !== null,  'date' => $student['ojt_start_date'] ? date('F d, Y', strtotime($student['ojt_start_date'])) : '—'],
  ['label' => 'First 100 Hours Rendered',             'done' => $student['rendered_hours'] >= 100,    'date' => 'September 16, 2025'],
  ['label' => 'Mid-OJT Report Submitted',             'done' => $rep_sub >= 1,   'date' => 'September 30, 2025 (deadline)'],
  ['label' => 'Company Mid-Term Evaluation',          'done' => $eval_done >= 1, 'date' => 'October 10, 2025 (estimated)'],
  ['label' => 'Final 486 Hours Rendered',             'done' => $student['rendered_hours'] >= 486,    'date' => 'November 5, 2025 (estimated)'],
  ['label' => 'Final Report Submitted',               'done' => $rep_sub >= 3,   'date' => 'November 10, 2025 (estimated)'],
  ['label' => 'Final Company Evaluation',             'done' => $eval_done >= 2, 'date' => 'November 12, 2025 (estimated)'],
  ['label' => 'OJT Completed',                        'done' => $student['ojt_status'] === 'completed', 'date' => 'November 15, 2025 (estimated)'],
];

$page_title = 'OJT Progress';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">OJT Progress Tracker</div>
  <div class="page-sub">Your complete OJT journey from start to completion</div>
</div>

<div class="grid" style="grid-template-columns:2fr 1fr;gap:20px">
  <div>
    <div class="card card-body mb-4">
      <div class="section-title mb-4">Overall Progress</div>
      <div class="space-y-4">
        <div class="progress-wrap">
          <div class="progress-label"><span class="text">Hours Rendered</span><span class="pct"><?= $pct ?>%</span></div>
          <div class="progress-track"><div class="progress-fill blue" data-pct="<?= $pct ?>"></div></div>
        </div>
        <div class="progress-wrap">
          <div class="progress-label"><span class="text">Requirements Approved</span><span class="pct"><?= $req_total > 0 ? round(($req_app/$req_total)*100) : 0 ?>%</span></div>
          <div class="progress-track"><div class="progress-fill green" data-pct="<?= $req_total>0?round(($req_app/$req_total)*100):0 ?>"></div></div>
        </div>
        <div class="progress-wrap">
          <div class="progress-label"><span class="text">Reports Submitted</span><span class="pct"><?= $rep_total > 0 ? round(($rep_sub/$rep_total)*100) : 0 ?>%</span></div>
          <div class="progress-track"><div class="progress-fill amber" data-pct="<?= $rep_total>0?round(($rep_sub/$rep_total)*100):0 ?>"></div></div>
        </div>
        <div class="progress-wrap">
          <div class="progress-label"><span class="text">Evaluations Completed</span><span class="pct"><?= $eval_done > 0 ? 50 * $eval_done : 0 ?>%</span></div>
          <div class="progress-track"><div class="progress-fill violet" data-pct="<?= 50*$eval_done ?>"></div></div>
        </div>
      </div>
    </div>

    <div class="card card-body">
      <div class="section-title mb-4">OJT Milestone Tracker</div>
      <div class="milestone-list">
        <?php foreach ($milestones as $i => $m): ?>
        <div class="milestone-item">
          <?php if ($i < count($milestones) - 1): ?>
            <div class="milestone-line <?= $m['done'] ? 'done' : '' ?>"></div>
          <?php endif; ?>
          <div class="milestone-dot <?= $m['done'] ? 'done' : '' ?>">
          </div>
          <div class="milestone-label <?= $m['done'] ? '' : 'pending' ?>"><?= e($m['label']) ?></div>
          <div class="milestone-date <?= $m['done'] ? 'done' : '' ?>"><?= e($m['date']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card card-body">
      <div class="section-title mb-3">Company Information</div>
      <div class="space-y-3">
        <?php $details = ['Company' => $student['company_name'] ?? '—', 'Supervisor' => $student['supervisor_name'] ?? '—', 'Location' => $student['location'] ?? '—', 'OJT Start Date' => $student['ojt_start_date'] ? date('F d, Y', strtotime($student['ojt_start_date'])) : '—', 'Required Hours' => $student['required_hours'] . ' hours']; foreach ($details as $k => $v): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light)">
          <span class="text-xs text-muted"><?= $k ?></span>
          <span class="text-sm font-bold" style="color:var(--text-700);text-align:right;max-width:160px"><?= e($v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card card-body text-center">
      <div class="text-xs text-muted mb-2">Overall OJT Status</div>
      <div style="width:80px;height:80px;border-radius:50%;border:4px solid var(--primary-light);margin:0 auto 10px;display:flex;align-items:center;justify-content:center">
        <span style="font-family:var(--font-display);font-size:20px;font-weight:800;color:var(--primary)"><?= $pct ?>%</span>
      </div>
      <?= status_badge($student['ojt_status']) ?>
      <p class="text-xs text-muted mt-2"><?= $pct >= 75 ? 'On track for completion' : 'Needs more hours' ?></p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
