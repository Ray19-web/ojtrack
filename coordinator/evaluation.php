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
    $draft_actions = [
        'save_form','add_section','rename_section','delete_section',
        'add_criterion','delete_criterion','move_section_up','move_section_down',
        'move_criterion_up','move_criterion_down','add_rule','delete_rule',
        'reorder_sections','reorder_criteria','publish_form'
    ];
    $posted_form_id = (int)($_POST['form_id'] ?? 0);

    if (in_array($action,$draft_actions,true) && !($action==='save_form' && $posted_form_id===0)) {
        $owned=normalized_eval_form_row($posted_form_id,$uid);
        if (!$owned) request_error(403,'Form not found or not owned by you.');
        $used=query_one(
            "SELECT id FROM evaluation_requests WHERE evaluation_definition_version_id=? LIMIT 1",
            [$posted_form_id],
            'i'
        );
        if ($owned['status']!=='draft' || $used) {
            request_error(409,'Published or assigned forms are read-only. Create a new draft version.');
        }
        if (isset($_POST['section_id']) &&
            !normalized_eval_section_owned((int)$_POST['section_id'],$posted_form_id)) {
            request_error(403,'Section does not belong to this form.');
        }
        $criterion_ids=$action==='reorder_criteria'
            ? ($_POST['order'] ?? [])
            : (isset($_POST['criterion_id']) ? [$_POST['criterion_id']] : []);
        if (!is_array($criterion_ids)) request_error(422,'Invalid criterion order.');
        foreach ($criterion_ids as $criterion_id) {
            if (!normalized_eval_criterion_owned((int)$criterion_id,$posted_form_id)) {
                request_error(403,'Criterion does not belong to this form.');
            }
        }
    }

    try {
        if ($action === 'send_form') {
            $form_id=(int)($_POST['form_id'] ?? 0);
            $company_id=(int)($_POST['company_id'] ?? 0);
            if (!$form_id || !$company_id) {
                $error='Select a form and a company.';
            } else {
                $n=normalized_eval_send_form(
                    $form_id,$company_id,(int)$coord['id'],$uid
                );
                log_activity($uid,'Evaluation Form Sent',"$n normalized assignment(s)");
                header('Location: /ojtrack/coordinator/evaluation.php?sent='.$n);
                exit;
            }
        } elseif ($action === 'save_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            $title=trim($_POST['title'] ?? '');
            $desc=trim($_POST['description'] ?? '');
            $mode=$_POST['score_mode'] ?? 'percentage';
            if ($mode!=='percentage') request_error(422,'Only percentage scoring is currently supported.');
            if ($title==='') {
                $error='Title is required.';
            } elseif ($id>0) {
                normalized_eval_update_form($id,$uid,$title,$desc);
                $success='Form details updated.';
                log_activity($uid,'Evaluation Form Updated',$title);
            } else {
                $form_id=normalized_eval_create_form($uid,$title,$desc,$mode);
                log_activity($uid,'Evaluation Form Created',$title);
                header('Location: /ojtrack/coordinator/evaluation.php?build='.$form_id.'&new=1');
                exit;
            }
        } elseif ($action === 'delete_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            if (!normalized_eval_delete_form($id,$uid)) {
                $error='Form not found.';
            } else {
                $success='Draft form deleted.';
            }
        } elseif ($action === 'duplicate_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            $new_id=normalized_eval_clone_version($id,$uid,true);
            $success='Form duplicated as a new draft.';
        } elseif ($action === 'archive_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            $new_status=normalized_eval_archive_toggle($id,$uid);
            $success=$new_status==='archived' ? 'Form archived.' : 'Form restored as '.$new_status.'.';
        } elseif ($action === 'publish_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            normalized_eval_publish($id,$uid);
            $success='Form published and set to Active.';
        } elseif ($action === 'edit_active') {
            $id=(int)($_POST['form_id'] ?? 0);
            $new_id=normalized_eval_clone_version($id,$uid,false);
            header('Location: /ojtrack/coordinator/evaluation.php?build='.$new_id);
            exit;
        } elseif (in_array($action,[
            'add_section','rename_section','delete_section',
            'add_criterion','delete_criterion',
            'move_section_up','move_section_down','move_criterion_up','move_criterion_down',
            'add_rule','delete_rule','reorder_sections','reorder_criteria'
        ],true)) {
            $form_id=(int)($_POST['form_id'] ?? 0);
            switch ($action) {
                case 'add_section':
                    $title=trim($_POST['section_title'] ?? '');
                    if ($title!=='') normalized_eval_add_section($form_id,$uid,$title);
                    break;
                case 'rename_section':
                    $title=trim($_POST['title'] ?? '');
                    if ($title!=='') normalized_eval_rename_section($form_id,$uid,(int)$_POST['section_id'],$title);
                    break;
                case 'delete_section':
                    normalized_eval_delete_section($form_id,$uid,(int)$_POST['section_id']);
                    break;
                case 'add_criterion':
                    $label=trim($_POST['criterion_label'] ?? '');
                    if ($label!=='') normalized_eval_add_criterion($form_id,$uid,(int)$_POST['section_id'],$label);
                    break;
                case 'delete_criterion':
                    normalized_eval_delete_criterion($form_id,$uid,(int)$_POST['criterion_id']);
                    break;
                case 'add_rule':
                    normalized_eval_add_rule(
                        $form_id,$uid,
                        (float)($_POST['score_min'] ?? 0),
                        (float)($_POST['score_max'] ?? 0),
                        (float)($_POST['equivalent'] ?? 0),
                        trim($_POST['description'] ?? '')
                    );
                    break;
                case 'delete_rule':
                    normalized_eval_delete_rule($form_id,$uid,(int)$_POST['rule_id']);
                    break;
                case 'reorder_sections':
                    normalized_eval_reorder_sections($form_id,$uid,$_POST['order'] ?? []);
                    break;
                case 'reorder_criteria':
                    normalized_eval_reorder_criteria($form_id,$uid,$_POST['order'] ?? []);
                    break;
                default:
                    // Move buttons are represented by the builder's reorder requests.
                    break;
            }
        }
    } catch (DomainException $exception) {
        if ($action==='add_rule') request_error(422,$exception->getMessage());
        $error=$exception->getMessage();
    }
}

$build_id = isset($_GET['build']) ? (int)$_GET['build'] : 0;
$build = $build_id ? normalized_eval_form_row($build_id,$uid) : null;
if ($build && ($build['status']!=='draft' || query_one(
    "SELECT id FROM evaluation_requests WHERE evaluation_definition_version_id=? LIMIT 1",
    [$build_id],
    'i'
))) {
    request_error(409,'This form is read-only. Use Edit from the forms list to create a new version.');
}

$forms = normalized_eval_forms_for_owner($uid);
$students = normalized_students_for_coordinator((int)$coord['id']);
$submissions = normalized_eval_submissions_for_requester($uid);

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
  $sections = normalized_eval_sections((int)$build['id']);
  $rules = normalized_eval_rules((int)$build['id']);
  ?>
  <!-- Builder view -->
  <div class="mb-4" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <a href="/ojtrack/coordinator/evaluation.php" class="btn btn-ghost btn-sm">← Back to Forms</a>
    <span class="badge <?= $build['status']==='active'?'badge-approved':($build['status']==='archived'?'badge-rejected':'badge-pending') ?>"><?= ucfirst($build['status']) ?></span>
    <span class="text-xs text-muted">Version <?= (int)$build['version'] ?> · <?= ucfirst($build['score_mode']) ?> scoring</span>
    <span style="flex:1"></span>
    <form method="POST" style="display:inline"><?= csrf_field() ?>
      <input type="hidden" name="action" value="publish_form">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <button class="btn btn-primary btn-sm">Publish</button>
    </form>
  </div>

  <div class="card card-body mb-4">
    <div class="section-title mb-3">Form Details</div>
    <form method="POST" class="form-row" style="align-items:end;gap:12px;flex-wrap:wrap"><?= csrf_field() ?>
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
        $crits = normalized_eval_criteria((int)$s['id']); ?>
      <div class="eval-section" draggable="true" data-id="<?= $s['id'] ?>" style="border:1px solid var(--border-light);border-radius:var(--radius);padding:14px;margin-bottom:12px;background:var(--bg)">
        <div class="flex-between mb-2" style="align-items:center">
          <div style="display:flex;align-items:center;gap:8px">
            <span class="drag-handle" style="cursor:grab;color:var(--text-400)">⠿</span>
            <form method="POST" style="display:flex;gap:6px"><?= csrf_field() ?>
              <input type="hidden" name="action" value="rename_section">
              <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
              <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
              <input type="text" name="title" class="form-control" style="width:260px;font-weight:700" value="<?= e($s['title']) ?>">
              <button class="btn btn-ghost btn-sm">Rename</button>
            </form>
          </div>
          <form method="POST" onsubmit="return confirm('Delete this section and its criteria?')"><?= csrf_field() ?>
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
            <form method="POST" onsubmit="return confirm('Delete this criterion?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_criterion">
              <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
              <input type="hidden" name="criterion_id" value="<?= $cr['id'] ?>">
              <button class="btn btn-ghost btn-sm" style="padding:2px 6px">✕</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>

        <form method="POST" style="display:flex;gap:6px;margin-top:8px;padding-left:26px"><?= csrf_field() ?>
          <input type="hidden" name="action" value="add_criterion">
          <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
          <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
          <input type="text" name="criterion_label" class="form-control" style="width:300px" placeholder="+ Add Criterion" required>
          <button class="btn btn-ghost btn-sm">Add</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" style="display:flex;gap:6px"><?= csrf_field() ?>
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
                <form method="POST" onsubmit="return confirm('Delete this rule?')"><?= csrf_field() ?>
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
    <form method="POST" style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap"><?= csrf_field() ?>
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
    <form method="POST" style="display:inline" onsubmit="return confirm('Publish this form and set it Active?')"><?= csrf_field() ?>
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
      <?php foreach ($sections as $s): $crits = normalized_eval_criteria((int)$s['id']); ?>
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
            fetch('', { method: 'POST', headers: {'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content}, body: fd }).then(() => location.reload());
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
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="edit_active"><input type="hidden" name="form_id" value="<?= $f['id'] ?>"><button class="btn btn-secondary btn-sm">Edit (v<?= (int)$f['version'] + 1 ?>)</button></form>
          <?php else: ?>
            <a href="?build=<?= $f['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <?php endif; ?>
          <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>"><button class="btn btn-ghost btn-sm">Duplicate</button></form>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= $f['status']==='archived'?'Restore this form?':'Archive this form? This stops new assignments; existing requests remain available.' ?>')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="archive_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>">
            <button class="btn btn-ghost btn-sm"><?= $f['status']==='archived'?'Restore':'Archive' ?></button>
          </form>
          <?php if ($f['sub_count'] == 0): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Permanently delete this form?')"><?= csrf_field() ?>
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
    <form method="POST"><?= csrf_field() ?>
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
    <form method="POST" action=""><?= csrf_field() ?>
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
