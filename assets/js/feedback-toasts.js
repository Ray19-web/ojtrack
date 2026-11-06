/* Shared bottom toast feedback for transient success and error messages. */
(function () {
  const undoHandlers = new Map();
  let region;

  function getRegion() {
    if (region?.isConnected) return region;
    region = document.createElement('div');
    region.className = 'feedback-toast-region';
    region.setAttribute('popover', 'manual');
    region.style.cssText = 'top:auto;margin:0;padding:0;border:0;background:transparent;overflow:visible;pointer-events:none;';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-relevant', 'additions');
    region.setAttribute('aria-label', 'Page messages');
    document.body.appendChild(region);
    return region;
  }

  function closeToast(toast, immediate) {
    if (!toast?.isConnected || toast.dataset.closing === '1') return;
    toast.dataset.closing = '1';
    window.clearTimeout(Number(toast.dataset.timer));
    if (immediate) {
      toast.remove();
      return;
    }
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 240);
  }

  function showToast(message, options) {
    const text = String(message || '').replace(/\s+/g, ' ').trim();
    if (!text) return null;
    const settings = options || {};
    const type = ['error', 'info'].includes(settings.type) ? settings.type : 'success';
    const duration = Number.isFinite(settings.duration) ? Math.max(0, settings.duration) : (type === 'error' ? 9000 : 8000);
    const regionNode = getRegion();
    if (typeof regionNode.showPopover === 'function') {
      if (regionNode.matches(':popover-open')) regionNode.hidePopover();
      regionNode.showPopover();
    }
    while (regionNode.children.length >= 4) closeToast(regionNode.firstElementChild, true);

    const toast = document.createElement('section');
    toast.className = 'feedback-toast feedback-toast-' + type;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.style.setProperty('--feedback-toast-duration', duration + 'ms');
    toast.innerHTML = '<span class="feedback-toast-icon" aria-hidden="true"></span><div class="feedback-toast-copy"><strong class="feedback-toast-title" hidden></strong><p class="feedback-toast-message"></p></div><div class="feedback-toast-actions"><a class="feedback-toast-open" hidden></a><button type="button" class="feedback-toast-undo" hidden>Undo</button><button type="button" class="feedback-toast-close" aria-label="Dismiss message">×</button></div><span class="feedback-toast-progress" aria-hidden="true"></span>';
    toast.querySelector('.feedback-toast-message').textContent = text;
    toast.querySelector('.feedback-toast-icon').textContent = type === 'error' ? '!' : (type === 'info' ? 'i' : '✓');
    if (settings.title) {
      const title = toast.querySelector('.feedback-toast-title');
      title.textContent = String(settings.title);
      title.hidden = false;
    }

    const openLink = toast.querySelector('.feedback-toast-open');
    if (settings.actionLabel && (settings.actionUrl || typeof settings.onAction === 'function')) {
      openLink.textContent = String(settings.actionLabel);
      openLink.hidden = false;
      if (typeof settings.onAction === 'function') {
        openLink.href = '#';
        openLink.addEventListener('click', event => {
          event.preventDefault();
          settings.onAction();
          closeToast(toast);
        });
      } else {
        try {
          const actionUrl = new URL(String(settings.actionUrl), window.location.href);
          if (actionUrl.origin === window.location.origin && actionUrl.pathname.startsWith('/ojtrack/')) {
            openLink.href = actionUrl.href;
          } else {
            openLink.hidden = true;
          }
        } catch (_) {
          openLink.hidden = true;
        }
      }
    }

    const undo = typeof settings.undo === 'function'
      ? settings.undo
      : (settings.undoKey && undoHandlers.get(String(settings.undoKey)));
    const undoButton = toast.querySelector('.feedback-toast-undo');
    if (typeof undo === 'function') {
      undoButton.hidden = false;
      undoButton.addEventListener('click', async () => {
        undoButton.disabled = true;
        try {
          const result = await undo();
          closeToast(toast);
          if (typeof result === 'string' && result.trim()) showToast(result, {type: 'success'});
        } catch (error) {
          closeToast(toast);
          showToast(error?.message || 'Could not undo that action.', {type: 'error'});
        }
      });
    }

    toast.querySelector('.feedback-toast-close').addEventListener('click', () => closeToast(toast));
    regionNode.appendChild(toast);

    let remaining = duration;
    let timerStarted = 0;
    const startTimer = () => {
      if (!duration || toast.dataset.closing === '1' || timerStarted) return;
      timerStarted = Date.now();
      toast.dataset.timer = String(window.setTimeout(() => closeToast(toast), remaining));
      toast.classList.add('is-timing');
      toast.classList.remove('is-paused');
    };
    const pauseTimer = () => {
      if (!timerStarted) return;
      window.clearTimeout(Number(toast.dataset.timer));
      remaining = Math.max(0, remaining - (Date.now() - timerStarted));
      timerStarted = 0;
      toast.classList.add('is-paused');
    };
    // Do not keep a toast on screen merely because the cursor stayed at the
    // same coordinates after a form submission or page navigation. The toast
    // message is click-through; its action buttons remain interactive.
    toast.addEventListener('focusin', pauseTimer);
    toast.addEventListener('focusout', event => {
      if (!toast.contains(event.relatedTarget)) startTimer();
    });
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    startTimer();
    return toast;
  }

  window.registerToastUndo = function (key, callback) {
    if (key && typeof callback === 'function') undoHandlers.set(String(key), callback);
  };
  window.showAppToast = showToast;
  // Explicitly marked locked records explain why editing is unavailable.
  const explainLocked = event => {
    const locked = event.target.closest('[data-locked-feedback]');
    if (!locked || event.target.closest('a,button,input,select,textarea,details')) return;
    if (event.type === 'keydown') {
      if (event.target !== locked || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
    }
    if (window.getSelection()?.toString()) return;
    showToast(locked.dataset.lockedFeedback, {type:'info'});
  };
  document.addEventListener('click', explainLocked);
  document.addEventListener('keydown', explainLocked);

  function consumeAlert(source) {
    if (!(source instanceof HTMLElement) || source.dataset.persistentAlert === 'true' || source.closest('.feedback-toast')) return;
    const classList = source.classList;
    const type = classList.contains('alert-error') || classList.contains('error') ? 'error' : 'success';
    const message = source.querySelector('.alert-body')?.innerText || source.innerText || source.textContent || '';
    const undoKey = source.dataset.undoKey;
    showToast(message, {type, undoKey});
    source.remove();
  }

  function scan(root) {
    if (!(root instanceof Element || root instanceof Document)) return;
    if (root instanceof Element && root.matches('.alert.alert-success, .alert.alert-error, .notice.success, .notice.error')) consumeAlert(root);
    root.querySelectorAll?.('.alert.alert-success, .alert.alert-error, .notice.success, .notice.error').forEach(consumeAlert);
  }

  scan(document);
  const observer = new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (node instanceof Element) scan(node);
    }));
  });
  observer.observe(document.body, {childList: true, subtree: true});
})();
