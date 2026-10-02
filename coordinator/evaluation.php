<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$uid   = (int)$user['id'];
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$uid], 'i');

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_form') {
        $form_id = (int)($_POST['form_id'] ?? 0);
        $company_id = (int)($_POST['company_id'] ?? 0);
        $form = query_one("SELECT * FROM evaluation_forms WHERE id=? AND created_by=? AND status='active'", [$form_id, $uid], 'ii');

        if (!$form || !$company_id) {
            $error = 'Select a form and a company.';
        } else {
            // Only students of this coordinator who applied/are assigned to the selected company
            $targets = query(
                "SELECT s.*, c.id AS company_id, c.user_id AS company_user_id, c.company_name, u.name
                 FROM students s
                 JOIN users u ON u.id=s.user_id
                 JOIN companies c ON c.id=s.company_id
                 WHERE s.coordinator_id=? AND s.company_id=?",
                [$coord['id'], $company_id], 'ii'
            ) ?: [];

            if (empty($targets)) {
                $error = 'You have no students assigned to that company.';
            } else {
                $n = 0;
                foreach ($targets as $stu) {
                    insert("INSERT INTO eval_submissions (form_id, form_version, student_id, company_id, requested_by) VALUES (?,?,?,?,?)", [$form_id, $form['version'], $stu['id'], $stu['company_id'], $uid], 'iiiii');
                    create_notification($stu['company_user_id'], "OJT Coordinator sent the evaluation form \"{$form['title']}\" for {$stu['name']}.", 'evaluation', '/ojtrack/company/evaluation.php');
                    $n++;
                }
                log_activity($uid, 'Evaluation Form Sent', "$n assignment(s)");
                header('Location: /ojtrack/coordinator/evaluation.php?sent=' . $n);
                exit;
            }
        }
    }

    if ($action === 'save_form') {
        $id    = (int)($_POST['form_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $mode  = $_POST['score_mode'] ?? 'percentage';
        if (!in_array($mode, ['percentage', 'raw', 'rating'], true)) $mode = 'percentage';

        if (!$title) {
            $error = 'Title is required.';
        } else {
            if ($id > 0) {
                query("UPDATE evaluation_forms SET title=?, description=?, score_mode=? WHERE id=? AND created_by=?", [$title, $desc, $mode, $id, $uid], 'sssii');
                $form_id = $id;
                $success = 'Form details updated.';
            } else {
                $form_id = insert("INSERT INTO evaluation_forms (created_by, title, description, score_mode, status, version) VALUES (?,?,?,?,'draft',1)", [$uid, $title, $desc, $mode], 'issss');
                header('Location: /ojtrack/coordinator/evaluation.php?build=' . $form_id . '&new=1');
                exit;
            }
            log_activity($uid, 'Evaluation Form Updated', $title);
        }
    } elseif ($action === 'delete_form') {
        $id = (int)($_POST['form_id'] ?? 0);
        $has = query_one("SELECT COUNT(*) AS c FROM eval_submissions WHERE form_id=?", [$id], 'i')['c'];
        if ($has > 0) {
            $error = 'This form has submitted evaluations and cannot be permanently deleted. Archive it instead.';
        } else {
            query("DELETE FROM evaluation_forms WHERE id=? AND created_by=?", [$id, $uid], 'ii');
            $success = 'Form deleted.';
        }
    } elseif ($action === 'duplicate_form') {
        $id = (int)($_POST['form_id'] ?? 0);
        $f = query_one("SELECT * FROM evaluation_forms WHERE id=? AND created_by=?", [$id, $uid], 'ii');
        if ($f) {
            $new_id = insert("INSERT INTO evaluation_forms (created_by, title, description, score_mode, status, version, parent_id) VALUES (?,?,?,?,'draft',?,?)",
                [$uid, $f['title'] . ' (Copy)', $f['description'], $f['score_mode'], $f['version'], $f['id']], 'isssii');
            $sections = query("SELECT * FROM eval_sections WHERE form_id=? ORDER BY sort_order", [$f['id']], 'i') ?: [];
            foreach ($sections as $s) {
                $sid = insert("INSERT INTO eval_sections (form_id, title, sort_order) VALUES (?,?,?)", [$new_id, $s['title'], $s['sort_order']], 'iss');
                $crits = query("SELECT * FROM eval_criteria WHERE section_id=? ORDER BY sort_order", [$s['id']], 'i') ?: [];
                foreach ($crits as $cr) {
                    insert("INSERT INTO eval_criteria (section_id, label, sort_order) VALUES (?,?,?)", [$sid, $cr['label'], $cr['sort_order']], 'iss');
                }
            }
            $rules = query("SELECT * FROM eval_rating_rules WHERE form_id=?", [$f['id']], 'i') ?: [];
            foreach ($rules as $r) {
                insert("INSERT INTO eval_rating_rules (form_id, score_min, score_max, equivalent, description) VALUES (?,?,?,?,?)", [$new_id, $r['score_min'], $r['score_max'], $r['equivalent'], $r['description']], 'iiids');
            }
            $success = 'Form duplicated as a new draft.';
        }
    } elseif ($action === 'archive_form') {
        $id = (int)($_POST['form_id'] ?? 0);
        $f = query_one("SELECT * FROM evaluation_forms WHERE id=? AND created_by=?", [$id, $uid], 'ii');
        if ($f) {
            $new_status = $f['status'] === 'archived' ? 'draft' : 'archived';
            query("UPDATE evaluation_forms SET status=? WHERE id=?", [$new_status, $id], 'si');
            $success = $new_status === 'archived' ? 'Form archived.' : 'Form restored to draft.';
        }
    } elseif ($action === 'publish_form') {
        $id = (int)($_POST['form_id'] ?? 0);
        $cnt = query_one("SELECT COUNT(*) AS c FROM eval_criteria c JOIN eval_sections s ON s.id=c.section_id WHERE s.form_id=?", [$id], 'i')['c'];
        if ($cnt == 0) {
            $error = 'Add at least one section and one criterion before publishing.';
        } else {
            query("UPDATE evaluation_forms SET status='active', published_at=NOW() WHERE id=? AND created_by=?", [$id, $uid], 'ii');
            $success = 'Form published and set to Active.';
        }
    } elseif ($action === 'edit_active') {
        // Create a new draft version to preserve existing submissions
        $id = (int)($_POST['form_id'] ?? 0);
        $f = query_one("SELECT * FROM evaluation_forms WHERE id=? AND created_by=?", [$id, $uid], 'ii');
        if ($f && $f['status'] === 'active') {
            $has = query_one("SELECT COUNT(*) AS c FROM eval_submissions WHERE form_id=?", [$id], 'i')['c'];
            if ($has > 0) {
                $new_id = insert("INSERT INTO evaluation_forms (created_by, title, description, score_mode, status, version, parent_id) VALUES (?,?,?,?,'draft',?,?)",
                    [$uid, $f['title'], $f['description'], $f['score_mode'], $f['version'] + 1, $f['id']], 'isssii');
                $sections = query("SELECT * FROM eval_sections WHERE form_id=? ORDER BY sort_order", [$f['id']], 'i') ?: [];
                foreach ($sections as $s) {
                    $sid = insert("INSERT INTO eval_sections (form_id, title, sort_order) VALUES (?,?,?)", [$new_id, $s['title'], $s['sort_order']], 'iss');
                    $crits = query("SELECT * FROM eval_criteria WHERE section_id=? ORDER BY sort_order", [$s['id']], 'i') ?: [];
                    foreach ($crits as $cr) {
                        insert("INSERT INTO eval_criteria (section_id, label, sort_order) VALUES (?,?,?)", [$sid, $cr['label'], $cr['sort_order']], 'iss');
                    }
                }
                $rules = query("SELECT * FROM eval_rating_rules WHERE form_id=?", [$f['id']], 'i') ?: [];
                foreach ($rules as $r) {
                    insert("INSERT INTO eval_rating_rules (form_id, score_min, score_max, equivalent, description) VALUES (?,?,?,?,?)", [$new_id, $r['score_min'], $r['score_max'], $r['equivalent'], $r['description']], 'iiids');
                }
                header('Location: /ojtrack/coordinator/evaluation.php?build=' . $new_id);
                exit;
            }
        }
        header('Location: /ojtrack/coordinator/evaluation.php?build=' . $id);
        exit;
    }

    // Structural edits in the builder
    if ($action === 'add_section' || $action === 'rename_section' || $action === 'delete_section' ||
        $action === 'add_criterion' || $action === 'delete_criterion' ||
        $action === 'move_section_up' || $action === 'move_section_down' ||
        $action === 'move_criterion_up' || $action === 'move_criterion_down' ||
        $action === 'add_rule' || $action === 'delete_rule' ||
        $action === 'reorder_sections' || $action === 'reorder_criteria') {

        $form_id = (int)($_POST['form_id'] ?? 0);
        $f = query_one("SELECT id FROM evaluation_forms WHERE id=? AND created_by=?", [$form_id, $uid], 'ii');
        if (!$f) { $error = 'Form not found.'; }
        else {
            switch ($action) {
                case 'add_section':
                    $t = trim($_POST['section_title'] ?? '');
                    if ($t) { $n = query_one("SELECT COALESCE(MAX(sort_order),0)+1 AS x FROM eval_sections WHERE form_id=?", [$form_id], 'i')['x']; insert("INSERT INTO eval_sections (form_id,title,sort_order) VALUES (?,?,?)", [$form_id, $t, $n], 'iss'); }
                    break;
                case 'rename_section':
                    $t = trim($_POST['title'] ?? '');
                    if ($t) query("UPDATE eval_sections SET title=? WHERE id=? AND form_id=?", [$t, (int)$_POST['section_id'], $form_id], 'sii');
                    break;
                case 'delete_section':
                    query("DELETE FROM eval_sections WHERE id=? AND form_id=?", [(int)$_POST['section_id'], $form_id], 'ii');
                    break;
                case 'add_criterion':
                    $t = trim($_POST['criterion_label'] ?? '');
                    if ($t) { $n = query_one("SELECT COALESCE(MAX(sort_order),0)+1 AS x FROM eval_criteria WHERE section_id=?", [(int)$_POST['section_id']], 'i')['x']; insert("INSERT INTO eval_criteria (section_id,label,sort_order) VALUES (?,?,?)", [(int)$_POST['section_id'], $t, $n], 'iss'); }
                    break;
                case 'delete_criterion':
                    query("DELETE FROM eval_criteria WHERE id=?", [(int)$_POST['criterion_id']], 'i');
                    break;
                case 'move_section_up':
                case 'move_section_down':
                case 'move_criterion_up':
                case 'move_criterion_down':
                    break;
                case 'add_rule':
                    $mn = (int)($_POST['score_min'] ?? 0); $mx = (int)($_POST['score_max'] ?? 0); $eq = (float)($_POST['equivalent'] ?? 0);
                    $dsc = trim($_POST['description'] ?? '');
                    if ($mn <= $mx && $eq > 0) insert("INSERT INTO eval_rating_rules (form_id,score_min,score_max,equivalent,description) VALUES (?,?,?,?,?)", [$form_id, $mn, $mx, $eq, $dsc], 'iiids');
                    break;
                case 'delete_rule':
                    query("DELETE FROM eval_rating_rules WHERE id=? AND form_id=?", [(int)$_POST['rule_id'], $form_id], 'ii');
                    break;
                case 'reorder_sections':
                    $ids = $_POST['order'] ?? [];
                    foreach ($ids as $i => $sid) query("UPDATE eval_sections SET sort_order=? WHERE id=? AND form_id=?", [$i + 1, (int)$sid, $form_id], 'iii');
                    break;
                case 'reorder_criteria':
                    $ids = $_POST['order'] ?? [];
                    foreach ($ids as $i => $cid) query("UPDATE eval_criteria SET sort_order=? WHERE id=?", [$i + 1, (int)$cid], 'ii');
                    break;
            }
            if ($action === 'move_section_up' || $action === 'move_section_down' || $action === 'move_criterion_up' || $action === 'move_criterion_down') {
                // handled client-side via reorder_* posts in this build
            }
        }
    }
}

$build_id = isset($_GET['build']) ? (int)$_GET['build'] : 0;
$build = $build_id ? query_one("SELECT * FROM evaluation_forms WHERE id=? AND created_by=?", [$build_id, $uid], 'ii') : null;

$forms = query(
    "SELECT f.*, (SELECT COUNT(*) FROM eval_submissions s WHERE s.form_id=f.id) AS sub_count,
            (SELECT COUNT(*) FROM eval_submissions s WHERE s.form_id=f.id AND s.status='completed') AS done_count
     FROM evaluation_forms f WHERE f.created_by=? ORDER BY f.id DESC", [$uid], 'i') ?: [];

$students = query(
    "SELECT s.id, u.name, c.company_name FROM students s JOIN users u ON u.id=s.user_id LEFT JOIN companies c ON c.id=s.company_id WHERE s.coordinator_id=? ORDER BY u.name",
    [$coord['id']], 'i') ?: [];

$submissions = query(
    "SELECT s.*, f.title AS form_title, u.name AS student_name, c.company_name FROM eval_submissions s
     JOIN evaluation_forms f ON f.id=s.form_id
     JOIN students st ON st.id=s.student_id
     JOIN users u ON u.id=st.user_id
     LEFT JOIN companies c ON c.id=s.company_id
     WHERE s.requested_by=? ORDER BY s.id DESC", [$uid], 'i') ?: [];

$page_title = 'Evaluation Forms';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Evaluation Forms</div>
    <div class="page-sub">Build, manage, and distribute evaluation forms to companies</div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-secondary" onclick="openModal('manageSubsModal')">Submissions</button>
    <button class="btn btn-secondary" onclick="openModal('sendFormModal')">Send to Company</button>
    <button class="btn btn-primary" onclick="openModal('newFormModal')">+ New Form</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($build): ?>
  <?php
  $sections = query("SELECT * FROM eval_sections WHERE form_id=? ORDER BY sort_order", [$build['id']], 'i') ?: [];
  $rules = query("SELECT * FROM eval_rating_rules WHERE form_id=? ORDER BY score_min DESC", [$build['id']], 'i') ?: [];
  ?>
  <!-- Builder view -->
  <div class="mb-4" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <a href="/ojtrack/coordinator/evaluation.php" class="btn btn-ghost btn-sm">← Back to Forms</a>
    <span class="badge <?= $build['status']==='active'?'badge-approved':($build['status']==='archived'?'badge-rejected':'badge-pending') ?>"><?= ucfirst($build['status']) ?></span>
    <span class="text-xs text-muted">Version <?= (int)$build['version'] ?> · <?= ucfirst($build['score_mode']) ?> scoring</span>
    <span style="flex:1"></span>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="publish_form">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <button class="btn btn-primary btn-sm">Publish</button>
    </form>
  </div>

  <div class="card card-body mb-4">
    <div class="section-title mb-3">Form Details</div>
    <form method="POST" class="form-row" style="align-items:end;gap:12px;flex-wrap:wrap">
      <input type="hidden" name="action" value="save_form">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <div class="form-group" style="flex:2">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" required value="<?= e($build['title']) ?>">
      </div>
      <div class="form-group" style="flex:2">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" value="<?= e($build['description']) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Scoring Mode</label>
        <select name="score_mode" class="form-control">
          <option value="percentage" <?= $build['score_mode']==='percentage'?'selected':'' ?>>Percentage (0–100)</option>
          <option value="raw" <?= $build['score_mode']==='raw'?'selected':'' ?>>Raw Score</option>
        </select>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm" style="margin-bottom:0">Save</button>
    </form>
  </div>

  <div class="card card-body mb-4">
    <div class="section-title mb-1">Sections &amp; Criteria</div>
    <p class="text-xs text-muted mb-3">Drag <span style="cursor:grab">⠿</span> to reorder · use small ✕ to remove</p>

    <div id="sectionsWrap" data-form="<?= $build['id'] ?>">
      <?php foreach ($sections as $s):
        $crits = query("SELECT * FROM eval_criteria WHERE section_id=? ORDER BY sort_order", [$s['id']], 'i') ?: []; ?>
      <div class="eval-section" draggable="true" data-id="<?= $s['id'] ?>" style="border:1px solid var(--border-light);border-radius:var(--radius);padding:14px;margin-bottom:12px;background:var(--bg)">
        <div class="flex-between mb-2" style="align-items:center">
          <div style="display:flex;align-items:center;gap:8px">
            <span class="drag-handle" style="cursor:grab;color:var(--text-400)">⠿</span>
            <form method="POST" style="display:flex;gap:6px">
              <input type="hidden" name="action" value="rename_section">
              <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
              <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
              <input type="text" name="title" class="form-control" style="width:260px;font-weight:700" value="<?= e($s['title']) ?>">
              <button class="btn btn-ghost btn-sm">Rename</button>
            </form>
          </div>
          <form method="POST" onsubmit="return confirm('Delete this section and its criteria?')">
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
            <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
            <button class="btn btn-ghost btn-sm">✕</button>
          </form>
        </div>

        <div class="eval-criteria" data-section="<?= $s['id'] ?>" style="padding-left:26px">
          <?php foreach ($crits as $cr): ?>
          <div class="eval-criterion" draggable="true" data-id="<?= $cr['id'] ?>" style="display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px dashed var(--border-light)">
            <span class="drag-handle" style="cursor:grab;color:var(--text-400)">⠿</span>
            <span class="text-sm" style="flex:1"><?= e($cr['label']) ?></span>
            <form method="POST" onsubmit="return confirm('Delete this criterion?')">
              <input type="hidden" name="action" value="delete_criterion">
              <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
              <input type="hidden" name="criterion_id" value="<?= $cr['id'] ?>">
              <button class="btn btn-ghost btn-sm" style="padding:2px 6px">✕</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>

        <form method="POST" style="display:flex;gap:6px;margin-top:8px;padding-left:26px">
          <input type="hidden" name="action" value="add_criterion">
          <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
          <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
          <input type="text" name="criterion_label" class="form-control" style="width:300px" placeholder="+ Add Criterion" required>
          <button class="btn btn-ghost btn-sm">Add</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" style="display:flex;gap:6px">
      <input type="hidden" name="action" value="add_section">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <input type="text" name="section_title" class="form-control" style="width:300px" placeholder="+ Add Section (e.g. Attendance and Punctuality)" required>
      <button class="btn btn-ghost btn-sm">Add Section</button>
    </form>
  </div>

  <div class="card card-body mb-4">
    <div class="section-title mb-3">Scoring / Equivalent Rating Rules</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Score Range</th><th>Equivalent</th><th>Description</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($rules)): ?><tr><td colspan="4" class="text-center text-muted">No rating rules yet. Example: 96–100 → 1.25</td></tr>
          <?php else: ?>
            <?php foreach ($rules as $r): ?>
            <tr>
              <td><?= (int)$r['score_min'] ?>–<?= (int)$r['score_max'] ?></td>
              <td><strong><?= e($r['equivalent']) ?></strong></td>
              <td class="text-sm text-muted"><?= e($r['description']) ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('Delete this rule?')">
                  <input type="hidden" name="action" value="delete_rule">
                  <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
                  <input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
                  <button class="btn btn-ghost btn-sm">✕</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <form method="POST" style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
      <input type="hidden" name="action" value="add_rule">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <input type="number" name="score_min" class="form-control" style="width:90px" placeholder="Min" required>
      <input type="number" name="score_max" class="form-control" style="width:90px" placeholder="Max" required>
      <input type="number" step="0.01" name="equivalent" class="form-control" style="width:110px" placeholder="Equiv. (1.25)" required>
      <input type="text" name="description" class="form-control" style="width:220px" placeholder="Description (optional)">
      <button class="btn btn-ghost btn-sm">+ Add Rating Rule</button>
    </form>
  </div>

  <div style="display:flex;gap:8px">
    <button class="btn btn-secondary" onclick="openModal('previewModal')">Preview Form</button>
    <form method="POST" style="display:inline" onsubmit="return confirm('Publish this form and set it Active?')">
      <input type="hidden" name="action" value="publish_form">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <button class="btn btn-primary">Publish Form</button>
    </form>
  </div>

  <!-- Preview Modal -->
  <div class="modal-overlay" id="previewModal">
    <div class="modal modal-lg">
      <div class="modal-title"><?= e($build['title']) ?> — Preview</div>
      <p class="modal-sub">This is how the evaluation form will appear to the company</p>
      <?php foreach ($sections as $s): $crits = query("SELECT * FROM eval_criteria WHERE section_id=? ORDER BY sort_order", [$s['id']], 'i') ?: []; ?>
      <div class="section-title mb-2" style="margin-top:12px"><?= e($s['title']) ?></div>
      <?php foreach ($crits as $cr): ?>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between"><span class="text-sm"><?= e($cr['label']) ?></span><span class="font-mono text-sm text-muted">0–<?= $build['score_mode']==='rating'?'5':'100' ?></span></div>
        <input type="range" class="score-slider" style="width:100%" disabled>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($rules): ?>
      <div class="section-title mb-2" style="margin-top:12px">Rating Guide</div>
      <?php foreach ($rules as $r): ?>
      <div class="text-sm text-muted"><?= (int)$r['score_min'] ?>–<?= (int)$r['score_max'] ?> → <strong><?= e($r['equivalent']) ?></strong> <?= e($r['description']) ?></div>
      <?php endforeach; ?>
      <?php endif; ?>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('previewModal')">Close</button></div>
    </div>
  </div>

  <script>
    // Simple drag & drop reorder for sections and criteria
    function enableDrag(containerSel, itemSel, action, formId) {
      const container = document.querySelector(containerSel);
      if (!container) return;
      let dragged = null;
      container.querySelectorAll(itemSel).forEach(item => {
        item.addEventListener('dragstart', e => { dragged = item; e.dataTransfer.effectAllowed = 'move'; });
        item.addEventListener('dragover', e => { e.preventDefault(); return false; });
        item.addEventListener('drop', e => {
          e.preventDefault();
          if (dragged && dragged !== item && dragged.parentNode === item.parentNode) {
            item.parentNode.insertBefore(dragged, item.nextSibling === dragged ? item : item.nextSibling);
            const ids = [...container.querySelectorAll(itemSel)].map(el => el.dataset.id);
            const fd = new FormData();
            fd.append('action', action); fd.append('form_id', formId);
            ids.forEach(id => fd.append('order[]', id));
            fetch('', { method: 'POST', body: fd }).then(() => location.reload());
          }
        });
      });
    }
    enableDrag('#sectionsWrap', '.eval-section', 'reorder_sections', <?= (int)$build['id'] ?>);
    document.querySelectorAll('.eval-criteria').forEach((c, i) => {
      enableDrag('.eval-criteria[data-section="' + c.dataset.section + '"]', '.eval-criterion', 'reorder_criteria', <?= (int)$build['id'] ?>);
    });
  </script>

<?php else: ?>
  <!-- Forms list -->
  <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
    <?php if (empty($forms)): ?>
      <div class="card card-body"><div class="empty-state"><p>No evaluation forms yet. Create your first form to get started.</p></div></div>
    <?php else: ?>
      <?php foreach ($forms as $f): ?>
      <div class="card card-body">
        <div class="flex-between mb-2">
          <strong><?= e($f['title']) ?></strong>
          <span class="badge <?= $f['status']==='active'?'badge-approved':($f['status']==='archived'?'badge-rejected':'badge-pending') ?>"><?= ucfirst($f['status']) ?></span>
        </div>
        <div class="text-xs text-muted mb-1"><?= e($f['description']) ?></div>
        <div class="text-xs text-muted mb-3">Version <?= (int)$f['version'] ?> · <?= ucfirst($f['score_mode']) ?> · <?= (int)$f['done_count'] ?>/<?= (int)$f['sub_count'] ?> submitted</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if ($f['status'] === 'active'): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="edit_active"><input type="hidden" name="form_id" value="<?= $f['id'] ?>"><button class="btn btn-secondary btn-sm">Edit (v<?= (int)$f['version'] + 1 ?>)</button></form>
          <?php else: ?>
            <a href="?build=<?= $f['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <?php endif; ?>
          <form method="POST" style="display:inline"><input type="hidden" name="action" value="duplicate_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>"><button class="btn btn-ghost btn-sm">Duplicate</button></form>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= $f['status']==='archived'?'Restore this form?':'Archive this form? Companies can no longer fill it.' ?>')">
            <input type="hidden" name="action" value="archive_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>">
            <button class="btn btn-ghost btn-sm"><?= $f['status']==='archived'?'Restore':'Archive' ?></button>
          </form>
          <?php if ($f['sub_count'] == 0): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete this form?')">
            <input type="hidden" name="action" value="delete_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- New Form Modal -->
<div class="modal-overlay" id="newFormModal">
  <div class="modal">
    <div class="modal-title">Create Evaluation Form</div>
    <p class="modal-sub">Start with the basics, then build sections and criteria</p>
    <form method="POST">
      <input type="hidden" name="action" value="save_form">
      <div class="form-group">
        <label class="form-label">Form Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. OJT Performance Evaluation">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Scoring Mode</label>
        <select name="score_mode" class="form-control">
          <option value="percentage">Percentage (0–100)</option>
          <option value="raw">Raw Score</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newFormModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create &amp; Build</button>
      </div>
    </form>
  </div>
</div>

<!-- Send Form Modal -->
<div class="modal-overlay" id="sendFormModal">
  <div class="modal">
    <div class="modal-title">Send Form to Company</div>
    <p class="modal-sub">Select an active form and a company related to your students</p>
    <form method="POST" action="">
      <input type="hidden" name="action" value="send_form">
      <div class="form-group">
        <label class="form-label">Evaluation Form</label>
        <select name="form_id" class="form-control" required>
          <option value="">Select active form...</option>
          <?php foreach ($forms as $f): if ($f['status'] !== 'active') continue; ?>
            <option value="<?= $f['id'] ?>"><?= e($f['title']) ?> (v<?= (int)$f['version'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Company</label>
        <select name="company_id" class="form-control" required>
          <option value="">Select company...</option>
          <?php
          $related_companies = query(
              "SELECT DISTINCT c.id, c.company_name
               FROM companies c
               JOIN students s ON s.company_id=c.id
               WHERE s.coordinator_id=?
               ORDER BY c.company_name",
              [$coord['id']], 'i'
          ) ?: [];
          foreach ($related_companies as $rc): ?>
            <option value="<?= $rc['id'] ?>"><?= e($rc['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1">The form will be sent for every student you supervise who is applied/assigned to the selected company.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('sendFormModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send</button>
      </div>
    </form>
  </div>
</div>

<!-- Submissions Modal -->
<div class="modal-overlay" id="manageSubsModal">
  <div class="modal modal-lg">
    <div class="modal-title">Form Submissions</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Form</th><th>Student</th><th>Company</th><th>Status</th><th>Score</th></tr></thead>
        <tbody>
          <?php if (empty($submissions)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No submissions yet.</td></tr>
          <?php else: ?>
            <?php foreach ($submissions as $s): ?>
            <tr>
              <td><strong><?= e($s['form_title']) ?></strong> <span class="text-xs text-muted">v<?= (int)$s['form_version'] ?></span></td>
              <td><?= e($s['student_name']) ?></td>
              <td><?= e($s['company_name'] ?? '—') ?></td>
              <td><?= status_badge($s['status']) ?></td>
              <td><?= $s['overall_score'] !== null ? number_format($s['overall_score'], 1) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('manageSubsModal')">Close</button></div>
  </div>
</div>

<?php if (isset($_GET['sent'])) $success = "Form sent to " . (int)$_GET['sent'] . " assignment(s)."; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
