<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$uid     = (int)$user['id'];
$student = query_one("SELECT * FROM students WHERE user_id=?", [$uid], 'i');
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
        if ($edit_id && !query_one("SELECT id FROM journal_entries WHERE id=? AND student_id=? AND status IN ('pending','rejected')", [$edit_id, $sid], 'ii')) {
            request_error(403, 'This journal cannot be edited.');
        }
        if (query_one("SELECT id FROM journal_entries WHERE student_id=? AND entry_date=? AND id!=?", [$sid, $date, $edit_id], 'isi')) $error = 'A journal already exists for this date.';
        // Optional proof image upload
        $proof_image = null;
        if (!$error && !empty($_FILES['proof_image']['name']) && ($_FILES['proof_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $error = 'Proof image must be a JPG, PNG, GIF, or WEBP file.';
            } else {
                $filename = 'proof_' . $sid . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
                if (store_private_upload($_FILES['proof_image']['tmp_name'], 'journal_proofs', $filename)) {
                    $proof_image = 'journal_proofs/' . $filename;
                } else {
                    $error = 'Failed to upload proof image.';
                }
            }
        }

        if ($error) {
            $view = 'form';
        } elseif (!$date || !$activities || !$learnings || !$challenges) {
            $error = 'All fields are required.';
        } else {
            $start = !empty($student['ojt_start_date']) ? strtotime($student['ojt_start_date']) : strtotime($date);
            $week = (int)max(1, floor((strtotime($date) - $start) / (7 * 86400)) + 1);
            if ($edit_id > 0) {
                // Edit existing pending entry
                query(
                    "UPDATE journal_entries
                     SET entry_date=?, week_number=?, activities=?, learnings=?, challenges=?, hours_rendered=?, status='pending', reviewed_at=NULL,
                         proof_image = COALESCE(?, proof_image)
                     WHERE id=? AND student_id=? AND status IN ('pending','rejected')",
                    [$date, $week, $activities, $learnings, $challenges, $hours, $proof_image, $edit_id, $sid],
                    'sisssdsii'
                );
                log_activity($uid, 'Journal Entry Updated', "Date: $date (#$edit_id)");
                $success = 'Journal entry updated successfully.';
                $view = 'list';
            } else {
                $existing = query_one("SELECT id FROM journal_entries WHERE student_id=? AND entry_date=?", [$sid, $date], 'is');
                if ($existing) {
                    $error = "A journal entry for $date already exists.";
                } else {
                    $start = !empty($student['ojt_start_date']) ? strtotime($student['ojt_start_date']) : strtotime($date);
                    $week = max(1, floor((strtotime($date) - $start) / (7 * 86400)) + 1);

                    insert(
                        "INSERT INTO journal_entries (student_id, entry_date, week_number, activities, learnings, challenges, hours_rendered, status, proof_image)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                        [$sid, $date, $week, $activities, $learnings, $challenges, $hours, $proof_image],
                        'isssssds'
                    );

                    log_activity($uid, 'Journal Entry Submitted', "Date: $date");
                    $success = 'Journal entry submitted for coordinator verification.';
                    $view = 'list';
                }
            }
        }
    } elseif ($action === 'delete_journal') {
        $del_id = (int)($_POST['del_id'] ?? 0);
        query("DELETE FROM journal_entries WHERE id=? AND student_id=? AND status IN ('pending','rejected')", [$del_id, $sid], 'ii');
        log_activity($uid, 'Journal Entry Deleted', "ID: $del_id");
        $success = 'Journal entry deleted.';
        $view = 'list';
    }
}

$entries = query("SELECT * FROM journal_entries WHERE student_id=? ORDER BY entry_date DESC", [$sid], 'i') ?: [];
$approved = count(array_filter($entries, fn($e) => $e['status'] === 'approved'));
$pending  = count(array_filter($entries, fn($e) => $e['status'] === 'pending'));
$rejected = count(array_filter($entries, fn($e) => $e['status'] === 'rejected'));

$detail = null;
if ($view === 'detail' && $entry_id > 0) {
    $detail = query_one("SELECT * FROM journal_entries WHERE id=? AND student_id=?", [$entry_id, $sid], 'ii');
}

$edit_entry = null;
if ($view === 'edit' && $entry_id > 0) {
    $edit_entry = query_one("SELECT * FROM journal_entries WHERE id=? AND student_id=? AND status IN ('pending','rejected')", [$entry_id, $sid], 'ii');
    if ($edit_entry) $view = 'form';
}

$page_title = 'Daily Journal';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($view === 'detail' && $detail): ?>
  <div class="mb-4 flex-between">
    <a href="/ojtrack/student/journal.php" class="btn btn-ghost btn-sm">← Back to Journal List</a>
    <?php if (in_array($detail['status'], ['pending', 'rejected'], true)): ?>
      <div class="flex-items-center gap-2">
        <a href="/ojtrack/student/journal.php?view=edit&id=<?= $detail['id'] ?>" class="btn btn-secondary btn-sm">Edit Entry</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this pending journal entry?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_journal">
          <input type="hidden" name="del_id" value="<?= $detail['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
    <?php endif; ?>
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
        <label class="form-label font-bold text-xs uppercase text-muted tracking-wider">1. Daily Activities Performed</label>
        <div class="p-4 bg-slate-50 rounded-lg text-sm text-700 leading-relaxed border">
          <?= nl2br(e($detail['activities'])) ?>
        </div>
      </div>

      <div>
        <label class="form-label font-bold text-xs uppercase text-primary tracking-wider">2. Key Learnings &amp; Insights</label>
        <div class="p-4 bg-blue-50 rounded-lg text-sm text-700 leading-relaxed border border-blue-100">
          <?= nl2br(e($detail['learnings'])) ?>
        </div>
      </div>

      <div>
        <label class="form-label font-bold text-xs uppercase text-amber-600 tracking-wider">3. Technical &amp; Workplace Challenges Faced</label>
        <div class="p-4 bg-amber-50 rounded-lg text-sm text-700 leading-relaxed border border-amber-100">
          <?= nl2br(e($detail['challenges'])) ?>
        </div>
      </div>

      <?php if (!empty($detail['proof_image'])): ?>
      <div>
        <label class="form-label font-bold text-xs uppercase text-gray-600 tracking-wider">Documentation / Proof</label>
        <a href="/ojtrack/download.php?file=<?= rawurlencode($detail['proof_image']) ?>" target="_blank">
          <img src="/ojtrack/download.php?file=<?= rawurlencode($detail['proof_image']) ?>&amp;preview=1" alt="Proof" style="max-width:100%;max-height:360px;border-radius:8px;border:1px solid var(--border)">
        </a>
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

<?php elseif ($view === 'form'): ?>
  <div class="mb-4">
    <a href="/ojtrack/student/journal.php" class="btn btn-ghost btn-sm">← Cancel &amp; Return</a>
  </div>

  <?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

  <div class="card card-body">
    <div class="section-header mb-4">
      <div class="section-title"><?= $edit_entry ? 'Edit Pending Journal Entry' : 'New Daily Journal Entry' ?></div>
      <div class="section-sub">Document your day's work, acquired skills, and problem resolution</div>
    </div>

    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
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
        <textarea name="activities" class="form-control" rows="5" placeholder="Detail the technical tasks, meetings, system modules worked on today..." required><?= e($edit_entry['activities'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Learnings &amp; Skills Acquired <span class="text-danger">*</span></label>
        <textarea name="learnings" class="form-control" rows="4" placeholder="What technologies, best practices, or concepts did you learn or improve?" required><?= e($edit_entry['learnings'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Challenges &amp; Solutions <span class="text-danger">*</span></label>
        <textarea name="challenges" class="form-control" rows="4" placeholder="What difficulties did you encounter and how did you resolve them?" required><?= e($edit_entry['challenges'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Documentation / Proof Image</label>
        <input type="file" name="proof_image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
        <?php if (!empty($edit_entry['proof_image'])): ?>
          <div class="text-xs text-muted mt-1">Current: <a href="/ojtrack/download.php?file=<?= rawurlencode($edit_entry['proof_image']) ?>" target="_blank">view proof</a> (upload new to replace)</div>
        <?php else: ?>
          <div class="text-xs text-muted mt-1">Upload a screenshot or photo proving your activity (optional).</div>
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
      <div class="page-title">Daily OJT Journal</div>
      <div class="page-sub">Document your day-to-day training experiences, technical progress, and reflections</div>
    </div>
    <a href="/ojtrack/student/journal.php?view=form" class="btn btn-primary">+ Write Daily Entry</a>
  </div>

  <?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>

  <?php
  $today = date('Y-m-d');
  $today_entry = query_one("SELECT id FROM journal_entries WHERE student_id=? AND entry_date=?", [$sid, $today], 'is');
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
      <div class="card-title">Journal Entries</div>
      <span class="text-sm text-muted"><?= count($entries) ?> recorded</span>
    </div>
    <div>
      <?php if (empty($entries)): ?>
        <div class="empty-state py-12 text-center">
          <p class="text-muted">No journal entries recorded yet. Click below to document your first day.</p>
          <a href="/ojtrack/student/journal.php?view=form" class="btn btn-primary btn-sm mt-3">+ Write Entry</a>
        </div>
      <?php else: ?>
        <?php foreach ($entries as $e): ?>
          <a href="/ojtrack/student/journal.php?view=detail&id=<?= $e['id'] ?>" class="journal-list-row">
            <div class="flex-between">
              <div class="flex-items-center gap-3">
                <div class="journal-date-badge">
                  <span class="week">Wk <?= $e['week_number'] ?></span>
                  <span class="day"><?= date('D', strtotime($e['entry_date'])) ?></span>
                </div>
                <div>
                  <div class="font-bold text-sm text-800"><?= date('F d, Y', strtotime($e['entry_date'])) ?></div>
                  <div class="text-xs text-muted truncate max-w-lg"><?= e(substr($e['activities'], 0, 95)) ?>...</div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
