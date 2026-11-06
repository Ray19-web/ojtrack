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
    "SELECT DISTINCT u.id, u.name, s.student_id_no, p.code program
     FROM ojt_enrollments oe
     JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
     JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.coordinator_id=? AND ec.ended_at IS NULL
     JOIN programs p ON p.id=oe.program_id
     JOIN students s ON s.id=oe.student_id
     JOIN users u ON u.id=s.user_id
     WHERE s.is_archived=0 AND u.status!='archived'
     ORDER BY u.name ASC",
    [$cid],
    'i'
) ?: [];

$available_companies = query(
    "SELECT u.id, co.company_name, co.supervisor_name
     FROM companies co
     JOIN users u ON u.id=co.user_id
     WHERE EXISTS (SELECT 1 FROM ojt_enrollments oe
       JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
       JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.coordinator_id=" . (int)$cid . " AND ec.ended_at IS NULL
       JOIN placements p ON p.ojt_enrollment_id=oe.id AND p.company_id=co.id AND p.status IN ('active','on_hold','planned','completed'))
     ORDER BY co.company_name ASC"
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
  <div class="modal modal-lg message-create-modal">
    <div class="modal-title">Create Conversation</div>
    <p class="modal-sub">Start a discussion with students or company representatives</p>
    <form method="POST" class="message-create-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="create_thread">
      <div class="message-create-body">
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

      <div class="form-group">
        <label class="form-label">Description (Optional)</label>
        <input type="text" name="description" class="form-control" placeholder="Brief topic or purpose">
      </div>

      <div class="form-group">
        <div class="send-requirements-group-heading">
          <label class="form-label">Select Participants <span class="text-danger">*</span></label>
          <span class="send-requirements-hint">Choose students or company partners for this conversation.</span>
        </div>
        <details class="send-multiselect message-participant-select recipient-picker" data-recipient-picker>
          <summary class="send-multiselect-trigger">
            <span class="send-multiselect-summary" data-message-participant-summary>Choose participants</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <div class="send-multiselect-panel">
            <div class="send-multiselect-tools">
              <input type="search" id="messageParticipantSearch" class="form-control send-multiselect-search recipient-search-input" data-recipient-filter="messageParticipantList" placeholder="Search students by name or ID, or find a company..." aria-label="Search message participants" autocomplete="off">
              <label class="send-multiselect-select-all"><input type="checkbox" data-message-select-visible> Select all matching</label>
            </div>
            <div class="recipient-picker-summary message-participant-count" aria-live="polite"><span data-recipient-selected>0 selected</span><span data-recipient-matches></span></div>
            <div class="send-selection-list send-students-list message-participant-list" id="messageParticipantList">
            <section class="recipient-group">
              <div class="message-recipient-group-title" data-recipient-group-title>Students</div>
              <?php foreach ($available_students as $st): ?>
                <label class="send-student-option message-recipient-option recipient-option" data-recipient-option>
                  <input type="checkbox" name="member_ids[]" value="<?= $st['id'] ?>">
                  <span class="message-recipient-copy"><span><?= e($st['name']) ?></span><small><?= e($st['student_id_no']) ?></small></span>
                </label>
              <?php endforeach; ?>
            </section>

            <section class="recipient-group">
              <div class="message-recipient-group-title" data-recipient-group-title>Company Partners</div>
              <?php foreach ($available_companies as $cp): ?>
                <label class="send-student-option message-recipient-option recipient-option" data-recipient-option>
                  <input type="checkbox" name="member_ids[]" value="<?= $cp['id'] ?>">
                  <span class="message-recipient-copy"><span><?= e($cp['company_name']) ?></span><small><?= e($cp['supervisor_name']) ?></small></span>
                </label>
              <?php endforeach; ?>
            </section>
            <div class="recipient-no-results send-selection-empty" data-recipient-no-results hidden>No matching recipients.</div>
          </div>
          </div>
        </details>
      </div>

      <div class="form-group">
        <label class="form-label">Initial Message (Optional)</label>
        <textarea name="initial_message" class="form-control" rows="3" placeholder="First message (optional)"></textarea>
      </div>
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
