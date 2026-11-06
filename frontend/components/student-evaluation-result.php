<?php
if (!defined('OJTRACK')) { http_response_code(404); exit; }
// The controller resolves this request through the signed-in student's enrollment.
$answerGroups = [];
foreach ($answers as $answer) {
    $sectionTitle = trim((string)($answer['section_title'] ?? '')) ?: 'Evaluation Questions';
    $answerGroups[$sectionTitle][] = $answer;
}
$pointsMode = ($selectedEvaluation['score_mode'] ?? '') === 'points';
$overallScore = $selectedEvaluation['overall_score'];
$resultRule = $overallScore !== null ? normalized_eval_score_rule((float)$overallScore, $rules, $selectedEvaluation['score_mode']) : null;
$formatScore = static fn($value) => rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
?>
<div id="studentEvaluationResult">
  <div class="evaluation-review-record">
    <div class="evaluation-review-form-title"><?= e($selectedEvaluation['form_title']) ?></div>
    <strong><?= e($student['name'] ?? '') ?></strong>
    <span><?= e($student['student_id_no'] ?? '') ?><?= !empty($student['program']) ? ' · '.e($student['program']) : '' ?></span>
    <span><?= e($selectedEvaluation['company_name'] ?? '') ?> · <?= e(trim((string)($selectedEvaluation['evaluator_name'] ?? '')) ?: 'Company supervisor') ?></span>
    <?php if (!empty($selectedEvaluation['evaluated_at'])): ?><span>Completed <?= e(date('F d, Y', strtotime($selectedEvaluation['evaluated_at']))) ?></span><?php endif; ?>
  </div>
  <?php if (!$answerGroups): ?>
    <div class="empty-state"><p>No saved question scores are available for this evaluation.</p></div>
  <?php else: ?>
    <div class="evaluation-review-sections">
    <?php foreach ($answerGroups as $sectionTitle => $sectionAnswers): ?>
      <section class="evaluation-review-section">
        <h3><?= e($sectionTitle) ?></h3>
        <div class="evaluation-review-table-wrap" tabindex="0" role="region" aria-label="<?= e($sectionTitle) ?> scores">
          <table class="evaluation-review-table">
            <thead><tr><th scope="col">Question</th><th scope="col">Score</th><th scope="col">Rating</th></tr></thead>
            <tbody>
            <?php foreach ($sectionAnswers as $answer):
                $hasScore = $answer['score'] !== null;
                $maximum = $pointsMode ? max(1, (float)$answer['maximum_points']) : 100;
                $percent = $hasScore ? (float)$answer['score'] / $maximum * 100 : null;
                $rating = '';
                foreach ($rules as $rule) {
                    if ($percent !== null && $percent >= (float)$rule['score_min'] && $percent <= (float)$rule['score_max']) {
                        $rating = trim((string)($rule['description'] ?? ''));
                        if ($rating === '' && $rule['equivalent'] !== null) $rating = (string)$rule['equivalent'];
                        break;
                    }
                }
                if ($rating === '' && $hasScore && $answer['equivalent'] !== null) $rating = (string)$answer['equivalent'];
            ?>
              <tr><td><?= e($answer['criterion_label']) ?></td><td><?= $hasScore ? e($formatScore($answer['score']).' / '.$formatScore($maximum)) : '—' ?></td><td><?= $rating !== '' ? e($rating) : '—' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($selectedEvaluation['comments'])): ?><div class="evaluation-result-comments"><strong>Comments &amp; Feedback</strong><p><?= nl2br(e($selectedEvaluation['comments'])) ?></p></div><?php endif; ?>
  <div class="evaluation-review-overall"><strong><?= $pointsMode ? 'Overall Score' : 'Overall Average' ?></strong><span><?= $overallScore !== null ? e(number_format((float)$overallScore, 2)).($pointsMode ? ' / 100' : '%') : 'Not recorded' ?></span></div>
  <?php if (!empty($resultRule['description'])): ?><div class="evaluation-review-result"><?= e($resultRule['description']) ?></div><?php endif; ?>
  <?php if ($selectedEvaluation['overall_equivalent'] !== null): ?><div class="evaluation-review-equivalent">Overall equivalent: <?= e($selectedEvaluation['overall_equivalent']) ?></div><?php endif; ?>
</div>
