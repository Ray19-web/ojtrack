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
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');

            create_notification($recipient_id, "New message from {$company['company_name']}", 'info', '/ojtrack/' . $recipient['role'] . '/messages.php?thread=' . $thread_id);
            header("Location: /ojtrack/company/messages.php?thread=" . $thread_id);
            exit;
        }
    }
}

// Get threads the company is part of
$threads = query(
    "SELECT mt.*,
            (SELECT m.message FROM messages m WHERE m.thread_id=mt.id ORDER BY m.sent_at DESC LIMIT 1) AS last_message,
            (SELECT m.sent_at FROM messages m WHERE m.thread_id=mt.id ORDER BY m.sent_at DESC LIMIT 1) AS last_msg_time,
            (SELECT COUNT(*) FROM messages m
             WHERE m.thread_id=mt.id
               AND m.sender_id != ?
               AND NOT EXISTS(SELECT 1 FROM message_reads mr WHERE mr.message_id=m.id AND mr.user_id=?)) AS unread
     FROM message_threads mt
     JOIN thread_members tm ON tm.thread_id=mt.id AND tm.user_id=?
     ORDER BY COALESCE(last_msg_time, mt.created_at) DESC",
    [$uid, $uid, $uid],
    'iii'
) ?: [];

$active_id     = (int)($_GET['thread'] ?? ($threads[0]['id'] ?? 0));
$active_thread = null;
$messages_list = [];

if ($active_id > 0) {
    $active_thread = query_one(
        "SELECT mt.* FROM message_threads mt
         JOIN thread_members tm ON tm.thread_id=mt.id AND tm.user_id=?
         WHERE mt.id=?",
        [$uid, $active_id],
        'ii'
    );

    if ($active_thread) {
        $messages_list = query(
            "SELECT m.*, u.name AS sender_name, u.role AS sender_role
             FROM messages m
             JOIN users u ON u.id=m.sender_id
             WHERE m.thread_id=?
             ORDER BY m.sent_at ASC",
            [$active_id],
            'i'
        ) ?: [];

        // Mark as read
        foreach ($messages_list as $msg) {
            if ($msg['sender_id'] != $uid) {
                insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)", [$msg['id'], $uid], 'ii');
            }
        }
    }
}

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
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages &amp; Communications</div>
    <div class="page-sub">Communicate directly with your assigned trainees and university coordinators</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('newMsgModal')">+ New Conversation</button>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="chat-container">
  <!-- Thread sidebar -->
  <div class="chat-threads">
    <div class="chat-threads-header">
      <span>Conversations</span>
      <span class="text-xs text-muted"><?= count($threads) ?></span>
    </div>

    <div class="chat-threads-list">
      <?php if (empty($threads)): ?>
        <div class="p-6 text-center text-sm text-muted">
          <p>No conversations yet.</p>
          <button class="btn btn-secondary btn-sm mt-3" onclick="openModal('newMsgModal')">Start a Chat</button>
        </div>
      <?php else: ?>
        <?php foreach ($threads as $t): ?>
          <a href="?thread=<?= $t['id'] ?>" class="chat-thread-item <?= $t['id'] === $active_id ? 'active' : '' ?>">
            <div class="avatar avatar-md <?= $t['thread_type'] === 'group' ? 'bg-primary' : 'bg-secondary' ?>">
              <?= strtoupper(substr($t['name'], 0, 2)) ?>
            </div>
            <div class="chat-thread-meta">
              <div class="chat-thread-top">
                <span class="chat-thread-title"><?= e($t['name']) ?></span>
                <?php if ($t['last_msg_time']): ?>
                  <span class="chat-thread-time"><?= time_ago($t['last_msg_time']) ?></span>
                <?php endif; ?>
              </div>
              <div class="chat-thread-preview">
                <?= $t['last_message'] ? e(substr($t['last_message'], 0, 48)) . (strlen($t['last_message']) > 48 ? '...' : '') : '<span class="italic">No messages yet</span>' ?>
              </div>
            </div>
            <?php if ($t['unread'] > 0): ?>
              <span class="badge badge-primary rounded-full px-2"><?= $t['unread'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Chat conversation pane -->
  <div class="chat-main">
    <?php if ($active_thread): ?>
      <div class="chat-header">
        <div class="flex-items-center gap-3">
          <div class="avatar avatar-md <?= $active_thread['thread_type'] === 'group' ? 'bg-primary' : 'bg-secondary' ?>">
            <?= strtoupper(substr($active_thread['name'], 0, 2)) ?>
          </div>
          <div>
            <div class="font-bold text-sm text-800"><?= e($active_thread['name']) ?></div>
            <div class="text-xs text-muted"><?= e($active_thread['description'] ?: ($active_thread['thread_type'] === 'group' ? 'Group Discussion' : 'Direct Conversation')) ?></div>
          </div>
        </div>
      </div>

      <div class="chat-messages-box" id="msgBox">
        <?php if (empty($messages_list)): ?>
          <div class="empty-state py-8">
            <p class="text-sm text-muted">No messages in this conversation yet. Send the first message below.</p>
          </div>
        <?php else: ?>
          <?php foreach ($messages_list as $m):
            $is_mine = ($m['sender_id'] == $uid);
          ?>
            <div class="chat-bubble-row <?= $is_mine ? 'mine' : 'theirs' ?>" data-id="<?= $m['id'] ?>">
              <?php if (!$is_mine): ?>
                <div class="avatar avatar-sm flex-shrink-0" title="<?= e($m['sender_name']) ?>">
                  <?= strtoupper(substr($m['sender_name'], 0, 1)) ?>
                </div>
              <?php endif; ?>
              <div class="chat-bubble-wrap">
                <?php if (!$is_mine): ?>
                  <div class="chat-sender-name"><?= e($m['sender_name']) ?> <span class="role-tag"><?= ucfirst($m['sender_role']) ?></span></div>
                <?php endif; ?>
                <div class="chat-bubble"><?= nl2br(e($m['message'])) ?></div>
                <div class="chat-time"><?= date('M d, h:i A', strtotime($m['sent_at'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="chat-footer">
        <form id="chatForm" onsubmit="handleSendChat(event)">
          <input type="hidden" name="thread_id" value="<?= $active_id ?>">
          <div class="chat-input-row">
            <textarea id="chatInput" name="message" class="form-control chat-input" placeholder="Type your message... (Press Enter to send)" rows="1" required></textarea>
            <button type="submit" class="btn btn-primary" id="chatSendBtn">Send</button>
          </div>
        </form>
      </div>

    <?php else: ?>
      <div class="empty-state h-full flex-center flex-col py-16">
        <div class="font-bold text-base mb-1">No conversation selected</div>
        <p class="text-xs text-muted max-w-sm text-center">Select an existing thread from the left or initiate a message to your trainees or the OJT coordinators.</p>
        <button class="btn btn-primary btn-sm mt-4" onclick="openModal('newMsgModal')">+ Start Conversation</button>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- New Message Modal -->
<div class="modal-overlay" id="newMsgModal">
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
        <textarea name="message" class="form-control" rows="4" placeholder="Write your message here..." required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newMsgModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Start Conversation</button>
      </div>
    </form>
  </div>
</div>

<script>
function scrollChatToBottom() {
  const el = document.getElementById('msgBox');
  if (el) el.scrollTop = el.scrollHeight;
}
document.addEventListener('DOMContentLoaded', scrollChatToBottom);

const chatInput = document.getElementById('chatInput');
if (chatInput) {
  chatInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      document.getElementById('chatForm').requestSubmit();
    }
  });
}

async function handleSendChat(e) {
  e.preventDefault();
  const input = document.getElementById('chatInput');
  const msg = input.value.trim();
  if (!msg) return;

  const btn = document.getElementById('chatSendBtn');
  btn.disabled = true;
  input.value = '';

  try {
    const res = await fetch('/ojtrack/api/messages.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
      body: 'thread_id=<?= $active_id ?>&message=' + encodeURIComponent(msg)
    });
    const data = await res.json();
    if (data.ok) {
      appendChatMessage(msg, '<?= e($user['name']) ?>', true, 'Just now');
      scrollChatToBottom();
    } else {
      alert(data.error || 'Failed to send message');
      input.value = msg;
    }
  } catch (err) {
    console.error(err);
    alert('Network error while sending message.');
    input.value = msg;
  } finally {
    btn.disabled = false;
    input.focus();
  }
}

function appendChatMessage(text, sender, mine, time) {
  const container = document.getElementById('msgBox');
  if (!container) return;

  const row = document.createElement('div');
  row.className = 'chat-bubble-row ' + (mine ? 'mine' : 'theirs');
  row.innerHTML = `
    <div class="chat-bubble-wrap">
      ${!mine ? `<div class="chat-sender-name">${escapeHtml(sender)}</div>` : ''}
      <div class="chat-bubble">${escapeHtml(text).replace(/\\n/g, '<br>')}</div>
      <div class="chat-time">${time}</div>
    </div>
  `;
  container.appendChild(row);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
