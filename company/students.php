<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['company']);

if (!defined('OJTRACK_TZ')) { date_default_timezone_set('Asia/Manila'); define('OJTRACK_TZ', 1); }
$user    = current_user();
$company = query_one("SELECT * FROM companies WHERE user_id=?", [$user['id']], 'i');

$search = trim($_GET['q'] ?? '');
$status_filter = (string)($_GET['status'] ?? '');
$allowed_status_filters = ['pending', 'not_started', 'ongoing', 'on_hold', 'completed'];
if (!in_array($status_filter, $allowed_status_filters, true)) $status_filter = '';
$students = normalized_students_for_company((int)$company['id'], $search);
if ($status_filter !== '') {
    $students = array_values(array_filter($students, static fn(array $student): bool => $student['ojt_status'] === $status_filter));
}
$all_students = ($search === '' && $status_filter === '') ? $students : normalized_students_for_company((int)$company['id']);

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

$page_title = 'Trainees';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($bulk_success)): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($bulk_success) ?></p></div></div><?php endif; ?>
<?php if (!empty($bulk_error)): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($bulk_error) ?></p></div></div><?php endif; ?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">My Trainees</div>
    <div class="page-sub">Placements, OJT hours, and trainee records</div>
  </div>
</div>

<div class="card company-trainee-table-card">
  <div class="card-header flex-between" data-table-tools-combine>
    <button type="button" class="btn btn-primary btn-sm" data-tour-company-time onclick="openModal('bulkTimeModal')">⏱ Time In / Time Out</button>
  </div>
  <form class="filter-toolbar" method="GET" data-live-filter>
    <div class="search-wrap"><input type="text" name="q" class="form-control search-input" placeholder="Search by trainee name or ID..." value="<?= e($search) ?>" aria-label="Search trainees by name or student ID"></div>
    <select name="status" class="form-control" aria-label="Filter by OJT status">
      <option value="">All OJT statuses</option>
      <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
      <option value="not_started" <?= $status_filter === 'not_started' ? 'selected' : '' ?>>Not started</option>
      <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
      <option value="on_hold" <?= $status_filter === 'on_hold' ? 'selected' : '' ?>>On hold</option>
      <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
    </select>
  </form>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Assigned trainees">
    <table class="company-trainee-table" aria-label="Assigned trainees">
      <thead><tr>
        <th scope="col">Trainee</th>
        <th scope="col">Program</th>
        <th scope="col">OJT Hours</th>
        <th scope="col">Placement</th>
        <th scope="col">OJT Status</th>
        <th scope="col">Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <?php $rowPct = (int)$s['required_hours'] > 0 ? min(100, round(((float)$s['rendered_hours'] / (int)$s['required_hours']) * 100)) : 0; ?>
        <tr class="company-trainee-row-clickable" tabindex="0" role="button" aria-haspopup="dialog" aria-label="View details for <?= e($s['name']) ?>" data-company-trainee-row title="View trainee details">
          <td>
            <div class="company-trainee-identity">
              <span class="company-trainee-avatar"><?= user_avatar_content($s['avatar'] ?? null, $s['name']) ?></span>
              <span class="company-trainee-copy">
                <strong><?= e($s['name']) ?></strong>
                <span><?= e($s['student_id_no'] ?? '') ?></span>
              </span>
            </div>
          </td>
          <td><span class="company-trainee-program"><?= e($s['program'] ?? '—') ?></span><span class="company-trainee-year"><?= e($s['year_level'] ?? '') ?></span></td>
          <td>
            <div class="company-trainee-hours-cell">
              <div class="progress-track sm company-trainee-progress-circle" role="progressbar" aria-label="OJT hours completed" aria-valuenow="<?= $rowPct ?>" aria-valuemin="0" aria-valuemax="100" style="--pct:<?= $rowPct ?>">
                <span class="progress-fill <?= $rowPct >= 100 ? 'green' : ($rowPct >= 50 ? 'blue' : 'amber') ?>" data-pct="<?= $rowPct ?>"><?= $rowPct ?>%</span>
              </div>
              <div class="company-trainee-hours-copy">
                <strong class="company-trainee-hours"><?= number_format((float)$s['rendered_hours'], 1) ?> / <?= (int)$s['required_hours'] ?>h</strong>
                <span class="company-trainee-year">OJT hours completed</span>
              </div>
            </div>
          </td>
          <td><span class="company-trainee-date"><?= !empty($s['ojt_start_date']) ? format_date($s['ojt_start_date']) : 'Not set' ?></span><span class="company-trainee-year">to <?= !empty($s['ojt_end_date']) ? format_date($s['ojt_end_date']) : 'Not set' ?></span></td>
          <td><?= status_badge($s['ojt_status']) ?></td>
          <td>
            <details class="company-trainee-actions">
              <summary aria-label="Actions for <?= e($s['name']) ?>" title="More actions">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg>
              </summary>
              <div class="company-trainee-actions-menu">
                <button type="button" data-company-trainee-details data-details-url="/ojtrack/company/student-details?student=<?= (int)$s['id'] ?>" aria-haspopup="dialog">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><span>View details</span>
                </button>
                <a href="/ojtrack/company/evaluation.php?student=<?= (int)$s['id'] ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4.5h8M9 3h6v3H9zM7 5H5.5v16h13V5H17M8.5 12l2.2 2.2 4.8-5"/></svg><span>Evaluate</span>
                </a>
                <a href="/ojtrack/company/messages.php?student=<?= (int)$s['user_id'] ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-6.5A7.5 7.5 0 1 1 20 11.5Z"/><path d="M8 11h8M8 14h5"/></svg><span>Message</span>
                </a>
              </div>
            </details>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="6" class="company-trainee-empty">No trainees assigned to your company.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay company-trainee-details-overlay" id="companyTraineeDetailsModal">
  <section class="modal company-trainee-details-modal" role="dialog" aria-modal="true" aria-labelledby="companyTraineeDetailsTitle" tabindex="-1">
    <header class="coordinator-student-modal-header">
      <div>
        <span class="coordinator-student-modal-eyebrow">Trainee record</span>
        <h2 class="modal-title" id="companyTraineeDetailsTitle">Trainee details</h2>
        <p>OJT progress, attendance, and submitted reports</p>
      </div>
      <button type="button" class="coordinator-student-modal-close" onclick="closeModal('companyTraineeDetailsModal')" aria-label="Close trainee details">&times;</button>
    </header>
    <div class="company-trainee-details-modal-body" id="companyTraineeDetailsContent" aria-live="polite">
      <div class="empty-state"><p>Select a trainee to view their record.</p></div>
    </div>
  </section>
</div>

<script>
(function () {
  var actionMenus = Array.from(document.querySelectorAll('.company-trainee-actions'));

  function placeActionMenu(details) {
    var summary = details.querySelector('summary');
    var menu = details.querySelector('.company-trainee-actions-menu');
    if (!summary || !menu || !details.open) return;
    var anchor = summary.getBoundingClientRect();
    var menuRect = menu.getBoundingClientRect();
    var left = Math.max(8, Math.min(anchor.right - menuRect.width, window.innerWidth - menuRect.width - 8));
    var top = anchor.bottom + 6;
    if (top + menuRect.height > window.innerHeight - 8) top = Math.max(8, anchor.top - menuRect.height - 6);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  }

  actionMenus.forEach(function (details) {
    details.querySelector('.company-trainee-actions-menu').addEventListener('click', function (event) {
      if (event.target.closest('a, button')) details.open = false;
    });
    details.addEventListener('toggle', function () {
      if (details.open) {
        actionMenus.forEach(function (other) { if (other !== details) other.open = false; });
        requestAnimationFrame(function () { placeActionMenu(details); });
      }
    });
  });
  document.addEventListener('click', function (event) {
    actionMenus.forEach(function (details) {
      if (details.open && !details.contains(event.target)) details.open = false;
    });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') actionMenus.forEach(function (details) { details.open = false; });
  });
  window.addEventListener('resize', function () { actionMenus.forEach(placeActionMenu); });
  window.addEventListener('scroll', function () { actionMenus.forEach(placeActionMenu); }, true);

  var traineeTable = document.querySelector('.company-trainee-table');
  if (traineeTable) {
    traineeTable.addEventListener('click', function (event) {
      if (event.target.closest('a, button, input, summary, details')) return;
      var row = event.target.closest('[data-company-trainee-row]');
      if (!row) return;
      var detailsTrigger = row.querySelector('[data-company-trainee-details]');
      if (detailsTrigger) detailsTrigger.click();
    });
    traineeTable.addEventListener('keydown', function (event) {
      var row = event.target.closest('[data-company-trainee-row]');
      if (!row || event.target !== row || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
      var detailsTrigger = row.querySelector('[data-company-trainee-details]');
      if (detailsTrigger) detailsTrigger.click();
    });
  }

  var requestNumber = 0;
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-company-trainee-details]');
    if (!trigger) return;
    event.preventDefault();

    var content = document.getElementById('companyTraineeDetailsContent');
    if (!content) return;
    var requestId = ++requestNumber;
    content.setAttribute('aria-busy', 'true');
    content.innerHTML = '<div class="company-trainee-modal-loading" role="status">Loading trainee details…</div>';
    openModal('companyTraineeDetailsModal');

    fetch(trigger.dataset.detailsUrl, {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
      .then(function (response) {
        if (!response.ok || response.redirected) throw new Error('Unable to load this trainee record.');
        if (!(response.headers.get('content-type') || '').includes('text/html')) throw new Error('Unexpected response.');
        return response.text();
      })
      .then(function (html) {
        if (requestId === requestNumber) content.innerHTML = html;
      })
      .catch(function () {
        if (requestId !== requestNumber) return;
        var message = document.createElement('p');
        message.className = 'company-trainee-modal-error';
        message.setAttribute('role', 'alert');
        message.textContent = 'We could not load this trainee’s details. Close this window and try again.';
        content.replaceChildren(message);
      })
      .finally(function () {
        if (requestId === requestNumber) content.removeAttribute('aria-busy');
      });
  });
})();
</script>

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
        <div class="text-xs text-muted mt-1">For each trainee: first punch is Time In; second is Time Out. AM and PM are separate.</div>
      </div>
      <div class="form-group">
        <div class="bulk-trainee-picker" data-bulk-trainee-picker>
          <label class="form-label" for="bulkTraineeSearch">Trainees</label>
          <input type="search" class="form-control bulk-trainee-search" id="bulkTraineeSearch" placeholder="Search trainees by name or ID..." aria-label="Search trainees by name or student ID" aria-controls="bulkTraineeList" data-bulk-trainee-search>
          <div class="bulk-trainee-picker-toolbar">
            <span class="text-xs text-muted" aria-live="polite" data-bulk-trainee-count>0 selected</span>
            <div class="bulk-trainee-picker-actions">
              <button type="button" class="btn btn-ghost btn-sm" data-select-visible-trainees>Select visible</button>
              <button type="button" class="btn btn-ghost btn-sm" data-clear-trainee-selection>Clear selection</button>
            </div>
          </div>
          <div class="bulk-trainee-list" id="bulkTraineeList" role="group" aria-label="Select trainees for attendance">
          <?php foreach ($all_students as $s): ?>
          <label class="bulk-trainee-option" data-bulk-trainee-option>
            <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>">
            <span class="bulk-trainee-copy"><span class="bulk-trainee-name"><?= e($s['name']) ?></span><span class="bulk-trainee-id"><?= e($s['student_id_no'] ?? '') ?></span></span>
          </label>
          <?php endforeach; ?>
          </div>
          <p class="bulk-trainee-no-results" data-bulk-trainee-no-results hidden>No trainees match that search.</p>
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

  var picker = document.querySelector('[data-bulk-trainee-picker]');
  if (picker) {
    var search = picker.querySelector('[data-bulk-trainee-search]');
    var options = Array.from(picker.querySelectorAll('[data-bulk-trainee-option]'));
    var count = picker.querySelector('[data-bulk-trainee-count]');
    var noResults = picker.querySelector('[data-bulk-trainee-no-results]');

    function refreshTraineePicker() {
      var query = search.value.trim().toLocaleLowerCase();
      var visible = 0;
      options.forEach(function (option) {
        var matches = option.textContent.toLocaleLowerCase().includes(query);
        option.hidden = !matches;
        if (matches) visible++;
      });
      var selected = options.reduce(function (total, option) {
        return total + (option.querySelector('input[type="checkbox"]').checked ? 1 : 0);
      }, 0);
      count.textContent = selected + ' selected · ' + visible + ' shown';
      picker.querySelector('.bulk-trainee-list').hidden = visible === 0;
      noResults.hidden = visible > 0;
    }

    search.addEventListener('input', refreshTraineePicker);
    picker.addEventListener('change', function (event) {
      if (event.target.matches('input[type="checkbox"]')) refreshTraineePicker();
    });
    picker.querySelector('[data-select-visible-trainees]').addEventListener('click', function () {
      options.forEach(function (option) {
        if (!option.hidden) option.querySelector('input[type="checkbox"]').checked = true;
      });
      refreshTraineePicker();
    });
    picker.querySelector('[data-clear-trainee-selection]').addEventListener('click', function () {
      options.forEach(function (option) { option.querySelector('input[type="checkbox"]').checked = false; });
      refreshTraineePicker();
    });
    refreshTraineePicker();
  }

  tick();
  setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
