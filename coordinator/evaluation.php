<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$uid   = (int)$user['id'];
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$uid], 'i');
$related_companies = query(
    "SELECT DISTINCT c.id, c.company_name
     FROM companies c
     JOIN placements p ON p.company_id=c.id AND p.status IN ('active','on_hold','planned','completed')
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
       AND oe.academic_term_id=(SELECT id FROM academic_terms WHERE status='active' ORDER BY id DESC LIMIT 1)
     JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id
       AND ec.coordinator_id=? AND ec.ended_at IS NULL
     ORDER BY c.company_name",
    [$coord['id']], 'i'
) ?: [];

$success = isset($_GET['sent'])
    ? 'Sent '.(int)($_GET['forms'] ?? 1).' form(s) to '.(int)$_GET['sent'].' company/companies, creating '.(int)($_GET['assignments'] ?? 0).' student assignment(s).'
    : (isset($_GET['saved']) ? 'Evaluation form saved as a draft.' : (isset($_GET['published']) ? 'Evaluation form published and activated.' : ''));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $draft_actions = [
        'save_form','save_draft_and_return','add_section','rename_section','delete_section',
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
            $submitted_form_ids=$_POST['form_ids'] ?? (isset($_POST['form_id']) ? [$_POST['form_id']] : []);
            if (!is_array($submitted_form_ids)) $submitted_form_ids=[$submitted_form_ids];
            $form_ids=array_values(array_unique(array_filter(
                array_map('intval',$submitted_form_ids),
                static fn($formId) => $formId > 0
            )));
            $submitted_company_ids=$_POST['company_ids'] ?? [];
            if (!is_array($submitted_company_ids)) $submitted_company_ids=[$submitted_company_ids];
            $company_ids=array_values(array_unique(array_filter(
                array_map('intval',$submitted_company_ids),
                static fn($companyId) => $companyId > 0
            )));
            if (!$form_ids || !$company_ids) {
                $error='Select at least one evaluation form and one company.';
            } else {
                foreach ($form_ids as $form_id) {
                    $selected_form=normalized_eval_form_row($form_id,$uid);
                    if (!$selected_form || $selected_form['status']!=='active') {
                        request_error(403,'One or more selected evaluation forms are unavailable or inactive.');
                    }
                }
                $related_company_ids=array_map('intval',array_column($related_companies,'id'));
                if (array_diff($company_ids,$related_company_ids)) {
                    request_error(403,'One or more selected companies are not available to your coordinator account.');
                }

                $assignment_count=0;
                db()->begin_transaction();
                try {
                    foreach ($company_ids as $company_id) {
                        foreach ($form_ids as $form_id) {
                            $assignment_count+=normalized_eval_send_form(
                                $form_id,$company_id,(int)$coord['id'],$uid
                            );
                        }
                    }
                    db()->commit();
                } catch (Throwable $exception) {
                    db()->rollback();
                    throw $exception;
                }
                log_activity($uid,'Evaluation Forms Sent',count($form_ids)." form(s) to ".count($company_ids)." company/companies; $assignment_count normalized assignment(s)");
                header('Location: /ojtrack/coordinator/evaluation.php?sent='.count($company_ids).'&forms='.count($form_ids).'&assignments='.$assignment_count);
                exit;
            }
        } elseif (in_array($action,['save_form','save_draft_and_return'],true)) {
            $id=(int)($_POST['form_id'] ?? 0);
            $title=trim($_POST['title'] ?? '');
            $desc=trim($_POST['description'] ?? '');
            $mode=$_POST['score_mode'] ?? 'percentage';
            if (!in_array($mode,['percentage','points'],true)) request_error(422,'Select percentage or points scoring.');
            if ($title==='') {
                $error='Title is required.';
            } elseif ($id>0) {
                normalized_eval_update_form($id,$uid,$title,$desc);
                $success='Form details updated.';
                log_activity($uid,'Evaluation Form Updated',$title);
                if ($action==='save_draft_and_return') {
                    header('Location: /ojtrack/coordinator/evaluation.php?saved=1');
                    exit;
                }
            } else {
                $form_id=normalized_eval_create_form($uid,$title,$desc,$mode);
                log_activity($uid,'Evaluation Form Created',$title);
                header('Location: /ojtrack/coordinator/evaluation.php?build='.$form_id.'&new=1');
                exit;
            }
        } elseif ($action === 'duplicate_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            $new_id=normalized_eval_clone_version($id,$uid,true);
            $success='Form duplicated as a new draft.';
        } elseif ($action === 'archive_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            $new_status=normalized_eval_archive_toggle($id,$uid);
            $success=$new_status==='archived' ? 'Form archived.' : 'Form restored as '.$new_status.'.';
        } elseif ($action === 'archive_selected_forms') {
            $submitted_form_ids=$_POST['form_ids'] ?? [];
            if (!is_array($submitted_form_ids)) $submitted_form_ids=[$submitted_form_ids];
            $form_ids=array_values(array_unique(array_filter(
                array_map('intval',$submitted_form_ids),
                static fn($formId) => $formId > 0
            )));
            if (!$form_ids) {
                $error='Select at least one active form to archive.';
            } else {
                foreach ($form_ids as $form_id) {
                    $selected_form=normalized_eval_form_row($form_id,$uid);
                    if (!$selected_form || $selected_form['status']!=='active') {
                        request_error(403,'One or more selected evaluation forms are unavailable or inactive.');
                    }
                }
                db()->begin_transaction();
                try {
                    foreach ($form_ids as $form_id) normalized_eval_archive_toggle($form_id,$uid);
                    db()->commit();
                } catch (Throwable $exception) {
                    db()->rollback();
                    throw $exception;
                }
                $success=count($form_ids)===1 ? 'Evaluation form archived.' : count($form_ids).' evaluation forms archived.';
                log_activity($uid,'Evaluation Forms Archived',count($form_ids).' active form(s)');
            }
        } elseif ($action === 'publish_form') {
            $id=(int)($_POST['form_id'] ?? 0);
            normalized_eval_publish($id,$uid);
            log_activity($uid,'Evaluation Form Published','Form #'.$id);
            header('Location: /ojtrack/coordinator/evaluation.php?published=1');
            exit;
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
                    if ($title!=='') {
                        normalized_eval_add_section($form_id,$uid,$title);
                        $success='Section added.';
                    }
                    break;
                case 'rename_section':
                    $title=trim($_POST['title'] ?? '');
                    if ($title!=='') {
                        normalized_eval_rename_section($form_id,$uid,(int)$_POST['section_id'],$title);
                        $success='Section name updated.';
                    }
                    break;
                case 'delete_section':
                    normalized_eval_delete_section($form_id,$uid,(int)$_POST['section_id']);
                    $success='Section removed.';
                    break;
                case 'add_criterion':
                    $label=trim($_POST['criterion_label'] ?? '');
                    if ($label!=='') {
                        normalized_eval_add_criterion($form_id,$uid,(int)$_POST['section_id'],$label,(int)($_POST['maximum_points'] ?? 1));
                        $success='Criterion added.';
                    }
                    break;
                case 'delete_criterion':
                    normalized_eval_delete_criterion($form_id,$uid,(int)$_POST['criterion_id']);
                    $success='Criterion removed.';
                    break;
                case 'add_rule':
                    normalized_eval_add_rule(
                        $form_id,$uid,
                        (float)($_POST['score_min'] ?? 0),
                        (float)($_POST['score_max'] ?? 0),
                        isset($_POST['equivalent']) && $_POST['equivalent']!=='' ? (float)$_POST['equivalent'] : null,
                        trim($_POST['description'] ?? '')
                    );
                    $success='Rating rule added.';
                    break;
                case 'delete_rule':
                    normalized_eval_delete_rule($form_id,$uid,(int)$_POST['rule_id']);
                    $success='Rating rule removed.';
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
$active_forms = array_values(array_filter($forms, static fn($form) => $form['status'] === 'active'));
$students = normalized_students_for_coordinator((int)$coord['id']);
$submissions = normalized_eval_submissions_for_requester($uid);

$page_title = 'Evaluation Forms';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$build): ?>
<div class="page-heading flex-between">
  <div>
    <div class="page-title">Evaluation Forms</div>
    <div class="page-sub">Build scorecards, assign them to companies, and track completed evaluations.</div>
  </div>
  <div class="evaluation-page-actions">
    <button class="btn btn-secondary" type="button" onclick="openModal('manageSubsModal')">View submissions</button>
    <button class="btn btn-secondary" type="button" onclick="openModal('sendFormModal')">Assign to company</button>
    <?php if ($forms): ?>
    <details class="evaluation-status-filter">
      <summary class="table-tool-button table-tool-icon-only table-tool-filter" aria-label="Filter forms" title="Filter forms">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16l-6.5 7.5v5L10.5 19v-6.5L4 5Z"/></svg>
      </summary>
      <div class="evaluation-status-filter-menu">
        <label for="evaluationFormStatusFilter">Filter forms by status</label>
        <select id="evaluationFormStatusFilter" class="form-control" aria-label="Filter evaluation forms by status">
          <option value="active" selected>Active</option>
          <option value="draft">Draft</option>
          <option value="archived">Archived</option>
          <option value="all">All forms</option>
        </select>
      </div>
    </details>
    <?php endif; ?>
    <button class="btn btn-primary" type="button" onclick="openModal('newFormModal')">+ Create form</button>
  </div>
</div>
<?php endif; ?>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($build): ?>
  <?php
  $sections = normalized_eval_sections((int)$build['id']);
  $rules = normalized_eval_rules((int)$build['id']);
  $criteriaBySection = [];
  $totalCriteria = 0;
  $pointsAssigned = 0;
  foreach ($sections as $section) {
    $sectionCriteria = normalized_eval_criteria((int)$section['id']);
    $criteriaBySection[(int)$section['id']] = $sectionCriteria;
    $totalCriteria += count($sectionCriteria);
    foreach ($sectionCriteria as $criterion) $pointsAssigned += (int)$criterion['weight'];
  }
  ?>
  <!-- Builder view -->
  <div class="evaluation-builder-toolbar mb-4">
    <div class="evaluation-builder-toolbar-copy">
      <a href="/ojtrack/coordinator/evaluation.php" class="btn btn-secondary btn-sm">← Back to Forms</a>
      <div class="evaluation-builder-status">
        <span class="badge <?= $build['status']==='active'?'badge-approved':($build['status']==='archived'?'badge-rejected':'badge-pending') ?>"><?= ucfirst($build['status']) ?></span>
        <span class="text-xs text-muted">Version <?= (int)$build['version'] ?> · <?= ucfirst($build['score_mode']) ?> scoring</span>
      </div>
    </div>
  </div>

  <nav class="evaluation-stepper" aria-label="Evaluation form setup steps" data-evaluation-stepper data-form-id="<?= (int)$build['id'] ?>">
    <button type="button" class="evaluation-step is-active" data-builder-step="1" aria-current="step"><span class="evaluation-step-title">Form details</span><span class="evaluation-step-subtitle">Name and scoring</span></button>
    <button type="button" class="evaluation-step" data-builder-step="2"><span class="evaluation-step-title">Questions</span><span class="evaluation-step-subtitle">Sections and criteria</span></button>
    <button type="button" class="evaluation-step" data-builder-step="3"><span class="evaluation-step-title">Rating rules</span><span class="evaluation-step-subtitle">Score meanings</span></button>
    <button type="button" class="evaluation-step" data-builder-step="4"><span class="evaluation-step-title">Review</span><span class="evaluation-step-subtitle">Preview and publish</span></button>
  </nav>

  <section class="card card-body evaluation-builder-panel evaluation-step-panel mb-4" data-builder-step-panel="1" aria-labelledby="evaluationDetailsHeading">
    <div class="evaluation-builder-panel-heading">
      <div><h2 id="evaluationDetailsHeading">Form details</h2><p>Set the name and instructions reviewers will see.</p></div>
    </div>
    <div class="evaluation-builder-form evaluation-builder-form--details">
      <div class="form-group" style="flex:2">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" required value="<?= e($build['title']) ?>">
      </div>
      <div class="form-group" style="flex:2">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Explain what the company should assess."><?= e($build['description']) ?></textarea>
      </div>
      <div class="evaluation-scoring-summary">
        <span class="evaluation-scoring-icon" aria-hidden="true">◎</span>
        <span><strong><?= $build['score_mode']==='points' ? 'Points scoring' : 'Percentage scoring' ?></strong><small><?= $build['score_mode']==='points' ? 'Criterion maximums must add up to 100 points.' : 'Each criterion is scored from 0 to 100; the final score is their average.' ?></small></span>
      </div>
      <div class="evaluation-builder-form-actions"><button type="button" class="btn btn-primary" data-builder-next="2">Continue to Questions</button></div>
    </div>
  </section>

  <section class="card card-body evaluation-builder-panel evaluation-step-panel mb-4" data-builder-step-panel="2" aria-labelledby="evaluationSectionsHeading" hidden>
    <div class="evaluation-builder-panel-heading evaluation-builder-panel-heading--sections">
      <div><h2 id="evaluationSectionsHeading">Sections &amp; criteria</h2><p>Organize the questions reviewers will score. Drag the handle to change the order.</p></div>
      <span class="evaluation-builder-count"><?= count($sections) ?> <?= count($sections)===1 ? 'section' : 'sections' ?></span>
    </div>

    <div id="sectionsWrap" data-form="<?= $build['id'] ?>">
      <?php foreach ($sections as $s):
        $crits = $criteriaBySection[(int)$s['id']] ?? []; ?>
      <div class="eval-section evaluation-section-card" draggable="true" data-id="<?= $s['id'] ?>">
        <div class="evaluation-section-heading">
          <span class="drag-handle evaluation-drag-handle" title="Drag to reorder" aria-label="Drag section to reorder">⠿</span>
          <form method="POST" class="evaluation-builder-form evaluation-builder-form--rename" data-evaluation-wizard><?= csrf_field() ?>
              <input type="hidden" name="action" value="rename_section">
              <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
              <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
              <label class="form-label" for="sectionTitle<?= (int)$s['id'] ?>">Section name</label>
              <input type="text" id="sectionTitle<?= (int)$s['id'] ?>" name="title" class="form-control" style="font-weight:700" value="<?= e($s['title']) ?>">
              <button class="btn btn-secondary btn-sm">Save name</button>
          </form>
          <form method="POST" data-evaluation-wizard data-confirm-message="Delete this section and its criteria?" class="evaluation-section-remove"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_section">
            <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
            <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
            <button class="btn btn-ghost btn-sm" aria-label="Remove section" title="Remove section">Remove section</button>
          </form>
        </div>

        <div class="eval-criteria evaluation-criteria-list" data-section="<?= $s['id'] ?>">
          <?php foreach ($crits as $cr): ?>
          <div class="eval-criterion evaluation-criterion-row" draggable="true" data-id="<?= $cr['id'] ?>">
            <span class="drag-handle evaluation-drag-handle" title="Drag to reorder" aria-label="Drag criterion to reorder">⠿</span>
            <span class="evaluation-criterion-label"><?= e($cr['label']) ?></span>
            <?php if ($build['score_mode']==='points'): ?><span class="evaluation-criterion-points"><?= (int)$cr['weight'] ?> pts max</span><?php endif; ?>
            <form method="POST" data-evaluation-wizard data-confirm-message="Delete this criterion?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_criterion">
              <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
              <input type="hidden" name="criterion_id" value="<?= $cr['id'] ?>">
              <button class="btn btn-ghost btn-sm" aria-label="Remove criterion" title="Remove criterion">✕</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>

        <form method="POST" class="evaluation-builder-form evaluation-builder-form--criterion" data-evaluation-wizard><?= csrf_field() ?>
          <input type="hidden" name="action" value="add_criterion">
          <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
          <input type="hidden" name="section_id" value="<?= $s['id'] ?>">
          <div class="evaluation-builder-field"><label class="form-label" for="criterionLabel<?= (int)$s['id'] ?>">New criterion</label>
          <input type="text" id="criterionLabel<?= (int)$s['id'] ?>" name="criterion_label" class="form-control" placeholder="e.g. Communication skills" required>
          </div>
          <?php if ($build['score_mode']==='points'): ?>
          <div class="evaluation-builder-field"><label class="form-label" for="criterionPoints<?= (int)$s['id'] ?>">Maximum points</label>
          <input type="number" id="criterionPoints<?= (int)$s['id'] ?>" name="maximum_points" class="form-control" min="1" max="100" step="1" required>
          </div>
          <?php endif; ?>
          <button class="btn btn-secondary">Add criterion</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" class="evaluation-builder-form evaluation-builder-form--add-section" data-evaluation-wizard><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_section">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <div class="evaluation-builder-field"><label class="form-label" for="newSectionTitle">Add a section</label>
      <input type="text" id="newSectionTitle" name="section_title" class="form-control" placeholder="e.g. Professionalism" required>
      </div><button class="btn btn-primary">Add section</button>
    </form>
    <div class="evaluation-step-navigation"><button type="button" class="btn btn-secondary" data-builder-previous="1">Back</button><button type="button" class="btn btn-primary" data-builder-next="3">Continue to rating rules</button></div>
  </section>

  <section class="card card-body evaluation-builder-panel evaluation-step-panel mb-4" data-builder-step-panel="3" aria-labelledby="evaluationRulesHeading" hidden>
    <div class="evaluation-builder-panel-heading"><div><h2 id="evaluationRulesHeading">Rating rules</h2><p>Define the rating or result shown for each score range.</p></div></div>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
      <table data-skip-table-tools="true">
        <thead><tr><th>Score Range</th><th>Equivalent</th><th>Description</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($rules)): ?><tr><td colspan="4" class="text-center text-muted">No rating rules yet. Example: 96–100 → 1.25</td></tr>
          <?php else: ?>
            <?php foreach ($rules as $r): ?>
            <tr>
              <td><?= (int)$r['score_min'] ?>–<?= (int)$r['score_max'] ?></td>
              <td><strong><?= $r['equivalent'] !== null ? e($r['equivalent']) : e($r['description']) ?></strong></td>
              <td class="text-sm text-muted"><?= e($r['description']) ?></td>
              <td>
                <div class="table-actions">
                  <form method="POST" data-evaluation-wizard data-confirm-message="Delete this rule?"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_rule">
                    <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
                    <input type="hidden" name="rule_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="table-action-icon is-danger" title="Delete rating rule" aria-label="Delete rating rule">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6"/></svg>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <form method="POST" class="evaluation-builder-form evaluation-builder-form--rule" data-evaluation-wizard><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_rule">
      <input type="hidden" name="form_id" value="<?= $build['id'] ?>">
      <div class="evaluation-builder-field"><label class="form-label" for="ruleScoreMin">Minimum score</label>
      <input type="number" id="ruleScoreMin" name="score_min" class="form-control" min="0" max="100" required></div>
      <div class="evaluation-builder-field"><label class="form-label" for="ruleScoreMax">Maximum score</label>
      <input type="number" id="ruleScoreMax" name="score_max" class="form-control" min="0" max="100" required></div>
      <?php if ($build['score_mode']==='points'): ?>
      <input type="hidden" name="equivalent" value="">
      <?php else: ?>
      <div class="evaluation-builder-field"><label class="form-label" for="ruleEquivalent">Equivalent rating</label>
      <input type="number" id="ruleEquivalent" step="0.01" name="equivalent" class="form-control" required></div>
      <?php endif; ?>
      <div class="evaluation-builder-field"><label class="form-label" for="ruleDescription">Description (optional)</label>
      <input type="text" id="ruleDescription" name="description" class="form-control"></div>
      <button class="btn btn-secondary">+ Add rating rule</button>
    </form>
    <div class="evaluation-step-navigation"><button type="button" class="btn btn-secondary" data-builder-previous="2">Back</button><button type="button" class="btn btn-primary" data-builder-next="4">Continue to Review</button></div>
  </section>

  <section class="card card-body evaluation-builder-panel evaluation-step-panel mb-4" data-builder-step-panel="4" aria-labelledby="evaluationReviewHeading" hidden>
    <div class="evaluation-builder-panel-heading"><div><h2 id="evaluationReviewHeading">Review your evaluation form</h2><p>Check the form setup before you publish it for company reviewers.</p></div></div>
    <div class="evaluation-review-card">
      <div class="evaluation-review-copy"><span class="evaluation-review-eyebrow">Form summary</span><h3 data-review-form-title><?= e($build['title']) ?></h3><p data-review-form-description><?= trim((string)$build['description']) !== '' ? e($build['description']) : 'No description added.' ?></p></div>
      <span class="evaluation-review-mode"><?= $build['score_mode']==='points' ? 'Points · 100 total' : 'Percentage · 0–100' ?></span>
    </div>
    <div class="evaluation-review-stats">
      <div><strong><?= count($sections) ?></strong><span><?= count($sections)===1 ? 'section' : 'sections' ?></span></div>
      <div><strong><?= $totalCriteria ?></strong><span><?= $totalCriteria===1 ? 'criterion' : 'criteria' ?></span></div>
      <div><strong><?= count($rules) ?></strong><span><?= count($rules)===1 ? 'rating rule' : 'rating rules' ?></span></div>
    </div>
    <?php if ($build['score_mode']==='points'): ?>
    <p class="evaluation-review-note <?= $pointsAssigned===100 ? 'is-ready' : 'is-warning' ?>"><?= $pointsAssigned===100 ? 'Your criterion maximums add up to 100 points.' : 'Your criterion maximums currently add up to '.$pointsAssigned.' points. They must total 100 before publishing.' ?></p>
    <?php endif; ?>
    <p class="evaluation-review-unsaved" data-review-unsaved hidden>Form details have changed. Save the draft before publishing.</p>
    <div class="evaluation-review-footer">
      <button type="button" class="btn btn-secondary" data-builder-previous="3">Back to rating rules</button>
      <div class="evaluation-review-actions">
        <button type="button" class="btn btn-secondary" onclick="openModal('previewModal')">Preview form</button>
        <form method="POST" class="evaluation-save-draft-form" data-confirm-skip>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_draft_and_return"><input type="hidden" name="form_id" value="<?= (int)$build['id'] ?>"><input type="hidden" name="score_mode" value="<?= e($build['score_mode']) ?>"><input type="hidden" name="title" value="<?= e($build['title']) ?>"><input type="hidden" name="description" value="<?= e($build['description']) ?>">
          <button type="submit" class="btn btn-secondary">Save draft</button>
        </form>
        <form method="POST" data-evaluation-wizard data-confirm-title="Publish this evaluation form?" data-confirm-message="Publish this information for its intended users?" data-confirm-label="Publish">
          <?= csrf_field() ?><input type="hidden" name="action" value="publish_form"><input type="hidden" name="form_id" value="<?= (int)$build['id'] ?>">
          <button class="btn btn-primary" data-builder-publish data-builder-publish-ready="<?= ($totalCriteria>0 && ($build['score_mode']!=='points' || $pointsAssigned===100)) ? 'true' : 'false' ?>" <?= ($totalCriteria===0 || ($build['score_mode']==='points' && $pointsAssigned!==100)) ? 'disabled title="Add criteria and meet the scoring total before publishing."' : '' ?>>Publish Form</button>
        </form>
      </div>
    </div>
  </section>

  <!-- Preview Modal -->
  <div class="modal-overlay" id="previewModal">
    <div class="modal modal-lg">
      <div class="modal-title"><?= e($build['title']) ?> — Preview</div>
      <p class="modal-sub">This is how the evaluation form will appear to the company</p>
      <?php foreach ($sections as $s): $crits = $criteriaBySection[(int)$s['id']] ?? []; ?>
      <div class="section-title mb-2" style="margin-top:12px"><?= e($s['title']) ?></div>
      <?php foreach ($crits as $cr): ?>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between"><span class="text-sm"><?= e($cr['label']) ?></span><span class="font-mono text-sm text-muted">0–<?= $build['score_mode']==='points' ? (int)$cr['weight'] : ($build['score_mode']==='rating'?'5':'100') ?></span></div>
        <input type="range" class="score-slider" style="width:100%" disabled>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($rules): ?>
      <div class="section-title mb-2" style="margin-top:12px">Rating Guide</div>
      <?php foreach ($rules as $r): ?>
      <div class="text-sm text-muted"><?= (int)$r['score_min'] ?>–<?= (int)$r['score_max'] ?> → <strong><?= $r['equivalent'] !== null ? e($r['equivalent']) : e($r['description']) ?></strong> <?= e($r['description']) ?></div>
      <?php endforeach; ?>
      <?php endif; ?>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('previewModal')">Close</button></div>
    </div>
  </div>

  <script>
    // Keep the user's current setup step when a builder action reloads the page.
    (() => {
      const stepper = document.querySelector('[data-evaluation-stepper]');
      if (!stepper) return;
      const formId = stepper.dataset.formId;
      const storageKey = 'ojtrack-evaluation-builder-step-' + formId;
      const steps = [...stepper.querySelectorAll('[data-builder-step]')];
      const panels = [...document.querySelectorAll('[data-builder-step-panel]')];
      const setStep = (value, shouldScroll = true) => {
        const step = Math.max(1, Math.min(4, Number(value) || 1));
        sessionStorage.setItem(storageKey, String(step));
        steps.forEach(button => {
          const buttonStep = Number(button.dataset.builderStep);
          button.classList.toggle('is-active', buttonStep === step);
          button.classList.toggle('is-complete', buttonStep < step);
          if (buttonStep === step) button.setAttribute('aria-current', 'step');
          else button.removeAttribute('aria-current');
        });
        panels.forEach(panel => { panel.hidden = Number(panel.dataset.builderStepPanel) !== step; });
        if (shouldScroll) stepper.scrollIntoView({behavior: 'smooth', block: 'start'});
      };
      const continueToStep = async targetValue => {
        const targetStep = Number(targetValue);
        const currentStep = Number(stepper.querySelector('[aria-current="step"]')?.dataset.builderStep || 1);
        if (!targetStep || targetStep === currentStep) return;
        if (targetStep < currentStep) { setStep(targetStep); return; }
        const targetName = steps.find(button => Number(button.dataset.builderStep) === targetStep)
          ?.querySelector('.evaluation-step-title')?.textContent?.trim() || 'the next step';
        if (currentStep === 1) {
          const confirmed = await confirmOJTrack({
            title: 'Save this step?',
            message: 'Save your form details and continue to ' + targetName + '?',
            confirmLabel: 'Save and continue'
          });
          if (!confirmed || !draftForm) return;
          syncDetails();
          sessionStorage.setItem(storageKey, String(targetStep));
          // Step progression saves the details, then reloads the builder at
          // the requested step. Only the explicit Review "Save draft" action
          // should return to the forms list.
          draftForm.elements.namedItem('action').value = 'save_form';
          draftForm.requestSubmit();
          return;
        }
        const savedStepName = currentStep === 2 ? 'Questions' : 'Rating rules';
        const confirmed = await confirmOJTrack({
          title: 'Continue to ' + targetName + '?',
          message: 'Your ' + savedStepName.toLowerCase() + ' changes are saved as you add them. Continue to ' + targetName + '?',
          confirmLabel: 'Continue'
        });
        if (confirmed) setStep(targetStep);
      };
      steps.forEach(button => button.addEventListener('click', () => continueToStep(button.dataset.builderStep)));
      document.querySelectorAll('[data-builder-next]').forEach(button => {
        button.addEventListener('click', () => continueToStep(button.dataset.builderNext));
      });
      document.querySelectorAll('[data-builder-previous]').forEach(button => {
        button.addEventListener('click', () => setStep(button.dataset.builderPrevious));
      });
      const detailsPanel = document.querySelector('.evaluation-builder-form--details');
      const draftForm = document.querySelector('.evaluation-save-draft-form');
      const titleField = detailsPanel?.querySelector('[name="title"]');
      const descriptionField = detailsPanel?.querySelector('[name="description"]');
      const reviewTitle = document.querySelector('[data-review-form-title]');
      const reviewDescription = document.querySelector('[data-review-form-description]');
      const unsavedNote = document.querySelector('[data-review-unsaved]');
      const publishButton = document.querySelector('[data-builder-publish]');
      const publishAllowed = publishButton?.dataset.builderPublishReady === 'true';
      const originalTitle = titleField?.value || '';
      const originalDescription = descriptionField?.value || '';
      let detailsChanged = false;
      const syncDetails = () => {
        if (!titleField || !descriptionField || !draftForm) return;
        draftForm.elements.namedItem('title').value = titleField.value;
        draftForm.elements.namedItem('description').value = descriptionField.value;
        if (reviewTitle) reviewTitle.textContent = titleField.value.trim() || 'Untitled evaluation form';
        if (reviewDescription) reviewDescription.textContent = descriptionField.value.trim() || 'No description added.';
      };
      [titleField, descriptionField].forEach(field => field?.addEventListener('input', () => {
        detailsChanged = (titleField?.value || '') !== originalTitle || (descriptionField?.value || '') !== originalDescription;
        syncDetails();
        if (unsavedNote) unsavedNote.hidden = !detailsChanged;
        if (publishButton) publishButton.disabled = !publishAllowed || detailsChanged;
      }));
      draftForm?.addEventListener('submit', syncDetails);
      draftForm?.querySelector('button[type="submit"]')?.addEventListener('click', async event => {
        event.preventDefault();
        const saveButton = event.currentTarget;
        const confirmed = await confirmOJTrack({
          title: 'Save this draft?',
          message: 'Save the form details you entered so you can continue editing later?',
          confirmLabel: 'Save draft'
        });
        if (confirmed) {
          syncDetails();
          draftForm.requestSubmit(saveButton);
        }
      });
      setStep(sessionStorage.getItem(storageKey) || 1, false);
    })();

    // Simple drag & drop reorder for sections and criteria
    function enableDrag(containerSel, itemSel, action, formId) {
      const container = document.querySelector(containerSel);
      if (!container) return;
      let dragged = null;
      container.querySelectorAll(itemSel).forEach(item => {
        item.addEventListener('dragstart', e => { dragged = item; e.dataTransfer.effectAllowed = 'move'; });
        item.addEventListener('dragover', e => { e.preventDefault(); return false; });
        item.addEventListener('drop', async e => {
          e.preventDefault();
          if (dragged && dragged !== item && dragged.parentNode === item.parentNode) {
            if (!await confirmOJTrack({title: 'Change evaluation order?', message: 'Save the new order of these evaluation items?', confirmLabel: 'Save Order'})) return;
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
  <?php if ($active_forms): ?>
  <div class="evaluation-selection-toolbar" id="evaluationSelectionToolbar" hidden>
    <span class="evaluation-selection-count" id="evaluationSelectionCount" aria-live="polite">0 forms selected</span>
    <div class="evaluation-selection-actions">
      <button class="btn btn-ghost btn-sm" type="button" id="selectAllEvaluationForms">Select all active</button>
      <button class="btn btn-ghost btn-sm" type="button" id="clearEvaluationFormSelection">Clear selection</button>
      <form method="POST" id="archiveSelectedFormsForm" data-confirm-title="Archive selected forms?" data-confirm-label="Archive forms" data-confirm-message="Archive the selected active evaluation forms? Existing evaluation requests will remain available."><?= csrf_field() ?><input type="hidden" name="action" value="archive_selected_forms">
        <button class="btn btn-primary btn-sm" type="submit" id="archiveSelectedEvaluationForms">Archive form</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <div class="grid evaluation-form-grid">
    <?php if ($forms): ?>
    <div class="card card-body evaluation-empty-filter-state" id="evaluationFormFilterEmpty" hidden>
      <div class="empty-state"><p>No forms match this status filter.</p></div>
    </div>
    <?php endif; ?>
    <?php if (empty($forms)): ?>
      <div class="card card-body evaluation-empty-state"><div class="empty-state"><p>No evaluation forms yet. Create a scorecard to get started.</p><button class="btn btn-primary" type="button" onclick="openModal('newFormModal')">Create evaluation form</button></div></div>
    <?php else: ?>
      <?php foreach ($forms as $f): ?>
      <article class="card card-body evaluation-form-card" data-form-status="<?= e($f['status']) ?>">
        <button class="evaluation-card-open" type="button" aria-label="View details for <?= e($f['title']) ?>" aria-haspopup="dialog" aria-controls="evaluationPreview<?= (int)$f['id'] ?>" onclick="openModal('evaluationPreview<?= (int)$f['id'] ?>')"></button>
        <?php if ($f['status'] === 'active'): ?>
        <div class="evaluation-card-selection-row">
          <label class="evaluation-card-select" title="Select this form to archive">
            <input type="checkbox" name="form_ids[]" form="archiveSelectedFormsForm" data-evaluation-card-select value="<?= (int)$f['id'] ?>" aria-label="Select <?= e($f['title']) ?> to archive">
            <span class="sr-only">Select this evaluation form</span>
          </label>
        </div>
        <?php endif; ?>
        <div class="evaluation-form-card-heading">
          <div class="evaluation-form-title-wrap">
            <strong class="evaluation-form-title"><?= e($f['title']) ?></strong>
            <span class="evaluation-form-version">Version <?= (int)$f['version'] ?> · <?= $f['score_mode']==='points' ? 'Points' : 'Percentage' ?> scoring</span>
          </div>
          <div class="evaluation-form-header-actions">
          <span class="badge <?= $f['status']==='active'?'badge-approved':($f['status']==='archived'?'badge-rejected':'badge-pending') ?>"><?= ucfirst($f['status']) ?></span>
          <details class="evaluation-more-actions">
            <summary aria-label="More form actions" title="More actions"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg></summary>
            <div class="evaluation-more-actions-menu">
              <button type="button" onclick="this.closest('details').open=false;openModal('evaluationPreview<?= (int)$f['id'] ?>')">Preview form</button>
              <?php if ($f['status'] === 'active'): ?>
              <form method="POST" class="evaluation-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="edit_active"><input type="hidden" name="form_id" value="<?= $f['id'] ?>"><button type="submit">Create v<?= (int)$f['version'] + 1 ?></button></form>
              <?php else: ?>
              <a href="?build=<?= $f['id'] ?>">Edit draft</a>
              <?php endif; ?>
              <form method="POST" class="evaluation-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>"><button type="submit">Duplicate as draft</button></form>
              <form method="POST" class="evaluation-inline-form" data-confirm-message="<?= $f['status']==='archived'?'Restore this form?':'Archive this form? New assignments will stop; existing requests remain available.' ?>"><?= csrf_field() ?>
                <input type="hidden" name="action" value="archive_form"><input type="hidden" name="form_id" value="<?= $f['id'] ?>">
                <button type="submit"><?= $f['status']==='archived'?'Restore form':'Archive form' ?></button>
              </form>
            </div>
          </details>
          </div>
        </div>
        <p class="evaluation-form-description<?= trim((string)$f['description'])===''?' is-empty':'' ?>"><?= trim((string)$f['description'])!=='' ? e($f['description']) : 'No instructions added.' ?></p>
        <div class="evaluation-form-progress" aria-label="<?= (int)$f['done_count'] ?> of <?= (int)$f['sub_count'] ?> evaluation requests completed">
          <div><strong><?= (int)$f['done_count'] ?></strong><span>completed</span></div>
          <div><strong><?= max(0,(int)$f['sub_count']-(int)$f['done_count']) ?></strong><span>not submitted</span></div>
          <div><strong><?= (int)$f['sub_count'] ?></strong><span>assigned</span></div>
        </div>
      </article>
      <?php endforeach; ?>
      <?php foreach ($forms as $f):
        $previewSections = normalized_eval_sections((int)$f['id']);
        $previewRules = normalized_eval_rules((int)$f['id']);
      ?>
      <div class="modal-overlay evaluation-preview-overlay" id="evaluationPreview<?= (int)$f['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="evaluationPreviewTitle<?= (int)$f['id'] ?>">
        <section class="modal modal-lg evaluation-preview-modal">
          <header class="evaluation-preview-heading">
            <div class="evaluation-preview-heading-copy"><span class="evaluation-preview-eyebrow">Evaluation form preview</span><h2 id="evaluationPreviewTitle<?= (int)$f['id'] ?>"><?= e($f['title']) ?></h2></div>
            <span class="badge <?= $f['status']==='active'?'badge-approved':($f['status']==='archived'?'badge-rejected':'badge-pending') ?>"><?= ucfirst($f['status']) ?></span>
            <button type="button" class="evaluation-preview-close" aria-label="Close preview" onclick="closeModal('evaluationPreview<?= (int)$f['id'] ?>')">×</button>
          </header>
          <div class="evaluation-preview-content">
            <div class="evaluation-preview-meta"><span>Version <?= (int)$f['version'] ?></span><span><?= $f['score_mode']==='points' ? 'Points out of 100' : 'Percentage (0–100)' ?></span><span><?= (int)$f['sub_count'] ?> assigned</span></div>
            <?php if (trim((string)$f['description'])!==''): ?><details class="evaluation-preview-description"><summary>Form instructions</summary><p><?= e($f['description']) ?></p></details><?php endif; ?>
            <div class="evaluation-preview-sections">
              <?php if (!$previewSections): ?><div class="empty-state"><p>This form has no sections or questions yet.</p></div><?php endif; ?>
              <?php foreach ($previewSections as $previewSection): $previewCriteria = normalized_eval_criteria((int)$previewSection['id']); ?>
              <section class="evaluation-preview-section"><h3><?= e($previewSection['title']) ?></h3>
                <?php if (!$previewCriteria): ?><p class="text-xs text-muted">No questions in this section.</p><?php endif; ?>
                <?php foreach ($previewCriteria as $previewCriterion): ?>
                <div class="evaluation-preview-question"><span><?= e($previewCriterion['label']) ?></span><strong>0–<?= $f['score_mode']==='points' ? (int)$previewCriterion['weight'].' pts' : '100%' ?></strong></div>
                <?php endforeach; ?>
              </section>
              <?php endforeach; ?>
            </div>
            <?php if ($previewRules): ?><section class="evaluation-preview-rules"><h3>Rating guide</h3><?php foreach ($previewRules as $previewRule): ?><div><strong><?= (int)$previewRule['score_min'] ?>–<?= (int)$previewRule['score_max'] ?></strong><span><?= e($previewRule['description'] ?: ($previewRule['equivalent'] ?? '')) ?></span></div><?php endforeach; ?></section><?php endif; ?>
          </div>
          <footer class="modal-footer evaluation-preview-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('evaluationPreview<?= (int)$f['id'] ?>')">Close</button></footer>
        </section>
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
    <form method="POST" data-evaluation-wizard><?= csrf_field() ?>
      <input type="hidden" name="action" value="save_form">
      <div class="form-group">
        <label class="form-label">Form Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. OJT Performance Evaluation">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Explain what the company should assess."></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Scoring Mode</label>
        <select name="score_mode" class="form-control">
          <option value="percentage">Percentage (0–100)</option>
          <option value="points">Points (total 100)</option>
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
  <div class="modal modal-lg evaluation-assignment-modal">
    <div class="modal-title">Send Form to Company</div>
    <p class="modal-sub">Choose an active evaluation form and one or more companies related to your students.</p>
    <form method="POST" action="" id="evaluationAssignmentForm"><?= csrf_field() ?>
      <input type="hidden" name="action" value="send_form">
      <div class="form-group">
        <div class="send-requirements-group-heading">
          <label class="form-label" id="evaluationFormsLabel">Evaluation Forms <span class="text-danger">*</span></label>
          <span class="send-requirements-hint">Choose one or more active forms to send.</span>
        </div>
        <?php if ($active_forms): ?>
          <details class="send-multiselect evaluation-form-multiselect" data-evaluation-form-multi-select data-placeholder="Choose evaluation forms">
            <summary class="send-multiselect-trigger" aria-labelledby="evaluationFormsLabel">
              <span class="send-multiselect-summary" id="evaluationFormSelectionCount">Choose evaluation forms</span>
              <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
            </summary>
            <div class="send-multiselect-panel">
              <div class="send-multiselect-tools evaluation-company-picker-tools">
                <input type="search" id="evaluationFormSearch" class="form-control send-multiselect-search"
                       placeholder="Search evaluation forms..." aria-label="Search evaluation forms" aria-controls="evaluationFormList" autocomplete="off">
                <div class="evaluation-company-picker-selection-tools">
                  <label class="send-multiselect-select-all"><input type="checkbox" id="evaluationFormSelectAll"> <span>Select all matching</span></label>
                  <button type="button" class="btn btn-ghost btn-sm evaluation-company-unselect-all" id="evaluationUnselectAllForms">Unselect all</button>
                </div>
              </div>
              <div class="send-selection-list evaluation-company-list evaluation-form-list" id="evaluationFormList" role="group" aria-labelledby="evaluationFormsLabel">
                <?php foreach ($active_forms as $f): ?>
                  <label class="evaluation-company-option evaluation-form-option" data-form-search="<?= e($f['title']) ?>">
                    <input type="checkbox" name="form_ids[]" value="<?= (int)$f['id'] ?>">
                    <span class="send-option-copy"><strong><?= e($f['title']) ?></strong><small>Version <?= (int)$f['version'] ?> · <?= $f['score_mode']==='points' ? 'Points scoring' : 'Percentage scoring' ?></small></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="send-multiselect-no-results" id="evaluationFormNoResults" hidden>No active forms match your search.</p>
            </div>
          </details>
        <?php else: ?>
          <div class="evaluation-form-empty">No active evaluation forms are available. Publish a form before assigning it.</div>
        <?php endif; ?>
        <div class="text-danger evaluation-company-error" id="evaluationFormError" role="alert" hidden>Select at least one evaluation form.</div>
      </div>
      <div class="form-group">
        <div class="send-requirements-group-heading">
          <label class="form-label" id="evaluationCompaniesLabel">Companies <span class="text-danger">*</span></label>
          <span class="send-requirements-hint">Choose the companies that should evaluate their assigned students.</span>
        </div>
        <?php if ($related_companies): ?>
          <details class="send-multiselect evaluation-company-multiselect" data-company-multi-select data-placeholder="Choose companies">
            <summary class="send-multiselect-trigger" aria-labelledby="evaluationCompaniesLabel">
              <span class="send-multiselect-summary" id="evaluationCompanySelectionCount">Choose companies</span>
              <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
            </summary>
            <div class="send-multiselect-panel">
              <div class="send-multiselect-tools evaluation-company-picker-tools">
                <input type="search" id="evaluationCompanySearch" class="form-control send-multiselect-search"
                       placeholder="Search companies..." aria-label="Search companies" aria-controls="evaluationCompanyList" autocomplete="off">
                <div class="evaluation-company-picker-selection-tools">
                  <label class="send-multiselect-select-all"><input type="checkbox" id="evaluationCompanySelectAll"> <span>Select all matching</span></label>
                  <button type="button" class="btn btn-ghost btn-sm evaluation-company-unselect-all" id="evaluationUnselectAllCompanies">Unselect all</button>
                </div>
              </div>
              <div class="send-selection-list evaluation-company-list" id="evaluationCompanyList" role="group" aria-labelledby="evaluationCompaniesLabel">
                <?php foreach ($related_companies as $rc): ?>
                  <label class="evaluation-company-option" data-company-search="<?= e($rc['company_name']) ?>">
                    <input type="checkbox" name="company_ids[]" value="<?= (int)$rc['id'] ?>">
                    <span class="send-option-copy"><?= e($rc['company_name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="send-multiselect-no-results" id="evaluationCompanyNoResults" hidden>No companies match your search.</p>
            </div>
          </details>
          <div class="text-xs text-muted mt-1">The form is sent to each selected company for its students assigned to you.</div>
        <?php else: ?>
          <div class="evaluation-company-empty evaluation-company-empty-static">No companies are currently connected to your students in the active term.</div>
        <?php endif; ?>
        <div class="text-danger evaluation-company-error" id="evaluationCompanyError" role="alert" hidden>Select at least one company.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('sendFormModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" <?= $related_companies && $active_forms ? '' : 'disabled' ?>>Send</button>
      </div>
    </form>
  </div>
</div>

<!-- Submissions Modal -->
<div class="modal-overlay" id="manageSubsModal">
  <div class="modal modal-lg">
    <div class="modal-title">Form Submissions</div>
    <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
      <table data-skip-table-tools="true">
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
              <td><?= $s['overall_score'] !== null ? number_format($s['overall_score'], 1) : '—' ?>
                <?php if ($s['overall_score'] !== null && ($s['score_mode'] ?? '')==='points'):
                  $resultRule=normalized_eval_score_rule((float)$s['overall_score'],normalized_eval_rules((int)$s['form_id']),'points'); ?>
                  <div class="text-xs font-bold"><?= e($resultRule['description'] ?? '') ?></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('manageSubsModal')">Close</button></div>
  </div>
</div>

<script>
function filterEvaluationForms(status) {
  const cards = [...document.querySelectorAll('.evaluation-form-card')];
  const empty = document.querySelector('#evaluationFormFilterEmpty');
  let visibleCount = 0;
  cards.forEach(card => {
    const visible = status === 'all' || card.dataset.formStatus === status;
    card.hidden = !visible;
    if (visible) visibleCount++;
  });
  if (empty) empty.hidden = visibleCount > 0;

  // Avoid archiving a selected card that became hidden by the new filter.
  document.querySelectorAll('[data-evaluation-card-select]:checked').forEach(input => { input.checked = false; });
  updateEvaluationCardSelection();
}

function updateEvaluationCardSelection() {
  const checkboxes = [...document.querySelectorAll('[data-evaluation-card-select]')];
  const selected = checkboxes.filter(input => input.checked);
  const toolbar = document.querySelector('#evaluationSelectionToolbar');
  const count = document.querySelector('#evaluationSelectionCount');
  if (!toolbar || !count) return;
  toolbar.hidden = selected.length === 0;
  count.textContent = selected.length + (selected.length === 1 ? ' form selected' : ' forms selected');
  const archiveButton = document.querySelector('#archiveSelectedEvaluationForms');
  if (archiveButton) archiveButton.textContent = selected.length === 1 ? 'Archive form' : 'Archive forms';
  document.querySelectorAll('.evaluation-form-card').forEach(card => {
    card.classList.toggle('is-selected', !!card.querySelector('[data-evaluation-card-select]:checked'));
  });
}

const evaluationFormStatusFilter = document.querySelector('#evaluationFormStatusFilter');
evaluationFormStatusFilter?.addEventListener('change', event => {
  filterEvaluationForms(event.target.value);
  event.target.closest('.evaluation-status-filter').open = false;
});
filterEvaluationForms(evaluationFormStatusFilter?.value || 'active');
document.addEventListener('click', event => {
  const filter = document.querySelector('.evaluation-status-filter[open]');
  if (filter && !filter.contains(event.target)) filter.open = false;
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  const filter = document.querySelector('.evaluation-status-filter[open]');
  if (!filter) return;
  filter.open = false;
  filter.querySelector('summary')?.focus({preventScroll: true});
});

function updateEvaluationCompanyPicker(form) {
  const picker = form?.querySelector('[data-company-multi-select]');
  if (!picker) return;
  const search = picker.querySelector('#evaluationCompanySearch');
  const list = picker.querySelector('#evaluationCompanyList');
  const options = [...list.querySelectorAll('.evaluation-company-option')];
  const checkboxes = options.map(option => option.querySelector('input[type="checkbox"]'));
  const query = (search?.value || '').trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
  let visibleCount = 0;
  options.forEach(option => {
    const searchable = (option.dataset.companySearch || option.textContent).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    const visible = searchable.includes(query);
    option.hidden = !visible;
    if (visible) visibleCount++;
  });
  picker.querySelector('#evaluationCompanyNoResults').hidden = visibleCount > 0;
  const selectedCount = checkboxes.filter(input => input.checked).length;
  const summary = picker.querySelector('#evaluationCompanySelectionCount');
  summary.textContent = selectedCount ? selectedCount + (selectedCount === 1 ? ' company selected' : ' companies selected') : picker.dataset.placeholder;
  summary.classList.toggle('has-selection', selectedCount > 0);
  const visibleCheckboxes = options.filter(option => !option.hidden).map(option => option.querySelector('input[type="checkbox"]'));
  const selectAll = picker.querySelector('#evaluationCompanySelectAll');
  const checkedVisible = visibleCheckboxes.filter(input => input.checked).length;
  selectAll.checked = visibleCheckboxes.length > 0 && checkedVisible === visibleCheckboxes.length;
  selectAll.indeterminate = checkedVisible > 0 && checkedVisible < visibleCheckboxes.length;
  selectAll.disabled = visibleCheckboxes.length === 0;
  if (selectedCount) form.querySelector('#evaluationCompanyError').hidden = true;
}

function updateEvaluationFormPicker(form) {
  const picker = form?.querySelector('[data-evaluation-form-multi-select]');
  if (!picker) return;
  const search = picker.querySelector('#evaluationFormSearch');
  const list = picker.querySelector('#evaluationFormList');
  const options = [...list.querySelectorAll('.evaluation-form-option')];
  const checkboxes = options.map(option => option.querySelector('input[type="checkbox"]'));
  const query = (search?.value || '').trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
  let visibleCount = 0;
  options.forEach(option => {
    const searchable = (option.dataset.formSearch || option.textContent).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
    const visible = searchable.includes(query);
    option.hidden = !visible;
    if (visible) visibleCount++;
  });
  picker.querySelector('#evaluationFormNoResults').hidden = visibleCount > 0;
  const selectedCount = checkboxes.filter(input => input.checked).length;
  const summary = picker.querySelector('#evaluationFormSelectionCount');
  summary.textContent = selectedCount ? selectedCount + (selectedCount === 1 ? ' form selected' : ' forms selected') : picker.dataset.placeholder;
  summary.classList.toggle('has-selection', selectedCount > 0);
  const visibleCheckboxes = options.filter(option => !option.hidden).map(option => option.querySelector('input[type="checkbox"]'));
  const selectAll = picker.querySelector('#evaluationFormSelectAll');
  const checkedVisible = visibleCheckboxes.filter(input => input.checked).length;
  selectAll.checked = visibleCheckboxes.length > 0 && checkedVisible === visibleCheckboxes.length;
  selectAll.indeterminate = checkedVisible > 0 && checkedVisible < visibleCheckboxes.length;
  selectAll.disabled = visibleCheckboxes.length === 0;
  if (selectedCount) form.querySelector('#evaluationFormError').hidden = true;
}

document.addEventListener('input', event => {
  if (event.target.matches('#evaluationCompanySearch')) updateEvaluationCompanyPicker(event.target.closest('#evaluationAssignmentForm'));
  if (event.target.matches('#evaluationFormSearch')) updateEvaluationFormPicker(event.target.closest('#evaluationAssignmentForm'));
});
document.addEventListener('change', event => {
  if (event.target.matches('[data-evaluation-card-select]')) {
    updateEvaluationCardSelection();
    return;
  }
  const form = event.target.closest('#evaluationAssignmentForm');
  if (!form) return;
  const picker = form.querySelector('[data-company-multi-select]');
  const formPicker = form.querySelector('[data-evaluation-form-multi-select]');
  if (event.target.matches('#evaluationFormSelectAll')) {
    formPicker.querySelectorAll('.evaluation-form-option:not([hidden]) input[type="checkbox"]').forEach(input => { input.checked = event.target.checked; });
  }
  if (event.target.matches('#evaluationFormList input[type="checkbox"], #evaluationFormSelectAll')) updateEvaluationFormPicker(form);
  if (event.target.matches('#evaluationCompanySelectAll')) {
    picker.querySelectorAll('.evaluation-company-option:not([hidden]) input[type="checkbox"]').forEach(input => { input.checked = event.target.checked; });
  }
  if (event.target.matches('#evaluationCompanyList input[type="checkbox"], #evaluationCompanySelectAll')) updateEvaluationCompanyPicker(form);
});
document.addEventListener('click', event => {
  if (event.target.closest('#selectAllEvaluationForms')) {
    document.querySelectorAll('[data-evaluation-card-select]').forEach(input => { input.checked = true; });
    updateEvaluationCardSelection();
    return;
  }
  if (event.target.closest('#clearEvaluationFormSelection')) {
    document.querySelectorAll('[data-evaluation-card-select]').forEach(input => { input.checked = false; });
    updateEvaluationCardSelection();
    return;
  }
  const unselectAll = event.target.closest('#evaluationUnselectAllCompanies');
  if (unselectAll) {
    const form = unselectAll.closest('#evaluationAssignmentForm');
    form.querySelectorAll('#evaluationCompanyList input[type="checkbox"]').forEach(input => { input.checked = false; });
    updateEvaluationCompanyPicker(form);
    return;
  }
  const unselectAllForms = event.target.closest('#evaluationUnselectAllForms');
  if (unselectAllForms) {
    const form = unselectAllForms.closest('#evaluationAssignmentForm');
    form.querySelectorAll('#evaluationFormList input[type="checkbox"]').forEach(input => { input.checked = false; });
    updateEvaluationFormPicker(form);
    return;
  }
  document.querySelectorAll('[data-company-multi-select][open], [data-evaluation-form-multi-select][open]').forEach(picker => {
    if (!picker.contains(event.target)) picker.open = false;
  });
});
document.addEventListener('toggle', event => {
  const picker = event.target.closest?.('[data-company-multi-select], [data-evaluation-form-multi-select]');
  if (!picker || !picker.open) return;
  document.querySelectorAll('[data-company-multi-select][open], [data-evaluation-form-multi-select][open]').forEach(other => { if (other !== picker) other.open = false; });
  const form = picker.closest('#evaluationAssignmentForm');
  if (picker.matches('[data-company-multi-select]')) {
    updateEvaluationCompanyPicker(form);
    picker.querySelector('#evaluationCompanySearch')?.focus({preventScroll: true});
  } else {
    updateEvaluationFormPicker(form);
    picker.querySelector('#evaluationFormSearch')?.focus({preventScroll: true});
  }
}, true);
document.addEventListener('keydown', event => {
  if (event.target.matches?.('#evaluationCompanySearch, #evaluationFormSearch') && event.key === 'Enter') {
    event.preventDefault();
    return;
  }
  if (event.key !== 'Escape') return;
  const picker = document.querySelector('[data-company-multi-select][open], [data-evaluation-form-multi-select][open]');
  if (!picker) return;
  event.preventDefault();
  event.stopPropagation();
  picker.open = false;
  picker.querySelector('summary')?.focus();
}, true);
document.addEventListener('submit', event => {
  const form = event.target.closest('#evaluationAssignmentForm');
  if (!form) return;
  const formCheckboxes = [...form.querySelectorAll('#evaluationFormList input[type="checkbox"]')];
  if (!formCheckboxes.some(input => input.checked)) {
    event.preventDefault();
    form.querySelector('#evaluationFormError').hidden = false;
    formCheckboxes[0]?.focus();
    return;
  }
  const checkboxes = [...form.querySelectorAll('#evaluationCompanyList input[type="checkbox"]')];
  if (!checkboxes.some(input => input.checked)) {
    event.preventDefault();
    form.querySelector('#evaluationCompanyError').hidden = false;
    checkboxes[0]?.focus();
    return;
  }
  const submit = form.querySelector('button[type="submit"]');
  if (submit) {
    submit.disabled = true;
    submit.textContent = 'Sending...';
  }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
