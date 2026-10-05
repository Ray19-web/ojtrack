<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

if (!defined('OJTRACK_TZ')) { date_default_timezone_set('Asia/Manila'); define('OJTRACK_TZ', 1); }
$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$search = trim($_GET['q'] ?? '');
$students = normalized_students_for_company((int)$company['id'], $search);

// Bulk time in / time out — normalized attendance writer.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_time') {
    $student_ids = $_POST['student_ids'] ?? [];

    if (empty($student_ids)) {
        $bulk_error = 'Select at least one trainee.';
    } else {
        $today = date('Y-m-d');
        $now   = date('H:i:s');
        if (!empty($_POST['client_time'])) {
            $ts = strtotime(str_replace('T', ' ', $_POST['client_time']));
            if ($ts !== false) {
                $today = date('Y-m-d', $ts);
                $now   = date('H:i:s', $ts);
            }
        }

        $done = 0; $skipped = 0;
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;
            try {
                $result = normalized_attendance_punch(
                    $sid,
                    (int)$company['id'],
                    $today,
                    $now,
                    (int)$user['id']
                );
                if ($result === 'skipped') $skipped++;
                else $done++;
            } catch (DomainException $exception) {
                $skipped++;
            }
        }

        log_activity($user['id'], 'Bulk Attendance', "$done trainee(s) recorded, $skipped skipped · $today $now");
        header('Location: /ojtrack/company/students.php?timedone=' . $done . '&skipped=' . $skipped);
        exit;
    }
}

$bulk_success = isset($_GET['timedone']) ? 'Attendance recorded for ' . (int)$_GET['timedone'] . ' trainee(s) at ' . date('h:i A') . (isset($_GET['skipped']) && (int)$_GET['skipped'] > 0 ? ' · ' . (int)$_GET['skipped'] . ' skipped (session already complete)' : '') . '.' : '';

$sel_id = isset($_GET['id']) ? (int)$_GET['id'] : ($students[0]['id'] ?? 0);
$selected = null;
foreach ($students as $s) { if ($s['id'] == $sel_id) { $selected = $s; break; } }

if ($selected) {
    $att_records = normalized_attendance_rows_for_student($sel_id, '', 10);
    $journal_cnt = count(normalized_journal_rows_for_student($sel_id, 1000));
    $reports     = array_slice(array_reverse(normalized_report_rows_for_student($sel_id)), 0, 5);
    $student_evals = normalized_eval_requests_for_student($sel_id);
    $midterm_eval = null;
    $final_eval = null;
    foreach ($student_evals as $evaluation) {
        if ($evaluation['evaluation_kind'] === 'midterm' && $evaluation['status'] === 'completed') $midterm_eval = $evaluation;
        if ($evaluation['evaluation_kind'] === 'final' && $evaluation['status'] === 'completed') $final_eval = $evaluation;
    }
}

$page_title = 'Trainees';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Trainee Management</div>
    <div class="page-sub">Monitor student attendance, submitted reports, and perform performance evaluations</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('bulkTimeModal')">⏱ Time In / Time Out</button>
</div>

<?php if (!empty($bulk_success)): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($bulk_success) ?></p></div></div><?php endif; ?>
<?php if (!empty($bulk_error)): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($bulk_error) ?></p></div></div><?php endif; ?>

<div class="grid" style="grid-template-columns:280px 1fr;gap:20px">
  <div class="card" style="align-self:flex-start">
    <div class="card-header flex-between">
      <div class="card-title">Trainees (<?= count($students) ?>)</div>
    </div>
    <div style="padding:10px 12px;border-bottom:1px solid var(--border-light)">
      <form method="GET">
        <div class="search-wrap" style="width:100%">
          <input type="text" name="q" class="form-control search-input" placeholder="Search trainees..." value="<?= e($search) ?>" style="font-size:12px;height:34px">
        </div>
      </form>
    </div>
    <div>
      <?php foreach ($students as $s): ?>
      <a href="?id=<?= $s['id'] ?>" style="display:flex;gap:10px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border-light);text-decoration:none;background:<?= $s['id']==$sel_id?'var(--primary-light)':'transparent' ?>;transition:background .15s">
        <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0"><?= strtoupper(substr($s['name'],0,2)) ?></div>
        <div style="min-width:0;flex:1">
          <div style="font-weight:700;font-size:13px;color:var(--text-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($s['name']) ?></div>
          <div class="text-xs text-muted"><?= e($s['student_id_no'] ?? '') ?></div>
        </div>
        <div><?= status_badge($s['ojt_status']) ?></div>
      </a>
      <?php endforeach; ?>
      <?php if (empty($students)): ?><div style="padding:24px 16px;text-align:center;color:var(--text-400);font-size:12px">No trainees assigned.</div><?php endif; ?>
    </div>
  </div>

  <?php if ($selected): ?>
  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card card-body">
      <div class="flex-between mb-4">
        <div style="display:flex;gap:14px;align-items:center">
          <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#fff"><?= strtoupper(substr($selected['name'],0,2)) ?></div>
          <div>
            <div style="font-weight:800;font-size:18px;color:var(--text-800)"><?= e($selected['name']) ?></div>
            <div class="text-sm text-muted"><?= e($selected['email']) ?> · ID: <?= e($selected['student_id_no'] ?? '—') ?></div>
            <div class="text-xs text-muted"><?= e($selected['program'] ?? 'BS Information Technology') ?> · <?= e($selected['year_level'] ?? '4th Year') ?></div>
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <a href="/ojtrack/company/evaluation.php?student=<?= $selected['id'] ?>" class="btn btn-primary btn-sm">Evaluate Trainee</a>
          <a href="/ojtrack/company/messages.php?student=<?= $selected['user_id'] ?>" class="btn btn-secondary btn-sm">Message</a>
        </div>
      </div>
      <?php $pct = $selected['required_hours']>0?min(100,round(($selected['rendered_hours']/$selected['required_hours'])*100)):0; ?>
      <div class="progress-wrap">
        <div class="progress-label"><span class="text">OJT Hours Rendered</span><span class="pct"><?= number_format($selected['rendered_hours'],1) ?> / <?= $selected['required_hours'] ?>h (<?= $pct ?>%)</span></div>
        <div class="progress-track"><div class="progress-fill <?= $pct>=100?'green':($pct>=50?'blue':'amber') ?>" data-pct="<?= $pct ?>"></div></div>
      </div>
      <div class="grid grid-4 gap-3 mt-4" style="border-top:1px solid var(--border-light);padding-top:14px">
        <div><div class="text-xs text-muted">OJT Start</div><div class="text-sm font-bold"><?= format_date($selected['ojt_start_date']) ?></div></div>
        <div><div class="text-xs text-muted">OJT End</div><div class="text-sm font-bold"><?= format_date($selected['ojt_end_date']) ?></div></div>
        <div><div class="text-xs text-muted">Journal Logs</div><div class="text-sm font-bold"><?= $journal_cnt ?> entries</div></div>
        <div>
          <div class="text-xs text-muted">Midterm / Final</div>
          <div class="text-xs font-bold mt-1">
            <?= $midterm_eval ? 'Midterm: '.number_format($midterm_eval['overall_score'],1) : 'Midterm: Pending' ?> ·
            <?= $final_eval ? 'Final: '.number_format($final_eval['overall_score'],1) : 'Final: Pending' ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header flex-between">
        <div class="card-title">Recent Attendance Logs</div>
        <a href="/ojtrack/company/attendance.php?student=<?= $selected['id'] ?>" class="btn btn-secondary btn-sm">View Full Attendance</a>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Rendered</th><th>Status</th><th>Remarks</th></tr></thead>
          <tbody>
            <?php foreach ($att_records as $a): ?>
            <tr>
              <td class="td-mono"><?= date('M d, Y · D', strtotime($a['date'])) ?></td>
              <td class="td-mono"><?= $a['time_in'] ? date('h:i A', strtotime($a['time_in'])) : '—' ?></td>
              <td class="td-mono"><?= $a['time_out'] ? date('h:i A', strtotime($a['time_out'])) : '—' ?></td>
              <td class="font-mono font-bold"><?= $a['hours_rendered'] ? number_format($a['hours_rendered'],2) . 'h' : '—' ?></td>
              <td><?= status_badge($a['status']) ?></td>
              <td class="text-xs text-muted"><?= $a['remarks'] ? e($a['remarks']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($att_records)): ?><tr><td colspan="6" class="text-center text-muted py-6">No attendance records logged yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header flex-between">
        <div class="card-title">Submitted Reports</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Report Title</th><th>Type</th><th>Submitted</th><th>Status</th><th>Document</th></tr></thead>
          <tbody>
            <?php foreach ($reports as $r): ?>
            <tr>
              <td class="font-bold text-sm"><?= e($r['report_name']) ?></td>
              <td><span class="badge" style="background:var(--bg);color:var(--text-700)"><?= ucfirst($r['report_type']) ?></span></td>
              <td class="td-mono text-sm"><?= $r['submitted_at'] ? date('M d, Y', strtotime($r['submitted_at'])) : '—' ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td>
                <?php if (!empty($r['file_path'])): ?>
                  <div class="table-actions">
                    <a href="/ojtrack/download.php?file=<?= rawurlencode($r['file_path']) ?>" target="_blank"
                       class="table-action-icon is-primary" title="View submitted report" aria-label="View submitted report">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    </a>
                  </div>
                <?php else: ?>
                  <span class="text-xs text-muted">No file</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($reports)): ?><tr><td colspan="5" class="text-center text-muted py-6">No reports submitted yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="card card-body"><div class="empty-state"><p>No trainees assigned to your company.</p></div></div>
  <?php endif; ?>
</div>

<!-- Bulk Time In/Out Modal -->
<div class="modal-overlay" id="bulkTimeModal">
  <div class="modal">
    <div class="modal-title">Bulk Time In / Time Out</div>
    <p class="modal-sub">Records the current time for the selected trainees — <span id="bulkTimeLive"></span></p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_time">
      <input type="hidden" name="client_time" id="clientTime" value="">
      <div class="form-group">
        <label class="form-label">Session (auto-detected — read only)</label>
        <input type="text" class="form-control" value="Auto-detected: <?= date('A') === 'AM' ? 'Morning' : 'Afternoon' ?> session, based on each trainee's existing records" readonly style="background:var(--bg);color:var(--text-500)">
        <div class="text-xs text-muted mt-1">AM → Morning, PM → Afternoon. First punch = Time In, second punch = Time Out (per trainee, per half-day).</div>
      </div>
      <div class="form-group">
        <label class="form-label">Trainees</label>
        <div style="max-height:220px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius);padding:10px">
          <?php foreach ($students as $s): ?>
          <label style="display:flex;gap:8px;align-items:center;padding:4px 0">
            <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>">
            <span class="text-sm"><?= e($s['name']) ?> <span class="text-xs text-muted">(<?= e($s['student_id_no'] ?? '') ?>)</span></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('bulkTimeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Record Now</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  function tick() {
    var el = document.getElementById('bulkTimeLive');
    if (el) el.textContent = new Date().toLocaleString('en-US', { year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true });
    var ct = document.getElementById('clientTime');
    if (ct && !ct.value) {
      var d = new Date();
      ct.value = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0') + ':' + String(d.getSeconds()).padStart(2,'0');
    }
  }
  document.querySelector('#bulkTimeModal form').addEventListener('submit', function () {
    var d = new Date();
    document.getElementById('clientTime').value = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0') + ':' + String(d.getSeconds()).padStart(2,'0');
  });
  tick();
  setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
