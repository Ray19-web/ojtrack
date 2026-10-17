<?php if (!defined('OJTRACK')) die('Direct access not allowed.'); ?>
<script>
function scrollChatToBottom() {
  const el = document.getElementById('msgBox');
  if (el) el.scrollTop = el.scrollHeight;
}
document.addEventListener('DOMContentLoaded', function () {
  const viewport = window.visualViewport;
  function fitViewport() {
    document.documentElement.style.setProperty('--messages-viewport-height', (viewport ? viewport.height : window.innerHeight) + 'px');
  }
  fitViewport();
  (viewport || window).addEventListener('resize', fitViewport);
  const input = document.getElementById('chatInput');
  if (input) {
    function fitInput() {
      input.style.height = '44px';
      input.style.height = Math.min(120, Math.max(44, input.scrollHeight + 2)) + 'px';
      input.style.overflowY = input.scrollHeight > 118 ? 'auto' : 'hidden';
    }
    input.addEventListener('input', fitInput);
    window.addEventListener('resize', fitInput);
    fitInput();
  }
  scrollChatToBottom();
  const history = document.getElementById('msgBox');
  if (history && window.ResizeObserver) {
    let pinned = true;
    history.addEventListener('scroll', () => {
      pinned = history.scrollHeight - history.scrollTop - history.clientHeight < 48;
    });
    new ResizeObserver(() => { if (pinned) scrollChatToBottom(); }).observe(history);
  }
});

const chatInput = document.getElementById('chatInput');
if (chatInput) {
  chatInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229 && !window.matchMedia('(pointer: coarse)').matches) {
      e.preventDefault();
      document.getElementById('chatForm').requestSubmit();
    }
  });
}

async function handleSendChat(e) {
  e.preventDefault();
  const input = document.getElementById('chatInput');
  const msg = input.value.trim();
  if (!msg || document.getElementById('chatSendBtn').disabled) return;
  if (!await confirmOJTrack({title: 'Send this message?', message: 'Send this message to the current conversation?', confirmLabel: 'Send'})) return;
  if (document.getElementById('chatSendBtn').disabled) return;

  const btn = document.getElementById('chatSendBtn');
  btn.disabled = true;
  const archiveButton = document.getElementById('chatArchiveBtn');
  if (archiveButton) archiveButton.disabled = true;
  input.value = '';
  input.dispatchEvent(new Event('input'));

  try {
    const res = await fetch('/ojtrack/api/messages.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
      body: 'thread_id=<?= $active_id ?>&message=' + encodeURIComponent(msg)
    });
    const data = await res.json();
    if (data.ok) {
      <?php if (!empty($archive_view)): ?>
      window.location.assign('messages.php?thread=<?= (int)$active_id ?>');
      return;
      <?php endif; ?>
      appendChatMessage(msg, '', true, 'Just now');
      scrollChatToBottom();
    } else {
      alert(data.error || 'Failed to send message');
      input.value = input.value ? msg + '\n' + input.value : msg;
    }
  } catch (err) {
    console.error(err);
    alert('Network error while sending message.');
    input.value = input.value ? msg + '\n' + input.value : msg;
  } finally {
    input.dispatchEvent(new Event('input'));
    btn.disabled = false;
    const archiveButton = document.getElementById('chatArchiveBtn');
    if (archiveButton) archiveButton.disabled = false;
    input.focus();
  }
}

function appendChatMessage(text, sender, mine, time) {
  const container = document.getElementById('msgBox');
  if (!container) return;

  container.querySelector('[data-chat-empty]')?.remove();
  const row = document.createElement('div');
  row.className = 'chat-bubble-row ' + (mine ? 'mine' : 'theirs');
  row.innerHTML = `
    <div class="chat-bubble-wrap">
      ${!mine ? `<div class="chat-sender-name">${escapeHtml(sender)}</div>` : ''}
      <div class="chat-bubble">${escapeHtml(text).replace(/\n/g, '<br>')}</div>
      <div class="chat-time">${time}</div>
    </div>
  `;
  container.appendChild(row);
}
async function handleArchiveConversation(event) {
  const button = event.currentTarget;
  if (button.disabled) return;
  const restoring = button.dataset.action === 'restore';
  if (!await confirmOJTrack({
    title: restoring ? 'Restore conversation?' : 'Archive conversation?',
    message: restoring ? 'Move this conversation back to your inbox?' : 'Move this conversation to your archive? Messages remain preserved.',
    confirmLabel: restoring ? 'Restore' : 'Archive'
  })) return;
  if (button.disabled) return;
  button.disabled = true;
  const sendButton = document.getElementById('chatSendBtn');
  if (sendButton) sendButton.disabled = true;
  try {
    const response = await fetch('/ojtrack/api/messages.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
      body: new URLSearchParams({action: button.dataset.action, thread_id: '<?= (int)$active_id ?>'})
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to update conversation.');
    window.location.assign(button.dataset.action === 'archive' ? 'messages.php' : 'messages.php?thread=<?= (int)$active_id ?>');
  } catch (error) {
    alert(error.message || 'Unable to update conversation. Please try again.');
    button.disabled = false;
    if (sendButton) sendButton.disabled = false;
  }
}
</script>

