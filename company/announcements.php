<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user = current_user();
$uid  = (int)$user['id'];
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$uid], 'i');

$success = '';
$error   = '';

// require_login() validates file contents and size before this handler runs.
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
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'post') {
            $title   = trim($_POST['title'] ?? '');
            $body    = trim($_POST['body'] ?? '');
            $tag     = trim($_POST['tag'] ?? 'General');
            $target  = $_POST['target'] ?? 'student';
            $pinned  = isset($_POST['is_pinned']);
            $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

            if (!$title || !$body) {
                $error = 'Title and body are required.';
            } elseif (!in_array($target, ['student','coordinator','both'], true)) {
                $error = 'Invalid target audience.';
            } else {
                $normalizedTarget = $target === 'both' ? 'all' : $target;
                [$attachmentFile, $attachmentName] = $save_announcement_upload();
                try {
                    $postId = normalized_announcement_create(
                        $uid,$title,$body,$tag,$normalizedTarget,$pinned,$expires,$attachmentFile,$attachmentName
                    );
                } catch (Throwable $exception) {
                    // A failed database transaction must not leave an orphaned new upload.
                    if ($attachmentFile !== null) @unlink(__DIR__ . '/../uploads/' . $attachmentFile);
                    throw $exception;
                }
                foreach (normalized_announcement_recipient_rows($uid,$normalizedTarget) as $recipient) {
                    $link = $recipient['role']==='coordinator'
                        ? '/ojtrack/coordinator/announcements.php'
                        : '/ojtrack/student/announcements.php';
                    create_notification(
                        (int)$recipient['user_id'],
                        "Notice from {$company['company_name']}: $title",
                        'info',
                        $link
                    );
                }
                log_activity($uid,'Company Notice Posted',"Post ID: $postId · $title");
                $success = 'Notice published' . ($target === 'both' ? ' for trainees and OJT coordinators.' : '.');
            }
        } elseif ($action === 'delete') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_soft_delete($id,$uid)) {
                log_activity($uid,'Company Notice Archived',"ID: $id");
                $success='Notice archived.';
            }
        } elseif ($action === 'toggle_pin') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_toggle_pin($id,$uid)) $success='Notice pin updated.';
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

$my_notices = normalized_announcements_authored($uid);

$archive_view = ($_GET['view'] ?? '') === 'archived';
$tag_filter = trim($_GET['tag'] ?? '');
$search     = trim($_GET['q'] ?? '');
$visible_notices = $archive_view
    ? array_values(array_filter($my_notices, static fn($notice) => (int)($notice['is_active'] ?? 0) === 0))
    : normalized_announcements_for_user($uid, false);

// Include the company's own active posts in the same feed as notices they receive.
if (!$archive_view) {
    $visibleIds = array_fill_keys(array_map(static fn($notice) => (int)$notice['id'], $visible_notices), true);
    foreach ($my_notices as $notice) {
        $noticeId = (int)($notice['id'] ?? 0);
        $isUnexpired = empty($notice['expires_at']) || (string)$notice['expires_at'] >= date('Y-m-d');
        if ((int)($notice['is_active'] ?? 0) === 1 && $isUnexpired && !isset($visibleIds[$noticeId])) {
            $visible_notices[] = $notice;
            $visibleIds[$noticeId] = true;
        }
    }
}

$containsSearch = static function(array $notice) use ($search): bool {
    if ($search === '') return true;
    foreach (['title', 'body', 'author_name'] as $field) {
        if (stripos((string)($notice[$field] ?? ''), $search) !== false) return true;
    }
    return false;
};
$announcements = array_values(array_filter($visible_notices, static function($notice) use ($tag_filter, $containsSearch): bool {
    return ($tag_filter === '' || (string)($notice['tag'] ?? '') === $tag_filter) && $containsSearch($notice);
}));
usort($announcements, static function($left, $right): int {
    $pinOrder = (int)($right['is_pinned'] ?? 0) <=> (int)($left['is_pinned'] ?? 0);
    return $pinOrder !== 0 ? $pinOrder : strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
});

$tagMap = [];
foreach (normalized_announcement_tags_for_user($uid) as $tagRow) $tagMap[(string)$tagRow['tag']] = (string)$tagRow['tag'];
foreach ($my_notices as $notice) {
    if ($archive_view && (int)($notice['is_active'] ?? 0) !== 0) continue;
    $tag = trim((string)($notice['tag'] ?? ''));
    if ($tag !== '') $tagMap[$tag] = $tag;
}
if ($tag_filter !== '') $tagMap[$tag_filter] = $tag_filter;
$all_tags = array_map(static fn($tag) => ['tag' => $tag], array_values($tagMap));
$archived_count = count(array_filter($my_notices, static fn($notice) => (int)($notice['is_active'] ?? 0) === 0));
$archiveParams = ['view' => $archive_view ? null : 'archived'];
if ($search !== '') $archiveParams['q'] = $search;
if ($tag_filter !== '') $archiveParams['tag'] = $tag_filter;
$archiveParams = array_filter($archiveParams, static fn($value) => $value !== null);
$archive_url = '/ojtrack/company/announcements.php' . ($archiveParams ? '?' . http_build_query($archiveParams) : '');

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="announcement-company-page">
  <form method="GET" class="card announcement-toolbar announcement-company-toolbar<?= $archive_view ? ' is-archived-view' : '' ?>" id="companyAnnouncementFilters" data-live-filter>
    <?php if ($archive_view): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
    <div class="announcement-toolbar-heading">
      <div class="page-title">Announcements</div>
      <div class="page-sub"><?= $archive_view ? 'Archived company notices' : 'Campus and partner updates' ?></div>
    </div>
    <input type="search" class="form-control announcement-toolbar-search" name="q" maxlength="150" placeholder="Search title, message, author..." aria-label="Search announcements" data-tour-announcement-search value="<?= e($search) ?>">
    <button type="button" class="table-tool-button announcement-toolbar-icon-button" data-tour-announcement-filter data-table-filter-toggle="companyAnnouncementFilters" aria-controls="companyAnnouncementFiltersDrawer" aria-expanded="false" aria-haspopup="dialog" title="Filter announcements" aria-label="Filter announcements">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.2l-3.6 1.8v-7z"/></svg>
    </button>
    <a class="btn btn-secondary announcement-toolbar-icon-button" data-tour-announcement-archive href="<?= e($archive_url) ?>" title="<?= $archive_view ? 'View active notices' : 'View archived notices' ?>" aria-label="<?= $archive_view ? 'View active notices' : 'View archived notices' ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="4" rx="1.2"/><path d="M5 8.5v11h14v-11M9 12h6"/></svg>
      <?php if (!$archive_view): ?><span class="announcement-archive-count"><?= $archived_count ?></span><?php endif; ?>
    </a>
    <?php if (!$archive_view): ?><button type="button" class="btn btn-primary" data-tour-announcement-compose onclick="openModal('composeModal')">+ Post Notice</button><?php endif; ?>
    <div class="table-filter-drawer announcement-filter-drawer" id="companyAnnouncementFiltersDrawer" data-filter-id="companyAnnouncementFilters" aria-hidden="true">
      <section class="table-filter-panel" role="dialog" aria-modal="false" aria-labelledby="companyAnnouncementFiltersTitle">
        <header class="table-filter-header">
          <div class="table-filter-heading-copy"><h2 id="companyAnnouncementFiltersTitle">Filters</h2><p class="table-filter-description">Choose a category to narrow the notices shown.</p></div>
          <button type="button" class="table-filter-close" aria-label="Close filters">×</button>
        </header>
        <div class="table-filter-content">
          <div class="table-filter-drawer-form">
            <div><label class="form-label" for="companyAnnouncementCategory">Category</label><select class="form-control" id="companyAnnouncementCategory" name="tag"><option value="">All categories</option><?php foreach ($all_tags as $t): ?><option value="<?= e($t['tag']) ?>" <?= $tag_filter === $t['tag'] ? 'selected' : '' ?>><?= e($t['tag']) ?></option><?php endforeach; ?></select></div>
          </div>
        </div>
        <footer class="table-filter-footer">
          <button type="button" class="table-filter-reset" onclick="const f=this.closest('form');f.querySelector('[name=q]').value='';f.querySelector('[name=tag]').selectedIndex=0;f.requestSubmit()">Reset</button>
          <button type="submit" form="companyAnnouncementFilters" class="table-filter-apply">Apply filters</button>
        </footer>
      </section>
    </div>
  </form>

  <script>
    (() => {
      if (document.documentElement.dataset.companyAnnouncementFilterBound === 'true') return;
      document.documentElement.dataset.companyAnnouncementFilterBound = 'true';
      const close = () => {
        const drawer = document.getElementById('companyAnnouncementFiltersDrawer');
        const trigger = document.querySelector('[data-table-filter-toggle="companyAnnouncementFilters"]');
        drawer?.classList.remove('is-open');
        drawer?.setAttribute('aria-hidden', 'true');
        trigger?.setAttribute('aria-expanded', 'false');
      };
      document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-table-filter-toggle="companyAnnouncementFilters"]');
        const drawer = document.getElementById('companyAnnouncementFiltersDrawer');
        if (trigger && drawer) {
          const panel = drawer.querySelector('.table-filter-panel');
          if (!panel) return;
          document.querySelectorAll('.table-filter-drawer.is-open').forEach(openDrawer => {
            openDrawer.classList.remove('is-open');
            openDrawer.setAttribute('aria-hidden', 'true');
          });
          const rect = trigger.getBoundingClientRect();
          drawer.classList.add('is-open');
          drawer.setAttribute('aria-hidden', 'false');
          trigger.setAttribute('aria-expanded', 'true');
          const left = Math.max(12, Math.min(rect.right - panel.offsetWidth, window.innerWidth - panel.offsetWidth - 12));
          let top = rect.bottom + 8;
          if (top + panel.offsetHeight > window.innerHeight - 12) top = Math.max(12, rect.top - panel.offsetHeight - 8);
          panel.style.left = `${left}px`;
          panel.style.top = `${top}px`;
          drawer.querySelector('.table-filter-close')?.focus({preventScroll: true});
          return;
        }
        if (event.target.closest('#companyAnnouncementFiltersDrawer .table-filter-close')) {
          close();
          return;
        }
        if (drawer?.classList.contains('is-open') && !drawer.contains(event.target)) close();
      });
      window.addEventListener('resize', close);
    })();
  </script>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<!-- Compose Notice Modal -->
<div class="modal-overlay" id="composeModal">
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Post a Company Notice</div>
        <p class="modal-sub">Send a notice to your trainees, the OJT Coordinator, or both</p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('composeModal')" aria-label="Close notice form">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
      <input type="hidden" name="action" value="post">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Safety orientation on Friday" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category / Tag</label>
          <select name="tag" class="form-control">
            <option value="General">General</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Reminder">Reminder</option>
            <option value="Schedule">Schedule</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Send To</label>
          <select name="target" class="form-control">
            <option value="both">Trainees &amp; OJT Coordinator</option>
            <option value="student">Trainees only</option>
            <option value="coordinator">OJT Coordinator only</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Expiration Date (Optional)</label>
        <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group flex-items-center">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1">
          <span>
            <strong>Pin this notice to top</strong>
            <small>Keep it visible above regular partner notices.</small>
          </span>
        </label>
      </div>
      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" class="form-control" rows="6" required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label" for="company-notice-attachment">Attachment (optional)</label>
        <div class="upload-area announcement-upload" role="button" tabindex="0" aria-label="Choose a notice attachment" aria-describedby="company-notice-attachment-help">
          <input id="company-notice-attachment" type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Choose or drop a file</div>
          <div class="upload-sub" id="company-notice-attachment-help">JPG, PNG, GIF, WEBP, PDF or Office file · Max 10 MB</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish Notice</button>
      </div>
    </form>
  </div>
</div>

<div class="announcement-company-feed">
    <?php if (empty($announcements)): ?>
      <div class="card card-body text-center py-12">
        <div class="empty-state">
          <div class="font-bold text-base mb-1">No announcements found</div>
          <p class="text-xs text-muted"><?= $archive_view ? 'Your archived notices will appear here.' : 'No matching notices.' ?></p>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($announcements as $a): ?>
      <?php $is_own_notice = (int)($a['created_by'] ?? 0) === $uid; ?>
      <?php $audience_labels = ['student' => 'Trainees', 'coordinator' => 'OJT Coordinator', 'all' => 'Trainees & Coordinator']; ?>
      <article class="card announcement-item <?= $a['is_pinned'] ? 'is-pinned' : '' ?><?= $archive_view ? ' is-archived' : '' ?>" data-tour-announcement-post>
          <div class="card-body">
            <?php $post_author = $a['author_name'] ?: 'OJT Office'; ?>
            <header class="announcement-social-head">
              <div class="announcement-author-avatar" aria-hidden="true"><?= e(user_avatar_initials($post_author)) ?></div>
              <div class="announcement-author-copy"><strong><?= e($post_author) ?></strong><span><?= ucfirst($a['author_role'] ?: 'Coordinator') ?> <span aria-hidden="true">·</span> <?= time_ago($a['created_at']) ?></span></div>
              <?php if ($is_own_notice): ?>
                <div class="announcement-social-actions" data-tour-announcement-actions>
                  <?php if (!$archive_view): ?>
                    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="announcement-icon-action <?= !empty($a['is_pinned']) ? 'is-active' : '' ?>" title="<?= !empty($a['is_pinned']) ? 'Unpin notice' : 'Pin notice' ?>" aria-label="<?= !empty($a['is_pinned']) ? 'Unpin notice' : 'Pin notice' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6-3 1-4 4-1 5-4-4 5-1 4-4 1-3Z"/><path d="m4 20 5-5"/></svg></button>
                    </form>
                    <form method="POST" data-confirm-title="Archive this notice?" data-confirm-label="Archive" data-confirm-message="Archive this notice?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>">
                      <button type="submit" class="announcement-icon-action" title="Archive notice" aria-label="Archive notice"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4.5" width="18" height="4" rx="1.2"/><path d="M5 8.5v11h14v-11M9 12h6"/></svg></button>
                    </form>
                  <?php else: ?>
                    <span class="announcement-label is-archived-label">Archived</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </header>
            <div class="announcement-item-head">
              <div class="flex-items-center gap-2">
                <?php if ($a['is_pinned']): ?>
                  <span class="badge badge-primary font-bold text-xs">PINNED</span>
                <?php endif; ?>
                <span class="badge badge-secondary"><?= e($a['tag'] ?: 'General') ?></span>
                <span class="announcement-label is-audience-label"><?= e($audience_labels[(string)($a['target_role'] ?? '')] ?? 'Company notice') ?></span>
              </div>
            </div>

            <h3 class="announcement-item-title"><?= e($a['title']) ?></h3>
            <div class="announcement-item-copy">
              <?= nl2br(e($a['body'])) ?>
            </div>

            <?php if (!empty($a['attachment_file'])): ?>
              <?php
                $att_ext = strtolower(pathinfo($a['attachment_file'], PATHINFO_EXTENSION));
                $is_img = in_array($att_ext, ['jpg','jpeg','png','gif','webp'], true);
              ?>
              <div class="announcement-item-attachment">
                <?php if ($is_img): ?>
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank">
                    <img src="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" alt="<?= e($a['attachment_name'] ?: 'Announcement image') ?>" class="announcement-item-image">
                  </a>
                <?php else: ?>
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" class="btn btn-secondary btn-sm announcement-item-file">
                    Download: <?= e($a['attachment_name'] ?: 'Attachment') ?>
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="announcement-item-footer">
              <span>Posted by <strong><?= e($a['author_name'] ?: 'OJT Office') ?></strong> (<?= ucfirst($a['author_role'] ?: 'Coordinator') ?>)</span>
              <span><?= time_ago($a['created_at']) ?></span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
