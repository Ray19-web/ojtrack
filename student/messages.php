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
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');

            create_notification($recipient_id, "New message from {$user['name']}", 'info', '/ojtrack/student/messages.php');
            header("Location: /ojtrack/student/messages.php?thread=" . $thread_id);
            exit;
        }
    }
}

// Get threads the student is part of
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

$active_id = (int)($_GET['thread'] ?? ($threads[0]['id'] ?? 0));
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
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages &amp; Communications</div>
    <div class="page-sub">Direct messaging with your OJT Coordinator and Company Supervisor</div>
  </div>
  <?php if (!empty($contacts)): ?>
    <button class="btn btn-primary" onclick="openModal('newMsgModal')">+ New Conversation</button>
  <?php endif; ?>
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
          <?php if (!empty($contacts)): ?>
            <button class="btn btn-secondary btn-sm mt-3" onclick="openModal('newMsgModal')">Start a Chat</button>
          <?php endif; ?>
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
            <div class="text-xs text-muted"><?= e($active_thread['description'] ?: ($active_thread['thread_type'] === 'group' ? 'Group Discussion' : 'Direct Message')) ?></div>
          </div>
        </div>
      </div>

      <div class="chat-messages-box" id="msgBox">
        <?php if (empty($messages_list)): ?>
          <div class="empty-state py-8">
            <p class="text-sm text-muted">This conversation has just started. Say hello!</p>
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
        <p class="text-xs text-muted max-w-sm text-center">Select an existing thread from the left or start a new conversation with your coordinator or supervisor.</p>
        <?php if (!empty($contacts)): ?>
          <button class="btn btn-primary btn-sm mt-4" onclick="openModal('newMsgModal')">+ Start Conversation</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- New Message Modal -->
<div class="modal-overlay" id="newMsgModal">
  <div class="modal">
    <div class="modal-title">New Conversation</div>
    <p class="modal-sub">Send a message to your coordinator or company supervisor</p>
    <form method="POST">
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
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
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
