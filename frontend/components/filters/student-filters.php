<div class="card-header student-filter-panel">
  <form class="student-filters" method="GET" data-live-filter data-archive-target="/ojtrack/admin/archived-users.php?role=student">
    <div class="student-filter-search">
      <label class="form-label" for="studentSearch">Search students</label>
      <input type="search" id="studentSearch" name="q" class="form-control search-input" placeholder="Search name, student ID, or email..." value="<?= e($search) ?>">
    </div>
    <div class="student-filter-archive">
      <label class="form-label" for="studentArchive">Accounts</label>
      <select id="studentArchive" name="archive" class="form-control">
        <option value="active" <?= $archive_filter==='active'?'selected':'' ?>>Active (<?= $counts['active'] ?>)</option>
        <option value="archived" <?= $archive_filter==='archived'?'selected':'' ?>>Archived (<?= $counts['archived'] ?>)</option>
        <option value="all" <?= $archive_filter==='all'?'selected':'' ?>>All Students</option>
      </select>
    </div>
    <?php if ($search !== '' || $archive_filter !== 'active'): ?>
      <a href="/ojtrack/admin/students.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </form>
</div>
