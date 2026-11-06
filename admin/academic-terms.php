<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = '';
$error = '';
$form_identity_locked = false;
$terms_table = query_one(
    "SELECT 1 AS present FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='academic_terms'"
);
$enrollments_table = query_one(
    "SELECT 1 AS present FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ojt_enrollments'"
);
$terms_ready = (bool)$terms_table;
$enrollments_ready = (bool)$enrollments_table;
$allowed_semesters = ['1st', '2nd', 'summer'];
$form_values = [
    'id' => 0,
    'academic_year_start' => '',
    'academic_year_end' => '',
    'semester' => '',
    'starts_on' => '',
    'ends_on' => '',
];

$parse_term_date = static function ($value): string|false|null {
    if (!is_string($value) || trim($value) === '') return null;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) return false;
    return $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if (!$terms_ready) {
        $error = 'Academic term storage is not installed. Apply database migration 001 before managing terms.';
    } elseif ($action === 'save_term') {
        $form_values = [
            'id' => filter_var($_POST['term_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0,
            'academic_year_start' => is_string($_POST['academic_year_start'] ?? null) ? trim($_POST['academic_year_start']) : '',
            'academic_year_end' => is_string($_POST['academic_year_end'] ?? null) ? trim($_POST['academic_year_end']) : '',
            'semester' => is_string($_POST['semester'] ?? null) ? trim($_POST['semester']) : '',
            'starts_on' => is_string($_POST['starts_on'] ?? null) ? trim($_POST['starts_on']) : '',
            'ends_on' => is_string($_POST['ends_on'] ?? null) ? trim($_POST['ends_on']) : '',
        ];
        $year_start = filter_var($form_values['academic_year_start'], FILTER_VALIDATE_INT);
        $year_end = filter_var($form_values['academic_year_end'], FILTER_VALIDATE_INT);
        $starts_on = $parse_term_date($form_values['starts_on']);
        $ends_on = $parse_term_date($form_values['ends_on']);
        $term_id = (int)$form_values['id'];
        $existing = $term_id > 0
            ? query_one('SELECT id, academic_year_start, academic_year_end, semester FROM academic_terms WHERE id=?', [$term_id], 'i')
            : null;

        if ($term_id > 0 && !$existing) {
            $error = 'The academic term could not be found. Refresh the page and try again.';
        } elseif ($year_start === false || $year_end === false || $year_start < 1900 || $year_start > 2198 || $year_end !== $year_start + 1) {
            $error = 'Enter a valid academic year, with consecutive start and end years (for example, 2026 and 2027).';
        } elseif (!in_array($form_values['semester'], $allowed_semesters, true)) {
            $error = 'Choose 1st semester, 2nd semester, or summer.';
        } elseif ($starts_on === false || $ends_on === false || ($starts_on && $ends_on && $starts_on > $ends_on)) {
            $error = 'Enter valid term dates. The end date must be the same as or later than the start date.';
        } else {
            $enrollment_count = $existing && $enrollments_ready
                ? (int)(query_one('SELECT COUNT(*) AS total FROM ojt_enrollments WHERE academic_term_id=?', [$term_id], 'i')['total'] ?? 0)
                : 0;
            $identity_changed = $existing && (
                (int)$existing['academic_year_start'] !== $year_start ||
                (int)$existing['academic_year_end'] !== $year_end ||
                $existing['semester'] !== $form_values['semester']
            );

            if ($identity_changed && $enrollment_count > 0) {
                $form_identity_locked = true;
                $error = 'This term already has enrollments. Its academic year and semester cannot be changed because those records depend on it.';
            } else {
                try {
                    if ($existing) {
                        query(
                            'UPDATE academic_terms SET academic_year_start=?, academic_year_end=?, semester=?, starts_on=?, ends_on=? WHERE id=?',
                            [$year_start, $year_end, $form_values['semester'], $starts_on, $ends_on, $term_id],
                            'iisssi'
                        );
                        try { log_activity($user['id'], 'Academic Term Updated', sprintf('%d-%d %s semester', $year_start, $year_end, $form_values['semester'])); }
                        catch (Throwable $log_error) { error_log('OJTRACK academic term update was saved but activity logging failed.'); }
                        $success = 'Academic term updated.';
                    } else {
                        insert(
                            "INSERT INTO academic_terms (academic_year_start, academic_year_end, semester, starts_on, ends_on, status, created_by) VALUES (?, ?, ?, ?, ?, 'draft', ?)",
                            [$year_start, $year_end, $form_values['semester'], $starts_on, $ends_on, (int)$user['id']],
                            'iisssi'
                        );
                        try { log_activity($user['id'], 'Academic Term Created', sprintf('%d-%d %s semester', $year_start, $year_end, $form_values['semester'])); }
                        catch (Throwable $log_error) { error_log('OJTRACK academic term was created but activity logging failed.'); }
                        $success = 'Academic term created as a draft. Set it as active when it should become the current term.';
                    }
                } catch (mysqli_sql_exception $exception) {
                    $error = (int)$exception->getCode() === 1062
                        ? 'That academic year and semester already exists.'
                        : 'The academic term could not be saved. Please try again.';
                    error_log('OJTRACK academic term save failed (code ' . $exception->getCode() . ').');
                }
            }
        }
    } elseif ($action === 'activate_term') {
        $term_id = filter_var($_POST['term_id'] ?? null, FILTER_VALIDATE_INT);
        $term = $term_id ? query_one('SELECT id, academic_year_start, academic_year_end, semester FROM academic_terms WHERE id=?', [$term_id], 'i') : null;
        if (!$term) {
            $error = 'The academic term could not be found. Refresh the page and try again.';
        } else {
            $transaction_started = false;
            try {
                db()->begin_transaction();
                $transaction_started = true;
                query("UPDATE academic_terms SET status='closed' WHERE status='active' AND id<>?", [(int)$term_id], 'i');
                query("UPDATE academic_terms SET status='active' WHERE id=?", [(int)$term_id], 'i');
                db()->commit();
                $transaction_started = false;
                $success = 'The academic term is now active. It is the current term used across OJTRACK.';
                try { log_activity($user['id'], 'Academic Term Activated', sprintf('%d-%d %s semester', $term['academic_year_start'], $term['academic_year_end'], $term['semester'])); }
                catch (Throwable $log_error) { error_log('OJTRACK academic term was activated but activity logging failed.'); }
            } catch (Throwable $exception) {
                if ($transaction_started) db()->rollback();
                $error = 'The academic term could not be activated. Please try again.';
                error_log('OJTRACK academic term activation failed (code ' . $exception->getCode() . ').');
            }
        }
    }
}

$terms = [];
$active_term = null;
if ($terms_ready) {
    $enrollment_select = $enrollments_ready ? 'COUNT(oe.id)' : '0';
    $enrollment_join = $enrollments_ready ? 'LEFT JOIN ojt_enrollments oe ON oe.academic_term_id=at.id' : '';
    $terms = query(
        "SELECT at.*, {$enrollment_select} AS enrollment_count
         FROM academic_terms at {$enrollment_join}
         GROUP BY at.id
         ORDER BY (at.status='active') DESC, at.academic_year_start DESC, at.academic_year_end DESC,
                  FIELD(at.semester,'1st','2nd','summer'), at.id DESC"
    ) ?: [];
    foreach ($terms as $term) {
        if ($term['status'] === 'active') { $active_term = $term; break; }
    }
}

$page_title = 'Academic Terms';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Academic Terms</div>
    <div class="page-sub">Set the school year and semester used for OJT records.</div>
  </div>
  <?php if ($terms_ready): ?><button type="button" class="btn btn-primary" data-tour-term-create onclick="openModal('addAcademicTermModal')">+ Add Academic Term</button><?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error && !($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_term')): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if (!$terms_ready): ?>
  <section class="card academic-term-setup-message">
    <div class="academic-term-status-icon" aria-hidden="true">!</div>
    <div><h2>Academic term setup is not installed</h2><p>Run the preflight and apply <code>001_core_identity_and_terms</code> using the steps in <code>database/migrations/README.md</code>. This page will be ready after the migration creates the academic terms table.</p></div>
  </section>
<?php else: ?>
  <section class="card academic-term-current" data-tour-term-current aria-live="polite">
    <div class="academic-term-current-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v15H4zM8 3v4M16 3v4M4 10h16M8 14h3m-3 3h6"/></svg></div>
    <div class="academic-term-current-copy">
      <span class="academic-term-eyebrow">CURRENT ACADEMIC TERM</span>
      <?php if ($active_term): ?>
        <strong><?= (int)$active_term['academic_year_start'] ?>–<?= (int)$active_term['academic_year_end'] ?> · <?= e($active_term['semester'] === 'summer' ? 'Summer' : $active_term['semester'] . ' Semester') ?></strong>
        <span>New and current OJT records across OJTRACK use this term.</span>
      <?php else: ?>
        <strong>No term is active</strong>
        <span>Activate a term to set the current school year and semester used by OJTRACK.</span>
      <?php endif; ?>
    </div>
    <span class="academic-term-current-badge"><?= $active_term ? 'Active' : 'Needs setup' ?></span>
  </section>

  <section class="card academic-term-list-card">
    <header class="academic-term-list-heading">
      <div><h2>School years and semesters</h2><p>Create terms ahead of time, then activate one when it becomes current.</p></div>
      <span class="academic-term-count"><?= count($terms) ?> <?= count($terms) === 1 ? 'term' : 'terms' ?></span>
    </header>
    <?php if (!$enrollments_ready): ?><div class="academic-term-inline-note">Enrollment counts will appear after the term enrollment migration is installed.</div><?php endif; ?>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Academic terms">
      <table class="academic-terms-table">
        <thead><tr><th>Academic year</th><th>Semester</th><th>Term dates</th><th>Enrollments</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($terms as $term):
          $term_label = (int)$term['academic_year_start'] . '–' . (int)$term['academic_year_end'];
          $semester_label = $term['semester'] === 'summer' ? 'Summer' : $term['semester'] . ' Semester';
          $date_label = $term['starts_on'] || $term['ends_on']
              ? (($term['starts_on'] ? date('M j, Y', strtotime($term['starts_on'])) : 'Date not set') . ' – ' . ($term['ends_on'] ? date('M j, Y', strtotime($term['ends_on'])) : 'Date not set'))
              : 'Dates not set';
          $status_label = ucfirst($term['status']);
          $term_payload = [
              'id' => (int)$term['id'],
              'academic_year_start' => (int)$term['academic_year_start'],
              'academic_year_end' => (int)$term['academic_year_end'],
              'semester' => $term['semester'],
              'starts_on' => $term['starts_on'] ?? '',
              'ends_on' => $term['ends_on'] ?? '',
              'enrollment_count' => (int)$term['enrollment_count'],
          ];
          $term_payload_json = e(json_encode($term_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        ?>
          <tr class="academic-term-row-clickable" tabindex="0" aria-label="Edit <?= e($term_label . ' ' . $semester_label) ?>" title="Click to edit this academic term" data-academic-term-row data-term="<?= $term_payload_json ?>">
            <td><strong><?= e($term_label) ?></strong></td>
            <td><?= e($semester_label) ?></td>
            <td class="academic-term-date-cell"><?= e($date_label) ?></td>
            <td><?= number_format((int)$term['enrollment_count']) ?></td>
            <td><span class="academic-term-status is-<?= e($term['status']) ?>"><span></span><?= e($status_label) ?></span></td>
            <td>
              <div class="academic-term-actions">
                <button type="button" class="table-action-icon is-primary" title="Edit academic term" aria-label="Edit <?= e($term_label . ' ' . $semester_label) ?>" data-tour-term-edit
                  data-term="<?= $term_payload_json ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <?php if ($term['status'] !== 'active'): ?>
                <form method="POST" class="academic-term-activate-form" data-term-label="<?= e($term_label . ' ' . $semester_label) ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="activate_term"><input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                  <button type="submit" class="btn btn-secondary" data-tour-term-activate>Set active</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$terms): ?><tr><td colspan="6" class="text-center text-muted py-6">No academic terms yet. Add a school year and semester to get started.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <div class="academic-term-guidance"><strong>Changing the active term</strong><span>When you activate another term, the current term is closed and OJTRACK switches its current OJT views to the new term. Past term records remain linked to their original term.</span></div>

  <div class="modal-overlay" id="addAcademicTermModal" aria-hidden="true">
    <div class="modal academic-term-modal" role="dialog" aria-modal="true" aria-labelledby="academicTermModalTitle">
      <button type="button" class="academic-term-modal-close" onclick="closeModal('addAcademicTermModal')" aria-label="Close">&times;</button>
      <h2 class="modal-title" id="academicTermModalTitle">Add academic term</h2>
      <p class="modal-sub" id="academicTermModalSub">Create a school year and semester. New terms start as drafts.</p>
      <?php if ($error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_term'): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>
      <form method="POST" id="academicTermForm">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_term"><input type="hidden" name="term_id" id="academicTermId" value="<?= (int)$form_values['id'] ?>">
        <div class="academic-term-fields">
          <div class="form-group"><label class="form-label" for="academicYearStart">School year starts</label><input class="form-control" id="academicYearStart" name="academic_year_start" type="number" min="1900" max="2198" step="1" placeholder="2026" value="<?= e($form_values['academic_year_start']) ?>" <?= $form_identity_locked ? 'disabled' : '' ?> required></div>
          <div class="form-group"><label class="form-label" for="academicYearEnd">School year ends</label><input class="form-control" id="academicYearEnd" name="academic_year_end" type="number" min="1901" max="2199" step="1" placeholder="2027" value="<?= e($form_values['academic_year_end']) ?>" <?= $form_identity_locked ? 'disabled' : '' ?> required></div>
          <div class="form-group academic-term-semester-field"><label class="form-label" for="academicSemester">Semester</label><select class="form-control" id="academicSemester" name="semester" <?= $form_identity_locked ? 'disabled' : '' ?> required><option value="">Choose semester</option><option value="1st" <?= $form_values['semester'] === '1st' ? 'selected' : '' ?>>1st Semester</option><option value="2nd" <?= $form_values['semester'] === '2nd' ? 'selected' : '' ?>>2nd Semester</option><option value="summer" <?= $form_values['semester'] === 'summer' ? 'selected' : '' ?>>Summer</option></select></div>
          <div class="form-group"><label class="form-label" for="academicTermStart">Term starts <span class="text-muted">(optional)</span></label><input class="form-control" id="academicTermStart" name="starts_on" type="date" value="<?= e($form_values['starts_on']) ?>"></div>
          <div class="form-group"><label class="form-label" for="academicTermEnd">Term ends <span class="text-muted">(optional)</span></label><input class="form-control" id="academicTermEnd" name="ends_on" type="date" value="<?= e($form_values['ends_on']) ?>"></div>
        </div>
        <p class="academic-term-form-note" id="academicTermFormNote">The start and end year must be consecutive, such as 2026–2027.</p>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addAcademicTermModal')">Cancel</button><button type="submit" class="btn btn-primary" id="academicTermSaveButton">Save term</button></div>
      </form>
    </div>
  </div>
  <script>
  (function(){
    const modalId='addAcademicTermModal';
    const form=document.getElementById('academicTermForm');
    const fields={id:document.getElementById('academicTermId'),start:document.getElementById('academicYearStart'),end:document.getElementById('academicYearEnd'),semester:document.getElementById('academicSemester'),from:document.getElementById('academicTermStart'),to:document.getElementById('academicTermEnd')};
    const title=document.getElementById('academicTermModalTitle');
    const subtitle=document.getElementById('academicTermModalSub');
    const note=document.getElementById('academicTermFormNote');
    const save=document.getElementById('academicTermSaveButton');
    const openEditTerm=(serializedTerm)=>{
      let term;
      try { term=JSON.parse(serializedTerm||'{}'); } catch (_) { return; }
      fields.id.value=term.id||0;fields.start.value=term.academic_year_start||'';fields.end.value=term.academic_year_end||'';fields.semester.value=term.semester||'';fields.from.value=term.starts_on||'';fields.to.value=term.ends_on||'';
      title.textContent='Edit academic term';subtitle.textContent='Update its school year, semester, or dates.';
      note.textContent=Number(term.enrollment_count)>0?'This term has enrollments. Its school year and semester are locked to protect those records.':'The start and end year must be consecutive, such as 2026–2027.';
      fields.start.disabled=Number(term.enrollment_count)>0;fields.end.disabled=Number(term.enrollment_count)>0;fields.semester.disabled=Number(term.enrollment_count)>0;
      save.textContent='Save changes';openModal(modalId);
    };
    document.querySelector('[data-tour-term-create]')?.addEventListener('click',()=>{
      form.reset();fields.id.value='0';fields.start.disabled=false;fields.end.disabled=false;fields.semester.disabled=false;title.textContent='Add academic term';subtitle.textContent='Create a school year and semester. New terms start as drafts.';note.textContent='The start and end year must be consecutive, such as 2026–2027.';save.textContent='Save term';
    });
    document.querySelectorAll('[data-tour-term-edit]').forEach(button=>button.addEventListener('click',()=>openEditTerm(button.dataset.term)));
    document.querySelectorAll('[data-academic-term-row]').forEach(row=>{
      row.addEventListener('click',event=>{
        if(event.target.closest('button,a,input,select,textarea,form,[data-tour-term-edit]'))return;
        openEditTerm(row.dataset.term);
      });
      row.addEventListener('keydown',event=>{
        if(event.target!==row||!['Enter',' '].includes(event.key))return;
        event.preventDefault();openEditTerm(row.dataset.term);
      });
    });
    document.getElementById('academicTermForm')?.addEventListener('submit',()=>{
      for(const input of [fields.start,fields.end,fields.semester]) if(input.disabled){input.disabled=false;input.dataset.locked='1';}
    });
    document.querySelectorAll('.academic-term-activate-form').forEach(form=>form.addEventListener('submit',event=>{
      const term=form.dataset.termLabel||'this term';
      if(!window.confirm('Set '+term+' as the active academic term? This switches the current term used by OJTRACK, and closes the previously active term.'))event.preventDefault();
    }));
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '' && ($_POST['action'] ?? '') === 'save_term'): ?>
      title.textContent=<?= json_encode($form_values['id'] > 0 ? 'Edit academic term' : 'Add academic term') ?>;
      save.textContent=<?= json_encode($form_values['id'] > 0 ? 'Save changes' : 'Save term') ?>;
      document.addEventListener('DOMContentLoaded',()=>openModal(modalId));
    <?php endif; ?>
  })();
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
