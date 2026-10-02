<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$success = ''; $error = '';

// Submit a coordinator-sent evaluation form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_submission') {
    $sub_id   = (int)($_POST['submission_id'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');

    $sub = query_one(
        "SELECT s.*, f.title AS form_title, f.score_mode FROM eval_submissions s
         JOIN evaluation_forms f ON f.id=s.form_id
         WHERE s.id=? AND s.company_id=? AND s.status='pending'",
        [$sub_id, $company['id']], 'ii');

    if (!$sub) {
        $error = 'Evaluation form not found or already submitted.';
    } else {
        $sections = query("SELECT * FROM eval_sections WHERE form_id=? ORDER BY sort_order", [$sub['form_id']], 'i') ?: [];
        $rules    = query("SELECT * FROM eval_rating_rules WHERE form_id=?", [$sub['form_id']], 'i') ?: [];
        $total = 0; $count = 0;

        foreach ($sections as $sec) {
            $crits = query("SELECT * FROM eval_criteria WHERE section_id=? ORDER BY sort_order", [$sec['id']], 'i') ?: [];
            foreach ($crits as $ci => $cr) {
                $val = max(0, min(100, (float)($_POST['criterion_' . $cr['id']] ?? 0)));
                $equiv = $val;
                foreach ($rules as $r) {
                    if ($val >= $r['score_min'] && $val <= $r['score_max']) { $equiv = (float)$r['equivalent']; break; }
                }
                insert("INSERT INTO eval_answers (submission_id, section_title, criterion_label, score, equivalent) VALUES (?,?,?,?,?)",
                    [$sub_id, $sec['title'], $cr['label'], $val, $equiv], 'isssd');
                $total += $val; $count++;
            }
        }
        $overall = $count > 0 ? round($total / $count, 2) : 0;
        $overall_eq = null;
        foreach ($rules as $r) {
            if ($overall >= $r['score_min'] && $overall <= $r['score_max']) { $overall_eq = (float)$r['equivalent']; break; }
        }

        query("UPDATE eval_submissions SET status='completed', overall_score=?, overall_equivalent=?, comments=?, submitted_at=NOW() WHERE id=?",
            [$overall, $overall_eq, $comments, $sub_id], 'ddsi');

        $stu = query_one("SELECT u.name, u.id AS uid, co.user_id AS coord_user_id FROM students s JOIN users u ON u.id=s.user_id LEFT JOIN coordinators co ON co.id=s.coordinator_id WHERE s.id=?", [$sub['student_id']], 'i');
        if ($stu) {
            create_notification($stu['uid'], "Your evaluation form was submitted by {$company['company_name']}.", 'evaluation', '/ojtrack/student/evaluation.php');
            if (!empty($stu['coord_user_id'])) {
                create_notification($stu['coord_user_id'], "Evaluation \"{$sub['form_title']}\" submitted by {$company['company_name']} for {$stu['name']} (score {$overall}/100).", 'evaluation', '/ojtrack/coordinator/evaluation.php');
            }
        }
        log_activity($user['id'], 'Evaluation Form Submitted', "Submission #$sub_id, Score: $overall");
        $success = "Evaluation submitted successfully with an overall score of " . number_format($overall, 1) . '/100.';
        header('Location: /ojtrack/company/evaluation.php?done=' . $sub_id);
        exit;
    }
}

$students = query("SELECT s.*, u.name, s.student_id_no FROM students s JOIN users u ON u.id=s.user_id WHERE s.company_id=? ORDER BY u.name", [$company['id']], 'i');
$sel_id   = isset($_GET['student']) ? (int)$_GET['student'] : 0;

$page_title = 'Trainee Evaluation';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Trainee Performance Evaluation</div>
    <div class="page-sub">Evaluate trainee performance across key competency dimensions</div>
  </div>
  <?php if (!empty($students)): ?>
  <div style="display:flex;gap:8px">
    <select class="form-control" style="width:240px" onchange="location.href='?student='+this.value">
      <option value="0">All Students</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $s['id']==$sel_id?'selected':'' ?>><?= e($s['name']) ?> (<?= e($s['student_id_no'] ?? '') ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php
$submissions = query(
    "SELECT s.*, f.title AS form_title, f.description, u.name AS student_name, st.student_id_no, st.program, st.ojt_status
     FROM eval_submissions s
     JOIN evaluation_forms f ON f.id=s.form_id
     JOIN students st ON st.id=s.student_id
     JOIN users u ON u.id=st.user_id
     WHERE s.company_id=?" . ($sel_id ? " AND s.student_id=$sel_id" : "") . " ORDER BY s.id DESC",
    [$company['id']], 'i') ?: [];

$esid = isset($_GET['esid']) ? (int)$_GET['esid'] : 0;
$sub = null;
foreach ($submissions as $a) { if ($a['id'] == $esid) { $sub = $a; break; } }

if ($sub):
  $sub_sections = query("SELECT * FROM eval_sections WHERE form_id=? ORDER BY sort_order", [$sub['form_id']], 'i') ?: [];
  $sub_rules    = query("SELECT * FROM eval_rating_rules WHERE form_id=? ORDER BY score_min DESC", [$sub['form_id']], 'i') ?: [];
?>
<div class="card card-body">
  <div class="flex-between mb-4">
    <div>
      <div class="section-title"><?= e($sub['form_title']) ?></div>
      <div class="text-xs text-muted">Student: <?= e($sub['student_name']) ?> · <?= e($sub['description']) ?></div>
    </div>
    <a href="/ojtrack/company/evaluation.php" class="btn btn-ghost btn-sm">← Back</a>
  </div>

  <?php $stu_row = query_one("SELECT st.*, u.name, u.email FROM students st JOIN users u ON u.id=st.user_id WHERE st.id=?", [$sub['student_id']], 'i'); ?>
  <div style="display:flex;gap:12px;align-items:center;padding:12px 14px;background:var(--bg);border-radius:var(--radius);margin-bottom:16px">
    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#fff;flex-shrink:0"><?= strtoupper(substr($stu_row['name'] ?? '',0,2)) ?></div>
    <div style="min-width:0">
      <div style="font-weight:700"><?= e($stu_row['name'] ?? $sub['student_name']) ?></div>
      <div class="text-xs text-muted"><?= e($stu_row['student_id_no'] ?? '') ?> · <?= e($stu_row['program'] ?? '') ?></div>
    </div>
    <div style="margin-left:auto"><?= status_badge($stu_row['ojt_status'] ?? 'pending') ?></div>
  </div>

  <?php if ($sub['status'] === 'completed'): ?>
    <?php $answers = query("SELECT * FROM eval_answers WHERE submission_id=? ORDER BY id", [$sub['id']], 'i') ?: []; ?>
    <div class="text-center mb-4">
      <div class="text-xs text-muted">Overall Score</div>
      <div style="font-family:var(--font-display);font-size:36px;font-weight:800;color:var(--primary)"><?= number_format($sub['overall_score'], 1) ?></div>
      <?php if ($sub['overall_equivalent'] !== null): ?><div class="text-sm">Equivalent: <strong><?= e($sub['overall_equivalent']) ?></strong></div><?php endif; ?>
      <span class="badge badge-approved">Submitted</span>
    </div>
    <?php foreach ($answers as $a): ?>
      <div class="mb-3">
        <div style="display:flex;justify-content:space-between"><span class="text-sm font-bold"><?= e($a['criterion_label']) ?></span><span class="font-mono font-bold"><?= $a['score'] ?>/100</span></div>
        <div class="progress-track"><div class="progress-fill blue" data-pct="<?= $a['score'] ?>"></div></div>
      </div>
    <?php endforeach; ?>
    <?php if ($sub['comments']): ?><div class="text-sm mt-3"><strong>Comments:</strong> <?= nl2br(e($sub['comments'])) ?></div><?php endif; ?>
  <?php else: ?>
  <form method="POST">
    <input type="hidden" name="action" value="submit_submission">
    <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
    <?php foreach ($sub_sections as $sec): $crits = query("SELECT * FROM eval_criteria WHERE section_id=? ORDER BY sort_order", [$sec['id']], 'i') ?: []; ?>
    <div class="section-title mb-2" style="margin-top:8px"><?= e($sec['title']) ?></div>
    <div class="space-y-4 mb-4">
      <?php foreach ($crits as $cr): ?>
      <div style="background:var(--bg);padding:14px 16px;border-radius:var(--radius)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <label class="text-sm font-bold"><?= e($cr['label']) ?></label>
          <span class="font-mono font-bold text-primary text-base" id="sc_<?= $cr['id'] ?>">85</span>
        </div>
        <input type="range" name="criterion_<?= $cr['id'] ?>" min="0" max="100" value="85" style="width:100%" oninput="document.getElementById('sc_<?= $cr['id'] ?>').textContent=this.value">
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($sub_rules): ?>
    <div class="text-xs text-muted mb-3">Rating guide: <?= implode(' · ', array_map(fn($r) => $r['score_min'] . '–' . $r['score_max'] . ' = ' . $r['equivalent'], $sub_rules)) ?></div>
    <?php endif; ?>
    <div class="form-group mb-4">
      <label class="form-label font-bold">Comments &amp; Feedback</label>
      <textarea name="comments" class="form-control" rows="4"></textarea>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px">
      <a href="/ojtrack/company/evaluation.php" class="btn btn-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary">Submit Evaluation</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php
else:
?>
<div class="card mb-4">
  <div class="card-header"><div class="card-title">Evaluation Forms from OJT Coordinator</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Form</th><th>Student</th><th>Status</th><th>Score</th><th>Action</th></tr></thead>
      <tbody>
        <?php if (empty($submissions)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No evaluation forms have been sent to you.</td></tr>
        <?php else: ?>
          <?php foreach ($submissions as $a): ?>
          <tr>
            <td><strong><?= e($a['form_title']) ?></strong></td>
            <td><strong><?= e($a['student_name']) ?></strong><div class="text-xs text-muted"><?= e($a['student_id_no'] ?? '') ?> · <?= e($a['program'] ?? '') ?></div></td>
            <td><?= status_badge($a['status']) ?></td>
            <td><?= $a['overall_score'] !== null ? number_format($a['overall_score'], 1) : '—' ?></td>
            <td><a href="?esid=<?= $a['id'] ?>" class="btn btn-primary btn-sm"><?= $a['status'] === 'pending' ? 'Fill Out' : 'View' ?></a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


