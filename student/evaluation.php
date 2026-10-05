<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$student = normalized_student_context_by_user((int)$user['id']);
$sid = (int)($student['id'] ?? 0);

$allEvaluations = normalized_eval_requests_for_student($sid);
$evaluations = [];
$assignments = [];
foreach ($allEvaluations as $evaluation) {
    if (in_array($evaluation['evaluation_kind'], ['midterm','final'], true)) {
        $answers = normalized_eval_answers_for_request((int)$evaluation['id']);
        $evaluation['technical_skills'] = null;
        $evaluation['work_ethic'] = null;
        $evaluation['communication'] = null;
        $evaluation['teamwork'] = null;
        $evaluation['initiative'] = null;
        $evaluation['adaptability'] = null;
        foreach ($answers as $answer) {
            $code = $answer['criterion_code'] ?? '';
            if (array_key_exists($code, $evaluation)) $evaluation[$code] = $answer['score'];
        }
        $evaluation['evaluation_type'] = $evaluation['evaluation_kind'];
        $evaluations[] = $evaluation;
    } else {
        $assignments[] = $evaluation;
    }
}

$placement = !empty($student['_enrollment_id'])
    ? normalized_current_placement_for_enrollment((int)$student['_enrollment_id'])
    : null;
$supervisor = $placement ? normalized_company_user((int)$placement['company_id']) : null;
$student['supervisor'] = $supervisor['name'] ?? 'Company Supervisor';
$issuedCertificate = normalized_certificate_for_student($sid);

$page_title = 'Evaluation';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading">
  <div class="page-title">OJT Evaluation</div>
  <div class="page-sub">View your company performance evaluation scores and feedback</div>
</div>

<?php if ($issuedCertificate): ?>
<div class="card card-body mb-4" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-color:#a7f3d0">
  <div>
    <div class="font-bold text-base mb-1" style="color:#059669">Official Certificate Issued</div>
    <div class="text-sm text-muted">Your permanent Certificate of Recognition is ready to view or download.</div>
  </div>
  <a href="/ojtrack/student/certificate.php" class="btn btn-primary">🎓 Download Certificate</a>
</div>
<?php endif; ?>

<div class="grid grid-2 gap-4">
  <?php if (!empty($assignments)): ?>
  <div class="card card-body" style="grid-column:span 2">
    <div class="section-title mb-3">Evaluation Forms from Coordinator</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Form</th><th>Company</th><th>Status</th><th>Score</th></tr></thead>
        <tbody>
          <?php foreach ($assignments as $a): ?>
          <tr>
            <td><strong><?= e($a['form_title']) ?></strong></td>
            <td><?= e($a['company_name'] ?? '—') ?></td>
            <td><?= status_badge($a['status']) ?></td>
            <td><?= $a['overall_score'] !== null ? number_format($a['overall_score'], 1) : '—' ?></td>
          </tr>
          <?php if ($a['status'] === 'completed' && !empty($a['comments'])): ?>
          <tr>
            <td colspan="4" style="background:var(--bg);font-size:12px;color:var(--text-700)">
              <strong>Supervisor's Remark:</strong> <?= nl2br(e($a['comments'])) ?>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php if (empty($evaluations) && empty($assignments)): ?>
    <div class="card card-body" style="grid-column:span 2">
      <div class="empty-state"><p>No evaluations available yet. Your company supervisor will evaluate you at mid-term and upon completion.</p></div>
    </div>
  <?php else: ?>
    <?php foreach ($evaluations as $ev): ?>
    <div class="card card-body">
      <div class="flex-between mb-4">
        <div>
          <div class="section-title"><?= ucfirst($ev['evaluation_type']) ?> Evaluation</div>
          <div class="section-sub">Evaluated by: <?= e($student['supervisor'] ?? 'Company Supervisor') ?> · <?= $ev['evaluated_at'] ? date('M d, Y', strtotime($ev['evaluated_at'])) : 'Pending' ?></div>
        </div>
        <?= status_badge($ev['status']) ?>
      </div>
      <?php if ($ev['status'] === 'completed'): ?>
      <?php
      $criteria = [
        'Technical Skills'              => $ev['technical_skills'],
        'Work Ethic & Punctuality'      => $ev['work_ethic'],
        'Communication Skills'          => $ev['communication'],
        'Teamwork & Collaboration'      => $ev['teamwork'],
        'Initiative & Problem Solving'  => $ev['initiative'],
        'Adaptability'                  => $ev['adaptability'],
      ];
      ?>
      <div class="space-y-4 mb-4">
        <?php foreach ($criteria as $label => $score): ?>
        <div>
          <div style="display:flex;justify-content:space-between;margin-bottom:5px">
            <span class="text-sm" style="font-weight:500;color:var(--text-700)"><?= $label ?></span>
            <span class="font-mono font-bold" style="color:var(--primary);font-size:13px"><?= $score ?>/100</span>
          </div>
          <div class="progress-track">
            <div class="progress-fill <?= $score >= 90 ? 'green' : ($score >= 80 ? 'blue' : 'amber') ?>" data-pct="<?= $score ?>"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($ev['comments']): ?>
      <div style="background:var(--bg);border-radius:var(--radius);padding:14px;margin-top:8px">
        <div class="form-label mb-2">Supervisor's Comments</div>
        <p style="font-size:13px;line-height:1.65;color:var(--text-700)"><?= nl2br(e($ev['comments'])) ?></p>
        <p class="text-xs text-muted mt-2">— <?= e($student['supervisor'] ?? '') ?>, <?= e($student['company_name'] ?? '') ?></p>
      </div>
      <?php endif; ?>
      <div style="margin-top:16px;text-align:center;padding-top:12px;border-top:1px solid var(--border-light)">
        <div class="text-muted text-xs mb-1">Overall Average Score</div>
        <div style="font-family:var(--font-display);font-size:36px;font-weight:800;color:var(--primary)"><?= number_format($ev['overall_score'], 1) ?></div>
        <div class="text-xs text-muted">/100</div>
      </div>
      <?php else: ?>
      <div class="empty-state"><p>Evaluation not yet completed.</p></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="card card-body">
    <div class="section-title mb-3">Evaluation Schedule</div>
    <div class="space-y-3">
      <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-light)">
        <span class="text-sm">Mid-Term Evaluation</span>
        <span class="text-sm font-bold"><?= e($student['ojt_start_date'] ? date('M d', strtotime($student['ojt_start_date'] . ' +30 days')) : 'TBD') ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:10px 0">
        <span class="text-sm">Final Evaluation</span>
        <span class="text-sm font-bold"><?= e($student['ojt_end_date'] ? date('M d, Y', strtotime($student['ojt_end_date'])) : 'Upon completion') ?></span>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
