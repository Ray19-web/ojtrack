<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_program') {
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        $name   = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'])) $status = 'active';

        if (empty($code) || empty($name)) {
            $error = 'Both Program Code and Full Program Name are required.';
        } else {
            // Check for duplicate program code
            $existing = query_one("SELECT id FROM programs WHERE UPPER(code)=?", [$code], 's');
            if ($existing) {
                $error = "Program code '{$code}' already exists. Please use a unique code.";
            } else {
                insert("INSERT INTO programs (code, name, status) VALUES (?, ?, ?)",
                    [$code, $name, $status], 'sss');
                log_activity($user['id'], 'Program Created', "Added program {$code} - {$name}");
                $success = "Program '{$code} - {$name}' created successfully.";
            }
        }
    } elseif ($action === 'edit_program') {
        $pid    = (int)($_POST['program_id'] ?? 0);
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        $name   = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive'])) $status = 'active';

        if (empty($code) || empty($name)) {
            $error = 'Both Program Code and Full Program Name are required.';
        } else {
            // Check for duplicate code excluding current
            $existing = query_one("SELECT id FROM programs WHERE UPPER(code)=? AND id!=?", [$code, $pid], 'si');
            if ($existing) {
                $error = "Program code '{$code}' is already used by another program.";
            } else {
                query("UPDATE programs SET code=?, name=?, status=? WHERE id=?",
                    [$code, $name, $status, $pid], 'sssi');

                // Keep legacy string in students table synced with new code
                query("UPDATE students SET program=? WHERE program_id=?", [$code, $pid], 'si');

                log_activity($user['id'], 'Program Updated', "Updated program ID {$pid} to {$code} - {$name}");
                $success = "Program '{$code}' updated successfully.";
            }
        }
    } elseif ($action === 'toggle_status') {
        $pid = (int)($_POST['program_id'] ?? 0);
        $current = query_one("SELECT status, code FROM programs WHERE id=?", [$pid], 'i');
        if ($current) {
            $new_st = ($current['status'] === 'active') ? 'inactive' : 'active';
            query("UPDATE programs SET status=? WHERE id=?", [$new_st, $pid], 'si');
            log_activity($user['id'], 'Program Status Changed', "Set program {$current['code']} to {$new_st}");
            $success = "Program '{$current['code']}' status set to {$new_st}.";
        }
    } elseif ($action === 'bulk_set_status') {
        $target_status = $_POST['target_status'] ?? '';
        $raw_program_ids = is_array($_POST['program_ids'] ?? null) ? $_POST['program_ids'] : [];
        $program_ids = array_values(array_unique(array_filter(array_map(
            static fn($id): int => is_scalar($id) && filter_var((string)$id, FILTER_VALIDATE_INT) !== false ? (int)$id : 0,
            $raw_program_ids
        ), static fn(int $id): bool => $id > 0)));

        if (!in_array($target_status, ['active', 'inactive'], true)) {
            $error = 'Choose whether to activate or deactivate the selected programs.';
        } elseif (!$program_ids) {
            $error = 'Select at least one program first.';
        } else {
            $marks = implode(',', array_fill(0, count($program_ids), '?'));
            $selected_programs = query(
                "SELECT id, code, status FROM programs WHERE id IN ($marks)",
                $program_ids,
                str_repeat('i', count($program_ids))
            ) ?: [];
            if (!$selected_programs) {
                $error = 'The selected programs could not be found.';
            } else {
                $changed_programs = array_values(array_filter($selected_programs, static fn(array $program): bool => $program['status'] !== $target_status));
                if ($changed_programs) {
                    $changed_ids = array_map(static fn(array $program): int => (int)$program['id'], $changed_programs);
                    $changed_marks = implode(',', array_fill(0, count($changed_ids), '?'));
                    query(
                        "UPDATE programs SET status=? WHERE id IN ($changed_marks)",
                        array_merge([$target_status], $changed_ids),
                        's' . str_repeat('i', count($changed_ids))
                    );
                    $changed_codes = implode(', ', array_map(static fn(array $program): string => $program['code'], $changed_programs));
                    log_activity($user['id'], 'Program Status Changed', "Set " . count($changed_programs) . " selected programs to {$target_status}: {$changed_codes}");
                }
                $success = count($selected_programs) . ' selected program' . (count($selected_programs) === 1 ? '' : 's') . ' set to ' . $target_status . '.';
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? 'active';

$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (p.code LIKE ? OR p.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if ($status_filter !== '') {
    $where .= " AND p.status=?";
    $params[] = $status_filter;
    $types .= 's';
}

$programs = query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM students s JOIN users u ON u.id=s.user_id
             WHERE (s.program_id = p.id OR s.program = p.code)
               AND s.is_archived = 0 AND u.status != 'archived') AS student_count,
            (SELECT COUNT(*) FROM ojt_enrollments oe JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
             JOIN students s ON s.id=oe.student_id JOIN users u ON u.id=s.user_id
             WHERE oe.program_id=p.id AND oe.status='ongoing'
               AND s.is_archived=0 AND u.status!='archived') AS active_ojt_count
     FROM programs p
     WHERE $where
     ORDER BY p.code ASC",
    $params,
    $types
);

$page_title = 'Programs';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Programs</div>
    <div class="page-sub">Program codes and names</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addProgramModal')">+ Add New Program</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form class="filter-toolbar" method="GET" data-live-filter style="display:flex;gap:8px;flex-wrap:wrap">
      <div class="search-wrap" style="width:280px">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
        </svg>
          <input type="text" name="q" class="form-control search-input" placeholder="Search by code or title..." value="<?= e($search) ?>">
      </div>
      <select name="status" class="form-control" style="width:140px">
        <option value="">All Statuses</option>
        <option value="active"   <?= $status_filter==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $status_filter==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
      <button type="submit" class="btn btn-secondary">Filter</button>
      <?php if ($search !== '' || $status_filter !== ''): ?>
        <a href="/ojtrack/admin/programs.php" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <form id="bulkProgramForm" method="POST" hidden data-confirm-message="Apply this status to all selected programs?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_set_status">
  </form>
  <div class="program-bulk-toolbar" data-program-bulk-toolbar hidden>
    <div class="program-bulk-selection"><strong><span data-program-selected-count>0</span> selected</strong><span>Choose an action for these programs.</span></div>
    <div class="program-bulk-actions">
      <button type="submit" form="bulkProgramForm" name="target_status" value="active" class="btn btn-secondary btn-sm" data-program-bulk-status="active">Activate selected</button>
      <button type="submit" form="bulkProgramForm" name="target_status" value="inactive" class="btn btn-danger btn-sm" data-program-bulk-status="inactive">Deactivate selected</button>
      <button type="button" class="program-selection-clear" data-program-clear-selection>Clear</button>
    </div>
  </div>

  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table data-program-multi-select>
      <thead>
        <tr>
          <th class="program-select-cell"><input type="checkbox" class="program-select-checkbox" data-program-select-all aria-label="Select all programs on this page"></th>
          <th>Program Code</th>
          <th>Full Program Name</th>
          <th>Enrolled Students</th>
          <th>Active OJT</th>
          <th>Status</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($programs as $p): ?>
        <tr class="row-clickable" onclick='openEditProgram(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
          <td class="program-select-cell" onclick="event.stopPropagation()"><input type="checkbox" class="program-select-checkbox" name="program_ids[]" value="<?= (int)$p['id'] ?>" data-program-status="<?= e($p['status']) ?>" form="bulkProgramForm" aria-label="Select <?= e($p['code']) ?>"></td>
          <td>
            <span class="badge" style="background:var(--primary-light);color:var(--primary);font-family:var(--font-mono);font-size:12px;font-weight:700">
              <?= e($p['code']) ?>
            </span>
          </td>
          <td>
            <div class="font-bold text-sm" style="color:var(--text-900)"><?= e($p['name']) ?></div>
          </td>
          <td onclick="stopRowClick(event)">
            <a href="/ojtrack/admin/students.php?program_id=<?= $p['id'] ?>" class="font-bold text-sm" style="color:var(--primary);text-decoration:none">
              <?= $p['student_count'] ?> students →
            </a>
          </td>
          <td>
            <span class="text-sm text-success font-bold"><?= $p['active_ojt_count'] ?></span>
          </td>
          <td><?= status_badge($p['status']) ?></td>
          <td class="td-mono text-xs"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
          <td onclick="stopRowClick(event)">
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary"
                      title="Edit program" aria-label="Edit program"
                      onclick='openEditProgram(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <form method="POST"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="program_id" value="<?= $p['id'] ?>">
                <button type="submit" class="table-action-icon <?= $p['status'] === 'active' ? 'is-danger' : 'is-success' ?>"
                        title="<?= $p['status'] === 'active' ? 'Deactivate program' : 'Activate program' ?>"
                        aria-label="<?= $p['status'] === 'active' ? 'Deactivate program' : 'Activate program' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <?php if ($p['status'] === 'active'): ?>
                      <path d="M8 12h8"/>
                    <?php else: ?>
                      <path d="m8 12 3 3 5-6"/>
                    <?php endif; ?>
                  </svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($programs)): ?>
          <tr><td colspan="8" class="text-center text-muted py-6">No academic programs found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Program Modal -->
<div class="modal-overlay" id="addProgramModal">
  <div class="modal">
    <div class="modal-title">Add New Academic Program</div>
    <p class="modal-sub">Create a new program code and degree title for student enrollment</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_program">

      <div class="user-form-section-heading">Program details</div>
      <div class="form-group">
        <label class="form-label">Program Code <span style="color:red">*</span></label>
        <input type="text" name="code" class="form-control" placeholder="e.g. BSIT" style="text-transform:uppercase" required>
        <div class="text-xs text-muted mt-1">Short acronym/code used in IDs and badges (e.g. BSIT).</div>
      </div>

      <div class="form-group">
        <label class="form-label">Full Program Name <span style="color:red">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="e.g. Bachelor of Science in Information Technology" required>
        <div class="text-xs text-muted mt-1">Complete spelled-out degree program title.</div>
      </div>

      <div class="user-form-section-heading">Program availability</div>
      <div class="form-group">
        <label class="form-label">Initial Status</label>
        <select name="status" class="form-control">
          <option value="active">Active (Available for students)</option>
          <option value="inactive">Inactive (Disabled)</option>
        </select>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addProgramModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Program</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Program Modal -->
<div class="modal-overlay" id="editProgramModal">
  <div class="modal">
    <div class="modal-title">Edit Academic Program</div>
    <p class="modal-sub" id="editProgramModalSub"></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_program">
      <input type="hidden" name="program_id" id="editProgramId">

      <div class="user-form-section-heading">Program details</div>
      <div class="form-group">
        <label class="form-label">Program Code <span style="color:red">*</span></label>
        <input type="text" name="code" id="editProgramCode" class="form-control" style="text-transform:uppercase" required>
      </div>

      <div class="form-group">
        <label class="form-label">Full Program Name <span style="color:red">*</span></label>
        <input type="text" name="name" id="editProgramName" class="form-control" required>
      </div>

      <div class="user-form-section-heading">Program availability</div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" id="editProgramStatus" class="form-control">
          <option value="active">Active (Available for students)</option>
          <option value="inactive">Inactive (Disabled)</option>
        </select>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editProgramModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditProgram(p) {
  document.getElementById('editProgramId').value = p.id;
  document.getElementById('editProgramCode').value = p.code;
  document.getElementById('editProgramName').value = p.name;
  document.getElementById('editProgramStatus').value = p.status || 'active';
  const enrolled = p.student_count != null ? p.student_count : 0;
  const activeOjt = p.active_ojt_count != null ? p.active_ojt_count : 0;
  document.getElementById('editProgramModalSub').textContent =
    p.code + ' — ' + p.name + ' · ' + enrolled + ' enrolled · ' + activeOjt + ' active OJT';
  openModal('editProgramModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
