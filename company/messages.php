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
    $recipient_id = (int)($_POST['recipient_id'] ?? 0);
    if (!message_recipient_allowed($user, $recipient_id)) request_error(403, 'This person is not an available contact.');
    $initial_msg  = trim($_POST['message'] ?? '');

    if (!$recipient_id || !$initial_msg) {
        $error = 'Please select a recipient and enter a message.';
    } else {
        $recipient = query_one("SELECT * FROM users WHERE id=?", [$recipient_id], 'i');
        if ($recipient) {
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
                $thread_id = $existing_thread['id'];
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
            header("Location: /ojtrack/company/messages.php?thread=" . $thread_id);
            exit;
        }
    }
}

// Get threads the company is part of
require __DIR__ . '/../includes/messages-load.php';

// Available recipients: assigned trainees and coordinators
$trainees = query(
    "SELECT u.id, u.name, s.student_id_no
     FROM students s
     JOIN users u ON u.id=s.user_id
     WHERE s.company_id=?
     ORDER BY u.name ASC",
    [$cid],
    'i'
) ?: [];

$coordinators = query(
    "SELECT u.id, u.name, c.department
     FROM coordinators c
     JOIN users u ON u.id=c.user_id
     WHERE EXISTS (SELECT 1 FROM students s WHERE s.coordinator_id=c.id AND s.company_id=" . (int)$cid . ") ORDER BY u.name ASC"
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
  <div class="modal">
    <div class="modal-title">New Conversation</div>
    <p class="modal-sub">Start a discussion with a trainee or university coordinator</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="start_thread">
      <div class="form-group">
        <label class="form-label">Recipient <span class="text-danger">*</span></label>
        <select name="recipient_id" class="form-control" required>
          <option value="">— Select recipient —</option>
          <optgroup label="Your Trainees">
            <?php foreach ($trainees as $tr): ?>
              <option value="<?= $tr['id'] ?>"><?= e($tr['name']) ?> (<?= e($tr['student_id_no']) ?>)</option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="OJT Coordinators">
            <?php foreach ($coordinators as $cd): ?>
              <option value="<?= $cd['id'] ?>"><?= e($cd['name']) ?> (<?= e($cd['department'] ?: 'Coordinator') ?>)</option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="message" class="form-control" rows="4" placeholder="Your message" required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newThreadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Start Conversation</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/messages-script.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
