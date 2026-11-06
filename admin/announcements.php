<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$uid  = (int)$user['id'];
$success = ''; $error = '';

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
            $target=$_POST['target_role'] ?? 'all';
            $tag=trim($_POST['tag'] ?? 'General');
            $pinned=isset($_POST['is_pinned']);
            if (!in_array($target,['all','student','coordinator','company'],true)) $target='all';

            if (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                $postId=normalized_announcement_create(
                    $uid,$title,$body,$tag,$target,$pinned,null,$attachmentFile,$attachmentName
                );
                foreach (normalized_announcement_recipient_rows($uid,$target) as $recipient) {
                    $link=match($recipient['role']) {
                        'coordinator'=>'/ojtrack/coordinator/announcements.php',
                        'company'=>'/ojtrack/company/announcements.php',
                        default=>'/ojtrack/student/announcements.php',
                    };
                    create_notification((int)$recipient['user_id'],"New announcement: $title",'info',$link);
                }
                log_activity($uid,'Announcement Posted',"Post ID: $postId · $title");
                $success='Announcement published successfully.';
            }
        } elseif ($action==='edit') {
            $id=(int)($_POST['ann_id'] ?? 0);
            $title=trim($_POST['title'] ?? '');
            $body=trim($_POST['body'] ?? '');
            $target=$_POST['target_role'] ?? 'all';
            $tag=trim($_POST['tag'] ?? 'General');
            $pinned=isset($_POST['is_pinned']);
            if (!in_array($target,['all','student','coordinator','company'],true)) $target='all';

            $existing=query_one("SELECT * FROM announcement_posts WHERE id=?",[$id],'i');
            if (!$existing) {
                $error='Announcement not found.';
            } elseif (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                normalized_announcement_update(
                    $id,$uid,$title,$body,$tag,$target,$pinned,$existing['expires_at'],
                    $attachmentFile,$attachmentName,!empty($_POST['remove_attachment'])
                );
                log_activity($uid,'Announcement Updated',$title);
                $success='Announcement updated successfully.';
            }
        } elseif ($action==='toggle_pin') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_toggle_pin($id,$uid)) $success='Pin status updated.';
        } elseif (in_array($action,['archive_post','restore_post'],true)) {
            $id=(int)($_POST['ann_id'] ?? 0);
            $post=query_one("SELECT id,title,is_active FROM announcement_posts WHERE id=?",[$id],'i');
            if (!$post) {
                $error='Announcement not found.';
            } else {
                $active=$action==='restore_post' ? 1 : 0;
                query("UPDATE announcement_posts SET is_active=? WHERE id=?",[$active,$id],'ii');
                log_activity($uid,$active ? 'Announcement Restored' : 'Announcement Archived',$post['title']);
                $success=$active ? 'Announcement restored.' : 'Announcement archived.';
            }
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

require_once __DIR__.'/../config/record-filters.php';
$filters=announcement_management_filters($_GET);
$archive_view=($_GET['view'] ?? '')==='archived';
$search=$filters['search'];
$announcements=normalized_announcements_admin($search,$_GET);
$tag_options=[''=>'All Categories'];
foreach (query("SELECT DISTINCT COALESCE(NULLIF(tag,''),'General') AS tag FROM announcement_posts ORDER BY tag") ?: [] as $tag_row) $tag_options[$tag_row['tag']]=$tag_row['tag'];
if ($filters['tag']!=='' && !isset($tag_options[$filters['tag']])) $tag_options[$filters['tag']]=$filters['tag'];
$total_count=(int)(query_one("SELECT COUNT(*) c FROM announcement_posts")['c'] ?? 0);
$pinned_count=(int)(query_one("SELECT COUNT(*) c FROM announcement_posts WHERE is_pinned=1")['c'] ?? 0);
$all_count=(int)(query_one("SELECT COUNT(*) c FROM announcement_posts WHERE target_role='all'")['c'] ?? 0);
$with_file=(int)(query_one("SELECT COUNT(DISTINCT announcement_post_id) c FROM announcement_attachments")['c'] ?? 0);

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="announcement-admin-feed">
  <div class="card announcement-filter-card">
    <form method="GET" class="announcement-toolbar<?= $archive_view ? ' is-archived-view' : '' ?>" id="announcementFilters" data-live-filter>
      <?php if ($archive_view): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
      <div class="announcement-toolbar-heading">
        <div class="page-title">Announcements</div>
        <div class="page-sub"><?= $archive_view ? 'Archived campus notices' : 'Campus notices and updates' ?></div>
      </div>
      <input type="search" class="form-control announcement-toolbar-search" name="q" maxlength="150" placeholder="Search title, message, author..." aria-label="Search announcements" data-tour-announcement-search value="<?= e($search) ?>">
      <button type="button" class="table-tool-button announcement-toolbar-icon-button" data-tour-announcement-filter data-table-filter-toggle="announcementFilters" aria-controls="announcementFiltersDrawer" aria-expanded="false" aria-haspopup="dialog" title="Filter announcements" aria-label="Filter announcements">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.2l-3.6 1.8v-7z"/></svg>
      </button>
      <a class="btn btn-secondary announcement-toolbar-icon-button" data-tour-announcement-archive href="/ojtrack/admin/announcements.php<?= $archive_view ? '' : '?view=archived' ?>" title="<?= $archive_view ? 'View active posts' : 'View archived posts' ?>" aria-label="<?= $archive_view ? 'View active posts' : 'View archived posts' ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="4" rx="1.2"/><path d="M5 8.5v11h14v-11M9 12h6"/></svg>
      </a>
      <?php if (!$archive_view): ?><button type="button" class="btn btn-primary" data-tour-announcement-compose onclick="openModal('composeModal')">+ Post Announcement</button><?php endif; ?>
      <div class="table-filter-drawer announcement-filter-drawer" id="announcementFiltersDrawer" data-filter-id="announcementFilters" aria-hidden="true">
        <section class="table-filter-panel" role="dialog" aria-modal="false" aria-labelledby="announcementFiltersTitle">
          <header class="table-filter-header">
            <div class="table-filter-heading-copy"><h2 id="announcementFiltersTitle">Filters</h2><p class="table-filter-description">Refine the announcements shown in this list.</p></div>
            <button type="button" class="table-filter-close" aria-label="Close filters">×</button>
          </header>
          <div class="table-filter-content">
            <div class="table-filter-drawer-form">
              <div><label class="form-label" for="announcementCategory">Category</label><select class="form-control" id="announcementCategory" name="tag"><?php foreach ($tag_options as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)$value===(string)$filters['tag'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
              <div><label class="form-label" for="announcementAudience">Audience</label><select class="form-control" id="announcementAudience" name="audience"><?php foreach ([''=>'Any Audience','all'=>'All Roles','student'=>'Student','coordinator'=>'Coordinator','company'=>'Company','admin'=>'Admin'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= (string)$value===(string)$filters['audience'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
          </div>
          <footer class="table-filter-footer">
            <button type="button" class="table-filter-reset" data-announcement-filter-reset>Reset</button>
            <button type="submit" class="table-filter-apply">Apply filters</button>
          </footer>
        </section>
      </div>
    </form>
  </div>
  <div class="announcement-feed">
    <?php if (empty($announcements)): ?>
      <div class="card card-body announcement-empty-state">
        <div class="announcement-empty-icon" aria-hidden="true">✦</div>
        <h2>No announcements found</h2>
        <p><?= $search !== '' ? 'Try another search or adjust your filters.' : ($archive_view ? 'Archived announcements will appear here.' : 'Published announcements will appear here.') ?></p>
      </div>
    <?php endif; ?>
    <?php foreach ($announcements as $a):
          $ann_payload = [
              'id' => (int)$a['id'],
              'title' => $a['title'] ?? '',
              'body' => $a['body'] ?? '',
              'target_role' => $a['target_role'] ?? 'all',
              'tag' => $a['tag'] ?? 'General',
              'is_pinned' => (int)($a['is_pinned'] ?? 0),
              'attachment_file' => $a['attachment_file'] ?? '',
              'attachment_name' => $a['attachment_name'] ?? '',
              'author_name' => $a['author_name'] ?? 'OJT Office',
              'created_at' => isset($a['created_at']) ? format_date($a['created_at']) : '',
          ];
        $author_name = $a['author_name'] ?: 'OJT Office';
        $audience_name = match ($a['target_role'] ?? 'all') {
            'student' => 'Students', 'coordinator' => 'Coordinators', 'company' => 'Company partners', default => 'Everyone',
        };
        $attachment_ext = strtolower(pathinfo($a['attachment_file'] ?? '', PATHINFO_EXTENSION));
        $is_image = in_array($attachment_ext, ['jpg','jpeg','png','gif','webp'], true);
    ?>
      <article class="card announcement-item announcement-admin-item <?= !empty($a['is_pinned']) ? 'is-pinned' : '' ?>" data-tour-announcement-post>
        <div class="announcement-item-inner">
          <header class="announcement-social-head">
            <div class="announcement-author-avatar" aria-hidden="true"><?= e(user_avatar_initials($author_name)) ?></div>
            <div class="announcement-author-copy">
              <strong><?= e($author_name) ?></strong>
              <span><?= format_date($a['created_at']) ?> <span aria-hidden="true">·</span> <?= e($audience_name) ?></span>
            </div>
            <div class="announcement-social-actions" data-tour-announcement-actions>
              <?php if ($archive_view): ?>
                <form method="POST"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore_post">
                  <input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="announcement-icon-action" data-tour-announcement-restore title="Restore announcement" aria-label="Restore announcement">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
                  </button>
                </form>
              <?php else: ?>
              <form method="POST"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_pin">
                <input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="announcement-icon-action <?= $a['is_pinned'] ? 'is-active' : '' ?>" title="<?= $a['is_pinned'] ? 'Unpin announcement' : 'Pin announcement' ?>" aria-label="<?= $a['is_pinned'] ? 'Unpin announcement' : 'Pin announcement' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6-3 1-4 4-1 5-4-4 5-1 4-4 1-3Z"/><path d="m4 20 5-5"/></svg>
                </button>
              </form>
              <button type="button" class="announcement-icon-action" onclick='openViewEditAnn(<?= htmlspecialchars(json_encode($ann_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' aria-label="Edit announcement" title="Edit announcement">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <form method="POST"><?= csrf_field() ?>
                <input type="hidden" name="action" value="archive_post">
                <input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="announcement-icon-action" title="Archive announcement" aria-label="Archive announcement">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v12h14V8M10 12h4"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </header>
          <div class="announcement-label-row">
            <?php if (!empty($a['is_pinned'])): ?><span class="announcement-label is-pinned-label">Pinned</span><?php endif; ?>
            <span class="announcement-label"><?= e($a['tag'] ?: 'General') ?></span>
            <span class="announcement-label is-audience-label"><?= e($audience_name) ?></span>
          </div>
          <h2 class="announcement-item-title"><?= e($a['title']) ?></h2>
          <div class="announcement-item-copy"><?= nl2br(e($a['body'])) ?></div>
          <?php if (!empty($a['attachment_file'])): ?>
            <div class="announcement-item-attachment">
              <?php if ($is_image): ?>
                <a class="announcement-image-link" href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" rel="noopener">
                  <img src="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" alt="<?= e($a['attachment_name'] ?: 'Announcement image') ?>" class="announcement-item-image" loading="lazy">
                </a>
              <?php else: ?>
                <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" rel="noopener" class="announcement-file-card"><span class="announcement-file-icon" aria-hidden="true">↧</span><span><strong><?= e($a['attachment_name'] ?: 'Download attachment') ?></strong><small><?= e(strtoupper($attachment_ext)) ?> file</small></span></a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <footer class="announcement-item-footer"><span>Official campus announcement</span></footer>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>

<!-- Compose Modal -->
<div class="modal-overlay" id="composeModal">
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Post New Announcement</div>
        <p class="modal-sub">Choose an audience and write your notice.</p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('composeModal')" aria-label="Close announcement form">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="post">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Schedule of OJT Clearance and Endorsement" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Target Audience <span class="text-danger">*</span></label>
          <select name="target_role" class="form-control" required>
            <option value="all">All Roles (Students, Coordinators, Companies)</option>
            <option value="student">Students Only</option>
            <option value="coordinator">Coordinators Only</option>
            <option value="company">Company Partners Only</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tag / Category</label>
          <select name="tag" class="form-control">
            <option value="General">General</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Policy">Policy</option>
            <option value="Welcome">Welcome</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Body Content <span class="text-danger">*</span></label>
        <textarea name="body" class="form-control" rows="6" placeholder="Enter announcement content..." required></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Attachment (File or Image)</label>
        <div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Browse or drop a file here</div>
          <div class="upload-sub">PDF, DOC, JPG, PNG, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1">
          <span>
            <strong>Pin to top</strong>
            <small>Keep this announcement above regular notices.</small>
          </span>
        </label>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish Announcement</button>
      </div>
    </form>
  </div>
</div>

<!-- View / Edit Announcement Modal -->
<div class="modal-overlay" id="viewEditAnnModal">
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Announcement Details</div>
        <p class="modal-sub" id="viewEditAnnMeta"></p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('viewEditAnnModal')" aria-label="Close announcement details">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="ann_id" id="editAnnId">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="editAnnTitle" class="form-control" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Target Audience <span class="text-danger">*</span></label>
          <select name="target_role" id="editAnnTarget" class="form-control" required>
            <option value="all">All Roles (Students, Coordinators, Companies)</option>
            <option value="student">Students Only</option>
            <option value="coordinator">Coordinators Only</option>
            <option value="company">Company Partners Only</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tag / Category</label>
          <select name="tag" id="editAnnTag" class="form-control">
            <option value="General">General</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Policy">Policy</option>
            <option value="Welcome">Welcome</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Body Content <span class="text-danger">*</span></label>
        <textarea name="body" id="editAnnBody" class="form-control" rows="7" required></textarea>
      </div>

      <div class="form-group announcement-current-file" id="editAnnCurrentFileWrap" style="display:none">
        <label class="form-label">Current Attachment</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div id="editAnnCurrentFilePreview" class="attachment-preview" data-attachment-preview aria-live="polite" hidden></div>
          <label class="announcement-remove-control">
            <input type="checkbox" name="remove_attachment" value="1" id="editAnnRemoveFile">
            <span>Remove attachment</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Replace / Add Attachment</label>
        <div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Browse or drop a file here</div>
          <div class="upload-sub">PDF, DOC, JPG, PNG, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1" id="editAnnPinned">
          <span>
            <strong>Pin to top</strong>
            <small>Keep this announcement above regular notices.</small>
          </span>
        </label>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('viewEditAnnModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openViewEditAnn(a) {
  window.clearAttachmentPreviewForInput(document.querySelector('#viewEditAnnModal input[type="file"]'), 'Browse or drop a file here');
  document.getElementById('editAnnId').value = a.id;
  document.getElementById('editAnnTitle').value = a.title || '';
  document.getElementById('editAnnBody').value = a.body || '';
  document.getElementById('editAnnTarget').value = a.target_role || 'all';
  document.getElementById('editAnnTag').value = a.tag || 'General';
  document.getElementById('editAnnPinned').checked = !!Number(a.is_pinned);
  document.getElementById('editAnnRemoveFile').checked = false;

  const metaParts = [];
  if (a.author_name) metaParts.push('By ' + a.author_name);
  if (a.created_at) metaParts.push(a.created_at);
  document.getElementById('viewEditAnnMeta').textContent = metaParts.join(' · ');

  const fileWrap = document.getElementById('editAnnCurrentFileWrap');
  const filePreview = document.getElementById('editAnnCurrentFilePreview');
  if (a.attachment_file) {
    fileWrap.style.display = 'block';
    const fileUrl = '/ojtrack/uploads/' + String(a.attachment_file).split('/').map(encodeURIComponent).join('/');
    const attachmentName = a.attachment_name || String(a.attachment_file).split('/').pop();
    const isImageAttachment = /\.(jpe?g|png|gif|webp)$/i.test(String(a.attachment_file));
    window.renderAttachmentPreview(filePreview, [{name: attachmentName, image: isImageAttachment, url: fileUrl, previewUrl: isImageAttachment ? fileUrl : ''}]);
  } else {
    fileWrap.style.display = 'none';
    window.renderAttachmentPreview(filePreview, []);
  }

  openModal('viewEditAnnModal');
}

(() => {
  const close = (drawer, toggle, restoreFocus = false) => {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    toggle.setAttribute('aria-expanded', 'false');
    if (restoreFocus) toggle.focus({preventScroll: true});
  };
  document.addEventListener('click', event => {
    const toggle = event.target.closest('[data-table-filter-toggle="announcementFilters"]');
    if (toggle) {
      const form = document.getElementById('announcementFilters');
      const drawer = document.getElementById('announcementFiltersDrawer');
      if (!form || !drawer) return;
      if (drawer.classList.contains('is-open')) {
        close(drawer, toggle);
        return;
      }
      if (typeof closeTableFilterDrawers === 'function') closeTableFilterDrawers();
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
      const panel = drawer.querySelector('.table-filter-panel');
      const rect = toggle.getBoundingClientRect();
      const left = Math.max(12, Math.min(rect.right - panel.offsetWidth, window.innerWidth - panel.offsetWidth - 12));
      let top = rect.bottom + 8;
      if (top + panel.offsetHeight > window.innerHeight - 12) top = Math.max(12, rect.top - panel.offsetHeight - 8);
      panel.style.left = left + 'px';
      panel.style.top = top + 'px';
      drawer.querySelector('select')?.focus({preventScroll: true});
      return;
    }
    const closeButton = event.target.closest('#announcementFiltersDrawer .table-filter-close');
    if (closeButton) {
      const drawer = closeButton.closest('.table-filter-drawer');
      const toggle = document.querySelector('[data-table-filter-toggle="announcementFilters"]');
      if (drawer && toggle) close(drawer, toggle, true);
      return;
    }
    const resetButton = event.target.closest('[data-announcement-filter-reset]');
    if (resetButton) {
      const form = document.getElementById('announcementFilters');
      if (!form) return;
      form.querySelectorAll('select').forEach(select => { select.selectedIndex = 0; });
      const search = form.querySelector('input[type="search"]');
      if (search) search.value = '';
      form.requestSubmit();
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
