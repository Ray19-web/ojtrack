<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$uid = (int)$user['id'];
$student = normalized_student_context_by_user($uid);

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
    <div class="page-sub">Official notices and updates from your OJT Coordinator and Administration</div>
  </div>
</div>

<div class="grid grid-sidebar-main gap-4">
  <!-- Filters Sidebar -->
  <div>
    <div class="card card-body mb-4">
      <div class="section-title mb-3">Filter by Category</div>
      <div class="space-y-1">
        <a href="/ojtrack/student/announcements.php<?= $search ? '?q=' . urlencode($search) : '' ?>"
           class="filter-tag-link <?= empty($tag_filter) ? 'active' : '' ?>">
          <span>All Announcements</span>
          <span class="badge badge-subtle"><?= count($announcements) ?></span>
        </a>
        <?php foreach ($all_tags as $t): ?>
          <a href="/ojtrack/student/announcements.php?tag=<?= urlencode($t['tag']) ?><?= $search ? '&q=' . urlencode($search) : '' ?>"
             class="filter-tag-link <?= $tag_filter === $t['tag'] ? 'active' : '' ?>">
            <span><?= e($t['tag']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card card-body">
      <div class="section-title mb-2">Notice Guidelines</div>
      <p class="text-xs text-muted leading-relaxed">
        Important announcements will be marked as <strong class="text-primary">PINNED</strong>. Check regularly for updates regarding submission deadlines, office hours, and clearance requirements.
      </p>
    </div>
  </div>

  <!-- Main Announcements List -->
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
          <a href="/ojtrack/student/announcements.php" class="btn btn-ghost">Reset</a>
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
