<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$uid   = (int)$user['id'];
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$uid], 'i');
$cid   = (int)($coord['id'] ?? 0);

$success = ''; $error = '';

// Handle creating thread
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_thread') {
    $title       = trim($_POST['title'] ?? '');
    $thread_type = in_array($_POST['thread_type'] ?? '', ['group', 'direct']) ? $_POST['thread_type'] : 'group';
    $desc        = trim($_POST['description'] ?? '');
    $member_ids  = $_POST['member_ids'] ?? [];
    if (!is_array($member_ids)) request_error(422, 'Invalid participant list.');
    $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
    foreach ($member_ids as $mid) if (!message_recipient_allowed($user, $mid)) request_error(403, 'A selected person is not an available contact.');
    if ($thread_type === 'direct' && count($member_ids) !== 1) request_error(422, 'Direct conversations require exactly one other person.');

    $initial_msg = trim($_POST['initial_message'] ?? '');

    if (!$title) {
        $error = 'Please provide a conversation title.';
    } elseif (empty($member_ids)) {
        $error = 'Please select at least one participant.';
    } else {
        $thread_id = insert(
            "INSERT INTO message_threads (name, thread_type, description, created_by) VALUES (?, ?, ?, ?)",
            [$title, $thread_type, $desc, $uid],
            'sssi'
        );

        insert("INSERT IGNORE INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $uid], 'ii');
        foreach ($member_ids as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                insert("INSERT IGNORE INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $mid], 'ii');
                $recipient = query_one("SELECT role FROM users WHERE id=?", [$mid], 'i');
                create_notification($mid, "You were added to conversation: $title", 'info', '/ojtrack/' . $recipient['role'] . '/messages.php?thread=' . $thread_id);
            }
        }

        if ($initial_msg) {
            insert("INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)", [$thread_id, $uid, $initial_msg], 'iis');
            reopen_conversation_for_new_message((int)$thread_id);
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');
        }

        header("Location: /ojtrack/coordinator/messages.php?thread=" . $thread_id);
        exit;
    }
}

// Get threads the coordinator is part of
require __DIR__ . '/../includes/messages-load.php';

// List of available students and company partners to start a message with
$available_students = query(
    "SELECT u.id, u.name, s.student_id_no, s.program
     FROM students s
     JOIN users u ON u.id=s.user_id
     WHERE s.coordinator_id=? AND s.is_archived=0 AND u.status!='archived'
     ORDER BY u.name ASC",
    [$cid],
    'i'
) ?: [];

$available_companies = query(
    "SELECT u.id, co.company_name, u.name AS supervisor_name
     FROM companies co
     JOIN users u ON u.id=co.user_id
     WHERE EXISTS (SELECT 1 FROM students s WHERE s.company_id=co.id AND s.coordinator_id=" . (int)$cid . ") ORDER BY co.company_name ASC"
) ?: [];

$page_title = 'Messages';
$page_class = 'messages-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages</div>
    <div class="page-sub">Students and company partners</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('newThreadModal')">+ New Conversation</button>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php require __DIR__ . '/../includes/messages-panel.php'; ?>

<!-- New Thread Modal -->
<div class="modal-overlay" id="newThreadModal">
  <div class="modal modal-lg">
    <div class="modal-title">Create Conversation</div>
    <p class="modal-sub">Start a discussion with students or company representatives</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="create_thread">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Conversation Title <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="e.g. BSIT 4A OJT Group or Juan dela Cruz" required>
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="thread_type" class="form-control">
            <option value="group">Group Discussion</option>
            <option value="direct">Direct Message (1-on-1)</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description (Optional)</label>
        <input type="text" name="description" class="form-control" placeholder="Brief topic or purpose">
      </div>

      <div class="form-group">
        <label class="form-label">Select Participants <span class="text-danger">*</span></label>
        <div class="multi-select-box" style="max-height: 160px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius); padding: 8px 12px;">
          <div class="text-xs font-bold text-muted uppercase tracking-wider mb-1">Students</div>
          <?php foreach ($available_students as $st): ?>
            <label class="flex-items-center gap-2 py-1 text-sm cursor-pointer hover:bg-slate-50">
              <input type="checkbox" name="member_ids[]" value="<?= $st['id'] ?>">
              <span><?= e($st['name']) ?> (<?= e($st['student_id_no']) ?>)</span>
            </label>
          <?php endforeach; ?>

          <div class="text-xs font-bold text-muted uppercase tracking-wider mt-2 mb-1">Company Partners</div>
          <?php foreach ($available_companies as $cp): ?>
            <label class="flex-items-center gap-2 py-1 text-sm cursor-pointer hover:bg-slate-50">
              <input type="checkbox" name="member_ids[]" value="<?= $cp['id'] ?>">
              <span><?= e($cp['company_name']) ?> — <?= e($cp['supervisor_name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Initial Message (Optional)</label>
        <textarea name="initial_message" class="form-control" rows="3" placeholder="First message (optional)"></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newThreadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Conversation</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/messages-script.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
