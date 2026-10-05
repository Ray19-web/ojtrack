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
                $postId = normalized_announcement_create(
                    $uid,$title,$body,$tag,$normalizedTarget,$pinned,$expires
                );
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
    <div class="page-title">Announcements &amp; Advisories</div>
    <div class="page-sub">University announcements and guidelines for company supervisors and industry partners</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('composeModal')">+ Post Notice</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card mb-4">
  <div class="card-header">
    <div class="card-title">Notices you posted</div>
    <span class="text-sm text-muted"><?= count($my_notices) ?> items</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Audience</th><th>Tag</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($my_notices)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">You haven't posted any notices yet.</td></tr>
        <?php else: ?>
          <?php foreach ($my_notices as $n): ?>
          <tr>
            <td><strong class="text-sm"><?= e($n['title']) ?></strong></td>
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
                <form method="POST" onsubmit="return confirm('Delete this notice?')"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="ann_id" value="<?= $n['id'] ?>">
                  <button type="submit" class="table-action-icon is-danger" title="Delete notice" aria-label="Delete notice">
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
</div>

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
    <form method="POST" class="announcement-form"><?= csrf_field() ?>
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
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish Notice</button>
      </div>
    </form>
  </div>
</div>

<div class="grid grid-sidebar-main gap-4">
  <!-- Filter sidebar -->
  <div>
    <div class="card card-body mb-4">
      <div class="section-title mb-3">Filter by Category</div>
      <div class="space-y-1">
        <a href="/ojtrack/company/announcements.php<?= $search ? '?q=' . urlencode($search) : '' ?>"
           class="filter-tag-link <?= empty($tag_filter) ? 'active' : '' ?>">
          <span>All Announcements</span>
          <span class="badge badge-subtle"><?= count($announcements) ?></span>
        </a>
        <?php foreach ($all_tags as $t): ?>
          <a href="/ojtrack/company/announcements.php?tag=<?= urlencode($t['tag']) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
             class="filter-tag-link <?= $tag_filter === $t['tag'] ? 'active' : '' ?>">
            <span><?= e($t['tag']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card card-body">
      <div class="section-title mb-2">Partner Guidelines</div>
      <p class="text-xs text-muted leading-relaxed">
        Stay updated with university OJT schedules, midterm/final evaluation deadlines, and policy reminders regarding intern supervision.
      </p>
    </div>
  </div>

  <!-- Main list -->
  <div class="space-y-4 announcement-feed">
    <!-- Search Bar -->
    <div class="card card-body py-3">
      <form method="GET" class="flex-items-center gap-3 announcement-search-form">
        <?php if ($tag_filter): ?><input type="hidden" name="tag" value="<?= e($tag_filter) ?>"><?php endif; ?>
        <div class="search-wrap flex-1">
          <input type="text" name="q" class="form-control search-input" placeholder="Search notices by keyword..." value="<?= e($search) ?>">
        </div>
        <button type="submit" class="btn btn-secondary">Search</button>
        <?php if ($search || $tag_filter): ?>
          <a href="/ojtrack/company/announcements.php" class="btn btn-ghost">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($announcements)): ?>
      <div class="card card-body text-center py-12">
        <div class="empty-state">
          <div class="font-bold text-base mb-1">No announcements found</div>
          <p class="text-xs text-muted">There are no notices matching your current search or filter criteria.</p>
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
