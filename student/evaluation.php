<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);
$user = current_user();
$student = normalized_student_context_by_user((int)$user['id']);
$sid = (int)($student['id'] ?? 0);
$evaluations = normalized_eval_requests_for_student($sid);
$selectedEvaluation = null;
$evaluationId = (int)($_GET['evaluation'] ?? 0);
if ($evaluationId) {
    // Resolve through the signed-in student's enrollment before loading answers.
    foreach ($evaluations as $evaluation) {
        if ((int)$evaluation['id'] === $evaluationId) { $selectedEvaluation = $evaluation; break; }
    }
    if (!$selectedEvaluation) request_error(404, 'Evaluation not found.');
    if ($selectedEvaluation['status'] !== 'completed') request_error(409, 'Your supervisor has not completed this evaluation yet.');
    $answers = normalized_eval_answers_for_request($evaluationId);
    $rules = normalized_eval_rules((int)$selectedEvaluation['form_id']);
}
if (isset($_GET['download_pdf'])) {
    $pdfEvaluations = $selectedEvaluation ? [$selectedEvaluation] : $evaluations;
    foreach ($pdfEvaluations as &$pdfEvaluation) {
        if ($pdfEvaluation['status'] !== 'completed') continue;
        $pdfEvaluation['answers'] = $selectedEvaluation ? $answers : normalized_eval_answers_for_request((int)$pdfEvaluation['id']);
        $pdfEvaluation['rules'] = $selectedEvaluation ? $rules : normalized_eval_rules((int)$pdfEvaluation['form_id']);
    }
    unset($pdfEvaluation);
    require_once __DIR__ . '/../includes/evaluation-pdf.php';
    $pdf = student_evaluations_pdf($student ?? [], $pdfEvaluations);
    $filename = $selectedEvaluation ? 'OJT_Evaluation_'.$evaluationId.'.pdf' : 'OJT_Evaluations.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.strlen($pdf));
    echo $pdf; exit;
}
if (isset($_GET['result'])) {
    if (!$selectedEvaluation) request_error(404, 'Evaluation not found.');
    header('Cache-Control: private, no-store');
    require __DIR__ . '/../includes/student-evaluation-result.php';
    exit;
}
$issuedCertificate = normalized_certificate_for_student($sid);
$page_title = 'Evaluation';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
  <div class="page-title">OJT Evaluations</div>
  <div class="page-sub">View your company evaluations, scores, and feedback.</div>
</div>
<?php if ($issuedCertificate): ?>
<div class="card card-body mb-4 student-evaluation-certificate">
  <div><strong>Official Certificate Issued</strong><div class="text-sm text-muted">Your certificate is ready.</div></div>
  <a href="/ojtrack/student/certificate.php" class="btn btn-primary">View Certificate</a>
</div>
<?php endif; ?>
<section class="card student-evaluations" aria-label="Your evaluations">
  <?php if (!$evaluations): ?>
    <div class="empty-state"><p>No evaluations assigned yet.</p><p class="text-sm text-muted">Evaluations will appear here when your coordinator assigns them to your company.</p></div>
  <?php else: ?>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Evaluation list">
    <table class="student-evaluation-table" data-pdf-export-url="/ojtrack/student/evaluation.php?download_pdf=1" data-pdf-export-label="Evaluations (PDF)">
      <thead><tr><th scope="col">Evaluation</th><th scope="col">Company / Evaluator</th><th scope="col">Status</th><th scope="col">Completed on</th><th scope="col">Overall score</th><th scope="col">Action</th></tr></thead>
      <tbody>
      <?php foreach ($evaluations as $evaluation):
          $completed = $evaluation['status'] === 'completed';
          $url = '/ojtrack/student/evaluation.php?evaluation='.(int)$evaluation['id'];
          $kind = match ($evaluation['evaluation_kind']) { 'midterm' => 'Midterm evaluation', 'final' => 'Final evaluation', default => 'Coordinator-assigned evaluation' };
      ?>
        <tr <?= $completed ? 'data-evaluation-url="'.e($url).'" tabindex="0"' : '' ?>>
          <td><strong><?= e($evaluation['form_title']) ?></strong><span class="student-evaluation-secondary"><?= e($kind) ?></span></td>
          <td><?= e($evaluation['company_name'] ?? '—') ?><span class="student-evaluation-secondary"><?= e(trim((string)($evaluation['evaluator_name'] ?? '')) ?: 'Company supervisor') ?></span></td>
          <td><?= status_badge($evaluation['status']) ?></td>
          <td><?= $completed && !empty($evaluation['evaluated_at']) ? e(date('M d, Y', strtotime($evaluation['evaluated_at']))) : '—' ?></td>
          <td class="student-evaluation-score"><?= $completed && $evaluation['overall_score'] !== null ? e(number_format((float)$evaluation['overall_score'], 2)).(($evaluation['score_mode'] ?? '') === 'points' ? ' / 100' : '%') : '—' ?></td>
          <td><?php if ($completed): ?><a class="btn btn-secondary btn-sm" href="<?= e($url) ?>" data-view-evaluation aria-label="View results for <?= e($evaluation['form_title']) ?>">View results</a><?php else: ?><button type="button" class="student-evaluation-awaiting" data-locked-feedback="Results will be available after your company supervisor completes this evaluation.">Awaiting evaluation</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<p class="student-evaluation-help">Your coordinator determines which evaluations are required and when they are assigned.</p>
<dialog id="studentEvaluationDialog" class="student-evaluation-dialog" aria-labelledby="studentEvaluationDialogTitle">
  <header class="evaluation-wizard-header">
    <h2 class="modal-title" id="studentEvaluationDialogTitle">Review Evaluation</h2>
    <button type="button" class="evaluation-wizard-close" data-close-evaluation aria-label="Close evaluation">&times;</button>
  </header>
  <div class="evaluation-wizard-content evaluation-review-content" id="studentEvaluationDialogBody" aria-live="polite" aria-busy="false">
    <?php if ($selectedEvaluation) require __DIR__ . '/../includes/student-evaluation-result.php'; ?>
  </div>
  <footer class="student-evaluation-dialog-footer"><button type="button" class="btn btn-secondary" data-close-evaluation>Close</button><a class="btn btn-primary" id="studentEvaluationPdf" <?= $selectedEvaluation ? 'href="/ojtrack/student/evaluation.php?evaluation='.(int)$selectedEvaluation['id'].'&amp;download_pdf=1"' : 'hidden' ?>>Download PDF</a></footer>
</dialog>
<style>
.student-evaluation-certificate{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.student-evaluations{overflow:hidden}
.student-evaluation-help{margin:6px 0 0;font-size:12px;color:var(--text-500);line-height:1.6}
.student-evaluation-help{margin:16px 4px}
.student-evaluation-table{min-width:850px}
.student-evaluation-table td{padding-top:20px;padding-bottom:20px;vertical-align:middle}
.student-evaluation-table th:last-child,.student-evaluation-table td:last-child{text-align:right}
.student-evaluation-secondary{display:block;margin-top:5px;color:var(--text-500);font-size:12px}
.student-evaluation-table [data-evaluation-url]{cursor:pointer}
.student-evaluation-table [data-evaluation-url]:hover{background:var(--bg)}
.student-evaluation-table [data-evaluation-url]:focus-visible{outline:2px solid var(--primary);outline-offset:-3px}
.student-evaluation-score{font-weight:700;white-space:nowrap;color:var(--primary);font-variant-numeric:tabular-nums}
.student-evaluation-awaiting{border:0;background:transparent;color:var(--text-500);font:inherit;font-size:12px;cursor:help;text-align:right}
.student-evaluation-dialog{position:fixed;inset:0;width:min(850px,calc(100% - 32px));max-width:850px;max-height:calc(100dvh - 40px);margin:auto;padding:0;border:1px solid var(--border);border-radius:22px;background:var(--card);color:var(--text-800);box-shadow:0 24px 80px #10234433;overflow:hidden}
.student-evaluation-dialog[open]{display:flex;flex-direction:column}
.student-evaluation-dialog::backdrop{background:rgba(15,23,42,.48)}
.student-evaluation-dialog .evaluation-wizard-content{flex:1 1 auto;padding:20px 28px;scrollbar-gutter:stable}
.student-evaluation-dialog .evaluation-wizard-close{border:0;background:transparent;cursor:pointer}
.student-evaluation-dialog .evaluation-review-record strong{font-size:17px}
.student-evaluation-dialog-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex:0 0 auto;padding:16px 28px;border-top:1px solid var(--border-light);background:var(--card)}
.student-evaluation-dialog-footer [hidden]{display:none}
#studentEvaluationResult{display:grid;gap:14px}
@media(max-width:600px){.student-evaluation-dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px);border-radius:16px}.student-evaluation-dialog .evaluation-wizard-content{padding:16px}.student-evaluation-dialog-footer{padding:14px 16px}.student-evaluation-dialog .evaluation-review-table{min-width:0}.student-evaluation-dialog .evaluation-review-table th:nth-child(2),.student-evaluation-dialog .evaluation-review-table td:nth-child(2){width:62px}.student-evaluation-dialog .evaluation-review-table th:nth-child(3),.student-evaluation-dialog .evaluation-review-table td:nth-child(3){width:88px}}
</style>
<script>
(() => {
  const dialog = document.getElementById('studentEvaluationDialog');
  const body = document.getElementById('studentEvaluationDialogBody');
  const pdfLink = document.getElementById('studentEvaluationPdf');
  let request, opener, previousOverflow;
  function show() {
    if (!dialog.open) {
      previousOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      dialog.showModal();
    }
  }
  async function openResult(url, trigger) {
    opener = trigger;
    request?.abort();
    request = new AbortController();
    body.textContent = 'Loading evaluation results…';
    body.setAttribute('aria-busy', 'true');
    pdfLink.hidden = true;
    show();
    const resultUrl = new URL(url, location.href);
    resultUrl.searchParams.set('result', '1');
    try {
      const response = await fetch(resultUrl, {signal: request.signal, headers: {'X-Requested-With': 'XMLHttpRequest'}});
      if (!response.ok) throw new Error('Could not load evaluation.');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const content = parsed.getElementById('studentEvaluationResult');
      if (!content) throw new Error('Evaluation unavailable.');
      body.replaceChildren(document.importNode(content, true));
      resultUrl.searchParams.delete('result');
      resultUrl.searchParams.set('download_pdf', '1');
      pdfLink.href = resultUrl.href;
      pdfLink.hidden = false;
      body.scrollTop = 0;
      body.setAttribute('aria-busy', 'false');
    } catch (error) {
      if (error.name === 'AbortError') return;
      body.textContent = 'Could not load the results. Close this window and try again, or refresh the page to sign in again.';
      body.setAttribute('aria-busy', 'false');
    }
  }
  document.querySelector('.student-evaluations').addEventListener('click', event => {
    const row = event.target.closest('[data-evaluation-url]');
    if (!row || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (event.target.closest('a,button,input,select,textarea') && !event.target.closest('[data-view-evaluation]')) return;
    if (window.getSelection()?.toString()) return;
    event.preventDefault();
    openResult(row.dataset.evaluationUrl, event.target.closest('[data-view-evaluation]') || row);
  });
  document.querySelector('.student-evaluations').addEventListener('keydown', event => {
    if (event.target.matches('[data-evaluation-url]') && ['Enter', ' '].includes(event.key)) {
      event.preventDefault();
      openResult(event.target.dataset.evaluationUrl, event.target);
    }
  });
  dialog.querySelectorAll('[data-close-evaluation]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => {
    const bounds = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
  });
  dialog.addEventListener('close', () => {
    request?.abort();
    document.body.style.overflow = previousOverflow ?? '';
    if (opener?.isConnected) opener.focus();
    else if (new URL(location.href).searchParams.has('evaluation')) location.href = '/ojtrack/student/evaluation.php';
  });
  if (body.querySelector('#studentEvaluationResult')) show();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
