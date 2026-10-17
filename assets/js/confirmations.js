/* Shared confirmations for record changes. Navigation and filters remain direct. */
(() => {
  'use strict';
  let pending = null;
  const approvedForms = new WeakSet();
  const waitingForms = new WeakSet();

  function makeDialog() {
    let overlay = document.getElementById('actionConfirmation');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'actionConfirmation';
    overlay.hidden = true;
    overlay.innerHTML = `<section class="action-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="actionConfirmationTitle" aria-describedby="actionConfirmationMessage" tabindex="-1">
      <h2 id="actionConfirmationTitle"></h2>
      <p id="actionConfirmationMessage"></p>
      <div class="action-confirm-buttons">
        <button type="button" class="action-confirm-cancel">Cancel</button>
        <button type="button" class="action-confirm-accept">Confirm</button>
      </div>
    </section>`;
    document.body.append(overlay);
    overlay.querySelector('.action-confirm-cancel').addEventListener('click', () => finish(false));
    overlay.querySelector('.action-confirm-accept').addEventListener('click', () => finish(true));
    overlay.addEventListener('click', event => { if (event.target === overlay) finish(false); });
    return overlay;
  }

  function finish(accepted) {
    if (!pending) return;
    const previous = pending;
    pending = null;
    document.getElementById('actionConfirmation').hidden = true;
    previous.background.forEach(([element, inert]) => { element.inert = inert; });
    document.body.style.overflow = previous.overflow;
    if (previous.focus?.isConnected) previous.focus.focus();
    previous.resolve(accepted);
  }

  window.confirmOJTrack = function ({title = 'Confirm action', message = 'Continue with this action?', confirmLabel = 'Confirm', danger = false} = {}) {
    if (pending) return Promise.resolve(false);
    const overlay = makeDialog();
    overlay.querySelector('h2').textContent = title;
    overlay.querySelector('p').textContent = message;
    const accept = overlay.querySelector('.action-confirm-accept');
    accept.textContent = confirmLabel;
    accept.classList.toggle('is-danger', danger);
    const focus = document.activeElement;
    const background = [...document.body.children].filter(element => element !== overlay).map(element => [element, element.inert]);
    return new Promise(resolve => {
      pending = {resolve, focus, background, overflow: document.body.style.overflow};
      background.forEach(([element]) => { element.inert = true; });
      document.body.style.overflow = 'hidden';
      overlay.hidden = false;
      overlay.querySelector('.action-confirm-cancel').focus();
    });
  };

  document.addEventListener('keydown', event => {
    if (!pending) return;
    if (event.key === 'Escape') {
      event.preventDefault(); event.stopImmediatePropagation(); finish(false);
    } else if (event.key === 'Tab') {
      event.preventDefault(); event.stopImmediatePropagation();
      const overlay = document.getElementById('actionConfirmation');
      const cancel = overlay.querySelector('.action-confirm-cancel');
      const accept = overlay.querySelector('.action-confirm-accept');
      (document.activeElement === cancel ? accept : cancel).focus();
    }
  }, true);

  function optionsFor(form, submitter) {
    const field = name => submitter?.name === name ? submitter.value : (form.elements.namedItem(name)?.value || '');
    let action = field('action');
    if (action === 'review_journal') action = field('status') === 'approved' ? 'approve' : 'reject';
    const path = new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href, location.href).pathname;
    const label = (submitter?.textContent?.trim() || submitter?.getAttribute('aria-label') || submitter?.title || '').replace(/\s+/g, ' ');
    if (action === 'archive_form' && /^restore/i.test(label)) action = 'restore_form';
    let title = label && label.length <= 75 ? `${label}?` : 'Confirm changes';
    let message = 'Save these changes?';
    let confirmLabel = 'Confirm';
    let danger = /^(delete|archive|reject|deactivate)/.test(action);
    if (/logout\.php$/.test(path)) {
      title = 'Sign out of OJTrack?'; message = 'You will need to sign in again to continue.'; confirmLabel = 'Sign Out'; danger = true;
    } else if (action === 'issue_certificate') {
      title = 'Issue official certificate?'; confirmLabel = 'Issue Certificate';
      message = 'The student details, hours, company, template, issuer, and issue date will be preserved permanently for future reprints.';
    } else if (/^restore/.test(action)) {
      title = 'Restore this record?'; message = 'Restore this record to active use?'; confirmLabel = 'Restore';
    } else if (/^archive/.test(action)) {
      title = 'Archive this record?'; message = 'Remove this record from active use?'; confirmLabel = 'Archive';
    } else if (/^delete/.test(action) || action === 'delete') {
      title = 'Remove this record?'; message = 'Continue with removing this record?'; confirmLabel = 'Remove';
      if (action === 'delete_template') {
        title = 'Archive this requirement?'; confirmLabel = 'Archive';
        message = 'Remove this requirement from the active library? Existing student assignments and submissions remain preserved.';
      }
    } else if (/approve/.test(action)) {
      title = 'Approve this submission?'; message = 'Confirm that you have reviewed this submission and want to approve it.'; confirmLabel = 'Approve';
    } else if (/reject|return/.test(action)) {
      title = 'Return this submission?'; message = 'Send this submission back to the student with your feedback?'; confirmLabel = 'Return'; danger = true;
    } else if (/^send|thread/.test(action)) {
      title = 'Send to selected recipients?'; message = 'Confirm the selected recipients and information before sending.'; confirmLabel = 'Send';
    } else if (action === 'publish_form' || action === 'post') {
      title = action === 'post' ? 'Publish this announcement?' : 'Publish this evaluation form?';
      message = 'Publish this information for its intended users?'; confirmLabel = 'Publish';
    } else if (/upload|submit/.test(action)) {
      title = 'Submit this information?'; message = 'Confirm that the details and any selected documents are ready to submit.'; confirmLabel = 'Submit';
    } else if (action === 'change_password') {
      title = 'Update your password?'; message = 'Use the new password for future sign-ins?'; confirmLabel = 'Update Password';
    } else if (/^add|^create/.test(action)) {
      title = label && label.length <= 75 ? `${label}?` : 'Create this record?';
      message = 'Confirm the entered details before creating this record.'; confirmLabel = 'Create';
    } else if (action === 'bulk_time') {
      title = 'Record attendance?'; message = 'Record attendance for the selected trainees?'; confirmLabel = 'Record Attendance';
    } else if (/^toggle/.test(action)) {
      message = 'Apply this change to the selected record?';
      danger = /deactivate|inactive|disable/i.test(label);
    }
    return {
      title: form.dataset.confirmTitle || title,
      message: form.dataset.confirmMessage || submitter?.dataset.confirmMessage || message,
      confirmLabel: form.dataset.confirmLabel || confirmLabel,
      danger,
    };
  }

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const submitter = event.submitter;
    const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
    if (method !== 'post' || form.hasAttribute('data-confirm-skip')) return;
    if (approvedForms.has(form)) { approvedForms.delete(form); return; }
    event.preventDefault(); event.stopImmediatePropagation();
    if (waitingForms.has(form)) return;
    waitingForms.add(form);
    try {
      if (!await window.confirmOJTrack(optionsFor(form, submitter))) return;
      if (!form.isConnected || (submitter && (!submitter.isConnected || submitter.disabled))) return;
      approvedForms.add(form);
      // Re-run browser validation and existing submit handlers with the same button.
      try { form.requestSubmit(submitter || undefined); }
      finally { approvedForms.delete(form); }
    } finally { waitingForms.delete(form); }
  }, true);
})();
