/* OJTRACK — Main JavaScript */

// ── Modals ──────────────────────────────────────────────────
const modalOpeners = new Map();
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  modalOpeners.set(id, document.activeElement);
  el.classList.add('open');
  const dialog = el.querySelector('.modal') || el;
  dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.tabIndex = -1;
  const title = dialog.querySelector('.modal-title');
  if (title) { if (!title.id) title.id = id + '-title'; dialog.setAttribute('aria-labelledby', title.id); }
  document.body.style.overflow = 'hidden';
  (dialog.querySelector('input:not([type="hidden"]), textarea, select, button') || dialog).focus();
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('open');
  if (!document.querySelector('.modal-overlay.open')) document.body.style.overflow = '';
  modalOpeners.get(id)?.focus(); modalOpeners.delete(id);
}
document.addEventListener('keydown', event => {
  const overlays = document.querySelectorAll('.modal-overlay.open');
  const el = overlays[overlays.length - 1];
  if (!el) return;
  if (event.key === 'Escape') { event.preventDefault(); closeModal(el.id); }
  if (event.key !== 'Tab') return;
  const focusable = [...el.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')].filter(node => node.getClientRects().length);
  if (!focusable.length) { event.preventDefault(); return; }
  const first = focusable[0], last = focusable[focusable.length - 1];
  if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
});

/** Prevent nested controls (buttons, links, forms) from triggering row/card click handlers */
function stopRowClick(e) {
  if (e) e.stopPropagation();
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    closeModal(e.target.id);
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

// Notification mutations use the same form encoding as the PHP API.
async function updateNotificationRead(id, all = false) {
  const body = new URLSearchParams({action: 'mark_read', ...(all ? {all: '1'} : {id: String(id)})});
  const response = await fetch('/ojtrack/api/notifications.php', {
    method: 'POST',
    headers: {'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
    body
  });
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to update notification.');
}
function reflectNotificationRead(el) {
  if (!el || !el.classList.contains('unread')) return;
  el.classList.remove('unread'); el.classList.add('read');
  el.querySelector('.notif-unread-dot')?.remove();
  const badge = document.getElementById('notifCounterBadge');
  if (badge) {
    const count = Math.max(0, (parseInt(badge.textContent, 10) || 0) - 1);
    if (count) badge.textContent = count; else badge.remove();
  }
}
async function handleNotificationClick(id, link, el) {
  try {
    await updateNotificationRead(id);
    reflectNotificationRead(el);
    if (link && link.startsWith('/ojtrack/') && !link.includes('\\')) window.location.href = link;
  } catch (error) { alert(error.message); }
}
async function markAllNotificationsRead(e) {
  e?.stopPropagation(); e?.preventDefault();
  try {
    await updateNotificationRead(null, true);
    document.querySelectorAll('.notif-item.unread').forEach(reflectNotificationRead);
    document.getElementById('notifCounterBadge')?.remove();
  } catch (error) { alert(error.message); }
}
async function markNotificationSingle(id, el) {
  try { await updateNotificationRead(id); reflectNotificationRead(el); }
  catch (error) { alert(error.message); }
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
    area.addEventListener('click', e => { if (e.target !== input) input.click(); });
    area.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        input.click();
      }
    });
    area.addEventListener('dragover', e => {
      e.preventDefault();
      area.classList.add('is-dragging');
      area.style.borderColor = 'var(--primary)';
    });
    area.addEventListener('dragleave', () => {
      area.classList.remove('is-dragging');
      area.style.borderColor = '';
    });
    area.addEventListener('drop', e => {
      e.preventDefault();
      area.classList.remove('is-dragging');
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
  area.classList.toggle('has-file', !!filename);
  if (filename) area.setAttribute('aria-label', 'Selected file: ' + filename);
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
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
    body: `thread_id=${encodeURIComponent(threadId)}&message=${encodeURIComponent(msg)}`
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      appendMessage(msg, 'Me', 'mine');
      textarea.value = '';
    }
  })
  .catch(() => { alert('Message was not sent. Please try again.'); });
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
// ── Profile avatar preview ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  const input = document.getElementById('profileAvatarInput');
  const preview = document.getElementById('profileAvatarPreview');
  const filename = document.getElementById('profileAvatarFileName');
  const saveButton = document.getElementById('profileAvatarSave');
  if (!input || !preview || !filename || !saveButton) return;

  const originalPreview = preview.innerHTML;
  const defaultHint = filename.textContent;
  let objectUrl = '';

  input.addEventListener('change', function() {
    const file = input.files && input.files[0] ? input.files[0] : null;

    if (objectUrl) {
      URL.revokeObjectURL(objectUrl);
      objectUrl = '';
    }

    if (!file) {
      preview.innerHTML = originalPreview;
      filename.textContent = defaultHint;
      filename.classList.remove('is-selected');
      saveButton.disabled = true;
      return;
    }

    const allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!allowed.includes(file.type) || file.size > 5 * 1024 * 1024) {
      input.value = '';
      preview.innerHTML = originalPreview;
      filename.textContent = file.size > 5 * 1024 * 1024
        ? 'That image is larger than 5 MB.'
        : 'Choose a JPG, PNG, GIF, or WEBP image.';
      filename.classList.remove('is-selected');
      saveButton.disabled = true;
      return;
    }

    objectUrl = URL.createObjectURL(file);
    const img = document.createElement('img');
    img.src = objectUrl;
    img.alt = 'Selected profile photo preview';
    img.className = 'profile-avatar-preview-img';
    preview.replaceChildren(img);

    const sizeMb = file.size / (1024 * 1024);
    filename.textContent = file.name + ' · ' + (sizeMb >= 0.1 ? sizeMb.toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB');
    filename.classList.add('is-selected');
    saveButton.disabled = false;
  });

  input.form?.addEventListener('submit', function() {
    if (!saveButton.disabled) {
      saveButton.disabled = true;
      saveButton.textContent = 'Saving…';
    }
  });
});

