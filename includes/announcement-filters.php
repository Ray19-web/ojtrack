<?php
// Shared read-only feed controls. The page has already loaded role-authorized notices.
$announcement_path = '/ojtrack/' . $role . '/announcements.php';
?>
<section class="card announcement-filters" aria-label="Announcement filters">
  <form method="GET" class="announcement-filter-search">
    <?php if ($tag_filter !== ''): ?><input type="hidden" name="tag" value="<?= e($tag_filter) ?>"><?php endif; ?>
    <label class="announcement-search-label" for="announcement-search">Search announcements</label>
    <input id="announcement-search" type="search" name="q" class="form-control" placeholder="Search announcements…" value="<?= e($search) ?>">
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($search !== '' || $tag_filter !== ''): ?><a href="<?= e($announcement_path) ?>" class="btn btn-secondary">Clear</a><?php endif; ?>
  </form>
  <div class="announcement-filter-bottom">
    <nav class="announcement-categories" aria-label="Announcement categories">
      <a href="<?= e($announcement_path . ($search !== '' ? '?q=' . urlencode($search) : '')) ?>" class="announcement-category <?= $tag_filter === '' ? 'is-active' : '' ?>"<?= $tag_filter === '' ? ' aria-current="page"' : '' ?>>All</a>
      <?php foreach ($all_tags as $t): ?>
        <a href="<?= e($announcement_path . '?tag=' . urlencode($t['tag']) . ($search !== '' ? '&q=' . urlencode($search) : '')) ?>" class="announcement-category <?= $tag_filter === $t['tag'] ? 'is-active' : '' ?>"<?= $tag_filter === $t['tag'] ? ' aria-current="page"' : '' ?>><?= e($t['tag']) ?></a>
      <?php endforeach; ?>
    </nav>
    <span class="announcement-result-count" role="status"><?= count($announcements) ?> <?= count($announcements) === 1 ? 'notice' : 'notices' ?></span>
  </div>
</section>
