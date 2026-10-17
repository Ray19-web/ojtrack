<div class="card-header student-filter-panel">
    <form class="student-filters" method="GET">
      <div class="student-filter-search">
        <label class="form-label" for="studentSearch">Search students</label>
        <input type="search" id="studentSearch" name="q" class="form-control search-input" placeholder="Search student, ID, program..." value="<?= e($search) ?>">
      </div>

      <div class="student-filter-archive">
        <label class="form-label" for="studentArchive">Accounts</label>
        <select id="studentArchive" name="archive" class="form-control">
        <option value="active" <?= $archive_filter==='active'?'selected':'' ?>>Active (<?= $counts['active'] ?>)</option>
        <option value="archived" <?= $archive_filter==='archived'?'selected':'' ?>>Archived (<?= $counts['archived'] ?>)</option>
        <option value="all" <?= $archive_filter==='all'?'selected':'' ?>>All Students</option>
      </select>
      </div>

      <div class="student-filter-status">
        <label class="form-label" for="studentStatus">OJT status</label>
        <select id="studentStatus" name="status" class="form-control">
        <option value="">All Statuses</option>
        <option value="pending"     <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="not_started" <?= $status==='not_started'?'selected':'' ?>>Not Started</option>
        <option value="ongoing"     <?= $status==='ongoing'?'selected':'' ?>>Ongoing</option>
        <option value="completed"   <?= $status==='completed'?'selected':'' ?>>Completed</option>
        <option value="on_hold"     <?= $status==='on_hold'?'selected':'' ?>>On Hold</option>
        <option value="withdrawn"   <?= $status==='withdrawn'?'selected':'' ?>>Withdrawn</option>
      </select>
      </div>

      <div class="student-filter-program_id">
        <label class="form-label" for="studentProgram">Academic program</label>
        <select id="studentProgram" name="program_id" class="form-control">
        <option value="">All Academic Programs</option>
        <?php foreach ($all_programs_filter as $pf): ?>
          <option value="<?= (int)$pf['id'] ?>" <?= (string)$program_filter === (string)$pf['id'] ? 'selected' : '' ?>>
            <?= e($pf['code']) ?> - <?= e($pf['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      </div>

      <div class="student-filter-actions">
        <button type="submit" class="btn btn-primary">Apply Filters</button>
      <?php if ($search !== '' || $status !== '' || $program_filter !== '' || $archive_filter !== 'active'): ?>
        <a href="/ojtrack/admin/students.php" class="btn btn-secondary">Clear</a>
      <?php endif; ?>
      </div>
    </form>
    <div class="student-filter-results text-sm text-muted" role="status"><?= count($students) ?> student(s) found</div>
  </div>
