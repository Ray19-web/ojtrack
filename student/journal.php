<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$uid     = (int)$user['id'];
$student = normalized_student_context_by_user($uid);
$sid     = (int)($student['id'] ?? 0);

$success = ''; $error = '';
$view = $_GET['view'] ?? 'list';
$entry_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_journal') {
        $date       = $_POST['entry_date'] ?? '';
        $activities = trim($_POST['activities'] ?? '');
        $learnings  = trim($_POST['learnings'] ?? '');
        $challenges = trim($_POST['challenges'] ?? '');
        $hours      = (float)($_POST['hours_rendered'] ?? 8.0);
        $edit_id    = (int)($_POST['edit_id'] ?? 0);

        $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed_date || $parsed_date->format('Y-m-d') !== $date || $date > date('Y-m-d') ||
            (!empty($student['ojt_start_date']) && $date < $student['ojt_start_date']) ||
            (!empty($student['ojt_end_date']) && $date > $student['ojt_end_date']) || $hours < 0 || $hours > 24) {
            $error = 'Use a valid training date and hours between 0 and 24.';
        }
        if ($edit_id) {
            $editable = normalized_journal_get($edit_id, $sid);
            if (!$editable || !in_array($editable['status'], ['pending','rejected'], true)) {
                request_error(403, 'This journal cannot be edited.');
            }
        }

        if (!$date || !$activities || !$learnings || !$challenges) $error = 'All fields are required.';
        $newProofKeys = [];
        $journalSaved = false;
        try {

        $additionalProofs = [];
        if (!$error && isset($_FILES['proof_image']['name']) && is_array($_FILES['proof_image']['name'])) {
            $batch = $_FILES['proof_image'];
            $firstUpload = null;
            foreach ($batch['name'] as $index => $name) {
                if (($batch['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if (($batch['error'][$index] ?? 1) !== UPLOAD_ERR_OK) { $error = 'An image could not be uploaded. Please select it again.'; break; }
                $upload = ['name'=>$name,'tmp_name'=>$batch['tmp_name'][$index],'error'=>$batch['error'][$index],'size'=>$batch['size'][$index],'type'=>$batch['type'][$index]];
                if ($firstUpload === null) { $firstUpload = $upload; continue; }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) { $error = 'Choose JPG, PNG, GIF, or WEBP images.'; break; }
                $filename = 'proof_' . $sid . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
                try {
                    if (!store_private_upload($upload['tmp_name'], 'journal_proofs', $filename)) throw new DomainException('Image upload failed.');
                    $newProofKeys[] = 'journal_proofs/'.$filename;
                    $additionalProofs[] = ['key'=>'journal_proofs/'.$filename,'name'=>$name];
                } catch (DomainException $exception) { $error = $exception->getMessage(); break; }
            }
            $_FILES['proof_image'] = $firstUpload ?? ['name'=>'','error'=>UPLOAD_ERR_NO_FILE];
        }
        $proof_image = null;
        $proof_name = null;
        if (!$error && !empty($_FILES['proof_image']['name']) && ($_FILES['proof_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $proof_name = $_FILES['proof_image']['name'];
            $ext = strtolower(pathinfo($proof_name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $error = 'Proof image must be a JPG, PNG, GIF, or WEBP file.';
            } else {
                $filename = 'proof_' . $sid . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
                try {
                    if (store_private_upload($_FILES['proof_image']['tmp_name'], 'journal_proofs', $filename)) {
                        $proof_image = 'journal_proofs/' . $filename;
                        $newProofKeys[] = $proof_image;
                    } else {
                        $error = 'Failed to upload proof image.';
                    }
                } catch (DomainException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        if ($error) {
            $view = 'form';
        } elseif (!$date || !$activities || !$learnings || !$challenges) {
            $error = 'All fields are required.';
            $view = 'form';
        } else {
            $start = !empty($student['ojt_start_date']) ? strtotime($student['ojt_start_date']) : strtotime($date);
            $week = (int)max(1, floor((strtotime($date) - $start) / (7 * 86400)) + 1);
            try {
                $savedId = normalized_journal_save(
                    $sid,$uid,$date,$week,$activities,$learnings,$challenges,$hours,
                    $edit_id,$proof_image,$proof_name,$additionalProofs
                );
                $journalSaved = true;
                log_activity($uid, $edit_id ? 'Journal Revision Submitted' : 'Journal Entry Submitted', "Date: $date (#$savedId)");
                $success = $edit_id
                    ? 'Journal revision submitted successfully. Earlier revisions were preserved.'
                    : 'Journal entry submitted for coordinator verification.';
                $view = 'list';
            } catch (DomainException $exception) {
                $error = $exception->getMessage();
                $view = 'form';
            }
        }
        } finally {
            if (!$journalSaved) {
                foreach ($newProofKeys as $key) {
                    try {
                        $newPath = resolve_private_document($key);
                        if ($newPath && !unlink($newPath)) error_log('OJTrack could not remove an unused journal upload.');
                    } catch (Throwable $cleanupError) {
                        error_log('OJTrack journal upload cleanup failed.');
                    }
                }
            }
        }
    } elseif ($action === 'delete_journal') {
        $error = 'Submitted journal history is retained for audit. Edit and resubmit the entry instead of deleting it.';
        $view = 'list';
    }
}

$entries = normalized_journal_rows_for_student($sid);
$approved = count(array_filter($entries, fn($e) => $e['status'] === 'approved'));
$pending  = count(array_filter($entries, fn($e) => $e['status'] === 'pending'));
$rejected = count(array_filter($entries, fn($e) => $e['status'] === 'rejected'));

$detail = null;
if ($view === 'detail' && $entry_id > 0) {
    $detail = normalized_journal_get($entry_id, $sid);
}

$edit_entry = null;
if ($view === 'edit' && $entry_id > 0) {
    $edit_entry = normalized_journal_get($entry_id, $sid);
    if ($edit_entry && in_array($edit_entry['status'], ['pending','rejected'], true)) $view = 'form';
    else $edit_entry = null;
}

$page_title = 'Daily Journal';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($view === 'detail' && $detail): ?>
<div id="journalDetailContent">
  <div class="mb-4 flex-between">
    <a href="/ojtrack/student/journal.php" class="btn btn-ghost btn-sm">← Back to Journal List</a>
  </div>

  <div class="card card-body mb-4">
    <div class="flex-between mb-4 pb-3 border-b">
      <div>
        <h2 class="font-display font-bold text-xl text-800"><?= date('F d, Y', strtotime($detail['entry_date'])) ?></h2>
        <p class="text-sm text-muted"><?= date('l', strtotime($detail['entry_date'])) ?> · Week <?= $detail['week_number'] ?> · <?= number_format($detail['hours_rendered'], 1) ?> hours rendered</p>
      </div>
      <div><?= status_badge($detail['status']) ?></div>
    </div>

    <div class="space-y-4">
      <div>
        <label class="form-label font-bold text-xs uppercase text-muted tracking-wider">Activities</label>
        <div class="p-4 bg-slate-50 rounded-lg text-sm text-700 leading-relaxed border">
          <?= nl2br(e($detail['activities'])) ?>
        </div>
      </div>

      <div>
        <label class="form-label font-bold text-xs uppercase text-primary tracking-wider">Learnings</label>
        <div class="p-4 bg-blue-50 rounded-lg text-sm text-700 leading-relaxed border border-blue-100">
          <?= nl2br(e($detail['learnings'])) ?>
        </div>
      </div>

      <div>
        <label class="form-label font-bold text-xs uppercase text-amber-600 tracking-wider">Challenges</label>
        <div class="p-4 bg-amber-50 rounded-lg text-sm text-700 leading-relaxed border border-amber-100">
          <?= nl2br(e($detail['challenges'])) ?>
        </div>
      </div>

      <?php if (!empty($detail['proof_image'])): ?>
      <div>
        <label class="form-label font-bold text-xs uppercase text-gray-600 tracking-wider">Attachment</label>
        <div class="journal-image-gallery">
        <?php foreach ($detail['proof_images'] ?? [['storage_key'=>$detail['proof_image']]] as $proof): ?>
        <a href="/ojtrack/download.php?file=<?= rawurlencode($proof['storage_key']) ?>" target="_blank">
          <img src="/ojtrack/download.php?file=<?= rawurlencode($proof['storage_key']) ?>&amp;preview=1" alt="<?= e($proof['original_filename'] ?? 'Proof') ?>" style="width:140px;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
        </a>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($detail['coordinator_remarks'])): ?>
        <div>
          <label class="form-label font-bold text-xs uppercase text-green-700 tracking-wider">Coordinator Feedback</label>
          <div class="p-4 bg-green-50 rounded-lg text-sm text-700 leading-relaxed border border-green-200">
            <?= nl2br(e($detail['coordinator_remarks'])) ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (in_array($detail['status'], ['pending', 'rejected'], true)): ?>
    <div class="journal-detail-actions">
      <a href="/ojtrack/student/journal.php?view=edit&id=<?= (int)$detail['id'] ?>" class="btn btn-primary">Edit Entry</a>
    </div>
  <?php endif; ?>
</div>
<?php elseif ($view === 'form'): ?>
  <div class="mb-4">
    <a href="/ojtrack/student/journal.php" class="btn btn-ghost btn-sm">← Cancel &amp; Return</a>
  </div>

  <?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

  <div class="card card-body">
    <div class="section-header mb-4">
      <div class="section-title"><?= $edit_entry ? 'Edit Pending Journal Entry' : 'New Daily Journal Entry' ?></div>
      <div class="section-sub">Record your daily work and learning.</div>
    </div>

    <form id="journalEntryForm" method="POST" action="/ojtrack/student/journal.php" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_journal">
      <?php if ($edit_entry): ?>
        <input type="hidden" name="edit_id" value="<?= $edit_entry['id'] ?>">
      <?php endif; ?>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Training Date <span class="text-danger">*</span></label>
          <input type="date" name="entry_date" class="form-control" value="<?= e($edit_entry['entry_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Hours Rendered</label>
          <input type="number" name="hours_rendered" class="form-control" min="1" max="16" step="0.5" value="<?= e($edit_entry['hours_rendered'] ?? 8) ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Activities &amp; Tasks Performed <span class="text-danger">*</span></label>
        <textarea name="activities" class="form-control" rows="5" placeholder="Tasks completed today" required><?= e($edit_entry['activities'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Learnings &amp; Skills Acquired <span class="text-danger">*</span></label>
        <textarea name="learnings" class="form-control" rows="4" placeholder="What did you learn?" required><?= e($edit_entry['learnings'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Challenges &amp; Solutions <span class="text-danger">*</span></label>
        <textarea name="challenges" class="form-control" rows="4" placeholder="Challenges and how you resolved them" required><?= e($edit_entry['challenges'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Activity photos <span class="text-muted">(optional)</span></label>
        <label class="journal-image-picker" data-custom-file-preview>+ Choose images<input type="file" name="proof_image[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp" aria-label="Choose activity images"></label>
        <div class="text-xs text-muted">Select multiple JPG, PNG, GIF, or WEBP images. Maximum 5 MB each.</div>
        <div class="journal-selected-images"></div>
        <?php if (!empty($edit_entry['proof_image'])): ?>
          <div class="attachment-preview" data-attachment-preview data-file-name="<?= e(basename($edit_entry['proof_image'])) ?>" data-file-url="/ojtrack/download.php?file=<?= e(rawurlencode($edit_entry['proof_image'])) ?>" data-preview-url="/ojtrack/download.php?file=<?= e(rawurlencode($edit_entry['proof_image'])) ?>&amp;preview=1" aria-live="polite" hidden></div>
          <div class="text-xs text-muted mt-1">Current proof (upload a new image to replace it).</div>
        <?php else: ?>
          <div class="text-xs text-muted mt-1">Activity photo or screenshot (optional).</div>
        <?php endif; ?>
      </div>

      <div class="form-actions-right">
        <a href="/ojtrack/student/journal.php" class="btn btn-secondary">Discard</a>
        <button type="submit" class="btn btn-primary"><?= $edit_entry ? 'Update Entry' : 'Submit Entry' ?></button>
      </div>
    </form>
  </div>

<?php else: ?>
  <div class="page-heading flex-between">
    <div>
      <div class="page-title">Daily Journal</div>
      <div class="page-sub">Activities, learning, and challenges</div>
    </div>
    <a href="/ojtrack/student/journal.php?view=form" class="btn btn-primary">+ Write Daily Entry</a>
  </div>

  <?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>

  <?php
  $today = date('Y-m-d');
  $today_entry = null;
  foreach ($entries as $entry) {
      if ($entry['entry_date'] === $today) { $today_entry = $entry; break; }
  }
  if (!$today_entry):
  ?>
    <div class="alert alert-warn mb-4 flex-between">
      <div class="flex-items-center gap-3">
        <div>
          <div class="font-bold text-sm">Today's journal entry has not been logged yet</div>
          <div class="text-xs text-muted">Keep your training log up to date for weekly review.</div>
        </div>
      </div>
      <a href="/ojtrack/student/journal.php?view=form" class="btn btn-primary btn-sm">Log Entry</a>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div class="card-title">Journal Entries <span class="text-sm text-muted">(<?= count($entries) ?>)</span></div>
    </div>
    <div>
      <?php if (empty($entries)): ?>
        <div class="empty-state py-12 text-center">
          <p class="text-muted">No journal entries recorded yet. Click below to document your first day.</p>
          <a href="/ojtrack/student/journal.php?view=form" class="btn btn-primary btn-sm mt-3">+ Write Entry</a>
        </div>
      <?php else: ?>
        <?php foreach ($entries as $e): ?>
          <a href="/ojtrack/student/journal.php?view=detail&id=<?= $e['id'] ?>" class="journal-list-row student-journal-row">
            <div class="flex-between">
              <div class="flex-items-center gap-3">
                <div class="journal-date-badge">
                  <span class="week">Wk <?= $e['week_number'] ?></span>
                  <span class="day"><?= date('D', strtotime($e['entry_date'])) ?></span>
                </div>
                <div>
                  <div class="font-bold text-sm text-800"><?= date('F d, Y', strtotime($e['entry_date'])) ?></div>
                  <div class="text-xs text-muted student-journal-preview"><?= e(substr($e['activities'], 0, 95)) ?>...</div>
                </div>
              </div>
              <div class="flex-items-center gap-3">
                <span class="font-mono text-xs text-muted font-bold"><?= number_format($e['hours_rendered'], 1) ?>h</span>
                <?= status_badge($e['status']) ?>
                <span class="text-muted text-sm">→</span>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<style>
.student-journal-row { display:block; padding:20px 24px; color:inherit; text-decoration:none; border-bottom:1px solid var(--border,#e5eaf0); transition:background .18s ease; }
.student-journal-row:last-child { border-bottom:0; }
.student-journal-row:hover { background:#f7f9fc; }
.student-journal-row:focus-visible { outline:2px solid #174781; outline-offset:-3px; }
.student-journal-row > .flex-between { display:flex; align-items:center; justify-content:space-between; gap:24px; }
.student-journal-row > .flex-between > div:first-child { display:flex; align-items:center; gap:16px; min-width:0; flex:1; }
.student-journal-row > .flex-between > div:first-child > div:last-child { min-width:0; }
.student-journal-row > .flex-between > div:last-child { display:flex; align-items:center; gap:16px; flex-shrink:0; }
.student-journal-row .journal-date-badge { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; width:64px; min-height:60px; flex-shrink:0; border-radius:10px; background:#edf2f8; color:#174781; }
.student-journal-row .journal-date-badge .week { font-size:11px; }
.student-journal-row .journal-date-badge .day { font-size:15px; font-weight:700; }
.student-journal-preview { margin-top:6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
@media(max-width:600px) {
 .student-journal-row { padding:16px; }
 .student-journal-row > .flex-between { flex-wrap:wrap; gap:12px; }
 .student-journal-row > .flex-between > div:first-child { flex-basis:100%; }
 .student-journal-row > .flex-between > div:last-child { margin-left:80px; }
}
</style><dialog id="journalDetailsDialog" aria-labelledby="journalDialogTitle">
  <header class="journal-dialog-header"><h2 id="journalDialogTitle">Journal entry</h2><button type="button" class="btn btn-secondary btn-sm" id="journalDialogClose" aria-label="Close journal details">×</button></header>
  <div id="journalDialogBody" aria-live="polite"></div><footer id="journalDialogFooter" hidden></footer>
</dialog>
<style>
#journalDetailsDialog { position:fixed; inset:0; margin:auto; width:min(820px,calc(100vw - 32px)); max-height:88vh; padding:0; border:1px solid #e2e8f0; border-radius:18px; color:var(--text-800,#172b49); background:var(--surface,#fff); }
#journalDetailsDialog { overflow:hidden; }
#journalDetailsDialog[open] { display:flex; flex-direction:column; }
#journalDetailsDialog .journal-dialog-header { flex-shrink:0; position:relative; padding:24px; }
#journalDialogBody { flex:1; min-height:0; max-height:none; overflow-y:auto; overscroll-behavior:contain; }
#journalDialogFooter { flex-shrink:0; padding:16px 24px; border-top:1px solid #e2e8f0; background:#fff; }
#journalDialogFooter[hidden] { display:none; }
#journalDialogFooter .journal-detail-actions { margin:0; padding:0; border:0; }#journalDetailsDialog::backdrop { background:rgba(15,23,42,.48); }
.journal-dialog-header { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:20px 24px; border-bottom:1px solid #e2e8f0; }
.journal-dialog-header h2 { margin:0; font-size:20px; }
#journalDialogBody { padding:24px; overflow:auto;  }
#journalDialogTitle { display:flex; flex-direction:column; gap:6px; }
#journalDialogTitle small { font-size:10px; letter-spacing:.12em; color:#71849f; }
#journalDialogTitle strong { font-size:20px; color:#123d73; }
#journalDialogTitle span { font-size:13px; font-weight:400; color:#71849f; }
.journal-dialog-header { background:#f8faff; align-items:flex-start; }
#journalDialogBody .card { border:0; padding:0; margin:0; background:transparent; }
#journalDialogBody .form-label { display:block; font-size:11px; font-weight:700; color:#344b6b; margin-bottom:8px; }
#journalDialogBody .space-y-4 > div > div { padding:16px; background:#f9fbfd; border:1px solid #e1e9f3; border-radius:10px; font-size:14px; line-height:1.65; overflow-wrap:anywhere; }
#journalDialogBody .space-y-4 > div:has(img) { padding:16px; border:1px solid #e1e9f3; border-radius:10px; }
#journalDialogBody img { display:block; object-fit:contain; }
#journalDialogBody .flex-between.mb-4 { margin-bottom:20px; }#journalDialogBody .card { box-shadow:none; }
.journal-detail-actions { display:flex; justify-content:flex-end; padding-top:20px; margin-top:20px; border-top:1px solid #e2e8f0; }
#journalDialogBody .space-y-4 > div + div { margin-top:20px; }
</style>
<script>
(() => {
 const dialog = document.getElementById('journalDetailsDialog');
 const body = document.getElementById('journalDialogBody'); const imageDialog = document.createElement('dialog');
 imageDialog.setAttribute('aria-label', 'Journal attachment preview');
 imageDialog.style.cssText = 'position:fixed;inset:0;margin:auto;width:min(1000px,94vw);max-height:92vh;padding:16px;border:0;border-radius:14px;background:#fff;overflow:auto;';
 const imageClose = document.createElement('button');
 imageClose.type = 'button'; imageClose.className = 'btn btn-secondary btn-sm'; imageClose.textContent = 'Close';
 imageClose.style.cssText = 'display:block;margin:0 0 12px auto;';
 const fullImage = document.createElement('img');
 fullImage.alt = 'Journal attachment'; fullImage.style.cssText = 'display:block;max-width:100%;max-height:78vh;margin:auto;object-fit:contain;';
 imageDialog.append(imageClose, fullImage); document.body.append(imageDialog);
 imageClose.addEventListener('click', () => imageDialog.close());
 imageDialog.addEventListener('click', event => { if (event.target === imageDialog) imageDialog.close(); });
 body.addEventListener('click', event => {
  const image = event.target.closest('a img');
  if (!image) return;
  event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation();
  fullImage.src = image.src;
  imageDialog.showModal();
 }, true);
 let request;
 document.getElementById('journalDialogClose').addEventListener('click', () => dialog.close());
 dialog.addEventListener('click', event => { if (event.target === dialog) { const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close(); } });
 dialog.addEventListener('close', () => request?.abort());
 document.querySelectorAll('.student-journal-row').forEach(link => link.addEventListener('click', async event => {
  event.preventDefault(); event.stopPropagation();
  request?.abort(); request = new AbortController();
  document.getElementById('journalDialogFooter').hidden = true;
  document.getElementById('journalDialogTitle').textContent = 'Journal entry';
  body.textContent = 'Loading journal entry…'; dialog.showModal();
  try {
   const response = await fetch(link.href, {signal:request.signal});
   if (!response.ok) throw new Error('Unable to load');
   const page = new DOMParser().parseFromString(await response.text(), 'text/html');
   const content = page.getElementById('journalDetailContent');
   if (!content) throw new Error('Entry unavailable');
   content.querySelector('a[href="/ojtrack/student/journal.php"]')?.remove();
   const heading = content.querySelector('h2');
   const metadata = heading?.nextElementSibling;
   const title = document.getElementById('journalDialogTitle');
   title.replaceChildren();
   const eyebrow = document.createElement('small'); eyebrow.textContent = 'DAILY JOURNAL';
   const dateTitle = document.createElement('strong'); dateTitle.textContent = heading?.textContent || 'Journal entry';
   const subtitle = document.createElement('span'); subtitle.textContent = metadata?.textContent || '';
   title.append(eyebrow, dateTitle, subtitle);
   heading?.parentElement.remove();
   content.querySelector(':scope > .mb-4.flex-between')?.remove();
   const footer = document.getElementById('journalDialogFooter');
   const actions = content.querySelector('.journal-detail-actions');
   footer.replaceChildren();
   footer.hidden = !actions;
   if (actions) footer.append(actions);
   body.replaceChildren(content);
  } catch (error) { if (error.name !== 'AbortError') body.textContent = 'Could not load this entry. Close this window and try again.'; }
 }));
})();
</script><dialog id="journalWriteDialog" aria-labelledby="journalWriteTitle">
 <header class="journal-dialog-header"><div><h2 id="journalWriteTitle">Write daily entry</h2><p class="text-sm text-muted" style="margin:6px 0 0">Record your activities, learning, and challenges.</p></div><button type="button" class="btn btn-secondary btn-sm" data-close-write aria-label="Close entry form">×</button></header>
 <div id="journalWriteBody" aria-live="polite"></div>
 <footer class="journal-write-footer"><button type="button" class="btn btn-secondary" data-close-write>Cancel</button><button type="submit" form="journalEntryForm" class="btn btn-primary" id="journalWriteSubmit" disabled>Submit Entry</button></footer>
</dialog>
<style>
#journalWriteDialog { position:fixed; inset:0; margin:auto; width:min(760px,calc(100vw - 32px)); max-height:90vh; padding:0; border:1px solid #e2e8f0; border-radius:18px; background:#fff; color:#172b49; overflow:hidden; }
#journalWriteDialog[open] { display:flex; flex-direction:column; }
#journalWriteDialog::backdrop { background:rgba(15,23,42,.48); }
#journalWriteDialog header { flex-shrink:0; padding:24px; background:#f8faff; }
#journalWriteBody { padding:24px; overflow:auto; min-height:0; overscroll-behavior:contain; }
#journalWriteBody .form-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
#journalWriteBody .form-group { margin-bottom:20px; }
#journalWriteBody textarea { min-height:96px; resize:vertical; }
#journalWriteBody .form-label { display:block; font-size:13px; font-weight:600; margin-bottom:8px; }
.journal-write-footer { display:flex; justify-content:flex-end; gap:12px; padding:16px 24px; border-top:1px solid #e2e8f0; flex-shrink:0; }
@media(max-width:480px) { #journalWriteBody .form-row { grid-template-columns:1fr; gap:0; } #journalWriteBody { padding:18px; } }
</style>
<script>
(() => {
 const modal = document.getElementById('journalWriteDialog');
 const content = document.getElementById('journalWriteBody');
 const submit = document.getElementById('journalWriteSubmit');
 let controller;
 modal.querySelectorAll('[data-close-write]').forEach(button => button.addEventListener('click', () => modal.close()));
 modal.addEventListener('close', () => controller?.abort());
 document.addEventListener('click', async event => {
  const link = event.target.closest('a[href]');
  if (!link) return;
  const target = new URL(link.href, location.href);
  if (target.origin !== location.origin || target.pathname !== '/ojtrack/student/journal.php' || !['form', 'edit'].includes(target.searchParams.get('view'))) return;
  const editing = target.searchParams.get('view') === 'edit';
  event.preventDefault(); event.stopPropagation();
  controller?.abort(); controller = new AbortController();
  document.getElementById('journalWriteTitle').textContent = editing ? 'Edit daily entry' : 'Write daily entry';
  submit.textContent = editing ? 'Update Entry' : 'Submit Entry';
  content.textContent = 'Loading entry form…'; submit.disabled = true; modal.showModal();
  try {
   const response = await fetch(link.href, {signal:controller.signal});
   if (!response.ok) throw new Error();
   const page = new DOMParser().parseFromString(await response.text(), 'text/html');
   const form = page.getElementById('journalEntryForm');
   if (!form) throw new Error();
   form.querySelector('.form-actions-right')?.remove();
   form.querySelectorAll('textarea').forEach(field => field.rows = 3);
   content.replaceChildren(form); submit.disabled = false;
  } catch (error) { if (error.name !== 'AbortError') content.textContent = 'Could not load the form. Close this window and try again.'; }
 }, true);
})();
</script><style>
.journal-image-picker { display:flex; align-items:center; justify-content:center; position:relative; padding:24px; margin-bottom:8px; border:1px dashed #aebfd7; border-radius:12px; background:#f7faff; color:#174781; font-weight:600; cursor:pointer; }
.journal-image-picker:focus-within { outline:2px solid #174781; outline-offset:2px; }
.journal-image-picker input { position:absolute; inset:0; opacity:0; width:100%; cursor:pointer; }
.journal-selected-images,.journal-image-gallery { display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; }
.journal-selected-images figure { position:relative; margin:0; width:110px; }
.journal-selected-images img { width:110px; height:90px; object-fit:cover; border-radius:8px; }
.journal-selected-images figcaption { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:11px; }
.journal-selected-images button { position:absolute; top:4px; right:4px; border:0; border-radius:50%; background:#fff; width:24px; height:24px; cursor:pointer; }
</style>
<script>
document.addEventListener('change', event => {
 const input = event.target;
 if (!input.matches('input[name="proof_image[]"]')) return;
 const host = input.closest('.form-group').querySelector('.journal-selected-images');
 const render = () => {
  host.querySelectorAll('img').forEach(img => URL.revokeObjectURL(img.src)); host.replaceChildren();
  Array.from(input.files).forEach((file, index) => {
   const figure = document.createElement('figure'); const img = document.createElement('img');
   img.src = URL.createObjectURL(file); img.alt = file.name;
   const caption = document.createElement('figcaption'); caption.textContent = file.name;
   const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', 'Remove ' + file.name);
   remove.addEventListener('click', () => { const transfer = new DataTransfer(); Array.from(input.files).forEach((item, i) => { if (i !== index) transfer.items.add(item); }); input.files = transfer.files; render(); });
   figure.append(img, caption, remove); host.append(figure);
  });
 }; render();
});
</script><?php require_once __DIR__ . '/../includes/footer.php'; ?>
