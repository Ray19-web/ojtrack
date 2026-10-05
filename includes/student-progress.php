<?php
if (!defined('OJTRACK') || ($user['role'] ?? '') !== 'student') { http_response_code(403); exit; }
$progress_sid = (int)$student['id'];
$progress_req_rows = normalized_requirement_rows_for_student($progress_sid);
$progress_rep_rows = normalized_report_rows_for_student($progress_sid);
$progress_eval_rows = normalized_eval_requests_for_student($progress_sid);
$progress_req = ['total'=>count($progress_req_rows),'done'=>count(array_filter($progress_req_rows,fn($row)=>($row['status'] ?? '')==='approved'))];
$progress_rep = ['total'=>count($progress_rep_rows),'done'=>count(array_filter($progress_rep_rows,fn($row)=>($row['status'] ?? '')==='approved'))];
$progress_eval = ['total'=>count($progress_eval_rows),'done'=>count(array_filter($progress_eval_rows,fn($row)=>($row['status'] ?? '')==='completed'))];
$progress_items = [
    ['Required hours', (float)$student['rendered_hours'], (float)$student['required_hours']],
    ['Requirements approved', (int)$progress_req['done'], (int)$progress_req['total']],
    ['Reports approved', (int)$progress_rep['done'], (int)$progress_rep['total']],
    ['Evaluations completed', (int)$progress_eval['done'], (int)$progress_eval['total']],
];
?>
<section class="card card-body mb-4" id="ojt-progress" aria-label="OJT Progress">
  <h2 class="section-title mb-3">OJT Progress</h2>
  <?php foreach ($progress_items as [$label, $done, $total]): $value = $total > 0 ? min(100, round($done / $total * 100)) : 0; ?>
    <div class="progress-wrap mb-3">
      <div class="progress-label"><span><?= e($label) ?></span><span><?= $total > 0 ? e($done . ' / ' . $total) : 'Not assigned' ?></span></div>
      <div class="progress-track"><div class="progress-fill blue" data-pct="<?= $value ?>"></div></div>
    </div>
  <?php endforeach; ?>
  <p class="text-xs text-muted">Progress reflects current assigned records. Final completion is confirmed by your coordinator.</p>
</section>
