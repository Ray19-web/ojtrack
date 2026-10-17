<div class="card card-body activity-filter-panel mb-4">
  <form class="activity-filters" method="GET" action="/ojtrack/admin/activity.php">
    <div class="activity-filter-search">
      <label class="form-label" for="activitySearch">Search</label>
      <input type="search" id="activitySearch" name="q" class="form-control" placeholder="Action, details, or user" value="<?= e($search) ?>">
    </div>
    <div>
      <label class="form-label" for="activityRole">Role</label>
      <select id="activityRole" name="role" class="form-control">
        <option value="">All Roles</option>
        <?php foreach (['student'=>'Student', 'coordinator'=>'Coordinator', 'company'=>'Company', 'admin'=>'Admin', 'system'=>'System'] as $filter_value=>$filter_label): ?>
        <option value="<?= e($filter_value) ?>" <?= $role_filter === $filter_value ? 'selected' : '' ?>><?= e($filter_label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label" for="activityDate">Date</label>
      <input type="date" id="activityDate" name="date" value="<?= e($date) ?>" class="form-control">
    </div>
    <div class="activity-filter-actions">
      <button type="submit" class="btn btn-primary">Apply</button>
      <?php if ($search !== '' || $role_filter !== '' || $date !== ''): ?>
      <a href="/ojtrack/admin/activity.php" class="btn btn-secondary">Clear</a>
      <?php endif; ?>
    </div>
  </form>
</div>
