<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$student = query_one(
    "SELECT s.*, c.user_id AS coordinator_user_id
     FROM students s
     LEFT JOIN coordinators c ON c.id=s.coordinator_id
     WHERE s.user_id=?",
    [$user['id']],
    'i'
);
$coord_uid = (int)($student['coordinator_user_id'] ?? 0);

$tag_filter = trim($_GET['tag'] ?? '');
$search     = trim($_GET['q'] ?? '');

// Campus/admin notices + notices from THIS student's coordinator only
$where = announcement_scope($user);
$params = []; $types = '';

if ($tag_filter) {
    $where .= " AND a.tag=?";
    $params[] = $tag_filter;
    $types .= 's';
}
if ($search) {
    $where .= " AND (a.title LIKE ? OR a.body LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

$announcements = query(
    "SELECT a.*, u.name AS author_name, u.role AS author_role
     FROM announcements a
     LEFT JOIN users u ON u.id=a.created_by
     WHERE $where
     ORDER BY a.is_pinned DESC, a.created_at DESC",
    $params,
    $types
) ?: [];

$all_tags = query("SELECT DISTINCT a.tag FROM announcements a WHERE " . announcement_scope($user) . " AND a.tag IS NOT NULL AND a.tag!=''") ?: [];

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
  <div class="space-y-4">
    <!-- Search Bar -->
    <div class="card card-body py-3">
      <form method="GET" class="flex-items-center gap-3">
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
            <div class="flex-between mb-2">
              <div class="flex-items-center gap-2">
                <?php if ($a['is_pinned']): ?>
                  <span class="badge badge-primary font-bold text-xs">PINNED</span>
                <?php endif; ?>
                <span class="badge badge-secondary"><?= e($a['tag'] ?: 'General') ?></span>
                <span class="text-xs text-muted"><?= format_date($a['created_at']) ?></span>
              </div>
            </div>

            <h3 class="font-bold text-base text-800 mb-2"><?= e($a['title']) ?></h3>
            <div class="text-sm text-600 leading-relaxed mb-3">
              <?= nl2br(e($a['body'])) ?>
            </div>

            <?php if (!empty($a['attachment_file'])): ?>
              <?php
                $att_ext = strtolower(pathinfo($a['attachment_file'], PATHINFO_EXTENSION));
                $is_img = in_array($att_ext, ['jpg','jpeg','png','gif','webp'], true);
              ?>
              <div class="mb-3">
                <?php if ($is_img): ?>
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank">
                    <img src="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" alt="<?= e($a['attachment_name'] ?: 'Announcement image') ?>" style="max-width:100%;max-height:280px;border-radius:8px;border:1px solid var(--border)">
                  </a>
                <?php else: ?>
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                    Download: <?= e($a['attachment_name'] ?: 'Attachment') ?>
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="pt-3 border-t flex-between text-xs text-muted">
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
