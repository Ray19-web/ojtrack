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
    if (!$sel_student || !empty($sel_student['is_archived']) || ($sel_student['user_status'] ?? '') === 'archived' || !$assignment || (int)$assignment['coordinator_id'] !== (int)$coord['id']) {
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

// Bulk actions are limited to pending entries in the authorized student's current journal list.
if ($student_id && isset($sel_student) && $sel_student && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_review_journals') {
    $decision = ($_POST['status'] ?? '') === 'approved' ? 'approved' : 'returned';
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['journal_ids'] ?? [])), static fn($id) => $id > 0)));
    $pendingIds = array_fill_keys(array_map(static fn($journal) => (int)$journal['id'], array_filter($journals, static fn($journal) => $journal['status'] === 'pending')), true);
    $selectedIds = array_values(array_filter($selectedIds, static fn($id) => isset($pendingIds[$id])));

    if (!$selectedIds) {
        $error = 'Select at least one pending journal entry.';
    } elseif ($decision === 'returned' && $remarks === '') {
        $error = 'Add feedback before returning the selected entries.';
    } else {
        $reviewedCount = 0;
        foreach ($selectedIds as $journalId) {
            $entry = normalized_journal_review($journalId, (int)$coord['id'], (int)$user['id'], $decision, $decision === 'approved' ? '' : $remarks);
            if (!$entry) continue;
            $reviewedCount++;
            log_activity($user['id'], 'Journal ' . ucfirst($decision), "Journal ID: $journalId for {$entry['student_name']}");
            create_notification(
                $entry['user_id'],
                'Your journal entry for ' . date('M d, Y', strtotime($entry['entry_date'])) . ' was ' . ($decision === 'approved' ? 'approved.' : 'returned with feedback.'),
                'info',
                '/ojtrack/student/journal.php'
            );
        }
        if ($reviewedCount > 0) {
            $success = $decision === 'approved'
                ? "$reviewedCount journal " . ($reviewedCount === 1 ? 'entry was' : 'entries were') . ' approved.'
                : "$reviewedCount journal " . ($reviewedCount === 1 ? 'entry was' : 'entries were') . ' returned.';
            $journals = normalized_journal_rows_for_student($student_id);
        } else {
            $error = 'The selected entries could not be reviewed. Refresh and try again.';
        }
    }
}

$page_title = 'Student Monitoring';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Daily Journals</div>
    <?php if (!$student_id): ?><div class="page-sub">Review journals and progress</div><?php endif; ?>
  </div>
  <?php if ($student_id): ?>
    <a href="/ojtrack/coordinator/monitoring.php" class="btn btn-primary btn-sm monitoring-back-students" aria-label="Back to all students">
      <span aria-hidden="true">←</span><span>Back to All Students</span>
    </a>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($student_id && isset($sel_student)): ?>
<?php $journalWeeks = array_values(array_unique(array_map('intval', array_column($journals, 'week_number')))); rsort($journalWeeks, SORT_NUMERIC); ?>
<div class="journal-entries-layout">
  <div class="card">
    <div class="card-header flex-between journal-entries-header">
      <div class="card-title">Entries <span class="journal-entry-count"><?= count($journals) ?></span></div>
      <?php if (!empty($journals)): ?>
      <div class="journal-entry-filters" role="search" aria-label="Filter journal entries">
        <button type="button" class="journal-filter-icon" id="journalSearchToggle" aria-label="Search entries" title="Search entries" aria-expanded="false" aria-controls="journalEntrySearch"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 4.5 4.5"></path></svg></button>
        <input type="search" class="form-control" id="journalEntrySearch" placeholder="Search entries..." aria-label="Search journal entries" hidden>
        <div class="journal-filter-wrap">
        <button type="button" class="journal-filter-icon" id="journalFilterToggle" aria-label="Filter entries" title="Filter entries" aria-expanded="false" aria-pressed="false" aria-controls="journalFilterPopover"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.3l-3.6 1.8v-7.1L4 5z"></path></svg></button>
          <div class="journal-filter-popover" id="journalFilterPopover" hidden>
            <label for="journalStatusFilter">Status</label>
            <select class="form-control" id="journalStatusFilter" aria-label="Filter by status">
              <option value="">All statuses</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Returned</option>
            </select>
            <label for="journalWeekFilter">Week</label>
            <select class="form-control" id="journalWeekFilter" aria-label="Filter by week">
              <option value="">All weeks</option>
              <?php foreach ($journalWeeks as $week): ?><option value="<?= $week ?>">Week <?= $week ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="journal-filter-clear" id="journalFilterClear" title="Clear filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3"></path></svg><span>Clear filters</span></button>
          </div>
        </div>
        <span class="journal-filter-summary" id="journalFilterSummary" aria-live="polite">Showing <?= count($journals) ?> of <?= count($journals) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php $pendingJournalCount = count(array_filter($journals, static fn($journal) => $journal['status'] === 'pending')); ?>
    <?php if ($pendingJournalCount > 0): ?>
    <div class="journal-selection-toolbar" id="journalSelectionToolbar" hidden>
      <label class="journal-select-all"><input type="checkbox" id="journalSelectAll"><span>Select all matching</span></label>
      <span class="journal-selected-count" id="journalSelectedCount" aria-live="polite">0 selected</span>
      <div class="journal-bulk-actions">
        <button type="button" class="btn btn-success btn-sm" id="journalApproveSelected">Approve selected</button>
        <button type="button" class="btn btn-danger btn-sm" id="journalReturnSelected">Return selected</button>
      </div>
    </div>
    <form method="POST" id="journalBulkReviewForm" hidden><?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_review_journals">
      <input type="hidden" name="status" id="journalBulkStatus" value="approved">
      <input type="hidden" name="remarks" id="journalBulkRemarks" value="">
      <div id="journalBulkIds"></div>
    </form>
    <?php endif; ?>
    <?php if (empty($journals)): ?>
      <div class="text-center text-muted py-8"><p>No journal entries yet.</p></div>
    <?php else: ?>
      <div class="journal-card-grid" id="journalCardGrid">
      <?php foreach ($journals as $j): ?>
      <?php
        $proofUrl = !empty($j['proof_image']) ? '/ojtrack/download.php?file=' . rawurlencode($j['proof_image']) : '';
        $proofIsImage = str_starts_with((string)($j['proof_mime'] ?? ''), 'image/');
        $journalProofs = query("SELECT a.storage_key,a.original_filename,a.detected_mime FROM journal_revision_attachments jra JOIN attachments a ON a.id=jra.attachment_id WHERE jra.journal_revision_id=? ORDER BY a.id", [(int)$j['revision_id']], 'i') ?: [];
      ?>
      <article class="journal-card-item<?= $j['status'] === 'pending' ? ' has-selection' : '' ?>" data-journal-item>
      <?php if ($j['status'] === 'pending'): ?><label class="journal-card-select" title="Select this pending entry"><input type="checkbox" class="journal-entry-select" value="<?= (int)$j['id'] ?>" aria-label="Select journal entry for <?= e(date('M d, Y', strtotime($j['entry_date']))) ?>"><span>Select</span></label><?php endif; ?>
      <button type="button" class="journal-entry-card" data-journal-entry
        data-entry-id="<?= (int)$j['id'] ?>"
        data-entry-date="<?= e(date('F d, Y · l', strtotime($j['entry_date']))) ?>"
        data-entry-week="<?= (int)$j['week_number'] ?>"
        data-entry-hours="<?= e(number_format((float)$j['hours_rendered'], 1)) ?>"
        data-entry-status="<?= e($j['status']) ?>"
        data-entry-activities="<?= e($j['activities'] ?? '') ?>"
        data-entry-learnings="<?= e($j['learnings'] ?? '') ?>"
        data-entry-challenges="<?= e($j['challenges'] ?? '') ?>"
        data-entry-feedback="<?= e($j['coordinator_remarks'] ?? '') ?>"
        data-entry-reviewed="<?= e(!empty($j['reviewed_at']) ? date('M d, Y · h:i A', strtotime($j['reviewed_at'])) : '') ?>"
        data-entry-proof="<?= e($proofUrl) ?>"
        data-entry-proofs="<?= e(json_encode($journalProofs)) ?>"
        data-entry-proof-image="<?= $proofIsImage ? '1' : '0' ?>"
        data-entry-proof-name="<?= e($j['proof_filename'] ?? 'Journal attachment') ?>"
        aria-haspopup="dialog" aria-label="View journal entry for <?= e(date('M d, Y', strtotime($j['entry_date']))) ?>">
        <span class="journal-card-topline">
          <span class="journal-card-date"><?= e(date('M d, Y · l', strtotime($j['entry_date']))) ?></span>
          <?= status_badge($j['status']) ?>
        </span>
        <span class="journal-card-meta"><span>Week <?= (int)$j['week_number'] ?></span><span><?= number_format((float)$j['hours_rendered'], 1) ?>h</span></span>
        <span class="journal-card-preview"><?= e($j['activities'] ?? '') ?></span>
        <?php if ($proofUrl): ?><span class="journal-card-attachment">📎 <?= $proofIsImage ? 'Photo attached' : 'Attachment available' ?></span><?php endif; ?>
        <?php if ($proofUrl && $proofIsImage): ?><img class="journal-card-thumb" src="<?= e($proofUrl . '&preview=1') ?>" alt="" loading="lazy"><?php endif; ?>
        <span class="journal-card-open">View details <span aria-hidden="true">→</span></span>
      </button>
      </article>
      <?php endforeach; ?>
        <div class="journal-filter-empty" id="journalFilterEmpty" hidden>No entries match these filters.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-overlay" id="journalBulkReturnModal">
  <div class="modal journal-bulk-return-modal" role="dialog" aria-modal="true" aria-labelledby="journalBulkReturnTitle">
    <h2 class="modal-title" id="journalBulkReturnTitle">Return selected entries</h2>
    <p class="modal-sub">Add feedback for the selected journals. Students will receive this note.</p>
    <label class="form-label" for="journalBulkReturnFeedback">Feedback <span class="text-danger">*</span></label>
    <textarea class="form-control" id="journalBulkReturnFeedback" rows="4" required placeholder="Explain what needs to be revised…"></textarea>
    <div class="journal-review-actions">
      <button type="button" class="btn btn-secondary" onclick="closeModal('journalBulkReturnModal')">Cancel</button>
      <button type="button" class="btn btn-danger" id="journalBulkReturnConfirm">Return selected</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="journalDetailModal">
  <div class="modal modal-lg journal-detail-modal">
    <div class="journal-detail-header">
      <div>
        <span class="journal-detail-eyebrow">Daily journal</span>
        <h2 class="modal-title" id="journalDetailDate"></h2>
        <p class="modal-sub" id="journalDetailMeta"></p>
      </div>
      <button type="button" class="requirement-detail-close" onclick="closeModal('journalDetailModal')" aria-label="Close">&times;</button>
    </div>
    <div class="journal-detail-body">
      <div class="journal-detail-status" id="journalDetailStatus"></div>
      <section class="journal-detail-section"><h3>Activities</h3><div class="journal-detail-copy" id="journalDetailActivities"></div></section>
      <section class="journal-detail-section" id="journalLearningSection"><h3>Learnings</h3><div class="journal-detail-copy" id="journalDetailLearnings"></div></section>
      <section class="journal-detail-section" id="journalChallengeSection"><h3>Challenges</h3><div class="journal-detail-copy" id="journalDetailChallenges"></div></section>
      <section class="journal-detail-media" id="journalDetailMedia" hidden></section>
      <section class="journal-detail-feedback" id="journalDetailFeedback" hidden><h3>Feedback</h3><div id="journalDetailFeedbackText"></div><small id="journalDetailReviewed"></small></section>
      <button type="button" class="btn btn-ghost btn-sm journal-edit-review" id="journalEditReview" onclick="toggleJournalReview()" hidden>Edit review</button>
    </div>
    <form method="POST" class="journal-detail-footer" id="journalReviewForm" hidden><?= csrf_field() ?>
      <input type="hidden" name="action" value="review_journal">
      <input type="hidden" name="journal_id" id="journalReviewId" value="">
      <div class="journal-footer-feedback">
        <label class="form-label" for="journalReviewRemarks">Feedback <span class="text-muted">(optional)</span></label>
        <textarea name="remarks" id="journalReviewRemarks" class="form-control" rows="2" placeholder="Add feedback…"></textarea>
      </div>
      <div class="journal-review-actions">
        <button type="submit" name="status" value="rejected" class="btn btn-danger">Return</button>
        <button type="submit" name="status" value="approved" class="btn btn-success">Approve</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('click', function (event) {
  const card = event.target.closest('[data-journal-entry]');
  if (!card) return;
  const get = name => card.dataset[name] || '';
  document.getElementById('journalDetailDate').textContent = get('entryDate');
  document.getElementById('journalDetailMeta').textContent = `Week ${get('entryWeek')} · ${get('entryHours')}h`;
  document.getElementById('journalDetailActivities').textContent = get('entryActivities');
  document.getElementById('journalDetailLearnings').textContent = get('entryLearnings');
  document.getElementById('journalDetailChallenges').textContent = get('entryChallenges');
  document.getElementById('journalLearningSection').hidden = !get('entryLearnings').trim();
  document.getElementById('journalChallengeSection').hidden = !get('entryChallenges').trim();
  const status = get('entryStatus');
  const statusBadge = document.getElementById('journalDetailStatus');
  const statusLabels = {pending: 'Pending', approved: 'Approved', rejected: 'Returned'};
  statusBadge.className = `badge badge-${status === 'rejected' ? 'rejected' : status}`;
  statusBadge.textContent = statusLabels[status] || status;
  const media = document.getElementById('journalDetailMedia');
  media.replaceChildren();
  const proof = get('entryProof');
  let proofs = [];
  try { proofs = JSON.parse(get('entryProofs') || '[]'); } catch (_) {}
  if (!proofs.length && proof) proofs = [{url:proof,original_filename:get('entryProofName'),detected_mime:get('entryProofImage') === '1' ? 'image/' : ''}];
  if (proofs.length) {
    media.hidden = false;
    const heading = document.createElement('h3'); heading.textContent = `Attachments (${proofs.length})`; media.appendChild(heading);
    const gallery = document.createElement('div'); gallery.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;margin-top:12px;';
    proofs.forEach(item => {
      const url = item.url || '/ojtrack/download.php?file=' + encodeURIComponent(item.storage_key);
      const link = document.createElement('a'); link.href = url; link.target = '_blank'; link.rel = 'noopener';
      link.style.cssText = 'display:block;width:140px;overflow:hidden;';
      if ((item.detected_mime || '').startsWith('image/')) {
        const img = document.createElement('img'); img.src = url + '&preview=1'; img.alt = item.original_filename || 'Journal photo'; img.style.cssText = 'width:140px;height:120px;object-fit:cover;border-radius:8px;'; link.append(img);
      }
      const caption = document.createElement('span'); caption.textContent = item.original_filename || 'Attachment'; caption.style.cssText = 'display:block;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'; link.append(caption); gallery.append(link);
    });
    media.append(gallery);
  } else {
    media.hidden = true;
  }
  const feedback = get('entryFeedback');
  const feedbackPanel = document.getElementById('journalDetailFeedback');
  feedbackPanel.hidden = !feedback && !get('entryReviewed');
  document.getElementById('journalDetailFeedbackText').textContent = feedback;
  document.getElementById('journalDetailReviewed').textContent = get('entryReviewed') ? `Reviewed ${get('entryReviewed')}` : '';
  const form = document.getElementById('journalReviewForm');
  const editButton = document.getElementById('journalEditReview');
  const remarks = document.getElementById('journalReviewRemarks');
  document.getElementById('journalReviewId').value = get('entryId');
  remarks.value = status === 'pending' ? '' : feedback;
  form.hidden = status !== 'pending';
  editButton.hidden = status === 'pending';
  openModal('journalDetailModal');
});

function filterJournalEntries() {
  const search = (document.getElementById('journalEntrySearch')?.value || '').trim().toLowerCase();
  const status = document.getElementById('journalStatusFilter')?.value || '';
  const week = document.getElementById('journalWeekFilter')?.value || '';
  const filterToggle = document.getElementById('journalFilterToggle');
  const hasFilters = Boolean(status || week);
  filterToggle?.classList.toggle('is-active', hasFilters);
  filterToggle?.setAttribute('aria-pressed', String(hasFilters));
  const cards = [...document.querySelectorAll('[data-journal-entry]')];
  let visible = 0;
  cards.forEach(card => {
    const searchable = [card.dataset.entryDate, card.dataset.entryWeek, card.dataset.entryHours,
      card.dataset.entryStatus, card.dataset.entryActivities, card.dataset.entryLearnings,
      card.dataset.entryChallenges, card.dataset.entryFeedback, card.dataset.entryProofName].join(' ').toLowerCase();
    const matches = (!search || searchable.includes(search))
      && (!status || card.dataset.entryStatus === status)
      && (!week || card.dataset.entryWeek === week);
    card.hidden = !matches;
    const item = card.closest('[data-journal-item]');
    if (item) item.hidden = !matches;
    if (!matches) {
      const checkbox = item?.querySelector('.journal-entry-select');
      if (checkbox) checkbox.checked = false;
    }
    if (matches) visible++;
  });
  const total = cards.length;
  const summary = document.getElementById('journalFilterSummary');
  if (summary) summary.textContent = `Showing ${visible} of ${total}`;
  const empty = document.getElementById('journalFilterEmpty');
  if (empty) empty.hidden = visible > 0;
  updateJournalSelection();
}
['journalEntrySearch', 'journalStatusFilter', 'journalWeekFilter'].forEach(id => {
  document.getElementById(id)?.addEventListener(id === 'journalEntrySearch' ? 'input' : 'change', filterJournalEntries);
});
document.getElementById('journalSearchToggle')?.addEventListener('click', () => {
  const input = document.getElementById('journalEntrySearch');
  input.hidden = false;
  document.getElementById('journalSearchToggle').setAttribute('aria-expanded', 'true');
  document.getElementById('journalSearchToggle').classList.add('is-active');
  input.focus();
});
document.getElementById('journalFilterToggle')?.addEventListener('click', () => {
  const popover = document.getElementById('journalFilterPopover');
  popover.hidden = !popover.hidden;
  document.getElementById('journalFilterToggle').setAttribute('aria-expanded', String(!popover.hidden));
});
document.getElementById('journalFilterClear')?.addEventListener('click', () => {
  document.getElementById('journalEntrySearch').value = '';
  document.getElementById('journalStatusFilter').value = '';
  document.getElementById('journalWeekFilter').value = '';
  filterJournalEntries();
  document.getElementById('journalFilterPopover').hidden = true;
  document.getElementById('journalFilterToggle').setAttribute('aria-expanded', 'false');
});
document.addEventListener('click', event => {
  if (event.target.closest('.journal-filter-wrap')) return;
  const popover = document.getElementById('journalFilterPopover');
  if (popover && !popover.hidden) {
    popover.hidden = true;
    document.getElementById('journalFilterToggle')?.setAttribute('aria-expanded', 'false');
  }
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const popover = document.getElementById('journalFilterPopover');
  if (popover && !popover.hidden) {
    popover.hidden = true;
    document.getElementById('journalFilterToggle')?.setAttribute('aria-expanded', 'false');
  }
});

function toggleJournalReview() {
  const form = document.getElementById('journalReviewForm');
  if (form) form.hidden = !form.hidden;
}

function updateJournalSelection() {
  const checks = [...document.querySelectorAll('.journal-entry-select')];
  const selected = checks.filter(check => check.checked);
  const toolbar = document.getElementById('journalSelectionToolbar');
  const count = document.getElementById('journalSelectedCount');
  const actions = document.querySelector('.journal-bulk-actions');
  const selectAll = document.getElementById('journalSelectAll');
  if (count) count.textContent = `${selected.length} selected`;
  if (actions) actions.hidden = selected.length === 0;
  if (selectAll) {
    const visibleChecks = checks.filter(check => !check.closest('[data-journal-item]')?.hidden);
    selectAll.checked = visibleChecks.length > 0 && visibleChecks.every(check => check.checked);
    selectAll.indeterminate = visibleChecks.some(check => check.checked) && !selectAll.checked;
  }
  if (toolbar) toolbar.hidden = checks.length === 0;
}

document.querySelectorAll('.journal-entry-select').forEach(check => check.addEventListener('change', updateJournalSelection));
document.getElementById('journalSelectAll')?.addEventListener('change', event => {
  document.querySelectorAll('.journal-entry-select').forEach(check => {
    if (!check.closest('[data-journal-item]')?.hidden) check.checked = event.target.checked;
  });
  updateJournalSelection();
});
function submitSelectedJournalReview(status, remarks = '') {
  const selectedIds = [...document.querySelectorAll('.journal-entry-select:checked')].map(check => check.value);
  if (!selectedIds.length) return;
  const form = document.getElementById('journalBulkReviewForm');
  const inputs = document.getElementById('journalBulkIds');
  inputs.replaceChildren(...selectedIds.map(id => {
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'journal_ids[]'; input.value = id;
    return input;
  }));
  document.getElementById('journalBulkStatus').value = status;
  document.getElementById('journalBulkRemarks').value = remarks;
  form.requestSubmit();
}
document.getElementById('journalApproveSelected')?.addEventListener('click', () => submitSelectedJournalReview('approved'));
document.getElementById('journalReturnSelected')?.addEventListener('click', () => {
  const field = document.getElementById('journalBulkReturnFeedback');
  field.value = '';
  openModal('journalBulkReturnModal');
  window.setTimeout(() => field.focus(), 0);
});
document.getElementById('journalBulkReturnConfirm')?.addEventListener('click', () => {
  const field = document.getElementById('journalBulkReturnFeedback');
  const remarks = field.value.trim();
  if (!remarks) { field.setCustomValidity('Add feedback before returning entries.'); field.reportValidity(); return; }
  field.setCustomValidity('');
  submitSelectedJournalReview('returned', remarks);
});
updateJournalSelection();
</script>

<?php else: ?>

<div class="card">
  <form class="filter-toolbar table-filter-source-only" method="GET" data-live-filter style="display:none" aria-hidden="true">
    <input type="search" name="q" placeholder="Search student or company..." value="<?= e($search) ?>">
  </form>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Student journal monitoring">
    <table data-search-placeholder="Search student or company...">
      <thead><tr><th>Student</th><th>Company</th><th>OJT Progress</th><th>Journal Entries</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($students as $s):
          $pct = (int)($s['required_hours'] ?? 0) > 0 ? min(100, round(((float)$s['rendered_hours'] / (int)$s['required_hours']) * 100)) : 0;
          $reviewUrl = '/ojtrack/coordinator/monitoring.php?student=' . (int)$s['id'];
          $pendingJournalCount = (int)($s['pending_journals'] ?? 0);
        ?>
        <tr class="row-clickable coordinator-monitoring-student-row<?= $pendingJournalCount > 0 ? ' has-pending-journals' : '' ?>" tabindex="0" role="link" aria-label="Review journals for <?= e($s['name']) ?><?= $pendingJournalCount > 0 ? '; ' . $pendingJournalCount . ' need review' : '' ?>" data-monitoring-url="<?= e($reviewUrl) ?>" title="Review this student's journals">
          <td>
            <div class="table-person">
              <div class="monitoring-student-avatar<?= $pendingJournalCount > 0 ? ' has-review-marker' : '' ?>"><?= user_avatar_content($s['avatar'] ?? null, $s['name']) ?><?php if ($pendingJournalCount > 0): ?><span class="monitoring-review-dot" title="<?= $pendingJournalCount ?> journal<?= $pendingJournalCount === 1 ? '' : 's' ?> need review" aria-label="<?= $pendingJournalCount ?> journals need review"></span><?php endif; ?></div>
              <div class="monitoring-student-copy"><strong><?= e($s['name']) ?></strong><div class="text-xs text-muted"><?= e($s['student_id_no'] ?? '—') ?></div><?php if ($pendingJournalCount > 0): ?><span class="journal-review-flag"><span aria-hidden="true">!</span> Needs review</span><?php endif; ?></div>
            </div>
          </td>
          <td><?= e($s['company_name'] ?? 'No Company') ?></td>
          <td>
            <div class="text-sm font-bold"><?= number_format((float)$s['rendered_hours'], 1) ?> / <?= (int)$s['required_hours'] ?>h <span class="text-muted">(<?= $pct ?>%)</span></div>
            <div class="progress-track" style="margin-top:6px"><div class="progress-fill <?= $pct>=100?'green':($pct>=50?'blue':'amber') ?>" data-pct="<?= $pct ?>"></div></div>
          </td>
          <td>
            <?php if ($pendingJournalCount > 0): ?>
              <span class="journal-review-count"><strong><?= $pendingJournalCount ?></strong><span>to review</span></span>
              <div class="text-xs text-muted"><?= (int)$s['total_journals'] ?> total entries</div>
            <?php else: ?>
              <?= (int)$s['total_journals'] ?> total entries
              <div class="text-xs text-muted">No pending reviews</div>
            <?php endif; ?>
          </td>
          <td><?= status_badge($s['ojt_status']) ?></td>
          <td><a class="table-action-icon is-primary" href="<?= e($reviewUrl) ?>" title="Review journals" aria-label="Review journals for <?= e($s['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM15 3v5h5M9 13h6M9 17h6"/></svg></a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="6" class="text-center text-muted py-8">No students assigned under your supervision.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('click', function (event) {
  const row = event.target.closest('tr.coordinator-monitoring-student-row');
  if (!row || event.defaultPrevented || event.button !== 0 || event.target.closest('a,button,input,select,textarea,label')) return;
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  window.location.href = row.dataset.monitoringUrl;
});
document.addEventListener('keydown', function (event) {
  const row = event.target.closest('tr.coordinator-monitoring-student-row');
  if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault();
    window.location.href = row.dataset.monitoringUrl;
  }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
