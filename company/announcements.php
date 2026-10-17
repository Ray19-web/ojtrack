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

$tag_filter = trim($_GET['tag'] ?? '');
$search     = trim($_GET['q'] ?? '');
$announcements = normalized_announcements_for_user($uid, false, $tag_filter, $search);
$all_tags = normalized_announcement_tags_for_user($uid);

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Announcements</div>
    <div class="page-sub">Campus and partner updates</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('composeModal')">+ Post Notice</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<details class="card mb-4 announcement-authored"<?= $my_notices ? ' open' : '' ?>>
  <summary><span>Your notices <span class="badge badge-subtle"><?= count($my_notices) ?></span></span><span class="announcement-disclosure" aria-hidden="true">⌄</span></summary>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead><tr><th>Title</th><th>Audience</th><th>Tag</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($my_notices)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">You haven't posted any notices yet.</td></tr>
        <?php else: ?>
          <?php foreach ($my_notices as $n): ?>
          <tr>
            <td>
              <strong class="text-sm"><?= e($n['title']) ?></strong>
              <?php if (!empty($n['attachment_file'])): ?>
                <div><a class="text-xs" href="/ojtrack/uploads/<?= e($n['attachment_file']) ?>" target="_blank" rel="noopener">Attachment: <?= e($n['attachment_name'] ?: 'View file') ?></a></div>
              <?php endif; ?>
            </td>
            <td><?= $n['target_role'] === 'student' ? 'Trainees' : ($n['target_role'] === 'coordinator' ? 'Coordinators' : ucfirst($n['target_role'])) ?></td>
            <td><span class="text-xs font-bold text-600"><?= e($n['tag'] ?: 'General') ?></span></td>
            <td class="td-mono text-xs"><?= format_date($n['created_at']) ?></td>
            <td>
              <div class="table-actions">
                <form method="POST"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_pin">
                  <input type="hidden" name="ann_id" value="<?= $n['id'] ?>">
                  <button type="submit" class="table-action-icon <?= $n['is_pinned'] ? 'is-primary' : '' ?>"
                          title="<?= $n['is_pinned'] ? 'Unpin notice' : 'Pin notice to top' ?>"
                          aria-label="<?= $n['is_pinned'] ? 'Unpin notice' : 'Pin notice to top' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6-3 1-4 4-1 5-4-4 5-1 4-4 1-3Z"/><path d="m4 20 5-5"/></svg>
                  </button>
                </form>
                <form method="POST" data-confirm-title="Archive this notice?" data-confirm-label="Archive" data-confirm-message="Archive this notice?"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="ann_id" value="<?= $n['id'] ?>">
                  <button type="submit" class="table-action-icon is-danger" title="Archive notice" aria-label="Archive notice">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6"/></svg>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</details>

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

<div class="announcements-layout">
  <?php require __DIR__ . '/../includes/announcement-filters.php'; ?>
  <div class="space-y-4 announcement-feed">
    <?php if (empty($announcements)): ?>
      <div class="card card-body text-center py-12">
        <div class="empty-state">
          <div class="font-bold text-base mb-1">No announcements found</div>
          <p class="text-xs text-muted">No matching notices.</p>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($announcements as $a): ?>
        <article class="card announcement-item <?= $a['is_pinned'] ? 'is-pinned' : '' ?>">
          <div class="card-body">
            <div class="announcement-item-head">
              <div class="flex-items-center gap-2">
                <?php if ($a['is_pinned']): ?>
                  <span class="badge badge-primary font-bold text-xs">PINNED</span>
                <?php endif; ?>
                <span class="badge badge-secondary"><?= e($a['tag'] ?: 'General') ?></span>
                <span class="text-xs text-muted"><?= format_date($a['created_at']) ?></span>
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
