/* Poll shared inbox activity so every authenticated page can surface new items. */
(function () {
  const page = document.body;
  if (!page || !page.dataset.tourUserId) return;

  let notificationCursor = Number.parseInt(page.dataset.notificationCursor || '0', 10) || 0;
  let messageCursor = Number.parseInt(page.dataset.messageCursor || '0', 10) || 0;
  let polling = false;
  const pollIntervalMs = 2000;

  function updateNotificationBadge(unread) {
    const button = document.getElementById('notifToggleBtn');
    if (!button) return;
    let badge = document.getElementById('notifCounterBadge');
    const count = Math.max(0, Number(unread) || 0);
    button.title = count ? `Notifications (${count} unread)` : 'Notifications';
    button.setAttribute('aria-label', button.title);
    if (count && !badge) {
      badge = document.createElement('span');
      badge.className = 'notif-counter';
      badge.id = 'notifCounterBadge';
      button.append(badge);
    }
    if (!badge) return;
    if (count) {
      badge.textContent = count > 99 ? '99+' : String(count);
    } else {
      badge.remove();
    }
  }

  function updateMessageBadges(unread) {
    const count = Math.max(0, Number(unread) || 0);
    document.querySelectorAll('[data-live-message-badge]').forEach(badge => {
      badge.textContent = count ? (count > 99 ? '99+' : String(count)) : '';
      badge.hidden = count === 0;
    });
  }

  function safeAppLink(value) {
    if (typeof value !== 'string' || !value.startsWith('/ojtrack/') || value.includes('\\')) return '';
    try {
      const url = new URL(value, window.location.origin);
      return url.origin === window.location.origin && url.pathname.startsWith('/ojtrack/') ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  function appendNotification(notification) {
    const container = document.getElementById('notifListContainer');
    if (!container) return null;
    const id = Number(notification.id) || 0;
    if (!id || container.querySelector(`[data-live-notification-id="${id}"]`)) return null;
    container.querySelector('.notif-empty')?.remove();

    const item = document.createElement('div');
    item.className = notification.is_read ? 'notif-item read' : 'notif-item unread';
    item.dataset.liveNotificationId = String(id);
    item.tabIndex = 0;
    item.setAttribute('role', 'button');
    const content = document.createElement('div');
    content.className = 'notif-item-content';
    const message = document.createElement('p');
    message.className = 'notif-text';
    message.textContent = String(notification.message || 'New notification');
    const time = document.createElement('span');
    time.className = 'notif-time';
    time.textContent = notification.time_ago || 'Just now';
    content.append(message, time);
    item.append(content);
    if (!notification.is_read) {
      const dot = document.createElement('span');
      dot.className = 'notif-unread-dot';
      item.append(dot);
    }
    const link = safeAppLink(notification.link || '');
    item.addEventListener('click', () => window.handleNotificationClick?.(id, link, item));
    item.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        window.handleNotificationClick?.(id, link, item);
      }
    });
    container.prepend(item);
    [...container.querySelectorAll('.notif-item')].slice(6).forEach(oldItem => oldItem.remove());
    return item;
  }

  async function pollNotifications() {
    const response = await fetch(`/ojtrack/api/notifications.php?after_id=${notificationCursor}`, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Accept': 'application/json'}
    });
    const result = await response.json();
    if (!response.ok || !result.ok) return;
    updateNotificationBadge(result.unread);
    const notifications = Array.isArray(result.notifications) ? result.notifications : [];
    for (const notification of notifications) {
      const id = Number(notification.id) || 0;
      notificationCursor = Math.max(notificationCursor, id);
      const item = appendNotification(notification);
      if (!notification.is_read) {
        const link = safeAppLink(notification.link || '');
        window.showAppToast?.(notification.message || 'You have a new notification.', {
          type: 'info',
          title: 'New notification',
          actionLabel: link ? 'Open' : '',
          onAction: link ? () => window.handleNotificationClick?.(id, link, item) : undefined,
          duration: 10000
        });
      }
    }
    notificationCursor = Math.max(notificationCursor, Number(result.cursor) || 0);
  }

  function currentThreadId() {
    if (!/\/messages\.php$/i.test(window.location.pathname)) return 0;
    return Number.parseInt(new URLSearchParams(window.location.search).get('thread') || '0', 10) || 0;
  }

  async function pollMessages() {
    const response = await fetch(`/ojtrack/api/activity-pulse.php?after_id=${messageCursor}`, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Accept': 'application/json'}
    });
    const result = await response.json();
    if (!response.ok || !result.ok) return;
    updateMessageBadges(result.unread);
    const messages = Array.isArray(result.messages) ? result.messages : [];
    const openThread = currentThreadId();
    const role = page.dataset.tourRole || '';
    const messagesUrl = ['coordinator', 'company', 'student'].includes(role) ? `/ojtrack/${role}/messages.php` : '';
    const visibleMessages = messages.filter(message => Number(message.thread_id) !== openThread);
    visibleMessages.slice(-3).forEach((message, index) => {
      const extras = visibleMessages.length > 3 && index === visibleMessages.slice(-3).length - 1
        ? ` · ${visibleMessages.length - 3} earlier message${visibleMessages.length - 3 === 1 ? '' : 's'}`
        : '';
      const link = messagesUrl
        ? `${messagesUrl}?thread=${encodeURIComponent(Number(message.thread_id) || 0)}`
        : '';
      window.showAppToast?.(`${message.sender_name || 'A contact'}: ${message.message || 'Sent you a message'}${extras}`, {
        type: 'info',
        title: 'New message',
        actionLabel: link ? 'Open chat' : '',
        actionUrl: link,
        duration: 12000
      });
    });
    messageCursor = Math.max(messageCursor, Number(result.cursor) || 0);
    page.dataset.unreadMessages = String(Number(result.unread) || 0);
  }

  async function poll() {
    if (polling || document.hidden) return;
    polling = true;
    try {
      await Promise.allSettled([pollNotifications(), pollMessages()]);
    } finally {
      polling = false;
    }
  }

  poll();
  window.setInterval(poll, pollIntervalMs);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) poll();
  });
})();
