<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user    = current_user();
$uid     = (int)$user['id'];
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$uid], 'i');
$cid     = (int)($company['id'] ?? 0);

$success = ''; $error = '';

// Handle creating thread
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_thread') {
    $member_ids = $_POST['member_ids'] ?? [];
    if (!is_array($member_ids)) request_error(422, 'Invalid participant list.');
    $member_ids = array_values(array_unique(array_filter(array_map('intval', $member_ids), static fn($id) => $id > 0)));
    $initial_msg = trim($_POST['message'] ?? '');

    if (!$member_ids || !$initial_msg) {
        $error = 'Please select at least one participant and enter a message.';
    } else {
        foreach ($member_ids as $member_id) {
            if (!message_recipient_allowed($user, $member_id)) {
                request_error(403, 'A selected person is not an available contact.');
            }
        }

        if (count($member_ids) === 1) {
            $recipient_id = (int)$member_ids[0];
            $recipient = query_one("SELECT id, name, role FROM users WHERE id=? AND status='active'", [$recipient_id], 'i');
            if (!$recipient) {
                $error = 'That participant is no longer available. Please refresh and try again.';
            } else {
                $existing_thread = query_one(
                    "SELECT mt.id
                     FROM message_threads mt
                     JOIN thread_members tm1 ON tm1.thread_id=mt.id AND tm1.user_id=?
                     JOIN thread_members tm2 ON tm2.thread_id=mt.id AND tm2.user_id=?
                     WHERE mt.thread_type='direct'
                     LIMIT 1",
                    [$uid, $recipient_id],
                    'ii'
                );

                if ($existing_thread) {
                    $thread_id = (int)$existing_thread['id'];
                } else {
                    $thread_name = $company['company_name'] . ' & ' . $recipient['name'];
                    $thread_id = insert(
                        "INSERT INTO message_threads (name, thread_type, description, created_by) VALUES (?, 'direct', 'Direct Message', ?)",
                        [$thread_name, $uid],
                        'si'
                    );
                    insert("INSERT INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $uid], 'ii');
                    insert("INSERT INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $recipient_id], 'ii');
                }

                insert("INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)", [$thread_id, $uid, $initial_msg], 'iis');
                reopen_conversation_for_new_message((int)$thread_id);
                insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');
                create_notification($recipient_id, "New message from {$company['company_name']}", 'info', '/ojtrack/' . $recipient['role'] . '/messages.php?thread=' . $thread_id);
            }
        } else {
            $thread_name = mb_substr($company['company_name'] . ' · Group Conversation', 0, 150);
            $thread_id = insert(
                "INSERT INTO message_threads (name, thread_type, description, created_by) VALUES (?, 'group', 'Company group conversation', ?)",
                [$thread_name, $uid],
                'si'
            );
            insert("INSERT INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $uid], 'ii');
            foreach ($member_ids as $member_id) {
                $member_id = (int)$member_id;
                insert("INSERT IGNORE INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $member_id], 'ii');
            }
            insert("INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)", [$thread_id, $uid, $initial_msg], 'iis');
            reopen_conversation_for_new_message((int)$thread_id);
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');
            foreach ($member_ids as $member_id) {
                $member_id = (int)$member_id;
                $recipient = query_one("SELECT role FROM users WHERE id=?", [$member_id], 'i');
                if ($recipient) {
                    create_notification($member_id, "New group conversation from {$company['company_name']}", 'info', '/ojtrack/' . $recipient['role'] . '/messages.php?thread=' . $thread_id);
                }
            }
        }

        if (!empty($thread_id)) {
            header("Location: /ojtrack/company/messages.php?thread=" . (int)$thread_id);
            exit;
        }
    }
}

// Get threads the company is part of
require __DIR__ . '/../includes/messages-load.php';

// Available recipients: assigned trainees and coordinators
$trainees = query(
    "SELECT DISTINCT u.id, u.name, s.student_id_no
     FROM ojt_enrollments oe
     JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
     JOIN placements p ON p.ojt_enrollment_id=oe.id AND p.company_id=? AND p.status IN ('active','on_hold','planned','completed')
     JOIN students s ON s.id=oe.student_id
     JOIN users u ON u.id=s.user_id
     WHERE s.is_archived=0 AND u.status='active'
     ORDER BY u.name ASC",
    [$cid],
    'i'
) ?: [];

// A Message action from a trainee record carries that student's user ID.
// Open their existing direct conversation, or prepare a new one with them selected.
$selected_recipient_id = 0;
$open_new_thread = false;
$requested_recipient_id = (int)($_GET['student'] ?? 0);
if ($requested_recipient_id > 0) {
    foreach ($trainees as $trainee) {
        if ((int)$trainee['id'] === $requested_recipient_id) {
            $selected_recipient_id = $requested_recipient_id;
            break;
        }
    }

    if ($selected_recipient_id > 0) {
        $existing_direct_thread = query_one(
            "SELECT mt.id
             FROM message_threads mt
             JOIN thread_members tm1 ON tm1.thread_id=mt.id AND tm1.user_id=?
             JOIN thread_members tm2 ON tm2.thread_id=mt.id AND tm2.user_id=?
             WHERE mt.thread_type='direct'
             LIMIT 1",
            [$uid, $selected_recipient_id],
            'ii'
        );
        if ($existing_direct_thread) {
            header('Location: /ojtrack/company/messages.php?thread=' . (int)$existing_direct_thread['id']);
            exit;
        }
        $open_new_thread = true;
    }
}

$coordinators = query(
    "SELECT u.id, u.name, c.department
     FROM coordinators c
     JOIN users u ON u.id=c.user_id
     WHERE EXISTS (SELECT 1 FROM ojt_enrollments oe
       JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
       JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.coordinator_id=c.id AND ec.ended_at IS NULL
       JOIN placements p ON p.ojt_enrollment_id=oe.id AND p.company_id=" . (int)$cid . " AND p.status IN ('active','on_hold','planned','completed'))
       AND u.status='active'
     ORDER BY u.name ASC"
) ?: [];

$page_title = 'Messages';
$page_class = 'messages-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages</div>
    <div class="page-sub">Trainees and coordinators</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('newThreadModal')">+ New Conversation</button>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php require __DIR__ . '/../includes/messages-panel.php'; ?>

<!-- New Message Modal -->
<div class="modal-overlay" id="newThreadModal">
  <div class="modal modal-lg message-create-modal">
    <div class="modal-title">New Conversation</div>
    <p class="modal-sub">Start a discussion with one or more trainees or university coordinators</p>
    <button type="button" class="message-create-close" onclick="closeModal('newThreadModal')" aria-label="Close new conversation">×</button>
    <form method="POST" class="message-create-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="start_thread">
      <div class="message-create-body">
      <div class="form-group">
        <div class="send-requirements-group-heading">
          <label class="form-label">Select Participants <span class="text-danger">*</span></label>
          <span class="send-requirements-hint">Choose one or more trainees or coordinators.</span>
        </div>
        <details class="send-multiselect message-participant-select recipient-picker" data-recipient-picker>
          <summary class="send-multiselect-trigger">
            <span class="send-multiselect-summary" data-message-participant-summary>Choose participants</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <div class="send-multiselect-panel">
            <div class="send-multiselect-tools">
              <input type="search" id="messageParticipantSearch" class="form-control send-multiselect-search recipient-search-input" data-recipient-filter="messageParticipantList" placeholder="Search by name, student ID, or department..." aria-label="Search message participants" autocomplete="off">
              <label class="send-multiselect-select-all"><input type="checkbox" data-message-select-visible> Select all matching</label>
            </div>
            <div class="recipient-picker-summary message-participant-count" aria-live="polite"><span data-recipient-selected>0 selected</span><span data-recipient-matches></span></div>
            <div class="send-selection-list send-students-list message-participant-list" id="messageParticipantList">
            <section class="recipient-group">
              <div class="message-recipient-group-title" data-recipient-group-title>Your Trainees</div>
            <?php foreach ($trainees as $tr): ?>
                <label class="send-student-option message-recipient-option recipient-option" data-recipient-option>
                  <input type="checkbox" name="member_ids[]" value="<?= (int)$tr['id'] ?>" <?= (int)$tr['id'] === $selected_recipient_id ? 'checked' : '' ?>>
                  <span class="message-recipient-copy"><span><?= e($tr['name']) ?></span><small><?= e($tr['student_id_no']) ?></small></span>
                </label>
            <?php endforeach; ?>
            </section>

            <section class="recipient-group">
              <div class="message-recipient-group-title" data-recipient-group-title>OJT Coordinators</div>
            <?php foreach ($coordinators as $cd): ?>
                <label class="send-student-option message-recipient-option recipient-option" data-recipient-option>
                  <input type="checkbox" name="member_ids[]" value="<?= (int)$cd['id'] ?>">
                  <span class="message-recipient-copy"><span><?= e($cd['name']) ?></span><small><?= e($cd['department'] ?: 'Coordinator') ?></small></span>
                </label>
            <?php endforeach; ?>
            </section>
            <div class="recipient-no-results send-selection-empty" data-recipient-no-results hidden>No matching participants.</div>
            </div>
          </div>
        </details>
      </div>
      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="message" class="form-control" rows="4" placeholder="Your message" required></textarea>
      </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newThreadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Start Conversation</button>
      </div>
    </form>
  </div>
</div>

<?php if ($open_new_thread): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof openModal === 'function') openModal('newThreadModal');
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/messages-script.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
