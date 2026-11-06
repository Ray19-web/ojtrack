/* OJTRACK — Main JavaScript */

// ── Modals ──────────────────────────────────────────────────
const modalOpeners = new Map();
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  // Shared help dialogs may be rendered inside a hidden add/edit form by a PHP include.
  // Lift this one to the document layer before opening so it is reachable from every form.
  if (el.classList.contains('name-extension-help-overlay') && el.parentElement !== document.body) {
    document.body.appendChild(el);
  }
  modalOpeners.set(id, document.activeElement);
  el.classList.add('open');
  const dialog = el.querySelector('.modal') || el;
  prepareFormModalHeader(dialog);
  dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); dialog.tabIndex = -1;
  const title = dialog.querySelector('.modal-title');
  if (title) { if (!title.id) title.id = id + '-title'; dialog.setAttribute('aria-labelledby', title.id); }
  normalizeUppercaseNameFields(dialog);
  dialog.querySelectorAll('form[method="post"], form[method="POST"]').forEach(captureAjaxFormBaseline);
  document.body.style.overflow = 'hidden';
  (dialog.querySelector('input:not([type="hidden"]), textarea, select, button') || dialog).focus({ preventScroll: true });
}

function prepareFormModalHeader(dialog) {
  if (!dialog?.classList.contains('modal') || dialog.classList.contains('student-form-modal') || dialog.classList.contains('announcement-modal') || dialog.classList.contains('evaluation-wizard-modal')) return;
  if (dialog.classList.contains('has-form-sticky-header')) return;
  const directForm = [...dialog.children].find(child => child.tagName === 'FORM' && !child.matches('.announcement-form, .evaluation-wizard-form, .student-form'));
  const title = [...dialog.children].find(child => child.classList?.contains('modal-title'));
  if (!directForm || !title) return;

  const subtitle = title.nextElementSibling?.classList.contains('modal-sub') ? title.nextElementSibling : null;
  const header = document.createElement('div');
  header.className = 'form-modal-sticky-header';
  dialog.insertBefore(header, title);
  header.appendChild(title);
  if (subtitle) header.appendChild(subtitle);
  dialog.classList.add('has-form-sticky-header');
}

function uppercaseNameField(field) {
  if (!field || !field.matches('[data-uppercase-name]')) return;
  const start = field.selectionStart, end = field.selectionEnd;
  const uppercase = field.value.toUpperCase();
  if (uppercase === field.value) return;
  field.value = uppercase;
  if (typeof start === 'number' && typeof end === 'number') {
    try { field.setSelectionRange(start, end); } catch (_) {}
  }
}

function normalizeUppercaseNameFields(root) {
  if (!root) return;
  if (root.matches?.('[data-uppercase-name]')) uppercaseNameField(root);
  root.querySelectorAll?.('[data-uppercase-name]').forEach(uppercaseNameField);
}

document.addEventListener('input', event => uppercaseNameField(event.target));
document.addEventListener('change', event => uppercaseNameField(event.target));
document.addEventListener('submit', event => normalizeUppercaseNameFields(event.target), true);
document.addEventListener('DOMContentLoaded', () => normalizeUppercaseNameFields(document));
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

// Student record rows share their existing, role-authorized details destination.
document.addEventListener('click', event => {
  const row = event.target.closest('tr[data-row-link]');
  if (!row || event.defaultPrevented || event.button !== 0) return;
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  if (event.target.closest('a, button, input, select, textarea, label, form, [contenteditable], [role="button"]')) return;
  if (window.getSelection()?.toString()) return;
  window.location.assign(row.dataset.rowLink);
});

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
  closeUserDropdown();

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
  document.getElementById('notifToggleBtn')?.setAttribute('aria-expanded', 'false');

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

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape' || document.querySelector('.modal-overlay.open, .sidebar.open')) return;
  if (document.getElementById('userDropdownMenu')?.classList.contains('open')) {
    closeUserDropdown();
    document.getElementById('userToggleBtn')?.focus();
  }
  const notifications = document.getElementById('notifDropdownMenu');
  if (notifications?.classList.contains('open')) {
    notifications.classList.remove('open');
    const button = document.getElementById('notifToggleBtn');
    button?.setAttribute('aria-expanded', 'false');
    button?.focus();
  }
});

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
  } catch (error) { window.showAppToast?.(error.message, {type: 'error'}); }
}
async function markAllNotificationsRead(e) {
  e?.stopPropagation(); e?.preventDefault();
  try {
    await updateNotificationRead(null, true);
    document.querySelectorAll('.notif-item.unread').forEach(reflectNotificationRead);
    document.getElementById('notifCounterBadge')?.remove();
  } catch (error) { window.showAppToast?.(error.message, {type: 'error'}); }
}
async function markNotificationSingle(id, el) {
  try { await updateNotificationRead(id); reflectNotificationRead(el); }
  catch (error) { window.showAppToast?.(error.message, {type: 'error'}); }
}

// ── Sidebar Toggle for Mobile ───────────────────────────────
function toggleSidebar(open, restoreFocus = true) {
  const sidebar = document.querySelector('.sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const menuButton = document.querySelector('.topbar-menu-btn');
  if (!sidebar) return;
  const mobile = !isDesktopSidebar();
  const wasOpen = sidebar.classList.contains('open');
  const shouldOpen = mobile && (typeof open === 'boolean' ? open : !wasOpen);
  sidebar.classList.toggle('open', shouldOpen);
  sidebar.inert = mobile && !shouldOpen;
  document.querySelector('.main-content')?.toggleAttribute('inert', shouldOpen);
  menuButton?.setAttribute('aria-expanded', String(shouldOpen));
  if (backdrop) {
    backdrop.classList.toggle('open', shouldOpen);
    backdrop.classList.toggle('show', shouldOpen);
  }
  document.body.classList.toggle('sidebar-open', shouldOpen);
  if (shouldOpen) {
    (sidebar.querySelector('.sidebar-close-btn') || sidebar.querySelector('a'))?.focus();
  } else if (wasOpen && restoreFocus && mobile) {
    menuButton?.focus();
  }
}

document.addEventListener('keydown', event => {
  const sidebar = document.querySelector('.sidebar.open');
  if (!sidebar || document.querySelector('.modal-overlay.open')) return;
  if (event.key === 'Escape') { event.preventDefault(); toggleSidebar(false); }
  if (event.key !== 'Tab') return;
  const items = [...sidebar.querySelectorAll('a[href], button:not([disabled])')].filter(node => node.getClientRects().length);
  const first = items[0], last = items[items.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
});

document.addEventListener('DOMContentLoaded', () => {
  if (!document.querySelector('.sidebar')) return;
  toggleSidebar(false, false);
  window.matchMedia('(min-width: 769px)').addEventListener('change', () => toggleSidebar(false, false));
  document.querySelector('.sidebar-nav')?.addEventListener('click', event => {
    if (event.target.closest('a') && !isDesktopSidebar()) toggleSidebar(false, false);
  });
});

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

// Each role keeps its own expanded navigation groups. The current page is
// revealed on every load; icon-only desktop navigation always exposes all links.
document.addEventListener('DOMContentLoaded', function () {
  const nav = document.querySelector('.sidebar-nav');
  if (!nav) return;
  const role = [...document.body.classList].find(name => name.startsWith('role-')) || 'default';
  const storageKey = 'ojtrack_nav_sections_' + role;
  let saved = {};
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
    if (stored && typeof stored === 'object' && !Array.isArray(stored)) saved = stored;
  } catch (_) {}
  for (const section of nav.querySelectorAll('.nav-section')) {
    const button = section.querySelector('.nav-section-toggle');
    if (!button) continue;
    function setExpanded(expanded) {
      section.classList.toggle('is-collapsed', !expanded);
      button.setAttribute('aria-expanded', String(expanded));
    }
    setExpanded(section.classList.contains('contains-active') || saved[section.dataset.sectionKey] === true);
    button.addEventListener('click', function () {
      const expanded = button.getAttribute('aria-expanded') !== 'true';
      setExpanded(expanded);
      saved[section.dataset.sectionKey] = expanded;
      try { localStorage.setItem(storageKey, JSON.stringify(saved)); } catch (_) {}
    });
  }
});

function updateNameExtensionControls(fields) {
  const rows = fields.querySelectorAll('[data-name-extension-row]');
  const addButton = fields.querySelector('[data-add-name-extension]');
  if (addButton) addButton.disabled = rows.length >= 10;
}

window.setNameExtensionFields = function (prefix, extensions) {
  const fields = document.querySelector('[data-name-extension-fields][data-name-extension-prefix="' + prefix + '"]');
  if (!fields) return;
  const list = fields.querySelector('[data-name-extension-list]');
  const template = fields.querySelector('[data-name-extension-template]');
  list.replaceChildren();
  const entries = Array.isArray(extensions) && extensions.length ? extensions.slice(0, 10) : [{}];
  entries.forEach(function (entry) {
    const row = template.content.firstElementChild.cloneNode(true);
    row.querySelector('select').value = entry.type || '';
    row.querySelector('input').value = entry.value || '';
    list.appendChild(row);
  });
  updateNameExtensionControls(fields);
};

document.addEventListener('click', function (event) {
  const addButton = event.target.closest('[data-add-name-extension]');
  if (addButton) {
    const fields = addButton.closest('[data-name-extension-fields]');
    const list = fields.querySelector('[data-name-extension-list]');
    if (list.querySelectorAll('[data-name-extension-row]').length < 10) {
      list.appendChild(fields.querySelector('[data-name-extension-template]').content.firstElementChild.cloneNode(true));
      updateNameExtensionControls(fields);
    }
    return;
  }
  const removeButton = event.target.closest('[data-remove-name-extension]');
  if (removeButton) {
    const fields = removeButton.closest('[data-name-extension-fields]');
    removeButton.closest('[data-name-extension-row]').remove();
    updateNameExtensionControls(fields);
  }
});

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

// Shared GET filters update the page body in place instead of navigating/reloading.
const liveFilterRequests = new WeakMap();

function liveFilterFocusInfo(pageBody) {
  const active = document.activeElement;
  if (!active || !pageBody.contains(active)) return null;
  const form = active.form;
  if (form && pageBody.contains(form)) {
    return {
      kind: 'form-control',
      formIndex: [...pageBody.querySelectorAll('form')].indexOf(form),
      controlIndex: [...form.elements].indexOf(active),
      start: typeof active.selectionStart === 'number' ? active.selectionStart : null,
      end: typeof active.selectionEnd === 'number' ? active.selectionEnd : null
    };
  }
  const tools = active.closest('.table-tools');
  if (tools) {
    return {
      kind: 'table-tool',
      toolsIndex: [...pageBody.querySelectorAll('.table-tools')].indexOf(tools),
      className: active.className,
      start: typeof active.selectionStart === 'number' ? active.selectionStart : null,
      end: typeof active.selectionEnd === 'number' ? active.selectionEnd : null
    };
  }
  return null;
}

function restoreLiveFilterFocus(pageBody, info) {
  if (!info) return;
  let target = null;
  if (info.kind === 'form-control') {
    const form = pageBody.querySelectorAll('form')[info.formIndex];
    target = form?.elements?.[info.controlIndex] || null;
  } else if (info.kind === 'table-tool') {
    const tools = pageBody.querySelectorAll('.table-tools')[info.toolsIndex];
    target = [...(tools?.querySelectorAll('input, button, select') || [])]
      .find(control => control.className === info.className) || null;
  }
  if (!target) return;
  target.focus({preventScroll: true});
  if (info.start !== null && typeof target.setSelectionRange === 'function') {
    try { target.setSelectionRange(info.start, info.end); } catch (_) {}
  }
}

async function updateLiveFilterResults(form, requestUrl = null) {
  const pageBody = document.querySelector('.page-body');
  if (!pageBody) return;
  const submittedFromDrawer = Boolean(form?.closest('.table-filter-drawer'));
  const url = requestUrl ? new URL(requestUrl, location.href) : new URL(form.getAttribute('action') || location.href, location.href);
  if (!requestUrl) {
    const params = new URLSearchParams(new FormData(form));
    url.search = params.toString();
  }
  if (url.origin !== location.origin) return;

  const liveForms = [...document.querySelectorAll('form[data-live-filter]')];
  const formIndex = form ? Number(form.dataset.liveFilterIndex ?? liveForms.indexOf(form)) : 0;
  if (form) liveFilterRequests.get(form)?.abort();
  const controller = new AbortController();
  if (form) liveFilterRequests.set(form, controller);
  const focusInfo = liveFilterFocusInfo(pageBody);
  const submitButton = [...form.elements].find(control => control.type === 'submit') || null;
  const originalButtonText = submitButton?.textContent;
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.dataset.liveFilterText = originalButtonText || '';
    submitButton.textContent = 'Searching…';
  }
  form.setAttribute('aria-busy', 'true');

  try {
    const response = await fetch(url.href, {
      method: 'GET',
      headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'},
      credentials: 'same-origin',
      signal: controller.signal
    });
    if (!response.ok) throw new Error('Search request failed');
    const html = await response.text();
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const incomingBody = parsed.querySelector('.page-body');
    if (!incomingBody || !document.querySelector('.page-body')) throw new Error('Search results were unavailable');

    const currentBody = document.querySelector('.page-body');
    closeTableFilterDrawers();
    document.querySelectorAll('.table-filter-drawer').forEach(drawer => drawer.remove());
    currentBody.querySelectorAll('.table-wrap table').forEach(table => table._paginationObserver?.disconnect());
    const existingModals = [...currentBody.querySelectorAll('.modal-overlay')];
    const refreshedContent = document.createDocumentFragment();
    [...incomingBody.childNodes].forEach(node => refreshedContent.appendChild(node.cloneNode(true)));
    refreshedContent.querySelectorAll('.modal-overlay').forEach(modal => modal.remove());
    currentBody.replaceChildren(refreshedContent);
    // Keep existing modal nodes and their event handlers intact while refreshing the list content.
    existingModals.forEach(modal => currentBody.appendChild(modal));
    bindLiveFilterForms(currentBody);
    currentBody.querySelectorAll('.table-wrap table').forEach((table, index) => addTableTools(table, index));
    restoreLiveFilterFocus(currentBody, focusInfo);
    if (submittedFromDrawer) {
      const refreshedForm = document.querySelector('[data-live-filter-index="' + formIndex + '"]');
      if (refreshedForm?.id) document.querySelector('[data-table-filter-toggle="' + refreshedForm.id + '"]')?.focus({preventScroll: true});
    }
    history.replaceState({}, '', url.pathname + url.search + url.hash);
  } catch (error) {
    if (error.name !== 'AbortError') {
      console.error('Could not update search results:', error);
      form.dataset.liveFilterError = 'true';
    }
  } finally {
    if (form && liveFilterRequests.get(form) === controller) liveFilterRequests.delete(form);
    const currentForm = document.querySelector('[data-live-filter-index="' + formIndex + '"]')
      || document.querySelectorAll('form[data-live-filter]')[formIndex];
    if (currentForm) {
      currentForm.removeAttribute('aria-busy');
      delete currentForm.dataset.liveFilterError;
      const currentButton = [...currentForm.elements].find(control => control.type === 'submit') || null;
      if (currentButton) {
        currentButton.disabled = false;
        currentButton.textContent = currentButton.dataset.liveFilterText || currentButton.textContent.replace(/^Searching…$/, originalButtonText || 'Search');
        delete currentButton.dataset.liveFilterText;
      }
    }
  }
}

function bindLiveFilterForms(root = document) {
  root.querySelectorAll('form[data-live-filter]').forEach(form => {
    if (!form.dataset.liveFilterIndex) {
      form.dataset.liveFilterIndex = String([...document.querySelectorAll('form[data-live-filter]')].indexOf(form));
    }
    if (form.dataset.liveFilterBound === 'true') return;
    form.dataset.liveFilterBound = 'true';
    let searchTimer;
    form.addEventListener('input', event => {
      if (!event.target.matches('input[type="search"], input[type="text"][name="q"]')) return;
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => updateLiveFilterResults(form), 350);
    });
    form.addEventListener('change', event => {
      if (!form.closest('.table-filter-drawer') && event.target.matches('select, input[type="date"], input[type="month"], input[type="week"]')) {
        window.clearTimeout(searchTimer);
        updateLiveFilterResults(form);
      }
    });
    form.addEventListener('submit', event => {
      event.preventDefault();
      window.clearTimeout(searchTimer);
      updateLiveFilterResults(form);
    });
  });
}

document.addEventListener('DOMContentLoaded', () => bindLiveFilterForms());

// Submit ordinary app forms in the background, then replace only the page content.
// This keeps navigation, filters, and forms feeling like one continuous app.
const ajaxFormBaselines = new WeakMap();

function captureAjaxFormBaseline(form) {
  if (!form || [...form.querySelectorAll('input[type="password"], input[type="file"]')].length) {
    if (form) ajaxFormBaselines.delete(form);
    return;
  }
  ajaxFormBaselines.set(form, new FormData(form));
}

function baselineFormData(form) {
  if (!form) return null;
  const openedBaseline = ajaxFormBaselines.get(form);
  if (openedBaseline) {
    const copy = new FormData();
    for (const [key, value] of openedBaseline.entries()) copy.append(key, value);
    return copy;
  }
  const baseline = form.cloneNode(true);
  baseline.reset();
  const data = new FormData(baseline);
  if ([...baseline.querySelectorAll('input[type="password"], input[type="file"]')].length) return null;
  return data;
}

function isUndoableUpdate(data) {
  const action = String(data?.get('action') || '');
  return /^(edit|update)_[a-z0-9_]+$/i.test(action)
    && !/password|avatar|credential|login/i.test(action);
}

function formDataWithAction(source, action) {
  const copy = new FormData();
  for (const [key, value] of source.entries()) copy.append(key, value);
  copy.set('action', action);
  return copy;
}

function getUndoAction(endpoint, submitted, baseline) {
  const action = String(submitted?.get('action') || '');
  if (isUndoableUpdate(submitted) && isUndoableUpdate(baseline) && formDataChanged(baseline, submitted)) {
    return {endpoint, payload: baseline};
  }
  if (action === 'archive_user') return {endpoint, payload: formDataWithAction(submitted, 'restore_user')};
  if (action === 'restore_user') return {
    endpoint: new URL('/ojtrack/admin/users.php', location.origin).href,
    payload: formDataWithAction(submitted, 'archive_user')
  };
  if (action === 'archive_post') return {endpoint, payload: formDataWithAction(submitted, 'restore_post')};
  if (action === 'restore_post') return {endpoint, payload: formDataWithAction(submitted, 'archive_post')};
  if (action === 'archive_form') return {endpoint, payload: submitted};
  return null;
}

function formDataChanged(before, submitted) {
  if (!before || !submitted) return false;
  const ignored = new Set(['csrf_token', '_token']);
  const keys = new Set([...before.keys(), ...submitted.keys()]);
  for (const key of keys) {
    if (ignored.has(key)) continue;
    const left = before.getAll(key).map(value => typeof value === 'string' ? value : value.name).join('\u0000');
    const right = submitted.getAll(key).map(value => typeof value === 'string' ? value : value.name).join('\u0000');
    if (left !== right) return true;
  }
  return false;
}

function extractPageFeedback(parsed) {
  const errors = [];
  const successes = [];
  let invalidModalId = '';
  parsed.querySelectorAll('.alert.alert-error, .notice.error, .alert.alert-success, .notice.success').forEach(alert => {
    if (alert.dataset.persistentAlert === 'true') return;
    const message = (alert.querySelector('.alert-body')?.textContent || alert.textContent || '').replace(/\s+/g, ' ').trim();
    if (alert.matches('.alert-error, .notice.error')) invalidModalId ||= alert.closest('.modal-overlay')?.id || '';
    if (message) (alert.matches('.alert-error, .notice.error') ? errors : successes).push(message);
    alert.remove();
  });
  return {errors, successes, invalidModalId};
}

function replacePageBody(parsed, finalUrl, invalidModalId = '') {
  const currentBody = document.querySelector('.page-body, .onboarding-card');
  const nextBody = parsed.querySelector(currentBody?.matches('.onboarding-card') ? '.onboarding-card' : '.page-body');
  if (!currentBody || !nextBody) return false;
  currentBody.querySelectorAll('.table-wrap table').forEach(table => table._paginationObserver?.disconnect());
  const nextContent = document.createDocumentFragment();
  [...nextBody.childNodes].forEach(node => nextContent.appendChild(node.cloneNode(true)));
  currentBody.replaceChildren(nextContent);
  bindLiveFilterForms(currentBody);
  currentBody.querySelectorAll('.table-wrap table').forEach((table, index) => addTableTools(table, index));
  if (parsed.title) document.title = parsed.title;
  const incomingName = parsed.querySelector('.user-chip-name');
  const currentName = document.querySelector('.user-chip-name');
  if (incomingName && currentName) currentName.textContent = incomingName.textContent;
  const incomingAvatar = parsed.querySelector('.topbar-avatar');
  const currentAvatar = document.querySelector('.topbar-avatar');
  if (incomingAvatar && currentAvatar) currentAvatar.replaceWith(incomingAvatar.cloneNode(true));
  if (finalUrl && finalUrl.href !== location.href) history.pushState({}, '', finalUrl.pathname + finalUrl.search + finalUrl.hash);

  // Preserve server-side validation feedback by reopening its containing form modal.
  const invalidModal = invalidModalId ? document.getElementById(invalidModalId) : currentBody.querySelector('.modal-overlay:has(.alert-error), .modal-overlay:has(.notice.error)');
  if (invalidModal?.id) openModal(invalidModal.id);
  return true;
}

async function sendFormInBackground(endpoint, payload, settings = {}) {
  const response = await fetch(endpoint, {
    method: 'POST',
    body: payload,
    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'},
    credentials: 'same-origin'
  });
  const html = await response.text();
  const parsed = new DOMParser().parseFromString(html, 'text/html');
  const finalUrl = new URL(response.url || endpoint, location.href);
  if (!parsed.querySelector('.page-body, .onboarding-card')) {
    if (finalUrl.pathname !== location.pathname) location.assign(finalUrl.href);
    const requestMessage = response.status < 500 ? parsed.body.querySelector('p')?.textContent?.trim() : '';
    if (requestMessage) throw new Error(requestMessage);
    throw new Error('The page could not be updated. Please try again.');
  }

  const feedback = extractPageFeedback(parsed);
  if (!replacePageBody(parsed, finalUrl, feedback.invalidModalId)) throw new Error('The page could not be updated. Please try again.');
  if (feedback.errors.length) throw new Error(feedback.errors[0]);
  if (!response.ok) throw new Error('The request could not be completed. Please try again.');

  return feedback.successes[0] || settings.successMessage || 'Changes saved.';
}

document.addEventListener('submit', async event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.method.toLowerCase() !== 'post') return;
  if (form.matches('[data-no-ajax], [data-confirm-skip], [data-evaluation-wizard], #chatForm')) return;
  const endpoint = new URL(form.getAttribute('action') || location.href, location.href);
  if (endpoint.origin !== location.origin || /\/api\/|\/logout\.php$|\/download\.php$/i.test(endpoint.pathname)) return;

  event.preventDefault();
  const submitter = event.submitter;
  const payload = new FormData(form);
  if (submitter?.name && !payload.has(submitter.name)) payload.append(submitter.name, submitter.value);
  const baseline = baselineFormData(form);
  const undoAction = getUndoAction(endpoint.href, payload, baseline);
  const submitButtons = [...form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])')];
  const buttonLabels = submitButtons.map(button => button.tagName === 'INPUT' ? button.value : button.textContent);
  submitButtons.forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
  form.setAttribute('aria-busy', 'true');

  try {
    const successMessage = await sendFormInBackground(endpoint.href, payload, {silent: true});
    if (undoAction) {
      window.showAppToast(successMessage, {
        type: 'success',
        undo: async () => {
          await sendFormInBackground(undoAction.endpoint, undoAction.payload, {silent: true});
          return 'Your changes were undone.';
        }
      });
    } else window.showAppToast(successMessage, {type: 'success'});
  } catch (error) {
    window.showAppToast(error?.message || 'The request could not be completed. Please try again.', {type: 'error'});
  } finally {
    form.removeAttribute('aria-busy');
    submitButtons.forEach((button, index) => {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      if (button.tagName === 'INPUT') button.value = buttonLabels[index];
      else button.textContent = buttonLabels[index];
    });
  }
});

document.addEventListener('click', event => {
  const openFilterDrawer = document.querySelector('.table-filter-drawer.is-open');
  if (openFilterDrawer && !openFilterDrawer.contains(event.target) && !event.target.closest('[data-table-filter-toggle]')) {
    closeTableFilterDrawers();
  }
  const link = event.target.closest('a[href]');
  if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  const pageBody = document.querySelector('.page-body');
  if (!pageBody?.contains(link) && !link.closest('.table-filter-drawer')) return;

  const form = link.closest('form[data-live-filter]')
    || link.closest('.card')?.querySelector('form[data-live-filter]');
  if (!form) return;
  const url = new URL(link.href, location.href);
  if (url.origin !== location.origin || url.pathname !== location.pathname) return;

  event.preventDefault();
  updateLiveFilterResults(form, url.href);
});

function closeTableFilterDrawers() {
  document.querySelectorAll('.table-filter-drawer.is-open').forEach(drawer => {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.querySelector('[data-table-filter-toggle="' + drawer.dataset.filterId + '"]')?.setAttribute('aria-expanded', 'false');
  });
}

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const drawer = document.querySelector('.table-filter-drawer.is-open');
  if (!drawer) return;
  const trigger = document.querySelector('[data-table-filter-toggle="' + drawer.dataset.filterId + '"]');
  closeTableFilterDrawers();
  trigger?.focus({preventScroll: true});
});
window.addEventListener('resize', closeTableFilterDrawers);
document.addEventListener('scroll', event => {
  if (event.target.closest?.('.table-filter-panel')) return;
  if (document.querySelector('.table-filter-drawer.is-open')) closeTableFilterDrawers();
}, true);

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
        input.dispatchEvent(new Event('change', { bubbles: true }));
        updateUploadLabel(area, e.dataTransfer.files[0].name, e.dataTransfer.files.length);
      }
    });
    input.addEventListener('change', () => {
      if (input.files.length) updateUploadLabel(area, input.files[0].name, input.files.length);
    });
  });
});

function updateUploadLabel(area, filename, fileCount = 1) {
  const title = area.querySelector('.upload-title');
  const multiple = fileCount > 1;
  if (title) title.textContent = multiple ? `${fileCount} files selected` : filename;
  area.classList.toggle('has-file', !!filename);
  if (filename) area.setAttribute('aria-label', multiple ? `${fileCount} files selected` : 'Selected file: ' + filename);
}

// ── Confirm dialog ───────────────────────────────────────────
function confirmAction(message, formId) {
  const form = document.getElementById(formId);
  if (!form) return;
  form.dataset.confirmMessage = message;
  form.requestSubmit();
}

// ── Chat ────────────────────────────────────────────────────
async function sendChatMessage() {
  const textarea = document.getElementById('chat-input');
  const msg = textarea ? textarea.value.trim() : '';
  if (!msg) return;

  const threadId = document.getElementById('active-thread-id')?.value;
  if (!threadId) return;
  if (!await confirmOJTrack({title: 'Send this message?', message: 'Send this message to the current conversation?', confirmLabel: 'Send'})) return;

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
  .catch(() => { window.showAppToast?.('Message was not sent. Please try again.', {type: 'error'}); });
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

    const image = new Image();
    image.onload = function() {
      const side = Math.min(image.naturalWidth, image.naturalHeight), canvas = document.createElement('canvas');
      canvas.width = 512; canvas.height = 512;
      const context = canvas.getContext('2d');
      if (!context) { filename.textContent = 'This browser cannot process the image.'; saveButton.disabled = true; return; }
      context.drawImage(image, (image.naturalWidth - side) / 2, (image.naturalHeight - side) / 2, side, side, 0, 0, 512, 512);
      canvas.toBlob(function(blob) {
        if (!blob) { filename.textContent = 'The image could not be prepared.'; saveButton.disabled = true; return; }
        const cropped = new File([blob], 'profile-photo.jpg', {type:'image/jpeg', lastModified:Date.now()});
        const transfer = new DataTransfer(); transfer.items.add(cropped); input.files = transfer.files;
        objectUrl = URL.createObjectURL(blob);
        const img = document.createElement('img'); img.src = objectUrl; img.alt = 'Selected profile photo preview'; img.className = 'profile-avatar-preview-img'; preview.replaceChildren(img);
        filename.textContent = 'Square photo · ' + Math.max(1, Math.round(blob.size / 1024)) + ' KB'; filename.classList.add('is-selected'); saveButton.disabled = false;
      }, 'image/jpeg', 0.82);
    };
    image.onerror = function() { filename.textContent = 'Choose a valid image file.'; saveButton.disabled = true; };
    image.src = URL.createObjectURL(file);
  });

  input.form?.addEventListener('submit', function() {
    if (!saveButton.disabled) {
      saveButton.disabled = true;
      saveButton.textContent = 'Saving…';
    }
  });
});

// ── Shared table tools for web list pages ────────────────────
function tableCellText(cell) {
  return (cell?.innerText || cell?.textContent || '').replace(/\s+/g, ' ').trim();
}

function csvSafeCell(value) {
  const text = String(value ?? '').replace(/\r?\n/g, ' ').trim();
  const safe = /^[=+\-@\t\r]/.test(text) ? "'" + text : text;
  return '"' + safe.replace(/"/g, '""') + '"';
}

function exportVisibleTable(table) {
  const header = table.tHead?.rows[0];
  if (!header) return;
  const columnIndexes = [...header.cells]
    .map((cell, index) => ({cell, index}))
    .filter(({cell}) => !cell.hidden && !/^(actions?)$/i.test(tableCellText(cell)))
    .map(({index}) => index);
  const rows = [...(table.tBodies[0]?.rows || [])].filter(row => {
    return !row.hidden && getComputedStyle(row).display !== 'none';
  });
  const lines = [
    columnIndexes.map(index => csvSafeCell(tableCellText(header.cells[index]))).join(','),
    ...rows.map(row => columnIndexes.map(index => {
      const cell = row.cells[index];
      return csvSafeCell(cell && !cell.hidden && cell.colSpan === 1 ? tableCellText(cell) : '');
    }).join(','))
  ];
  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], {type: 'text/csv;charset=utf-8'});
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  const pageName = location.pathname.split('/').pop()?.replace(/\.php$/i, '') || 'records';
  link.href = url;
  link.download = pageName + '-export.csv';
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function addTablePagination(table) {
  if (!table || table.dataset.paginationReady === 'true' || table.closest('[data-custom-pagination]') || table.closest('[data-scroll-list="five"]')) return;
  const tableWrap = table.closest('.table-wrap');
  const tbody = table.tBodies[0];
  if (!tableWrap || !tbody) return;

  const allRows = [...tbody.rows];
  const emptyRows = allRows.filter(row => row.cells.length === 1 && row.cells[0].colSpan > 1);
  const rows = allRows.filter(row => !emptyRows.includes(row));
  const pagination = document.createElement('div');
  pagination.className = 'table-pagination';
  pagination.hidden = true;
  pagination.innerHTML = '<span class="table-pagination-size"><strong>10</strong><span>rows per page</span></span><nav class="table-pagination-pages" aria-label="Table pages"></nav><div class="table-pagination-actions"><button type="button" class="table-pagination-button" data-page-previous>‹ <span>Previous</span></button><button type="button" class="table-pagination-button" data-page-next><span>Next</span> ›</button></div>';
  tableWrap.after(pagination);
  table.dataset.paginationReady = 'true';

  const pageNav = pagination.querySelector('.table-pagination-pages');
  const previous = pagination.querySelector('[data-page-previous]');
  const next = pagination.querySelector('[data-page-next]');
  let currentPage = 1;

  const renderPageNumbers = (pageCount) => {
    pageNav.replaceChildren();
    let pages;
    if (pageCount <= 7) {
      pages = Array.from({length: pageCount}, (_, index) => index + 1);
    } else if (currentPage <= 4) {
      pages = [1, 2, 3, 4, 5, '…', pageCount];
    } else if (currentPage >= pageCount - 3) {
      pages = [1, '…', pageCount - 4, pageCount - 3, pageCount - 2, pageCount - 1, pageCount];
    } else {
      pages = [1, '…', currentPage - 1, currentPage, currentPage + 1, '…', pageCount];
    }
    pages.forEach(page => {
      if (page === '…') {
        const ellipsis = document.createElement('span');
        ellipsis.className = 'table-pagination-ellipsis';
        ellipsis.textContent = '…';
        pageNav.appendChild(ellipsis);
        return;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'table-pagination-page' + (page === currentPage ? ' is-current' : '');
      button.textContent = String(page);
      button.setAttribute('aria-label', 'Page ' + page);
      if (page === currentPage) button.setAttribute('aria-current', 'page');
      button.addEventListener('click', () => { currentPage = page; render(); });
      pageNav.appendChild(button);
    });
  };

  const rowMatchesFilters = row => !row.hidden && row.style.display !== 'none' && !row.classList.contains('table-quick-search-hidden');
  const render = (resetPage = false) => {
    if (resetPage) currentPage = 1;
    const matchingRows = rows.filter(rowMatchesFilters);
    const pageCount = Math.max(1, Math.ceil(matchingRows.length / 10));
    currentPage = Math.min(currentPage, pageCount);
    const start = (currentPage - 1) * 10;
    const visibleRows = new Set(matchingRows.slice(start, start + 10));

    const matchingSet = new Set(matchingRows);
    rows.forEach(row => row.classList.toggle('table-page-hidden', matchingSet.has(row) && !visibleRows.has(row)));
    emptyRows.forEach(row => { row.hidden = matchingRows.length > 0; });
    pagination.hidden = matchingRows.length <= 10;
    previous.disabled = currentPage <= 1;
    next.disabled = currentPage >= pageCount;
    renderPageNumbers(pageCount);
  };

  table._renderPagination = render;
  const filterObserver = new MutationObserver(() => render(true));
  table._paginationObserver = filterObserver;
  rows.forEach(row => filterObserver.observe(row, {attributes: true, attributeFilter: ['hidden', 'style']}));
  previous.addEventListener('click', () => { if (currentPage > 1) { currentPage -= 1; render(); } });
  next.addEventListener('click', () => {
    const pageCount = Math.ceil(rows.filter(rowMatchesFilters).length / 10);
    if (currentPage < pageCount) { currentPage += 1; render(); }
  });
  render();
}

function initializeFiveItemScrollLists() {
  const scrollLists = [...document.querySelectorAll('[data-scroll-list="five"]')];
  scrollLists.forEach(container => {
    const table = container.querySelector('table');
    const items = table
      ? [...(table.tBodies[0]?.rows || [])].filter(row => !(row.cells.length === 1 && row.cells[0].colSpan > 1))
      : [...container.querySelectorAll('[data-scroll-item]')];

    if (items.length <= 5) {
      container.classList.remove('is-scrollable');
      container.style.maxHeight = '';
      return;
    }

    container.classList.add('is-scrollable');
    const updateMaxHeight = () => {
      const headerHeight = table?.tHead?.getBoundingClientRect().height || 0;
      const fiveItemHeight = items.slice(0, 5).reduce((height, item) => {
        const marginBottom = Number.parseFloat(getComputedStyle(item).marginBottom) || 0;
        return height + item.getBoundingClientRect().height + marginBottom;
      }, 0);
      container.style.maxHeight = Math.ceil(headerHeight + fiveItemHeight) + 'px';
    };

    updateMaxHeight();
    document.fonts?.ready.then(updateMaxHeight);
    window.addEventListener('resize', updateMaxHeight, {passive: true});
  });
}

function removeEmptyTableCardHeaders() {
  document.querySelectorAll('table').forEach(table => {
    const card = table.closest('.card');
    card?.querySelectorAll(':scope > .card-header').forEach(header => {
      const hasText = Boolean(header.textContent.trim());
      const hasControlsOrContent = Boolean(header.querySelector('a, button, input, select, textarea, form, svg, img, [role="button"], [aria-label]'));
      if (!hasText && !hasControlsOrContent) header.remove();
    });
  });
}

const tableToolIcons = {
  manage: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/></svg>',
  export: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 12v7m-3-3 3 3 3-3"/></svg>',
  filter: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.2l-3.6 1.8v-7z"/></svg>',
  columns: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16m6-16v16"/></svg>',
  archive: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="4" rx="1.2"/><path d="M5 8.5v11h14v-11M9 12h6"/></svg>'
};

function setTableToolIcon(element, icon, label) {
  if (!element || !tableToolIcons[icon]) return;
  element.innerHTML = tableToolIcons[icon];
  element.classList.add('table-tool-icon-only');
  element.title = label;
  element.setAttribute('aria-label', label);
}

function initializeProgramMultiSelect(table) {
  if (!table?.matches('[data-program-multi-select]') || table.dataset.programMultiSelectReady === 'true') return;
  table.dataset.programMultiSelectReady = 'true';
  const card = table.closest('.card');
  const selectAll = table.querySelector('[data-program-select-all]');
  const toolbar = card?.querySelector('[data-program-bulk-toolbar]');
  const selectedCount = toolbar?.querySelector('[data-program-selected-count]');
  const activateButton = toolbar?.querySelector('[data-program-bulk-status="active"]');
  const deactivateButton = toolbar?.querySelector('[data-program-bulk-status="inactive"]');
  const selectionBoxes = () => [...table.querySelectorAll('tbody input[name="program_ids[]"]')];
  const visibleBoxes = () => selectionBoxes().filter(box => {
    const row = box.closest('tr');
    return row && !row.hidden && !row.classList.contains('table-page-hidden') && !row.classList.contains('table-quick-search-hidden') && getComputedStyle(row).display !== 'none';
  });
  const refresh = () => {
    const boxes = selectionBoxes();
    const selected = boxes.filter(box => box.checked);
    const visible = visibleBoxes();
    if (selectedCount) selectedCount.textContent = String(selected.length);
    if (toolbar) toolbar.hidden = selected.length === 0;
    if (activateButton) activateButton.hidden = !selected.some(box => box.dataset.programStatus === 'inactive');
    if (deactivateButton) deactivateButton.hidden = !selected.some(box => box.dataset.programStatus === 'active');
    if (selectAll) {
      const visibleSelected = visible.filter(box => box.checked).length;
      selectAll.checked = visible.length > 0 && visibleSelected === visible.length;
      selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
    }
  };

  table.addEventListener('change', event => {
    if (event.target === selectAll) {
      visibleBoxes().forEach(box => { box.checked = selectAll.checked; });
      refresh();
    } else if (event.target.matches('input[name="program_ids[]"]')) {
      refresh();
    }
  });
  table.addEventListener('click', event => {
    if (event.target.closest('.program-select-cell')) event.stopPropagation();
  });
  card?.querySelector('[data-program-clear-selection]')?.addEventListener('click', () => {
    selectionBoxes().forEach(box => { box.checked = false; });
    refresh();
  });

  const observer = new MutationObserver(refresh);
  if (table.tBodies[0]) observer.observe(table.tBodies[0], {subtree:true, attributes:true, attributeFilter:['class','hidden','style']});
  table._programMultiSelectObserver = observer;
  refresh();
}

function initializeUserMultiSelect(table) {
  if (!table?.matches('[data-user-multi-select]') || table.dataset.userMultiSelectReady === 'true') return;
  table.dataset.userMultiSelectReady = 'true';
  const card = table.closest('.card');
  const selectAll = table.querySelector('[data-user-select-all]');
  const toolbar = card?.querySelector('[data-user-bulk-toolbar]');
  const selectedCount = toolbar?.querySelector('[data-user-selected-count]');
  const activateButton = toolbar?.querySelector('[data-user-bulk-status="active"]');
  const deactivateButton = toolbar?.querySelector('[data-user-bulk-status="inactive"]');
  const archiveButton = toolbar?.querySelector('[data-user-bulk-archive]');
  const form = card?.querySelector('#bulkUserForm');
  const selectionBoxes = () => [...table.querySelectorAll('tbody input[name="user_ids[]"]')];
  const visibleBoxes = () => selectionBoxes().filter(box => {
    const row = box.closest('tr');
    return !box.disabled && row && !row.hidden && !row.classList.contains('table-page-hidden') && !row.classList.contains('table-quick-search-hidden') && getComputedStyle(row).display !== 'none';
  });
  const refresh = () => {
    const boxes = selectionBoxes();
    const selected = boxes.filter(box => box.checked && !box.disabled);
    const visible = visibleBoxes();
    const hasInactive = selected.some(box => box.dataset.userStatus === 'inactive');
    const hasActive = selected.some(box => box.dataset.userStatus === 'active');
    if (selectedCount) selectedCount.textContent = String(selected.length);
    if (toolbar) toolbar.hidden = selected.length === 0;
    if (activateButton) activateButton.hidden = !hasInactive;
    if (deactivateButton) deactivateButton.hidden = !hasActive;
    if (archiveButton) archiveButton.hidden = selected.length === 0;
    if (selectAll) {
      const visibleSelected = visible.filter(box => box.checked).length;
      selectAll.checked = visible.length > 0 && visibleSelected === visible.length;
      selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
    }
  };

  table.addEventListener('change', event => {
    if (event.target === selectAll) {
      visibleBoxes().forEach(box => { box.checked = selectAll.checked; });
      refresh();
    } else if (event.target.matches('input[name="user_ids[]"]')) {
      refresh();
    }
  });
  card?.querySelector('[data-user-clear-selection]')?.addEventListener('click', () => {
    selectionBoxes().forEach(box => { box.checked = false; });
    refresh();
  });
  form?.addEventListener('submit', event => {
    if (event.submitter?.hasAttribute('data-user-bulk-archive') && !window.confirm('Archive the selected accounts? They will lose login access, while their related records are preserved.')) event.preventDefault();
  });

  const observer = new MutationObserver(refresh);
  if (table.tBodies[0]) observer.observe(table.tBodies[0], {subtree:true, attributes:true, attributeFilter:['class','hidden','style']});
  table._userMultiSelectObserver = observer;
  refresh();
}

function initializeArchivedUserMultiSelect(table) {
  if (!table?.matches('[data-archived-user-multi-select]') || table.dataset.archivedUserMultiSelectReady === 'true') return;
  table.dataset.archivedUserMultiSelectReady = 'true';
  const card = table.closest('.card');
  const selectAll = table.querySelector('[data-archived-user-select-all]');
  const toolbar = card?.querySelector('[data-archived-user-bulk-toolbar]');
  const selectedCount = toolbar?.querySelector('[data-archived-user-selected-count]');
  const form = card?.querySelector('#bulkArchivedUserForm');
  const selectionBoxes = () => [...table.querySelectorAll('tbody input[name="user_ids[]"]')];
  const visibleBoxes = () => selectionBoxes().filter(box => {
    const row = box.closest('tr');
    return row && !row.hidden && !row.classList.contains('table-page-hidden') && !row.classList.contains('table-quick-search-hidden') && getComputedStyle(row).display !== 'none';
  });
  const refresh = () => {
    const boxes = selectionBoxes();
    const selected = boxes.filter(box => box.checked);
    const visible = visibleBoxes();
    if (selectedCount) selectedCount.textContent = String(selected.length);
    if (toolbar) toolbar.hidden = selected.length === 0;
    if (selectAll) {
      const visibleSelected = visible.filter(box => box.checked).length;
      selectAll.checked = visible.length > 0 && visibleSelected === visible.length;
      selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
    }
  };

  table.addEventListener('change', event => {
    if (event.target === selectAll) {
      visibleBoxes().forEach(box => { box.checked = selectAll.checked; });
      refresh();
    } else if (event.target.matches('input[name="user_ids[]"]')) {
      refresh();
    }
  });
  toolbar?.querySelector('[data-archived-user-clear]')?.addEventListener('click', () => {
    selectionBoxes().forEach(box => { box.checked = false; });
    refresh();
  });
  form?.addEventListener('submit', event => {
    if (event.submitter?.hasAttribute('data-archived-user-restore') && !window.confirm('Restore the selected accounts to active status?')) event.preventDefault();
  });

  const observer = new MutationObserver(refresh);
  if (table.tBodies[0]) observer.observe(table.tBodies[0], {subtree:true, attributes:true, attributeFilter:['class','hidden','style']});
  table._archivedUserMultiSelectObserver = observer;
  refresh();
}

function addTableTools(table, tableIndex) {
  if (table?.dataset.skipTableTools === 'true' || table?.hasAttribute('data-skip-table-tools')) return;
  const header = table.tHead?.rows[0];
  const tableWrap = table.closest('.table-wrap');
  if (!header || !tableWrap || table.dataset.tableToolsReady === 'true') return;
  table.dataset.tableToolsReady = 'true';
  if (!table.id) table.id = 'webDataTable' + tableIndex;

  const tools = document.createElement('div');
  tools.className = 'table-tools';
  tools.setAttribute('data-table-tools-for', table.id);

  const card = table.closest('.card');
  const pageBody = table.closest('.page-body');
  const pageHeading = tableIndex === 0 ? pageBody?.querySelector('.page-heading') : null;
  const headingTitle = pageHeading?.querySelector(':scope > div:first-child') || null;
  const headingSubtitle = pageHeading?.querySelector(':scope > .page-sub') || null;
  const combinedCardHeader = card?.querySelector('.card-header[data-table-tools-combine]') || null;
  const combinedCardTitle = combinedCardHeader?.querySelector('.card-title') || null;
  const combinedCardAction = combinedCardHeader?.querySelector('a, button') || null;
  const filterForm = card?.querySelector('form[data-live-filter]') || pageHeading?.querySelector('form[data-live-filter]');
  const filterField = filterForm?.querySelector('select, input[type="date"], input[type="month"], input[type="week"]');
  const sourceSearch = filterForm?.querySelector('input[type="search"], input[type="text"][name="q"]');
  const hasActionControls = filterForm && [...filterForm.querySelectorAll('button, input[type="submit"]')]
    .some(button => /issue|download|reprint|template/i.test(button.textContent + ' ' + (button.getAttribute('onclick') || '')));
  const useFilterDrawer = Boolean(filterField && !hasActionControls);
  if (useFilterDrawer) {
    if (!filterForm.id) filterForm.id = 'tableFilterForm' + tableIndex;
    filterForm.dataset.liveFilterIndex = String([...document.querySelectorAll('form[data-live-filter]')].indexOf(filterForm));
  } else if (sourceSearch) {
    // The compact table search replaces the legacy standalone text-search control.
    filterForm.classList.add('table-filter-source-only');
  }

  const heading = document.createElement('div');
  heading.className = 'table-tools-heading';
  const actions = document.createElement('div');
  actions.className = 'table-tools-actions';
  actions.setAttribute('role', 'group');
  actions.setAttribute('aria-label', 'Table controls');
  tools.append(heading, actions);
  if (headingTitle) heading.appendChild(headingTitle);
  if (headingSubtitle) heading.appendChild(headingSubtitle);
  if (combinedCardTitle) heading.appendChild(combinedCardTitle);
  if (combinedCardHeader) combinedCardHeader.remove();

  if (pageHeading) {
    [...pageHeading.children].forEach(child => {
      if (child === headingTitle || child === headingSubtitle) return;
      if (child.classList.contains('page-heading-actions')) {
        [...child.children].forEach(action => actions.appendChild(action));
      } else {
        actions.appendChild(child);
      }
    });
    pageHeading.remove();
  }

  const search = document.createElement('input');
  search.type = 'search';
  search.className = 'form-control table-quick-search';
  search.placeholder = sourceSearch?.getAttribute('placeholder') || table.dataset.searchPlaceholder || 'Search by ID, name...';
  search.setAttribute('aria-label', 'Search table by ID or name');
  if (sourceSearch) search.value = sourceSearch.value;
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    [...(table.tBodies[0]?.rows || [])].forEach(row => {
      if (row.cells.length === 1 && row.cells[0].colSpan > 1) return;
      row.classList.toggle('table-quick-search-hidden', Boolean(query) && !tableCellText(row).toLocaleLowerCase().includes(query));
    });
    table._renderPagination?.(true);
    if (sourceSearch && sourceSearch.value !== search.value) {
      sourceSearch.value = search.value;
      sourceSearch.dispatchEvent(new Event('input', {bubbles: true}));
    }
  });
  actions.prepend(search);

  const manageAssignments = [...actions.querySelectorAll('.btn, .table-tool-button')]
    .find(action => /manage\s+assignments/i.test(action.textContent));
  if (manageAssignments) {
    manageAssignments.classList.remove('btn', 'btn-secondary', 'btn-ghost');
    manageAssignments.classList.add('table-tool-button');
    setTableToolIcon(manageAssignments, 'manage', 'Manage Assignments');
  }

  const exportMenu = document.createElement('details');
  exportMenu.className = 'table-column-picker table-export-menu';
  const exportSummary = document.createElement('summary');
  setTableToolIcon(exportSummary, 'export', 'Export');
  exportMenu.appendChild(exportSummary);
  const exportOptions = document.createElement('div');
  exportOptions.className = 'table-column-menu';
  const csvButton = document.createElement('button');
  csvButton.type = 'button';
  csvButton.className = 'table-menu-action';
  csvButton.textContent = 'CSV (.csv)';
  csvButton.addEventListener('click', () => {
    exportVisibleTable(table);
    exportMenu.open = false;
  });
  exportOptions.appendChild(csvButton);
  if (table.dataset.pdfExportUrl) {
    const pdfLink = document.createElement('a');
    pdfLink.className = 'table-menu-action';
    pdfLink.textContent = table.dataset.pdfExportLabel || 'Download DTR (PDF)';
    pdfLink.href = table.dataset.pdfExportUrl;
    pdfLink.addEventListener('click', () => { exportMenu.open = false; });
    exportOptions.appendChild(pdfLink);
  }
  exportMenu.appendChild(exportOptions);
  actions.appendChild(exportMenu);

  if (useFilterDrawer) {
    const filterButton = document.createElement('button');
    filterButton.type = 'button';
    filterButton.className = 'table-tool-button table-tool-filter';
    setTableToolIcon(filterButton, 'filter', 'Filter records');
    filterButton.setAttribute('aria-controls', filterForm.id + 'Drawer');
    filterButton.setAttribute('aria-expanded', 'false');
    filterButton.setAttribute('aria-haspopup', 'dialog');
    filterButton.dataset.tableFilterToggle = filterForm.id;
    const drawer = document.createElement('div');
    drawer.className = 'table-filter-drawer';
    drawer.id = filterForm.id + 'Drawer';
    drawer.dataset.filterId = filterForm.id;
    drawer.setAttribute('aria-hidden', 'true');
    drawer.innerHTML = '<section class="table-filter-panel" role="dialog" aria-modal="false" aria-labelledby="' + filterForm.id + 'Title"><header class="table-filter-header"><div class="table-filter-heading-copy"><h2 id="' + filterForm.id + 'Title">Filters</h2><p class="table-filter-description">Refine the records shown in this list.</p></div><button type="button" class="table-filter-close" aria-label="Close filters">×</button></header><div class="table-filter-content"></div></section>';
    const closeDrawer = () => {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      filterButton.setAttribute('aria-expanded', 'false');
    };
    const openDrawer = () => {
      closeTableFilterDrawers();
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      filterButton.setAttribute('aria-expanded', 'true');
      const panel = drawer.querySelector('.table-filter-panel');
      const buttonRect = filterButton.getBoundingClientRect();
      const panelWidth = panel.offsetWidth;
      const panelHeight = panel.offsetHeight;
      const left = Math.max(12, Math.min(buttonRect.right - panelWidth, window.innerWidth - panelWidth - 12));
      let top = buttonRect.bottom + 8;
      if (top + panelHeight > window.innerHeight - 12) top = Math.max(12, buttonRect.top - panelHeight - 8);
      panel.style.left = left + 'px';
      panel.style.top = top + 'px';
      (filterForm.querySelector('select, input[type="date"], input[type="month"], input[type="week"]') || drawer.querySelector('.table-filter-close')).focus({preventScroll: true});
    };
    filterButton.addEventListener('click', openDrawer);
    drawer.querySelector('.table-filter-close').addEventListener('click', () => { closeDrawer(); filterButton.focus({preventScroll: true}); });

    const searchWrapper = sourceSearch?.closest('.student-filter-search, .activity-filter-search, .search-wrap') || sourceSearch?.parentElement;
    if (searchWrapper) searchWrapper.classList.add('table-filter-search-source');
    filterForm.querySelectorAll('button[type="submit"], input[type="submit"], .activity-filter-actions, .student-filter-actions').forEach(control => control.remove());
    filterForm.querySelectorAll('a[href]').forEach(link => link.remove());
    const originalParent = filterForm.parentElement;
    drawer.querySelector('.table-filter-content').appendChild(filterForm);
    filterForm.classList.add('table-filter-drawer-form');
    filterForm.classList.remove('table-filter-form-collapsible', 'is-collapsed', 'is-open');
    const footer = document.createElement('div');
    footer.className = 'table-filter-footer';
    const reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'table-filter-reset';
    reset.textContent = 'Reset';
    const apply = document.createElement('button');
    apply.type = 'submit';
    apply.setAttribute('form', filterForm.id);
    apply.className = 'table-filter-apply';
    apply.textContent = 'Apply filters';
    reset.addEventListener('click', () => {
      filterForm.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
      filterForm.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"])').forEach(input => {
        if (input.type === 'checkbox' || input.type === 'radio') input.checked = false;
        else input.value = '';
      });
      filterForm.requestSubmit(apply);
    });
    footer.append(reset, apply);
    drawer.querySelector('.table-filter-panel').appendChild(footer);
    document.body.appendChild(drawer);
    if (originalParent && !originalParent.querySelector('form') && !originalParent.textContent.trim()) originalParent.remove();
    actions.appendChild(filterButton);
  }

  const picker = document.createElement('details');
  picker.className = 'table-column-picker';
  const summary = document.createElement('summary');
  setTableToolIcon(summary, 'columns', 'Show or hide columns');
  picker.appendChild(summary);
  const menu = document.createElement('div');
  menu.className = 'table-column-menu';
  menu.setAttribute('aria-label', 'Show or hide table columns');

  [...header.cells].forEach((heading, index) => {
    const label = document.createElement('label');
    label.className = 'table-column-option';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.checked = true;
    checkbox.addEventListener('change', () => {
      table.querySelectorAll('tr').forEach(row => {
        const cell = row.cells[index];
        if (cell && cell.colSpan === 1) cell.hidden = !checkbox.checked;
      });
    });
    const name = document.createElement('span');
    name.textContent = tableCellText(heading) || ('Column ' + (index + 1));
    label.append(checkbox, name);
    menu.appendChild(label);
  });
  picker.appendChild(menu);
  actions.appendChild(picker);

  if (tableIndex === 0) {
    const archiveSelect = filterForm?.querySelector('select[name="archive"]');
    const archiveLink = [...actions.querySelectorAll('a')]
      .find(link => /archived/i.test(link.getAttribute('href') || '') && !location.pathname.includes('archived'));
    if ((archiveSelect && [...archiveSelect.options].some(option => option.value === 'archived')) || archiveLink) {
      const divider = document.createElement('span');
      divider.className = 'table-tool-divider';
      divider.setAttribute('aria-hidden', 'true');
      actions.appendChild(divider);
      const archiveTarget = archiveSelect?.form?.dataset.archiveTarget;
      if (archiveTarget) {
        const archiveRouteLink = document.createElement('a');
        archiveRouteLink.href = archiveTarget;
        archiveRouteLink.className = 'table-tool-button';
        setTableToolIcon(archiveRouteLink, 'archive', 'View archived student accounts');
        actions.appendChild(archiveRouteLink);
      } else if (archiveLink) {
        archiveLink.classList.remove('btn', 'btn-secondary', 'btn-ghost');
        archiveLink.classList.add('table-tool-button');
        setTableToolIcon(archiveLink, 'archive', 'View archived records');
        actions.appendChild(archiveLink);
      } else {
        const archiveButton = document.createElement('button');
        archiveButton.type = 'button';
        archiveButton.className = 'table-tool-button';
        setTableToolIcon(archiveButton, 'archive', 'Show archived records');
        archiveButton.addEventListener('click', () => {
          archiveSelect.value = 'archived';
          if (archiveSelect.form?.closest('.table-filter-drawer')) archiveSelect.form.requestSubmit();
          else archiveSelect.dispatchEvent(new Event('change', {bubbles: true}));
        });
        actions.appendChild(archiveButton);
      }
    }

    const newAction = [...actions.querySelectorAll('.btn-primary, .btn-secondary, .btn-ghost')]
      .find(action => /add|new|create|register|post|compose/i.test(action.textContent + ' ' + (action.getAttribute('onclick') || '')));
    if (newAction && /add|new|create|register|post|compose/i.test(newAction.textContent + ' ' + (newAction.getAttribute('onclick') || ''))) {
      const divider = document.createElement('span');
      divider.className = 'table-tool-divider';
      divider.setAttribute('aria-hidden', 'true');
      actions.appendChild(divider);
      newAction.classList.remove('btn', 'btn-primary', 'btn-secondary');
      newAction.classList.add('table-tool-button', 'table-tool-new');
      newAction.title = newAction.textContent.trim();
      newAction.textContent = '+ New';
      actions.appendChild(newAction);
    }
  }

  if (combinedCardAction) actions.appendChild(combinedCardAction);
  tableWrap.before(tools);
  addTablePagination(table);
  initializeProgramMultiSelect(table);
  initializeUserMultiSelect(table);
  initializeArchivedUserMultiSelect(table);
}

document.addEventListener('DOMContentLoaded', function() {
  initializeFiveItemScrollLists();
  document.querySelectorAll('.table-wrap table').forEach(addTableTools);
  removeEmptyTableCardHeaders();
});

