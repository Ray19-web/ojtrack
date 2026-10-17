<?php
if (!defined('OJTRACK')) die('Direct access not allowed.');
$archive_view = $archive_view ?? false;
$conversation_counts = $conversation_counts ?? ['inbox'=>count($threads), 'archived'=>0];
$can_start_conversation = ($role !== 'student' || !empty($contacts));
?>
<div class="chat-container <?= $active_thread ? 'is-conversation-open' : '' ?>">
  <!-- Thread sidebar -->
  <div class="chat-threads">
    <div class="chat-threads-header">
      <span>Conversations</span>
    </div>

    <nav class="chat-inbox-tabs" aria-label="Conversation folders">
      <a href="messages.php" class="<?= !$archive_view ? 'active' : '' ?>" <?= !$archive_view ? 'aria-current="page"' : '' ?>>Inbox <span><?= (int)$conversation_counts['inbox'] ?></span></a>
      <a href="?view=archived" class="<?= $archive_view ? 'active' : '' ?>" <?= $archive_view ? 'aria-current="page"' : '' ?>>Archived <span><?= (int)$conversation_counts['archived'] ?></span></a>
    </nav>
    <div class="chat-threads-list" aria-label="Conversations">
      <?php if (empty($threads)): ?>
        <div class="p-6 text-center text-sm text-muted">
          <p><?= $archive_view ? 'No archived conversations.' : 'No conversations yet.' ?></p>
          <?php if ($can_start_conversation && !$archive_view): ?>
          <button class="btn btn-secondary btn-sm mt-3" onclick="openModal('newThreadModal')">New Conversation</button>
          <?php elseif (!$archive_view): ?><p class="mt-3">Contacts appear once assigned.</p><?php endif; ?>
        </div>
      <?php else: ?>
        <?php foreach ($threads as $t): ?>
          <a href="?<?= $archive_view ? 'view=archived&amp;' : '' ?>thread=<?= (int)$t['id'] ?>" class="chat-thread-item <?= (int)$t['id'] === $active_id ? 'active' : '' ?>">
            <div class="avatar avatar-md <?= $t['thread_type'] === 'group' ? 'bg-primary' : 'bg-secondary' ?>">
              <?= user_avatar_content($t['avatar'] ?? null, $t['name']) ?>
            </div>
            <div class="chat-thread-meta">
              <div class="chat-thread-top">
                <span class="chat-thread-title"><?= e($t['name']) ?></span>
                <?php if ($t['last_msg_time']): ?>
                  <span class="chat-thread-time"><?= time_ago($t['last_msg_time']) ?></span>
                <?php endif; ?>
              </div>
              <div class="chat-thread-preview">
                <?= $t['last_message'] ? e($t['last_message']) : '<span class="italic">No messages yet</span>' ?>
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
  <section class="chat-main" aria-label="Conversation">
    <?php if ($active_thread): ?>
      <div class="chat-header">
        <a class="btn btn-secondary chat-back" href="messages.php<?= $archive_view ? '?view=archived' : '' ?>" aria-label="Back to conversations">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="flex-items-center gap-3">
          <div class="avatar avatar-md <?= $active_thread['thread_type'] === 'group' ? 'bg-primary' : 'bg-secondary' ?>">
            <?= user_avatar_content($active_thread['avatar'] ?? null, $active_thread['name']) ?>
          </div>
          <div>
            <div class="font-bold text-sm text-800"><?= e($active_thread['name']) ?></div>
            <div class="text-xs text-muted"><?= e($active_thread['description'] ?: ($active_thread['thread_type'] === 'group' ? 'Group Discussion' : 'Direct Conversation')) ?></div>
          </div>
        </div>
        <button type="button" class="btn btn-secondary chat-archive-action" id="chatArchiveBtn" data-action="<?= $archive_view ? 'restore' : 'archive' ?>" onclick="handleArchiveConversation(event)" aria-label="<?= $archive_view ? 'Restore conversation' : 'Archive conversation' ?>" title="<?= $archive_view ? 'Restore conversation' : 'Archive conversation' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v13h14V7"/><?= $archive_view ? '<path d="M12 17v-7m-3 3 3-3 3 3"/>' : '<path d="M9 12h6"/>' ?></svg>
          <span><?= $archive_view ? 'Restore' : 'Archive' ?></span>
        </button>
      </div>
      <?php if ($archive_view): ?><div class="chat-archive-notice">Archived · New messages move this chat to Inbox.</div><?php endif; ?>

      <div class="chat-messages-box" id="msgBox" role="log" aria-label="Messages" aria-live="polite" tabindex="0">
        <?php if (empty($messages_list)): ?>
          <div class="empty-state py-8" data-chat-empty>
            <p class="text-sm text-muted">Send the first message.</p>
          </div>
        <?php else: ?>
          <?php foreach ($messages_list as $m):
            $is_mine = ($m['sender_id'] == $uid);
          ?>
            <div class="chat-bubble-row <?= $is_mine ? 'mine' : 'theirs' ?>" data-id="<?= $m['id'] ?>">
              <?php if (!$is_mine): ?>
                <div class="avatar avatar-sm flex-shrink-0" title="<?= e($m['sender_name']) ?>">
                  <?= user_avatar_content($m['sender_avatar'] ?? null, $m['sender_name']) ?>
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
            <textarea id="chatInput" name="message" class="form-control chat-input" placeholder="Write a message…" aria-label="Message" rows="1" required></textarea>
            <button type="submit" class="btn btn-primary" id="chatSendBtn" aria-label="Send message">Send</button>
          </div>
        </form>
      </div>

    <?php else: ?>
      <div class="empty-state h-full flex-center flex-col py-16">
        <div class="font-bold text-base mb-1">No conversation selected</div>
        <p class="text-xs text-muted max-w-sm text-center">Select a conversation or start a new one.</p>
        <?php if ($can_start_conversation && !$archive_view): ?>
        <button class="btn btn-primary btn-sm mt-4" onclick="openModal('newThreadModal')">+ New Conversation</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

