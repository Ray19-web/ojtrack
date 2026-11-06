<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$uid   = (int)$user['id'];
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$uid], 'i');
$success = '';
$error   = '';
$archive_view = ($_GET['view'] ?? '') === 'archived';

$save_announcement_upload = function() {
    if (empty($_FILES['attachment']['name']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null,null];
    }
    if (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('Failed to upload the attachment. Please try again.');
    }
    $orig=$_FILES['attachment']['name'];
    $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
    $allowed=['pdf','doc','docx','jpg','jpeg','png','gif','webp','xls','xlsx','ppt','pptx'];
    if (!in_array($ext,$allowed,true)) throw new DomainException('Invalid attachment type. Allowed: PDF, DOC, images, and common office files.');
    if ((int)($_FILES['attachment']['size'] ?? 0)>10*1024*1024) throw new DomainException('Attachment is too large. Maximum size is 10MB.');
    $dir=__DIR__.'/../uploads/announcements/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $filename='ann_'.bin2hex(random_bytes(16)).'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'],$dir.$filename)) {
        throw new DomainException('Failed to upload the attachment. Please try again.');
    }
    return ['announcements/'.$filename,$orig];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action=$_POST['action'] ?? '';
    try {
        if ($action==='post') {
            $title=trim($_POST['title'] ?? '');
            $body=trim($_POST['body'] ?? '');
            $tag=trim($_POST['tag'] ?? 'Department');
            $pinned=isset($_POST['is_pinned']);
            $expires=!empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            if (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                $postId=normalized_announcement_create(
                    $uid,$title,$body,$tag,'student',$pinned,$expires,$attachmentFile,$attachmentName
                );
                foreach (normalized_announcement_recipient_rows($uid,'student') as $recipient) {
                    create_notification((int)$recipient['user_id'],"Announcement: $title",'info','/ojtrack/student/announcements.php');
                }
                log_activity($uid,'Department Notice Posted',"Post ID: $postId · $title");
                $success='Announcement published. Students under your supervision were notified.';
            }
        } elseif ($action==='edit') {
            $id=(int)($_POST['ann_id'] ?? 0);
            $title=trim($_POST['title'] ?? '');
            $body=trim($_POST['body'] ?? '');
            $tag=trim($_POST['tag'] ?? 'Department');
            $pinned=isset($_POST['is_pinned']);
            $expires=!empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            if (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                $updated=normalized_announcement_update(
                    $id,$uid,$title,$body,$tag,'student',$pinned,$expires,
                    $attachmentFile,$attachmentName,!empty($_POST['remove_attachment'])
                );
                if (!$updated) $error='You can only edit notices you created.';
                else {
                    log_activity($uid,'Department Notice Updated',$title);
                    $success='Notice updated successfully.';
                }
            }
        } elseif ($action==='archive') {
            $id=(int)($_POST['ann_id'] ?? 0);
            $owned_notice=query_one("SELECT id FROM announcement_posts WHERE id=? AND created_by=? AND is_active=1",[$id,$uid],'ii');
            if ($owned_notice && normalized_announcement_soft_delete($id,$uid)) {
                log_activity($uid,'Department Notice Archived',"ID: $id");
                $success='Notice archived.';
            } else {
                $error='Notice not found or you do not have permission to archive it.';
            }
        } elseif ($action==='restore') {
            $id=(int)($_POST['ann_id'] ?? 0);
            $owned_notice=query_one("SELECT id FROM announcement_posts WHERE id=? AND created_by=? AND is_active=0",[$id,$uid],'ii');
            if ($owned_notice) {
                query("UPDATE announcement_posts SET is_active=1 WHERE id=? AND created_by=? AND is_active=0",[$id,$uid],'ii');
                log_activity($uid,'Department Notice Restored',"ID: $id");
                $success='Notice restored to active announcements.';
            } else {
                $error='Archived notice not found or you do not have permission to restore it.';
            }
        } elseif ($action==='toggle_pin') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_toggle_pin($id,$uid)) $success='Pin status updated.';
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

$announcements = $archive_view
    ? (query(
        normalized_announcement_select_sql()." WHERE ap.created_by=? AND ap.is_active=0 ORDER BY ap.created_at DESC",
        [$uid],
        'i'
    ) ?: [])
    : normalized_announcements_for_user($uid, true);
$archived_count=(int)(query_one(
    "SELECT COUNT(*) AS c FROM announcement_posts WHERE created_by=? AND is_active=0",
    [$uid],
    'i'
)['c'] ?? 0);
$dept_student_count = count(normalized_students_for_coordinator((int)$coord['id']));
$announcement_tags = array_values(array_unique(array_map(
    static fn(array $announcement): string => trim((string)($announcement['tag'] ?? 'Department')) ?: 'Department',
    $announcements
)));
natcasesort($announcement_tags);
$announcement_tags = array_values($announcement_tags);

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card announcement-management-toolbar-card">
  <div class="announcement-toolbar announcement-management-toolbar<?= $archive_view ? ' is-archived-view' : '' ?>">
    <div class="announcement-toolbar-heading">
      <div class="page-title"><?= $archive_view ? 'Archived Announcements' : 'Announcements' ?></div>
      <div class="page-sub"><?= $archive_view ? 'Notices you archived. Restore one to publish it again.' : 'Students in your program (' . e($coord['department'] ?? 'your program') . ') · ' . $dept_student_count . ' recipients' ?></div>
    </div>
    <input type="search" class="form-control announcement-toolbar-search" id="announcementToolbarSearch" data-tour-announcement-search placeholder="Search title or message..." aria-label="Search announcements">
    <div class="announcement-filter-wrap">
      <button type="button" class="table-tool-button announcement-toolbar-icon-button announcement-filter-trigger" id="announcementFilterToggle" data-tour-announcement-filter aria-label="Filter announcements" title="Filter announcements" aria-expanded="false" aria-controls="announcementFilterPopover">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.3l-3.6 1.8v-7.1L4 5z"></path></svg>
      </button>
      <div class="journal-filter-popover announcement-filter-popover" id="announcementFilterPopover" hidden>
        <label for="announcementCategoryFilter">Category</label>
        <select class="form-control" id="announcementCategoryFilter">
          <option value="">All categories</option>
          <?php foreach ($announcement_tags as $tag): ?><option value="<?= e(mb_strtolower($tag, 'UTF-8')) ?>"><?= e($tag) ?></option><?php endforeach; ?>
        </select>
        <label class="announcement-filter-pinned"><input type="checkbox" id="announcementPinnedFilter"><span>Pinned posts only</span></label>
        <div class="announcement-filter-footer">
          <span class="journal-filter-summary" id="announcementFilterSummary" aria-live="polite"></span>
          <button type="button" class="journal-filter-clear" id="announcementFilterClear">Clear filters</button>
        </div>
      </div>
    </div>
    <a class="btn btn-secondary announcement-toolbar-icon-button" data-tour-announcement-archive href="/ojtrack/coordinator/announcements.php<?= $archive_view ? '' : '?view=archived' ?>" title="<?= $archive_view ? 'View active posts' : 'View archived posts (' . $archived_count . ')' ?>" aria-label="<?= $archive_view ? 'View active posts' : 'View archived posts, ' . $archived_count . ' archived' ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="4" rx="1.2"/><path d="M5 8.5v11h14v-11M9 12h6"/></svg>
    </a>
    <?php if (!$archive_view): ?><button class="btn btn-primary" data-tour-announcement-compose onclick="openModal('composeModal')">+ Post Announcement</button><?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="announcement-feed announcement-management-feed">
  <?php if (empty($announcements)): ?>
    <div class="card card-body announcement-empty-state">
      <div class="announcement-empty-icon" aria-hidden="true">✦</div>
      <h2><?= $archive_view ? 'No archived notices' : 'No announcements yet' ?></h2>
      <p><?= $archive_view ? 'Notices you archive will appear here, where you can restore them.' : 'Notices for your students will appear here. Post one to keep them informed.' ?></p>
    </div>
  <?php endif; ?>
  <?php foreach ($announcements as $a):
          $ann_payload = [
              'id' => (int)$a['id'],
              'editable' => (int)$a['created_by'] === $uid,
              'title' => $a['title'] ?? '',
              'body' => $a['body'] ?? '',
              'tag' => $a['tag'] ?? 'Department',
              'is_pinned' => (int)($a['is_pinned'] ?? 0),
              'expires_at' => !empty($a['expires_at']) ? date('Y-m-d', strtotime($a['expires_at'])) : '',
              'created_at' => isset($a['created_at']) ? format_date($a['created_at']) : '',
              'attachment_file' => $a['attachment_file'] ?? '',
              'attachment_name' => $a['attachment_name'] ?? '',
          ];
    $editable = (int)$a['created_by'] === $uid;
    $author_name = $a['author_name'] ?: 'OJT Office';
    $attachment_ext = strtolower(pathinfo($a['attachment_file'] ?? '', PATHINFO_EXTENSION));
    $is_image = in_array($attachment_ext, ['jpg','jpeg','png','gif','webp'], true);
  ?>
    <article class="card announcement-item announcement-management-item <?= !empty($a['is_pinned']) ? 'is-pinned' : '' ?>" data-tour-announcement-post data-announcement-card data-category="<?= e(mb_strtolower(trim((string)($a['tag'] ?? 'Department')) ?: 'Department', 'UTF-8')) ?>" data-search="<?= e(mb_strtolower(($a['title'] ?? '') . ' ' . ($a['body'] ?? ''), 'UTF-8')) ?>" data-pinned="<?= !empty($a['is_pinned']) ? '1' : '0' ?>">
      <div class="announcement-item-inner">
        <header class="announcement-social-head">
          <div class="announcement-author-avatar" aria-hidden="true"><?= e(user_avatar_initials($author_name)) ?></div>
          <div class="announcement-author-copy">
            <strong><?= e($author_name) ?></strong>
            <span><?= format_date($a['created_at']) ?> <span aria-hidden="true">·</span> <?= !empty($a['expires_at']) ? 'Expires ' . format_date($a['expires_at']) : 'No expiration' ?></span>
          </div>
          <div class="announcement-social-actions" data-tour-announcement-actions>
            <?php if ($editable && $archive_view): ?>
              <form method="POST" data-confirm-title="Restore this notice?" data-confirm-label="Restore" data-confirm-message="Restore this notice to the active announcements feed?"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="announcement-icon-action" data-tour-announcement-restore title="Restore notice" aria-label="Restore notice"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg></button>
              </form>
            <?php elseif ($editable): ?>
              <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="announcement-icon-action <?= $a['is_pinned'] ? 'is-active' : '' ?>" title="<?= $a['is_pinned'] ? 'Unpin notice' : 'Pin notice' ?>" aria-label="<?= $a['is_pinned'] ? 'Unpin notice' : 'Pin notice' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6-3 1-4 4-1 5-4-4 5-1 4-4 1-3Z"/><path d="m4 20 5-5"/></svg></button>
              </form>
              <form method="POST" data-confirm-title="Archive this notice?" data-confirm-label="Archive" data-confirm-message="Archive this notice? It will be removed from active announcement feeds."><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="announcement-icon-action" title="Archive notice" aria-label="Archive notice"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16l1 4H3l1-4Z"/><path d="M5 8v11h14V8M9 12h6"/></svg></button>
              </form>
            <?php endif; ?>
          </div>
        </header>
        <div class="announcement-label-row">
          <?php if ($archive_view): ?><span class="announcement-label is-archived-label">Archived</span><?php elseif (!empty($a['is_pinned'])): ?><span class="announcement-label is-pinned-label">Pinned</span><?php endif; ?>
          <span class="announcement-label"><?= e($a['tag'] ?: 'Department') ?></span>
          <?php if (!$archive_view): ?>
            <?php if (!empty($a['expires_at']) && strtotime($a['expires_at']) < strtotime('today')): ?><span class="announcement-label is-expired-label">Expired</span><?php else: ?><span class="announcement-label is-active-label">Active</span><?php endif; ?>
          <?php endif; ?>
        </div>
        <h2 class="announcement-item-title"><?= e($a['title']) ?></h2>
        <div class="announcement-item-copy"><?= nl2br(e($a['body'])) ?></div>
        <?php if (!empty($a['attachment_file'])): ?>
          <div class="announcement-item-attachment">
            <?php if ($is_image): ?><a class="announcement-image-link" href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" rel="noopener"><img src="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" alt="<?= e($a['attachment_name'] ?: 'Announcement image') ?>" class="announcement-item-image" loading="lazy"></a>
            <?php else: ?><a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" rel="noopener" class="announcement-file-card"><span class="announcement-file-icon" aria-hidden="true">↧</span><span><strong><?= e($a['attachment_name'] ?: 'Download attachment') ?></strong><small><?= e(strtoupper($attachment_ext)) ?> file</small></span></a><?php endif; ?>
          </div>
        <?php endif; ?>
        <footer class="announcement-item-footer"><span><?= $archive_view ? 'Your archived department notice' : ($editable ? 'Your department notice' : 'Shared by OJT administration') ?></span><?php if ($editable && !$archive_view): ?><button type="button" class="announcement-edit-link" onclick='openViewEditAnn(<?= htmlspecialchars(json_encode($ann_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>Edit notice <span aria-hidden="true">→</span></button><?php endif; ?></footer>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!empty($announcements)): ?>
    <div class="card card-body announcement-empty-state announcement-filter-empty" id="announcementFilterEmpty" hidden>
      <div class="announcement-empty-icon" aria-hidden="true">⌕</div>
      <h2>No matching announcements</h2>
      <p>Try changing the search or clearing one of the filters.</p>
    </div>
  <?php endif; ?>
</div>

<!-- Compose Notice Modal -->
<div class="modal-overlay" id="composeModal">
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Post Department Notice</div>
        <p class="modal-sub">This notifies only students assigned to you — not campus-wide</p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('composeModal')" aria-label="Close notice form">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="post">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Midterm narrative report deadline" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category / Tag</label>
          <select name="tag" class="form-control">
            <option value="Department">Department</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Reminder">Reminder</option>
            <option value="Requirements">Requirements</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Expiration Date (Optional)</label>
          <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="form-group flex-items-center">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1">
          <span>
            <strong>Pin this notice to top</strong>
            <small>Keep it visible above regular department notices.</small>
          </span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" class="form-control" rows="6" placeholder="Your announcement" required></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Attachment (image or file, optional)</label>
<div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Browse or drop a file here</div>
          <div class="upload-sub">PDF, DOC, images, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish to Department</button>
      </div>
    </form>
  </div>
</div>

<!-- View / Edit Notice Modal -->
<div class="modal-overlay" id="viewEditAnnModal">
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">View &amp; Edit Notice</div>
        <p class="modal-sub" id="viewEditAnnMeta"></p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('viewEditAnnModal')" aria-label="Close notice details">×</button>
    </div>
    <form method="POST" id="viewEditAnnForm" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="ann_id" id="editAnnId">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="editAnnTitle" class="form-control" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category / Tag</label>
          <select name="tag" id="editAnnTag" class="form-control">
            <option value="Department">Department</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Reminder">Reminder</option>
            <option value="Requirements">Requirements</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Expiration Date (Optional)</label>
          <input type="date" name="expires_at" id="editAnnExpires" class="form-control">
        </div>
      </div>

      <div class="form-group flex-items-center">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1" id="editAnnPinned">
          <span>
            <strong>Pin this notice to top</strong>
            <small>Keep it visible above regular department notices.</small>
          </span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" id="editAnnBody" class="form-control" rows="7" required></textarea>
      </div>

      <div class="form-group announcement-current-file" id="editAnnCurrentFileWrap" style="display:none">
        <label class="form-label">Current Attachment</label>
        <div id="editAnnCurrentFilePreview" class="attachment-preview" data-attachment-preview aria-live="polite" hidden></div>
        <label class="announcement-remove-control mt-2">
          <input type="checkbox" name="remove_attachment" value="1">
          <span>Remove attachment</span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Replace / Add Attachment (optional)</label>
<div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Choose a replacement file</div>
          <div class="upload-sub">PDF, DOC, images, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('viewEditAnnModal')">Close</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openViewEditAnn(a) {
  window.clearAttachmentPreviewForInput(document.querySelector('#viewEditAnnModal input[type="file"]'), 'Choose a replacement file');
  document.getElementById('editAnnId').value = a.id;
  document.getElementById('editAnnTitle').value = a.title || '';
  document.getElementById('editAnnBody').value = a.body || '';
  document.getElementById('editAnnTag').value = a.tag || 'Department';
  document.getElementById('editAnnExpires').value = a.expires_at || '';
  document.getElementById('editAnnPinned').checked = !!Number(a.is_pinned);
  document.getElementById('viewEditAnnMeta').textContent = a.created_at ? ('Posted · ' + a.created_at) : '';
  var fw = document.getElementById('editAnnCurrentFileWrap');
  var filePreview = document.getElementById('editAnnCurrentFilePreview');
  if (a.attachment_file) {
    fw.style.display = '';
    var fileUrl = '/ojtrack/uploads/' + String(a.attachment_file).split('/').map(encodeURIComponent).join('/');
    var attachmentName = a.attachment_name || String(a.attachment_file).split('/').pop();
    var isImageAttachment = /\.(jpe?g|png|gif|webp)$/i.test(String(a.attachment_file));
    window.renderAttachmentPreview(filePreview, [{name: attachmentName, image: isImageAttachment, url: fileUrl, previewUrl: isImageAttachment ? fileUrl : ''}]);
  } else {
    fw.style.display = 'none';
    window.renderAttachmentPreview(filePreview, []);
  }
  document.querySelectorAll('#viewEditAnnModal input:not([type=hidden]), #viewEditAnnModal textarea, #viewEditAnnModal select, #viewEditAnnModal button[type=submit]').forEach(el => el.disabled = !a.editable);
  openModal('viewEditAnnModal');
}

(function initAnnouncementFilters() {
  var toggle = document.getElementById('announcementFilterToggle');
  var popover = document.getElementById('announcementFilterPopover');
  var search = document.getElementById('announcementToolbarSearch');
  var category = document.getElementById('announcementCategoryFilter');
  var pinned = document.getElementById('announcementPinnedFilter');
  var clear = document.getElementById('announcementFilterClear');
  var summary = document.getElementById('announcementFilterSummary');
  var empty = document.getElementById('announcementFilterEmpty');
  var cards = Array.from(document.querySelectorAll('[data-announcement-card]'));
  if (!toggle || !popover) return;

  var setOpen = function(open) {
    popover.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    toggle.classList.toggle('is-active', open);
    if (open) category.focus();
  };
  var applyFilters = function() {
    var query = (search.value || '').trim().toLocaleLowerCase();
    var selectedCategory = category.value;
    var pinnedOnly = pinned.checked;
    var visible = 0;
    cards.forEach(function(card) {
      var matches = (!query || (card.dataset.search || '').includes(query))
        && (!selectedCategory || card.dataset.category === selectedCategory)
        && (!pinnedOnly || card.dataset.pinned === '1');
      card.hidden = !matches;
      if (matches) visible++;
    });
    if (summary) summary.textContent = 'Showing ' + visible + ' of ' + cards.length;
    if (empty) empty.hidden = visible > 0;
  };

  toggle.addEventListener('click', function() { setOpen(popover.hidden); });
  search.addEventListener('input', applyFilters);
  category.addEventListener('change', applyFilters);
  pinned.addEventListener('change', applyFilters);
  clear.addEventListener('click', function() {
    search.value = '';
    category.value = '';
    pinned.checked = false;
    applyFilters();
    search.focus();
  });
  document.addEventListener('click', function(event) {
    if (!popover.hidden && !popover.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
  });
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && !popover.hidden) {
      setOpen(false);
      toggle.focus();
    }
  });
  applyFilters();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
