<?php // $record_filter_ui is supplied by the page; all submitted values are escaped. ?>
<div class="card-header record-filter-panel">
  <form method="GET" class="activity-filters">
    <div class="activity-filter-search">
      <label class="form-label" for="recordSearch"><?= e($record_filter_ui['label']) ?></label>
      <input type="search" class="form-control" id="recordSearch" name="q" maxlength="150" placeholder="<?= e($record_filter_ui['placeholder']) ?>" value="<?= e($record_filter_ui['search']) ?>">
    </div>
    <?php foreach ($record_filter_ui['fields'] as $field): ?>
    <div>
      <label class="form-label" for="record-<?= e($field['name']) ?>"><?= e($field['label']) ?></label>
      <?php if (isset($field['options'])): ?>
      <select class="form-control" id="record-<?= e($field['name']) ?>" name="<?= e($field['name']) ?>">
        <?php foreach ($field['options'] as $value=>$label): ?>
        <option value="<?= e($value) ?>" <?= (string)$value===(string)$field['value'] ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="date" class="form-control" id="record-<?= e($field['name']) ?>" name="<?= e($field['name']) ?>" value="<?= e($field['value']) ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="activity-filter-actions">
      <button type="submit" class="btn btn-primary">Apply Filters</button>
      <?php if ($record_filter_ui['active']): ?><a class="btn btn-secondary" href="<?= e($record_filter_ui['clear']) ?>">Clear</a><?php endif; ?>
    </div>
  </form>
  <div class="text-sm text-muted coordinator-filter-count" role="status"><?= e($record_filter_ui['count']) ?></div>
  <?php if (!empty($record_filter_ui['error'])): ?><p class="text-danger text-sm" role="alert"><?= e($record_filter_ui['error']) ?></p><?php endif; ?>
</div>
