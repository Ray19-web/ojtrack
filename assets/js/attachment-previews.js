/* Shared previews for newly selected and already saved attachments. */
(function () {
  const objectUrls = new WeakMap();
  const imageExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp']);
  const previewableDocumentExtensions = new Set(['pdf']);
  let viewerReturnFocus = null;
  let viewerPreviousOverflow = '';

  function fileExtension(name) {
    const parts = String(name || '').split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
  }

  function extensionOf(name) {
    const parts = String(name || '').split('.');
    return parts.length > 1 ? parts.pop().toUpperCase() : 'FILE';
  }

  function isImage(file) {
    const type = String(file.type || '').toLowerCase();
    const extension = String(file.name || '').split('.').pop().toLowerCase();
    return type.startsWith('image/') || imageExtensions.has(extension);
  }

  function formatSize(bytes) {
    const size = Number(bytes || 0);
    if (!size) return '';
    if (size >= 1024 * 1024) return (size / (1024 * 1024)).toFixed(1) + ' MB';
    return Math.max(1, Math.round(size / 1024)) + ' KB';
  }

  function makePreviewItem(file) {
    const item = document.createElement('div');
    item.className = 'attachment-preview-item';
    const image = !!file.image;
    const href = file.url || '';

    if (href) {
      const link = document.createElement('a');
      link.className = 'attachment-preview-link';
      link.href = href;
      link.target = '_blank';
      link.rel = 'noopener';
      link.setAttribute('aria-label', 'Open ' + file.name);
      item.append(link);
      item._content = link;
    } else {
      item._content = item;
    }

    if (image && file.previewUrl) {
      const thumb = document.createElement('img');
      thumb.className = 'attachment-preview-image';
      thumb.src = file.previewUrl;
      thumb.alt = file.name;
      item._content.append(thumb);
    } else {
      const type = document.createElement('span');
      type.className = 'attachment-preview-type';
      type.textContent = extensionOf(file.name);
      item._content.append(type);
    }

    const details = document.createElement('span');
    details.className = 'attachment-preview-details';
    const name = document.createElement('strong');
    name.className = 'attachment-preview-name';
    name.textContent = file.name || 'Attached file';
    details.append(name);
    const meta = document.createElement('span');
    meta.className = 'attachment-preview-meta';
    meta.textContent = [extensionOf(file.name) + ' file', formatSize(file.size)].filter(Boolean).join(' · ');
    details.append(meta);
    item._content.append(details);
    return item;
  }

  window.renderAttachmentPreview = function (container, files) {
    if (!container) return;
    (objectUrls.get(container) || []).forEach(url => URL.revokeObjectURL(url));
    objectUrls.set(container, []);
    container.replaceChildren();
    const normalized = Array.from(files || []).filter(Boolean);
    if (!normalized.length) {
      container.hidden = true;
      return;
    }

    normalized.forEach(file => {
      let previewUrl = file.previewUrl || '';
      let url = file.url || '';
      if (file instanceof File) {
        url = URL.createObjectURL(file);
        previewUrl = isImage(file) ? url : '';
        objectUrls.get(container).push(url);
        file = { name: file.name, size: file.size, image: isImage(file), url, previewUrl };
      }
      container.append(makePreviewItem({
        name: file.name || 'Attached file',
        size: file.size || 0,
        image: typeof file.image === 'boolean' ? file.image : imageExtensions.has(String(file.name || '').split('.').pop().toLowerCase()),
        url,
        previewUrl,
      }));
    });
    container.hidden = false;
  };

  function ensureViewer() {
    let overlay = document.getElementById('attachmentViewerModal');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'modal-overlay attachment-viewer-overlay';
    overlay.id = 'attachmentViewerModal';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = '<section class="modal attachment-viewer-modal" role="dialog" aria-modal="true" aria-labelledby="attachmentViewerTitle" tabindex="-1">' +
      '<header class="attachment-viewer-header"><div><h2 class="modal-title" id="attachmentViewerTitle">Attachment</h2><p class="modal-sub attachment-viewer-sub" id="attachmentViewerMeta"></p></div>' +
      '<button type="button" class="btn btn-secondary attachment-viewer-close" data-attachment-viewer-close aria-label="Close attachment preview">&times;</button></header>' +
      '<div class="attachment-viewer-content" id="attachmentViewerContent"></div>' +
      '<footer class="attachment-viewer-footer"><a class="btn btn-primary" id="attachmentViewerDownload" download>Download file</a><button type="button" class="btn btn-secondary" data-attachment-viewer-close>Close</button></footer>' +
      '</section>';
    document.body.append(overlay);
    overlay.addEventListener('click', event => {
      if (event.target === overlay || event.target.closest('[data-attachment-viewer-close]')) closeViewer();
    });
    return overlay;
  }

  function previewUrlFor(url) {
    if (!url) return '';
    try {
      const parsed = new URL(url, window.location.href);
      if (/\/download\.php$/i.test(parsed.pathname)) parsed.searchParams.set('preview', '1');
      return parsed.href;
    } catch (_) {
      return url;
    }
  }

  function closeViewer() {
    const overlay = document.getElementById('attachmentViewerModal');
    if (!overlay) return;
    overlay.classList.remove('open');
    const topLayerHost = document.getElementById('attachmentViewerTopLayer');
    if (topLayerHost?.open) topLayerHost.close();
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = viewerPreviousOverflow;
    const content = document.getElementById('attachmentViewerContent');
    if (content) content.replaceChildren();
    viewerReturnFocus?.focus?.();
    viewerReturnFocus = null;
  }

  window.openAttachmentViewer = function (file) {
    const name = String(file?.name || 'Attachment');
    const url = String(file?.url || '');
    if (!url) return;
    const ext = fileExtension(name) || fileExtension(url.split('?')[0]);
    const image = typeof file.image === 'boolean' ? file.image : imageExtensions.has(ext);
    const overlay = ensureViewer();
    const dialog = overlay.querySelector('.attachment-viewer-modal');
    const title = document.getElementById('attachmentViewerTitle');
    const meta = document.getElementById('attachmentViewerMeta');
    const content = document.getElementById('attachmentViewerContent');
    const download = document.getElementById('attachmentViewerDownload');
    title.textContent = name;
    meta.textContent = [ext ? ext.toUpperCase() + ' file' : 'File attachment', file.size ? formatSize(file.size) : ''].filter(Boolean).join(' · ');
    download.href = url;
    download.download = name;
    content.replaceChildren();

    if (image) {
      const img = document.createElement('img');
      img.className = 'attachment-viewer-image';
      img.src = file.previewUrl || previewUrlFor(url);
      img.alt = name;
      content.append(img);
    } else if (previewableDocumentExtensions.has(ext)) {
      const frame = document.createElement('iframe');
      frame.className = 'attachment-viewer-frame';
      frame.src = previewUrlFor(url);
      frame.title = name;
      frame.setAttribute('referrerpolicy', 'no-referrer');
      content.append(frame);
    } else {
      const notice = document.createElement('div');
      notice.className = 'attachment-viewer-unavailable';
      const badge = document.createElement('span');
      badge.className = 'attachment-viewer-type';
      badge.textContent = ext ? ext.toUpperCase() : 'FILE';
      const message = document.createElement('p');
      message.textContent = 'This file type cannot be previewed in the browser. Download the file to open it.';
      notice.append(badge, message);
      content.append(notice);
    }

    viewerReturnFocus = document.activeElement;
    viewerPreviousOverflow = document.body.style.overflow;
    // Keep the viewer above any modal that launched it, including dialogs
    // whose z-index is higher than the shared default modal layer.
    if (overlay.parentElement !== document.body || overlay !== document.body.lastElementChild) {
      document.body.append(overlay);
    }
    const highestOpenLayer = [...document.querySelectorAll('.modal-overlay.open')]
      .filter(openOverlay => openOverlay !== overlay)
      .reduce((highest, openOverlay) => {
        const layer = Number.parseInt(window.getComputedStyle(openOverlay).zIndex, 10);
        return Math.max(highest, Number.isFinite(layer) ? layer : 200);
      }, 200);
    overlay.style.zIndex = String(highestOpenLayer + 1);
    if (document.querySelector('dialog[open]')) {
      let host = document.getElementById('attachmentViewerTopLayer');
      if (!host) {
        host = document.createElement('dialog');
        host.id = 'attachmentViewerTopLayer';
        host.style.cssText = 'position:fixed;inset:0;margin:0;width:100vw;height:100vh;max-width:none;max-height:none;padding:0;border:0;background:transparent;';
        host.addEventListener('cancel', event => { event.preventDefault(); closeViewer(); });
        document.body.append(host);
      }
      host.append(overlay);
      if (!host.open) host.showModal();
    }
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    dialog.focus();
  };

  document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download')) return;
    const href = link.getAttribute('href') || '';
    const isPreviewCard = link.classList.contains('attachment-preview-link');
    const isAttachmentPath = /(?:^|\/)download\.php\?file=/i.test(href) || /\/uploads\//i.test(href);
    if (!isPreviewCard && !isAttachmentPath) return;
    if (link.target === '_blank' && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) return;
    event.preventDefault();
    event.stopPropagation();
    const detailsName = link.querySelector('.attachment-preview-name')?.textContent?.trim();
    const image = link.querySelector('img');
    const url = link.href;
    let pathName = '';
    try {
      const parsed = new URL(url, window.location.href);
      pathName = parsed.searchParams.get('file') || parsed.pathname.split('/').pop() || '';
      pathName = decodeURIComponent(pathName.split('/').pop());
    } catch (_) {}
    const ariaName = link.dataset.fileName || link.getAttribute('aria-label') || link.getAttribute('title') || '';
    const usefulAriaName = /^(view|open)\b/i.test(ariaName) ? '' : ariaName;
    window.openAttachmentViewer({
      name: detailsName || image?.alt || usefulAriaName || pathName || link.textContent.trim() || 'Attachment',
      url,
      image: image ? true : undefined,
      previewUrl: image?.src || '',
    });
  }, true);

  document.addEventListener('keydown', event => {
    const overlay = document.getElementById('attachmentViewerModal');
    if (!overlay?.classList.contains('open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopImmediatePropagation();
      closeViewer();
      return;
    }
    if (event.key === 'Tab' && typeof window.openModal !== 'function') {
      const focusable = [...overlay.querySelectorAll('a[href], button:not([disabled])')].filter(node => node.getClientRects().length);
      if (!focusable.length) { event.preventDefault(); return; }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && (document.activeElement === first || !overlay.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  window.clearAttachmentPreviewForInput = function (input, defaultLabel) {
    if (!input) return;
    input.value = '';
    const area = input.closest('.upload-area');
    if (area) {
      area.classList.remove('has-file', 'is-dragging');
      const title = area.querySelector('.upload-title');
      if (title && defaultLabel) title.textContent = defaultLabel;
      area.removeAttribute('aria-label');
    }
    const requirement = input.closest('.requirement');
    const formGroup = input.closest('.form-group');
    const parent = requirement?.querySelector('.req-actions') || formGroup || input.parentElement;
    const adjacentHost = area?.nextElementSibling;
    const host = (adjacentHost?.matches('.attachment-preview[data-generated-preview]') ? adjacentHost : null)
      || parent?.querySelector(':scope > .attachment-preview[data-generated-preview]');
    if (host) window.renderAttachmentPreview(host, []);
  };

  function previewHostFor(input) {
    const uploadArea = input.closest('.upload-area');
    const requirement = input.closest('.requirement');
    const formGroup = input.closest('.form-group');
    const parent = requirement?.querySelector('.req-actions') || formGroup || input.parentElement;
    let host = parent.querySelector(':scope > .attachment-preview[data-generated-preview]');
    if (!host) {
      host = document.createElement('div');
      host.className = 'attachment-preview';
      host.dataset.generatedPreview = 'true';
      host.setAttribute('aria-live', 'polite');
      if (uploadArea) uploadArea.insertAdjacentElement('afterend', host);
      else parent.append(host);
    }
    return host;
  }

  function showSelectedFiles(input) {
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || input.id === 'profileAvatarInput') return;
    // Some upload flows provide their own richer preview UI. Leave those inputs
    // alone so this shared renderer does not create a duplicate preview list.
    if (input.closest('[data-custom-file-preview]')) return;
    const host = previewHostFor(input);
    window.renderAttachmentPreview(host, Array.from(input.files || []));
    Array.from(host.children).forEach((item, index) => {
      const file = input.files[index];
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = '×';
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.title = 'Remove file';
      remove.style.cssText = 'position:absolute;top:8px;right:8px;width:30px;height:30px;border:1px solid #d7e1ed;border-radius:50%;background:#fff;color:#344b6b;font-size:20px;line-height:1;cursor:pointer;z-index:1;';
      item.style.position = 'relative';
      item.style.paddingRight = '40px';
      remove.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const remaining = new DataTransfer();
        Array.from(input.files).forEach((selected, selectedIndex) => {
          if (selectedIndex !== index) remaining.items.add(selected);
        });
        input.files = remaining.files;
        input.dispatchEvent(new Event('change', {bubbles:true}));
      });
      item.append(remove);
    });
  }

  document.addEventListener('change', event => {
    if (event.target instanceof HTMLInputElement && event.target.type === 'file') showSelectedFiles(event.target);
  });

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-attachment-preview]').forEach(container => {
      const { fileName, fileUrl, previewUrl, fileSize } = container.dataset;
      if (fileName && fileUrl) {
        window.renderAttachmentPreview(container, [{
          name: fileName,
          url: fileUrl,
          previewUrl: previewUrl || '',
          size: Number(fileSize || 0),
        }]);
      }
    });
  });
})();
