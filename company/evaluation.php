<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';

require_once __DIR__ . '/../config/evaluations.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_submission') {
    try {
        submit_evaluation((int)$company['id'], (int)($_POST['submission_id'] ?? 0), $_POST, trim($_POST['comments'] ?? ''), (int)$user['id']);
        header('Location: /ojtrack/company/evaluation.php?done=1&esid=' . (int)($_POST['submission_id'] ?? 0));
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('OJTrack evaluation submission failed: ' . $exception->getMessage());
        $error = 'Evaluation was not saved. Please try again.';
    }
}

$submissions = normalized_eval_requests_for_company((int)$company['id']);
$pending_evaluation_count = count(array_filter($submissions, static fn($item) => $item['status'] === 'pending'));
$evaluated_count = count(array_filter($submissions, static fn($item) => $item['status'] === 'completed'));
$esid = isset($_GET['esid']) ? (int)$_GET['esid'] : (int)($_POST['submission_id'] ?? 0);
$sub = null;
foreach ($submissions as $submission) {
    if ((int)$submission['id'] === $esid) { $sub = $submission; break; }
}
$sub_sections = $sub ? normalized_eval_sections((int)$sub['form_id']) : [];
$sub_rules = $sub ? normalized_eval_rules((int)$sub['form_id']) : [];
$wizard_criteria = [];
foreach ($sub_sections as $section) {
    foreach (normalized_eval_criteria((int)$section['id']) as $criterion) {
        $criterion['section_title'] = $section['title'];
        $wizard_criteria[] = $criterion;
    }
}
$show_completion = isset($_GET['done']) && $_GET['done'] === '1' && $sub && $sub['status'] === 'completed';
$completion_answers = $show_completion ? normalized_eval_answers_for_request((int)$sub['id']) : [];

$page_title = 'Evaluations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Evaluations</div>
    <div class="page-sub">Rate trainee performance</div>
  </div>
</div>

<?php if ($error && !$sub): ?><div class="alert alert-error mb-4" role="alert"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($sub): ?>
<?php $stu_row = normalized_student_context((int)$sub['student_id']); ?>
<div class="modal-overlay evaluation-wizard-overlay" id="evaluationWizardModal">
  <section class="modal evaluation-wizard-modal <?= $sub['status'] === 'completed' && !$show_completion ? 'evaluation-review-modal' : '' ?>" aria-label="Evaluation form">
    <header class="evaluation-wizard-header">
      <div class="modal-title"><?= $show_completion ? 'Evaluation Submitted' : ($sub['status'] === 'completed' ? 'Review Evaluation' : 'Surveys / Assessments') ?></div>
      <a class="evaluation-wizard-close" href="/ojtrack/company/evaluation.php" aria-label="Close evaluation">×</a>
    </header>

    <div class="evaluation-wizard-content <?= $sub['status'] === 'completed' && !$show_completion ? 'evaluation-review-content' : '' ?>">
      <div class="evaluation-wizard-intro">
        <div class="evaluation-wizard-eyebrow"><?= e($sub['form_title']) ?></div>
        <h2><?= e($stu_row['name'] ?? $sub['student_name']) ?></h2>
        <div class="evaluation-wizard-course"><?= e($stu_row['program'] ?? $sub['program'] ?? '') ?></div>
      </div>

      <?php if ($error && $sub['status'] !== 'completed'): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

      <?php if ($show_completion): ?>
        <div class="evaluation-complete-state" role="status">
          <div class="evaluation-complete-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></div>
          <h2>Evaluation Submitted!</h2>
          <p>Thank you for evaluating <strong><?= e($stu_row['name'] ?? $sub['student_name']) ?></strong>.</p>
          <div class="evaluation-complete-meta"><?= count($completion_answers) ?> of <?= count($wizard_criteria) ?> questions answered (<?= count($wizard_criteria) > 0 ? (int)round(count($completion_answers) / count($wizard_criteria) * 100) : 0 ?>%)</div>
          <a href="/ojtrack/company/evaluation.php" class="btn btn-primary">Done</a>
        </div>
      <?php elseif ($sub['status'] === 'completed'): ?>
        <?php
          $answers = normalized_eval_answers_for_request((int)$sub['id']);
          $answerGroups = [];
          foreach ($answers as $answer) {
              $sectionTitle = trim((string)($answer['section_title'] ?? '')) ?: 'Evaluation Questions';
              $answerGroups[$sectionTitle][] = $answer;
          }
          $resultRule = normalized_eval_score_rule((float)$sub['overall_score'], $sub_rules, $sub['score_mode']);
          $overallDisplayValue = $sub['score_mode'] !== 'points' && $sub['overall_equivalent'] !== null
              ? (float)$sub['overall_equivalent']
              : (float)$sub['overall_score'];
          $overallDisplayUnit = $sub['score_mode'] === 'points'
              ? ' / 100'
              : ($sub['overall_equivalent'] === null ? '%' : '');
        ?>
        <div class="evaluation-review-record">
          <div class="evaluation-review-form-title"><?= e($sub['form_title']) ?></div>
          <strong><?= e($stu_row['name'] ?? $sub['student_name']) ?></strong>
          <span><?= e($sub['student_id_no'] ?? '') ?><?= !empty($stu_row['program']) || !empty($sub['program']) ? ' · ' . e($stu_row['program'] ?? $sub['program']) : '' ?></span>
        </div>

        <?php if (!$answerGroups): ?>
          <div class="empty-state"><p>No saved answers were found for this evaluation.</p></div>
        <?php else: ?>
          <div class="evaluation-review-sections">
            <?php foreach ($answerGroups as $sectionTitle => $sectionAnswers): ?>
            <section class="evaluation-review-section">
              <h3><?= e($sectionTitle) ?></h3>
              <div class="evaluation-review-table-wrap">
                <table class="evaluation-review-table">
                  <thead><tr><th scope="col">Question</th><th scope="col">Score</th><th scope="col">Rating</th></tr></thead>
                  <tbody>
                    <?php foreach ($sectionAnswers as $answer):
                      $answerScore = (float)$answer['score'];
                      $answerMaximum = $sub['score_mode'] === 'points' ? max(1, (float)$answer['maximum_points']) : 100.0;
                      $answerPercent = $sub['score_mode'] === 'points' ? $answerScore / $answerMaximum * 100 : $answerScore;
                      $answerRule = null;
                      foreach ($sub_rules as $rule) {
                          if ($answerPercent >= (float)$rule['score_min'] && $answerPercent <= (float)$rule['score_max']) {
                              $answerRule = $rule;
                              break;
                          }
                      }
                      $ratingLabel = trim((string)($answerRule['description'] ?? ''));
                      if ($ratingLabel === '' && $answerRule && $answerRule['equivalent'] !== null) {
                          $ratingLabel = rtrim(rtrim(number_format((float)$answerRule['equivalent'], 2, '.', ''), '0'), '.');
                      }
                      if ($ratingLabel === '' && $answer['equivalent'] !== null) {
                          $ratingLabel = rtrim(rtrim(number_format((float)$answer['equivalent'], 2, '.', ''), '0'), '.');
                      }
                    ?>
                    <tr>
                      <td><?= e($answer['criterion_label']) ?></td>
                      <td><?= rtrim(rtrim(number_format($answerScore, 2, '.', ''), '0'), '.') ?><?= $sub['score_mode'] === 'points' ? ' / ' . rtrim(rtrim(number_format((float)$answer['maximum_points'], 2, '.', ''), '0'), '.') : '' ?></td>
                      <td><?= $ratingLabel !== '' ? e($ratingLabel) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($sub['comments']): ?><div class="evaluation-result-comments"><strong>Comments &amp; Feedback</strong><p><?= nl2br(e($sub['comments'])) ?></p></div><?php endif; ?>
        <div class="evaluation-review-overall">
          <strong><?= $sub['score_mode'] === 'points' ? 'Overall Score' : 'Overall Average' ?></strong>
          <span><?= number_format($overallDisplayValue, 2) ?><?= $overallDisplayUnit ?></span>
        </div>
        <?php if ($resultRule && !empty($resultRule['description'])): ?><div class="evaluation-review-result"><?= e($resultRule['description']) ?></div><?php endif; ?>
        <?php if ($sub['score_mode'] === 'points' && $sub['overall_equivalent'] !== null): ?><div class="evaluation-review-equivalent">Overall equivalent: <?= e($sub['overall_equivalent']) ?></div><?php endif; ?>
        <footer class="evaluation-wizard-footer"><a href="/ojtrack/company/evaluation.php" class="btn btn-secondary">Close</a></footer>
      <?php elseif (empty($wizard_criteria)): ?>
        <div class="empty-state"><p>This evaluation form has no questions yet. Contact your coordinator for help.</p></div>
        <footer class="evaluation-wizard-footer"><a href="/ojtrack/company/evaluation.php" class="btn btn-secondary">Close</a></footer>
      <?php else: ?>
        <div class="evaluation-progress" aria-live="polite">
          <div class="evaluation-progress-label"><span data-wizard-counter>Question 1 of <?= count($wizard_criteria) ?></span><strong data-wizard-percent>0%</strong></div>
          <div class="evaluation-progress-track" role="progressbar" aria-label="Evaluation progress" aria-valuemin="0" aria-valuemax="<?= count($wizard_criteria) ?>" aria-valuenow="1"><span data-wizard-progress></span></div>
        </div>

        <form method="POST" class="evaluation-wizard-form" data-evaluation-wizard data-step-count="<?= count($wizard_criteria ?? []) ?>"
              data-confirm-title="Submit this evaluation?"
              data-confirm-message="Submit the completed evaluation for <?= e($stu_row['name'] ?? $sub['student_name']) ?>? You will not be able to edit it afterward."
              data-confirm-label="Submit Evaluation"><?= csrf_field() ?>
          <?php if (!isset($wizard_criteria)) { $wizard_criteria=[]; foreach ($sub_sections as $section) { foreach (normalized_eval_criteria((int)$section['id']) as $criterion) { $criterion['section_title']=$section['title']; $wizard_criteria[]=$criterion; } } } ?>
          <input type="hidden" name="action" value="submit_submission">
          <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
          <p class="evaluation-scoring-note"><?= $sub['score_mode']==='points' ? 'Add the awarded points for a total out of 100.' : 'Rate each criterion from 0 to 100. The overall score is the average rounded to the nearest whole number.' ?></p>
          <?php foreach ($wizard_criteria as $index => $criterion): $scoreMax=$sub['score_mode']==='points'?(int)$criterion['weight']:100; ?>
          <section class="evaluation-question-step" data-evaluation-step>
            <div class="evaluation-question-section"><?= e($criterion['section_title']) ?></div>
            <h2 class="evaluation-question-title"><span class="text-danger" aria-hidden="true">*</span> <?= e($criterion['label']) ?></h2>
            <div class="evaluation-answer-card">
              <?php if ($sub_rules): ?>
              <details class="evaluation-rating-guide">
                <summary><span aria-hidden="true">ⓘ</span> How to respond?</summary>
                <div class="evaluation-rating-guide-body">
                  <strong>Rating guide</strong>
                  <?php if ($sub['score_mode']==='points'): ?><div class="text-xs text-muted">Ranges represent the percentage of points possible for this criterion.</div><?php endif; ?>
                  <?php foreach ($sub_rules as $rule): ?>
                  <div class="evaluation-rating-rule"><span><?= e($rule['score_min']) ?>–<?= e($rule['score_max']) ?></span><span><?= e($rule['equivalent'] ?? $rule['description']) ?><?= !empty($rule['description']) && !empty($rule['equivalent']) ? ' — ' . e($rule['description']) : '' ?></span></div>
                  <?php endforeach; ?>
                </div>
              </details>
              <?php endif; ?>
              <label class="form-label" for="criterionScore<?= (int)$criterion['id'] ?>">Your rating <span class="text-danger">*</span></label>
              <div class="evaluation-score-input-row">
                <input type="number" class="form-control" id="criterionScore<?= (int)$criterion['id'] ?>" name="criterion_<?= (int)$criterion['id'] ?>" min="0" max="<?= $scoreMax ?>" step="1" inputmode="numeric" required value="<?= e($_POST['criterion_' . $criterion['id']] ?? '') ?>" aria-describedby="scoreHelp<?= (int)$criterion['id'] ?>">
                <span class="evaluation-score-limit">/ <?= $scoreMax ?><?= $sub['score_mode']==='points' ? ' pts' : '' ?></span>
              </div>
              <div class="text-xs text-muted" id="scoreHelp<?= (int)$criterion['id'] ?>">Enter a whole number from 0 to <?= $scoreMax ?><?= $sub['score_mode']==='points' ? ' points' : '' ?>.</div>
            </div>
            <?php if ($index === count($wizard_criteria)-1): ?>
            <div class="form-group evaluation-comments-group">
              <label class="form-label" for="evaluationComments">Other Comments and Suggestions <span class="text-muted">(Optional)</span></label>
              <textarea id="evaluationComments" name="comments" class="form-control" rows="4" placeholder="Write your comments or suggestions here..."><?= e($_POST['comments'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>
          </section>
          <?php endforeach; ?>
          <footer class="evaluation-wizard-footer">
            <button type="button" class="btn btn-secondary" data-wizard-previous disabled>‹ Previous</button>
            <button type="button" class="btn btn-primary" data-wizard-next>Next ›</button>
            <button type="submit" class="btn btn-primary" data-wizard-submit hidden>Submit Evaluation</button>
          </footer>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header"><div class="card-title">Evaluation Forms from OJT Coordinator</div></div>
  <div class="evaluation-list-toolbar" data-evaluation-list>
    <div class="evaluation-list-tabs" role="group" aria-label="Evaluation status">
      <button type="button" class="evaluation-list-tab is-active" data-evaluation-filter="pending" aria-pressed="true">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M8 11h8M8 15h5"/></svg> For Evaluation <span class="evaluation-list-count"><?= $pending_evaluation_count ?></span>
      </button>
      <button type="button" class="evaluation-list-tab" data-evaluation-filter="completed" aria-pressed="false">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg> Evaluated <span class="evaluation-list-count"><?= $evaluated_count ?></span>
      </button>
    </div>
    <div class="evaluation-list-tools">
      <label class="evaluation-list-search">
        <span class="sr-only">Search evaluations</span>
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
        <input type="search" class="form-control" data-evaluation-search placeholder="Search trainee..." autocomplete="off">
      </label>
    </div>
  </div>
  <div class="table-wrap" data-custom-pagination tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead><tr><th>Form</th><th>Student</th><th>Status</th><th>Score</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($submissions as $a): ?>
          <tr class="row-clickable" data-row-link="?esid=<?= (int)$a['id'] ?>" tabindex="0" aria-label="Open evaluation for <?= e($a['student_name']) ?>" title="Open evaluation"
              data-evaluation-row data-evaluation-status="<?= e($a['status']) ?>" data-evaluation-search="<?= e($a['form_title'].' '.$a['student_name'].' '.($a['student_id_no'] ?? '').' '.($a['program'] ?? '')) ?>">
            <td><strong><?= e($a['form_title']) ?></strong></td>
            <td><strong><?= e($a['student_name']) ?></strong><div class="text-xs text-muted"><?= e($a['student_id_no'] ?? '') ?> · <?= e($a['program'] ?? '') ?></div></td>
            <td><?= status_badge($a['status']) ?></td>
            <td><?= $a['overall_score'] !== null ? number_format($a['overall_score'], 1) : '—' ?></td>
            <td>
              <div class="table-actions">
                <a href="?esid=<?= $a['id'] ?>"
                   class="table-action-icon is-primary"
                   title="<?= $a['status'] === 'pending' ? 'Fill out evaluation' : 'View evaluation' ?>"
                   aria-label="<?= $a['status'] === 'pending' ? 'Fill out evaluation' : 'View evaluation' ?>">
                  <?= $a['status'] === 'pending' ? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h4"/></svg>' : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>' ?>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr class="evaluation-list-empty" data-evaluation-empty hidden><td colspan="5" class="text-center text-muted py-4"><?= empty($submissions) ? 'No evaluation forms have been sent to you.' : 'No evaluation requests match these filters.' ?></td></tr>
      </tbody>
    </table>
  </div>
  <div class="evaluation-list-pagination" data-evaluation-pagination>
    <span data-evaluation-range aria-live="polite"></span>
    <nav class="evaluation-list-page-numbers" data-evaluation-pages aria-label="Evaluation pages"></nav>
    <div class="evaluation-list-page-buttons">
      <button type="button" class="btn btn-secondary btn-sm" data-evaluation-prev disabled>Previous</button>
      <button type="button" class="btn btn-secondary btn-sm" data-evaluation-next disabled>Next</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const list = document.querySelector('[data-evaluation-list]');
  if (list) {
    const rows = Array.from(document.querySelectorAll('[data-evaluation-row]'));
    const tabs = Array.from(list.querySelectorAll('[data-evaluation-filter]'));
    const search = list.querySelector('[data-evaluation-search]');
    const empty = document.querySelector('[data-evaluation-empty]');
    const range = document.querySelector('[data-evaluation-range]');
    const pageNumbers = document.querySelector('[data-evaluation-pages]');
    const previousPage = document.querySelector('[data-evaluation-prev]');
    const nextPage = document.querySelector('[data-evaluation-next]');
    let activeStatus = 'pending';
    let currentPage = 1;

    function renderEvaluationList(resetPage) {
      if (resetPage) currentPage = 1;
      const term = (search?.value || '').trim().toLocaleLowerCase();
      const matches = rows.filter((row) => row.dataset.evaluationStatus === activeStatus &&
        (!term || row.dataset.evaluationSearch.toLocaleLowerCase().includes(term)));
      const size = 10;
      const pageCount = Math.max(1, Math.ceil(matches.length / size));
      currentPage = Math.min(currentPage, pageCount);
      const firstIndex = (currentPage - 1) * size;
      const visibleRows = new Set(matches.slice(firstIndex, firstIndex + size));

      rows.forEach((row) => { row.hidden = !visibleRows.has(row); });
      if (empty) empty.hidden = matches.length !== 0;
      if (range) range.textContent = '10 per page';
      if (previousPage) previousPage.disabled = currentPage <= 1 || matches.length === 0;
      if (nextPage) nextPage.disabled = currentPage >= pageCount || matches.length === 0;
      if (pageNumbers) {
        pageNumbers.replaceChildren();
        let pages;
        if (pageCount <= 7) pages = Array.from({length: pageCount}, (_, index) => index + 1);
        else if (currentPage <= 4) pages = [1, 2, 3, 4, 5, '…', pageCount];
        else if (currentPage >= pageCount - 3) pages = [1, '…', pageCount - 4, pageCount - 3, pageCount - 2, pageCount - 1, pageCount];
        else pages = [1, '…', currentPage - 1, currentPage, currentPage + 1, '…', pageCount];
        pages.forEach((page) => {
          if (page === '…') {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'table-pagination-ellipsis';
            ellipsis.textContent = '…';
            pageNumbers.appendChild(ellipsis);
            return;
          }
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'table-pagination-page' + (page === currentPage ? ' is-current' : '');
          button.textContent = String(page);
          button.setAttribute('aria-label', 'Page ' + page);
          if (page === currentPage) button.setAttribute('aria-current', 'page');
          button.addEventListener('click', () => { currentPage = page; renderEvaluationList(false); });
          pageNumbers.appendChild(button);
        });
      }
    }

    tabs.forEach((tab) => tab.addEventListener('click', () => {
      activeStatus = tab.dataset.evaluationFilter;
      tabs.forEach((item) => {
        const selected = item === tab;
        item.classList.toggle('is-active', selected);
        item.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
      renderEvaluationList(true);
    }));
    search?.addEventListener('input', () => renderEvaluationList(true));
    previousPage?.addEventListener('click', () => { currentPage -= 1; renderEvaluationList(false); });
    nextPage?.addEventListener('click', () => { currentPage += 1; renderEvaluationList(false); });
    rows.forEach((row) => row.addEventListener('keydown', (event) => {
      if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
      event.preventDefault();
      window.location.assign(row.dataset.rowLink);
    }));
    renderEvaluationList(true);
  }

  const modal = document.getElementById('evaluationWizardModal');
  const form = modal?.querySelector('[data-evaluation-wizard]');
  if (modal && typeof openModal === 'function') openModal(modal.id);
  if (!form) return;

  const steps = Array.from(form.querySelectorAll('[data-evaluation-step]'));
  const previous = form.querySelector('[data-wizard-previous]');
  const next = form.querySelector('[data-wizard-next]');
  const submit = form.querySelector('[data-wizard-submit]');
  const counter = modal.querySelector('[data-wizard-counter]');
  const percent = modal.querySelector('[data-wizard-percent]');
  const progress = modal.querySelector('[data-wizard-progress]');
  const progressTrack = modal.querySelector('[role="progressbar"]');
  let current = 0;

  function showStep(index, moveFocus) {
    current = Math.max(0, Math.min(index, steps.length - 1));
    steps.forEach((step, stepIndex) => { step.hidden = stepIndex !== current; });
    const shown = current + 1;
    const value = Math.round(shown / steps.length * 100);
    if (counter) counter.textContent = `Question ${shown} of ${steps.length}`;
    if (percent) percent.textContent = `${value}%`;
    if (progress) progress.style.width = `${value}%`;
    if (progressTrack) progressTrack.setAttribute('aria-valuenow', String(shown));
    if (previous) previous.disabled = current === 0;
    if (next) next.hidden = current === steps.length - 1;
    if (submit) submit.hidden = current !== steps.length - 1;
    if (moveFocus) steps[current].querySelector('input[type="number"]')?.focus();
  }

  next?.addEventListener('click', function () {
    const answer = steps[current].querySelector('input[type="number"]');
    if (answer && !answer.reportValidity()) return;
    showStep(current + 1, true);
  });
  previous?.addEventListener('click', () => showStep(current - 1, true));
  form.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && event.target.matches('input[type="number"]') && current < steps.length - 1) {
      event.preventDefault();
      next?.click();
    }
  });
  showStep(0, false);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


