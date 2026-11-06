<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$uid = (int)$user['id'];
$student = normalized_student_context_by_user($uid);

$tag_filter = is_string($_GET['tag'] ?? '') ? trim($_GET['tag'] ?? '') : '';
$search = is_string($_GET['q'] ?? '') ? trim($_GET['q'] ?? '') : '';

$announcements = normalized_announcements_for_user($uid, false, $tag_filter, $search);
$all_tags = normalized_announcement_tags_for_user($uid);

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="student-announcement-page">
  <section class="card announcement-management-toolbar-card" aria-label="Announcement search and filters">
    <form method="GET" class="announcement-toolbar student-announcement-toolbar" id="studentAnnouncementFilters" data-live-filter>
      <div class="announcement-toolbar-heading">
        <h1 class="page-title">Announcements</h1>
        <div class="page-sub">Coordinator and campus updates</div>
      </div>
      <input type="search" class="form-control announcement-toolbar-search" name="q" maxlength="150" placeholder="Search title, message, author..." aria-label="Search announcements" data-tour-announcement-search value="<?= e($search) ?>">
      <details class="student-announcement-filter" data-reader-announcement-filter>
        <summary class="table-tool-button announcement-toolbar-icon-button <?= $tag_filter !== '' ? 'has-filter' : '' ?>" aria-label="Filter announcements" title="Filter announcements" data-tour-announcement-filter>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.2l-3.6 1.8v-7z"/></svg>
          <?php if ($tag_filter !== ''): ?><span class="student-announcement-filter-dot" aria-hidden="true"></span><?php endif; ?>
        </summary>
        <section class="table-filter-panel student-announcement-filter-panel" aria-label="Announcement filters">
          <header class="table-filter-header">
            <div class="table-filter-heading-copy"><h2>Filters</h2><p class="table-filter-description">Refine the announcements shown in this list.</p></div>
            <button type="button" class="table-filter-close" data-reader-filter-close aria-label="Close filters">&times;</button>
          </header>
          <div class="table-filter-content">
            <label class="form-label" for="studentAnnouncementCategory">Category</label>
            <select class="form-control" id="studentAnnouncementCategory" name="tag">
              <option value="">All categories</option>
              <?php foreach ($all_tags as $t): ?><option value="<?= e($t['tag']) ?>" <?= $tag_filter === $t['tag'] ? 'selected' : '' ?>><?= e($t['tag']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <footer class="table-filter-footer">
            <button type="button" class="table-filter-reset" data-reader-filter-reset>Reset</button>
            <button type="submit" class="table-filter-apply">Apply filters</button>
          </footer>
        </section>
      </details>
      <button type="submit" class="student-announcement-search-submit">Search announcements</button>
    </form>
  </section>
  <div class="announcement-feed">
    <?php if (empty($announcements)): ?>
      <div class="card card-body text-center py-12">
        <div class="empty-state">
          <div class="font-bold text-base mb-1">No announcements found</div>
          <p class="text-xs text-muted">No matching notices.</p>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($announcements as $a): ?>
        <article class="card announcement-item <?= $a['is_pinned'] ? 'is-pinned' : '' ?>" data-tour-announcement-post>
          <div class="card-body">
            <?php $post_author = $a['author_name'] ?: 'OJT Office';
              $audience_name = ($a['target_role'] ?? 'all') === 'student' ? 'Students' : 'Everyone'; ?>
            <header class="announcement-social-head">
              <div class="announcement-author-avatar" aria-hidden="true"><?= e(user_avatar_initials($post_author)) ?></div>
              <div class="announcement-author-copy"><strong><?= e($post_author) ?></strong><span><?= e(date('M d, Y', strtotime($a['created_at']))) ?> <span aria-hidden="true">·</span> <?= e($audience_name) ?></span></div>
            </header>
            <div class="announcement-label-row">
                <?php if ($a['is_pinned']): ?>
                  <span class="announcement-label is-pinned-label">Pinned</span>
                <?php endif; ?>
                <span class="announcement-label"><?= e($a['tag'] ?: 'General') ?></span>
                <span class="announcement-label is-audience-label"><?= e($audience_name) ?></span>
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
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" rel="noopener" class="announcement-image-link">
                    <img src="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" alt="<?= e($a['attachment_name'] ?: 'Announcement image') ?>" class="announcement-item-image" loading="lazy">
                  </a>
                <?php else: ?>
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" rel="noopener" class="announcement-file-card"><span class="announcement-file-icon" aria-hidden="true">&#8615;</span><span><strong><?= e($a['attachment_name'] ?: 'Download attachment') ?></strong><small><?= e(strtoupper($att_ext)) ?> file</small></span></a>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<style>
.student-announcement-page{display:grid;gap:18px;max-width:1300px;margin:0 auto;min-width:0}
.student-announcement-page>.announcement-management-toolbar-card{width:100%;margin:0;overflow:visible}
.student-announcement-toolbar{grid-template-columns:minmax(220px,auto) minmax(220px,1fr) 46px}
.student-announcement-toolbar .page-title{margin:0}
.student-announcement-page .announcement-feed{width:100%;max-width:1150px}
.student-announcement-filter>summary{position:relative;list-style:none;display:flex;align-items:center;justify-content:center;cursor:pointer}
.student-announcement-filter>summary::-webkit-details-marker{display:none}
.student-announcement-filter[open]>summary,.student-announcement-filter .has-filter{background:#f1f6fd;border-color:#c6d5e8;color:var(--primary)}
.student-announcement-filter-dot{position:absolute;top:7px;right:7px;width:6px;height:6px;border-radius:50%;background:var(--primary)}
.student-announcement-filter-panel{position:fixed;z-index:101;width:min(360px,calc(100vw - 24px));max-height:calc(100dvh - 24px);overflow-y:auto;border:1px solid var(--border);border-radius:14px;background:var(--card);box-shadow:0 16px 40px #10234426}
.student-announcement-filter[open] .student-announcement-filter-panel{opacity:1;pointer-events:auto;transform:none}
.student-announcement-filter-panel .form-control{width:100%}
.student-announcement-search-submit{position:absolute;width:1px;height:1px;clip-path:inset(50%);overflow:hidden;white-space:nowrap}
.student-announcement-search-submit:focus-visible{position:static;width:auto;height:auto;clip-path:none;grid-column:1/-1;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--primary)}
@media(max-width:1050px){.student-announcement-toolbar{grid-template-columns:minmax(0,1fr) 46px}.student-announcement-toolbar .announcement-toolbar-heading{grid-column:1/-1}.student-announcement-toolbar .announcement-toolbar-search{grid-column:auto}.student-announcement-toolbar .page-sub{white-space:normal}}
</style>
<script>
(() => {
  function closeFilters(restoreFocus = false) {
    document.querySelectorAll('[data-reader-announcement-filter][open]').forEach(details => {
      details.open = false;
      if (restoreFocus) details.querySelector('summary').focus({preventScroll:true});
    });
  }
  document.addEventListener('toggle', event => {
    const details = event.target;
    if (!details.matches?.('[data-reader-announcement-filter]') || !details.open) return;
    const panel = details.querySelector('.student-announcement-filter-panel');
    const rect = details.querySelector('summary').getBoundingClientRect();
    panel.style.left = Math.max(12, Math.min(rect.right-panel.offsetWidth, innerWidth-panel.offsetWidth-12))+'px';
    panel.style.top = Math.max(12, Math.min(rect.bottom+8, innerHeight-panel.offsetHeight-12))+'px';
  }, true);
  document.addEventListener('click', event => {
    if (event.target.closest('[data-reader-filter-close]')) { closeFilters(true); return; }
    const reset = event.target.closest('[data-reader-filter-reset]');
    if (reset) {
      const form = reset.closest('form');
      form.querySelector('[name="q"]').value = '';
      form.querySelector('[name="tag"]').value = '';
      closeFilters(); form.requestSubmit(); return;
    }
    if (!event.target.closest('[data-reader-announcement-filter]')) closeFilters();
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && document.querySelector('[data-reader-announcement-filter][open]')) { event.preventDefault(); closeFilters(true); } });
  window.addEventListener('resize', () => closeFilters());
  document.addEventListener('scroll', event => { if (!event.target.closest?.('.student-announcement-filter-panel')) closeFilters(); }, true);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
