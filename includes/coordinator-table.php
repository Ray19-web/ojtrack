<?php
// Use program codes consistently for both current and legacy department names.
$program_label_by_code = [];
foreach ($programs_list as $pr) {
    $program_label_by_code[strtoupper($pr['code'])] = $pr['code'];
    $program_label_by_code[strtoupper($pr['name'])] = $pr['code'];
}
?>
<div class="card mb-5">
  <div class="card-header coordinator-filter-panel">
    <form method="GET" class="activity-filters">
      <div class="activity-filter-search">
        <label class="form-label" for="coordSearch">Search coordinators</label>
        <input type="search" id="coordSearch" name="q" class="form-control" maxlength="150" placeholder="Search name, ID, email..." value="<?= e($coord_filters['search']) ?>">
      </div>
      <div>
        <label class="form-label" for="coordProgram">Program</label>
        <select id="coordProgram" name="program" class="form-control">
          <option value="">All Programs</option>
          <?php foreach ($filter_programs as $pr): ?>
          <option value="<?= e($pr['code']) ?>" <?= $coord_filters['program'] === $pr['code'] ? 'selected' : '' ?>><?= e($pr['code']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label" for="coordStatus">Status</label>
        <select id="coordStatus" name="status" class="form-control">
          <option value="">All Statuses</option>
          <option value="active" <?= $coord_filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $coord_filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="activity-filter-actions">
        <button type="submit" class="btn btn-primary">Apply Filters</button>
        <?php if ($coord_filters['search'] !== '' || $coord_filters['program'] !== '' || $coord_filters['status'] !== ''): ?>
        <a class="btn btn-secondary" href="/ojtrack/admin/coordinators.php">Clear</a>
        <?php endif; ?>
      </div>
    </form>
    <div class="text-sm text-muted coordinator-filter-count" role="status"><?= count($coordinators) ?> coordinator(s) found</div>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Coordinators">
    <table>
      <thead><tr><th>Name</th><th>Coordinator ID</th><th>Program</th><th>Phone</th><th>Assigned Trainees</th><th>Active in OJT</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($coordinators as $c):
            $dept_raw = trim((string)($c['department'] ?? ''));
            $dept_label = $program_label_by_code[strtoupper($dept_raw)] ?? ($dept_raw ?: '—');
            $edit_payload = htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
        <tr class="row-clickable" onclick='openEditCoord(<?= $edit_payload ?>)' title="View &amp; edit coordinator">
          <td><div class="table-person">
            <div aria-hidden="true" style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= user_avatar_content($c['avatar'] ?? null, $c['name']) ?></div>
            <div><div class="font-bold text-sm"><?= e($c['name']) ?></div><div class="text-xs text-muted"><?= e($c['email'] ?: 'Not provided') ?></div></div>
          </div></td>
          <td class="td-mono"><?= e($c['coordinator_id_no'] ?: '—') ?></td>
          <td><span class="badge"><?= e($dept_label) ?></span></td>
          <td><?= e($c['contact_number'] ?: 'Not provided') ?></td>
          <td><?= (int)$c['student_count'] ?></td>
          <td><?= (int)$c['active_students'] ?></td>
          <td><?= status_badge($c['status']) ?></td>
          <td onclick="stopRowClick(event)"><div class="table-actions">
            <button type="button" class="table-action-icon is-primary" title="Edit Details" aria-label="Edit <?= e($c['name']) ?>" onclick='openEditCoord(<?= $edit_payload ?>)'>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($coordinators)): ?><tr><td colspan="8"><div class="empty-state"><p>No coordinators found.</p></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
