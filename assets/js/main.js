/* OJTRACK — Main JavaScript */

// ── Modals ──────────────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('open');
    document.body.style.overflow = '';
  }
}

/** Prevent nested controls (buttons, links, forms) from triggering row/card click handlers */
function stopRowClick(e) {
  if (e) e.stopPropagation();
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

// ── Tabs ────────────────────────────────────────────────────
function switchTab(tabGroup, tabId) {
  document.querySelectorAll(`[data-tab-group="${tabGroup}"]`).forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tabId);
  });
  document.querySelectorAll(`[data-tab-content="${tabGroup}"]`).forEach(pane => {
    pane.style.display = pane.dataset.tab === tabId ? 'block' : 'none';
  });
}

// ── Notifications ────────────────────────────────────────────
function toggleNotifDropdown(e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  const menu = document.getElementById('notifDropdownMenu');
  const btn = document.getElementById('notifToggleBtn');
  if (!menu) return;

  const isOpen = menu.classList.contains('open');
  // Close user dropdown if open
  const userMenu = document.getElementById('userDropdownMenu');
  if (userMenu) userMenu.classList.remove('open');

  if (isOpen) {
    menu.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  } else {
    menu.classList.add('open');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }
}

// ── User Dropdown ─────────────────────────────────────────────
function toggleUserDropdown(e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  const menu = document.getElementById('userDropdownMenu');
  const btn = document.getElementById('userToggleBtn');
  if (!menu) return;

  const isOpen = menu.classList.contains('open');
  // Close notification dropdown if open
  const notifMenu = document.getElementById('notifDropdownMenu');
  if (notifMenu) notifMenu.classList.remove('open');

  if (isOpen) {
    menu.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  } else {
    menu.classList.add('open');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }
}

function closeUserDropdown() {
  const menu = document.getElementById('userDropdownMenu');
  const btn = document.getElementById('userToggleBtn');
  if (menu) menu.classList.remove('open');
  if (btn) btn.setAttribute('aria-expanded', 'false');
}

// Handle notification click - mark read and redirect
function handleNotificationClick(id, link, el) {
  if (id) {
    fetch('/ojtrack/api/notifications.php?action=mark_read&id=' + encodeURIComponent(id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    }).catch(() => {});
  }
  if (el) {
    el.classList.remove('unread');
    el.classList.add('read');
    const dot = el.querySelector('.notif-unread-dot');
    if (dot) dot.remove();
  }
  const badge = document.getElementById('notifCounterBadge');
  if (badge) {
    const current = parseInt(badge.textContent, 10);
    if (current > 1) {
      badge.textContent = current - 1;
    } else {
      badge.remove();
    }
  }
  if (link) {
    window.location.href = link;
  }
}

async function markAllNotificationsRead(e) {
  if (e) {
    e.stopPropagation();
    e.preventDefault();
  }
  try {
    const res = await fetch('/ojtrack/api/notifications.php?action=mark_read', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });
    const data = await res.json();
    if (data.success) {
      const badge = document.getElementById('notifCounterBadge');
      if (badge) badge.remove();
      document.querySelectorAll('.notif-item.unread').forEach(item => {
        item.classList.remove('unread');
        item.classList.add('read');
      });
      document.querySelectorAll('.notif-unread-dot').forEach(dot => dot.remove());
    }
  } catch (err) {
    console.error('Failed to mark notifications read:', err);
  }
}

async function markNotificationSingle(id, el) {
  if (!id) return;
  try {
    await fetch('/ojtrack/api/notifications.php?action=mark_read&id=' + encodeURIComponent(id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });
    if (el) {
      el.classList.remove('unread');
      el.classList.add('read');
      const dot = el.querySelector('.notif-unread-dot');
      if (dot) dot.remove();
    }
    const badge = document.getElementById('notifCounterBadge');
    if (badge) {
      const current = parseInt(badge.textContent, 10);
      if (current > 1) {
        badge.textContent = current - 1;
      } else {
        badge.remove();
      }
    }
  } catch (err) {
    console.error('Error marking notification as read:', err);
  }
}

// ── Sidebar Toggle for Mobile ───────────────────────────────
function toggleSidebar(open) {
  const sidebar = document.querySelector('.sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  const shouldOpen = typeof open === 'boolean' ? open : !sidebar.classList.contains('open');
  sidebar.classList.toggle('open', shouldOpen);
  if (backdrop) {
    backdrop.classList.toggle('open', shouldOpen);
    backdrop.classList.toggle('show', shouldOpen);
  }
  document.body.classList.toggle('sidebar-open', shouldOpen);
}

// ── Desktop Sidebar Collapse (icons only) ───────────────────
const SIDEBAR_COLLAPSE_KEY = 'ojtrack_sidebar_collapsed';

function isDesktopSidebar() {
  return window.matchMedia('(min-width: 769px)').matches;
}

function applySidebarCollapsed(collapsed) {
  const on = !!collapsed && isDesktopSidebar();
  document.documentElement.classList.toggle('sidebar-collapsed', on);
  document.body.classList.toggle('sidebar-collapsed', on);

  const btn = document.getElementById('sidebarCollapseBtn');
  if (btn) {
    btn.setAttribute('aria-label', on ? 'Expand sidebar' : 'Collapse sidebar');
    btn.setAttribute('title', on ? 'Expand menu' : 'Collapse menu');
    btn.setAttribute('aria-expanded', on ? 'false' : 'true');
  }

  const fab = document.getElementById('sidebarExpandFab');
  if (fab) fab.setAttribute('aria-hidden', on ? 'false' : 'true');
}

function toggleSidebarCollapse(force) {
  if (!isDesktopSidebar() && typeof force !== 'boolean') return;

  let next;
  if (typeof force === 'boolean') {
    next = force;
  } else {
    next = !document.documentElement.classList.contains('sidebar-collapsed');
  }

  try {
    localStorage.setItem(SIDEBAR_COLLAPSE_KEY, next ? '1' : '0');
  } catch (e) {}

  applySidebarCollapsed(next);
}

function initSidebarCollapse() {
  if (!document.getElementById('sidebar')) return;

  let saved = false;
  try {
    saved = localStorage.getItem(SIDEBAR_COLLAPSE_KEY) === '1';
  } catch (e) {}

  applySidebarCollapsed(saved);

  // Clicking the logo while collapsed expands the menu
  const logo = document.querySelector('.sidebar-logo');
  if (logo && !logo.dataset.collapseBound) {
    logo.dataset.collapseBound = '1';
    logo.addEventListener('click', function (e) {
      if (!document.documentElement.classList.contains('sidebar-collapsed')) return;
      if (e.target.closest('a, button')) return;
      toggleSidebarCollapse(false);
    });
  }

  // Ensure floating expand button exists
  if (!document.getElementById('sidebarExpandFab')) {
    const fab = document.createElement('button');
    fab.type = 'button';
    fab.id = 'sidebarExpandFab';
    fab.className = 'sidebar-expand-fab';
    fab.setAttribute('aria-label', 'Expand sidebar');
    fab.title = 'Expand menu';
    fab.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
    fab.addEventListener('click', function (e) {
      e.preventDefault();
      toggleSidebarCollapse(false);
    });
    document.body.appendChild(fab);
  }

  window.addEventListener('resize', function () {
    let savedState = false;
    try {
      savedState = localStorage.getItem(SIDEBAR_COLLAPSE_KEY) === '1';
    } catch (e) {}
    applySidebarCollapsed(savedState);
  });
}

document.addEventListener('DOMContentLoaded', initSidebarCollapse);

// Global click handler to close dropdowns and mobile sidebar
document.addEventListener('click', function(e) {
  const notifMenu = document.getElementById('notifDropdownMenu');
  const notifWrap = document.getElementById('notifDropdownWrap');
  if (notifMenu && notifMenu.classList.contains('open')) {
    if (!notifWrap || !notifWrap.contains(e.target)) {
      notifMenu.classList.remove('open');
      const btn = document.getElementById('notifToggleBtn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
  }

  const userMenu = document.getElementById('userDropdownMenu');
  const userWrap = document.getElementById('userDropdownWrap');
  if (userMenu && userMenu.classList.contains('open')) {
    if (!userWrap || !userWrap.contains(e.target)) {
      userMenu.classList.remove('open');
      const btn = document.getElementById('userToggleBtn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
  }

  const sidebar = document.querySelector('.sidebar');
  const menuBtn = document.querySelector('.topbar-menu-btn');
  if (sidebar && sidebar.classList.contains('open')) {
    if (!sidebar.contains(e.target) && (!menuBtn || !menuBtn.contains(e.target))) {
      toggleSidebar(false);
    }
  }
});

// ── Search filter for tables ─────────────────────────────────
function filterTable(inputId, tableId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  document.querySelectorAll(`#${tableId} tbody tr`).forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// ── Upload area ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.upload-area').forEach(area => {
    const input = area.querySelector('input[type=file]');
    if (!input) return;
    area.addEventListener('click', () => input.click());
    area.addEventListener('dragover', e => { e.preventDefault(); area.style.borderColor = 'var(--primary)'; });
    area.addEventListener('dragleave', () => { area.style.borderColor = ''; });
    area.addEventListener('drop', e => {
      e.preventDefault();
      area.style.borderColor = '';
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        updateUploadLabel(area, e.dataTransfer.files[0].name);
      }
    });
    input.addEventListener('change', () => {
      if (input.files.length) updateUploadLabel(area, input.files[0].name);
    });
  });
});

function updateUploadLabel(area, filename) {
  const title = area.querySelector('.upload-title');
  if (title) title.textContent = filename;
}

// ── Confirm dialog ───────────────────────────────────────────
function confirmAction(message, formId) {
  if (confirm(message)) {
    document.getElementById(formId).submit();
  }
}

// ── Chat ────────────────────────────────────────────────────
function sendChatMessage() {
  const textarea = document.getElementById('chat-input');
  const msg = textarea ? textarea.value.trim() : '';
  if (!msg) return;

  const threadId = document.getElementById('active-thread-id')?.value;
  if (!threadId) return;

  fetch('/ojtrack/api/messages.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `thread_id=${encodeURIComponent(threadId)}&message=${encodeURIComponent(msg)}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      appendMessage(msg, 'Me', 'mine');
      textarea.value = '';
    }
  })
  .catch(() => {
    appendMessage(msg, 'Me', 'mine');
    textarea.value = '';
  });
}

function appendMessage(text, sender, type) {
  const container = document.getElementById('chat-messages');
  if (!container) return;

  const div = document.createElement('div');
  div.className = 'msg-group ' + (type === 'mine' ? 'mine' : '');
  div.innerHTML = `
    <div class="sidebar-avatar" style="width:28px;height:28px;font-size:10px">${sender.split(' ').map(n=>n[0]).join('').slice(0,2).toUpperCase()}</div>
    <div>
      ${type !== 'mine' ? `<div class="msg-sender">${sender}</div>` : ''}
      <div class="msg-bubble">${escapeHtml(text)}</div>
      <div class="msg-meta">Just now</div>
    </div>
  `;
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
}

function escapeHtml(str) {
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Enter to send in chat
document.addEventListener('DOMContentLoaded', function() {
  const chatInput = document.getElementById('chat-input');
  if (chatInput) {
    chatInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendChatMessage();
      }
    });
  }
});

// ── Circular progress animation ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const fillColors = {
    blue: '#2563eb',
    green: '#10b981',
    amber: '#f59e0b',
    violet: '#7c3aed'
  };

  document.querySelectorAll('.progress-fill[data-pct]').forEach(fill => {
    const pct = Math.max(0, Math.min(100, parseFloat(fill.dataset.pct) || 0));
    const track = fill.closest('.progress-track');
    fill.textContent = Math.round(pct) + '%';
    if (track) {
      const colorKey = Object.keys(fillColors).find(k => fill.classList.contains(k));
      if (colorKey) track.style.setProperty('--fill-color', fillColors[colorKey]);
      track.style.setProperty('--pct', '0');
      setTimeout(() => { track.style.setProperty('--pct', String(pct)); }, 80);
    }
  });

  document.querySelectorAll('.ojt-banner-progress[data-pct]').forEach(el => {
    const pct = Math.max(0, Math.min(100, parseFloat(el.dataset.pct) || 0));
    const fill = el.querySelector('.fill');
    if (fill) fill.textContent = Math.round(pct) + '%';
    el.style.setProperty('--pct', '0');
    setTimeout(() => { el.style.setProperty('--pct', String(pct)); }, 80);
  });
});

// ── Flash message auto-dismiss ───────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const flash = document.querySelector('.flash-message');
  if (flash) setTimeout(() => { flash.style.opacity = '0'; flash.style.transition = 'opacity .5s'; setTimeout(() => flash.remove(), 500); }, 4000);
});

// ── Role selector on login ───────────────────────────────────
document.querySelectorAll('.role-option').forEach(opt => {
  opt.addEventListener('click', function() {
    document.querySelectorAll('.role-option').forEach(o => o.classList.remove('selected'));
    this.classList.add('selected');
    const radio = this.querySelector('input[type=radio]');
    if (radio) radio.checked = true;
  });
});
