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
                create_notification($mid, "You were added to conversation: $title", 'info', '/ojtrack/coordinator/messages.php');
            }
        }

        if ($initial_msg) {
            insert("INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)", [$thread_id, $uid, $initial_msg], 'iis');
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (LAST_INSERT_ID(), ?)", [$uid], 'i');
        }

        header("Location: /ojtrack/coordinator/messages.php?thread=" . $thread_id);
        exit;
    }
}

// Get threads the coordinator is part of
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

// List of available students and company partners to start a message with
$available_students = query(
    "SELECT u.id, u.name, s.student_id_no, s.program
     FROM students s
     JOIN users u ON u.id=s.user_id
     WHERE s.coordinator_id=?
     ORDER BY u.name ASC",
    [$cid],
    'i'
) ?: [];

$available_companies = query(
    "SELECT u.id, co.company_name, u.name AS supervisor_name
     FROM companies co
     JOIN users u ON u.id=co.user_id
     ORDER BY co.company_name ASC"
) ?: [];

$page_title = 'Messages';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages &amp; Discussions</div>
    <div class="page-sub">Communicate directly with students, cohorts, and company partners</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('newThreadModal')">+ New Conversation</button>
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
          <button class="btn btn-secondary btn-sm mt-3" onclick="openModal('newThreadModal')">Create Thread</button>
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
        <p class="text-xs text-muted max-w-sm text-center">Select an existing thread from the left or create a new conversation with your students or company supervisors.</p>
        <button class="btn btn-primary btn-sm mt-4" onclick="openModal('newThreadModal')">+ New Conversation</button>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- New Thread Modal -->
<div class="modal-overlay" id="newThreadModal">
  <div class="modal modal-lg">
    <div class="modal-title">Create Conversation</div>
    <p class="modal-sub">Start a discussion with students or company representatives</p>
    <form method="POST">
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
        <textarea name="initial_message" class="form-control" rows="3" placeholder="Write opening message..."></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newThreadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Conversation</button>
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
