<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user    = current_user();
$uid     = (int)$user['id'];
$student = query_one(
    "SELECT s.*, u.name, co.user_id AS company_user_id, cu.name AS supervisor_name,
            co.company_name,
            cord.user_id AS coord_user_id, cord_u.name AS coordinator_name
     FROM students s
     JOIN users u ON u.id=s.user_id
     LEFT JOIN companies co ON co.id=s.company_id
     LEFT JOIN users cu ON cu.id=co.user_id
     LEFT JOIN coordinators cord ON cord.id=s.coordinator_id
     LEFT JOIN users cord_u ON cord_u.id=cord.user_id
     WHERE s.user_id=?",
    [$uid],
    'i'
);

$success = ''; $error = '';

// Handle creating direct thread with coordinator or supervisor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_thread') {
    $recipient_id = (int)($_POST['recipient_id'] ?? 0);
    if (!message_recipient_allowed($user, $recipient_id)) request_error(403, 'This person is not an available contact.');
    $initial_msg  = trim($_POST['message'] ?? '');

    if (!$recipient_id || !$initial_msg) {
        $error = 'Please select a recipient and enter a message.';
    } else {
        $recipient = query_one("SELECT * FROM users WHERE id=?", [$recipient_id], 'i');
        if ($recipient) {
            // Check if 1-on-1 thread already exists between these 2 users
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
                $thread_name = $recipient['name'] . ' & ' . $user['name'];
                $thread_id = insert(
                    "INSERT INTO message_threads (name, thread_type, description, created_by) VALUES (?, 'direct', 'Direct Conversation', ?)",
                    [$thread_name, $uid],
                    'si'
                );
                insert("INSERT INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $uid], 'ii');
                insert("INSERT INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $recipient_id], 'ii');
            }

            insert("INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)", [$thread_id, $uid, $initial_msg], 'iis');
            reopen_conversation_for_new_message((int)$thread_id);
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');

            create_notification($recipient_id, "New message from {$user['name']}", 'info', '/ojtrack/' . $recipient['role'] . '/messages.php?thread=' . $thread_id);
            header("Location: /ojtrack/student/messages.php?thread=" . $thread_id);
            exit;
        }
    }
}

// Get threads the student is part of
require __DIR__ . '/../includes/messages-load.php';

// Available contacts for student to message
$contacts = [];
if (!empty($student['coord_user_id'])) {
    $contacts[] = [
        'id'   => $student['coord_user_id'],
        'name' => $student['coordinator_name'] ?: 'OJT Coordinator',
        'role' => 'Coordinator'
    ];
}
if (!empty($student['company_user_id'])) {
    $contacts[] = [
        'id'   => $student['company_user_id'],
        'name' => ($student['supervisor_name'] ?: 'Company Supervisor') . ' (' . ($student['company_name'] ?: 'Company') . ')',
        'role' => 'Company Supervisor'
    ];
}

$page_title = 'Messages';
$page_class = 'messages-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages</div>
    <div class="page-sub">Your coordinator and supervisor</div>
  </div>
  <?php if (!empty($contacts)): ?>
    <button class="btn btn-primary" onclick="openModal('newThreadModal')">+ New Conversation</button>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php require __DIR__ . '/../includes/messages-panel.php'; ?>

<!-- New Message Modal -->
<div class="modal-overlay" id="newThreadModal">
  <div class="modal">
    <div class="modal-title">New Conversation</div>
    <p class="modal-sub">Send a message to your coordinator or company supervisor</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="start_thread">
      <div class="form-group">
        <label class="form-label">Recipient <span class="text-danger">*</span></label>
        <select name="recipient_id" class="form-control" required>
          <option value="">— Select recipient —</option>
          <?php foreach ($contacts as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= $c['role'] ?>)</option>
          <?php endforeach; ?>
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
