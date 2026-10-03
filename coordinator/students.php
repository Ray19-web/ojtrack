<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$user['id']], 'i');

$allowed_ojt_statuses = ['pending', 'not_started', 'ongoing', 'completed', 'on_hold', 'withdrawn'];
$success = '';
$error   = '';

// CRUD: Update student assignment / status (Create = admin; Delete = archive soft-remove from active list)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $sid    = (int)($_POST['student_id'] ?? 0);

    $stu = query_one(
        "SELECT s.*, u.name, u.email FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.coordinator_id=?",
        [$sid, $coord['id']],
        'ii'
    );

    if (!$stu) {
        $error = 'Student not found or not assigned to you.';
    } elseif ($action === 'update_student') {
        $company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
        $ojt_status = $_POST['ojt_status'] ?? 'pending';
        $req_hours  = (int)($_POST['required_hours'] ?? 486);
        $start_date = !empty($_POST['ojt_start_date']) ? $_POST['ojt_start_date'] : null;
        $end_date   = !empty($_POST['ojt_end_date']) ? $_POST['ojt_end_date'] : null;
        $status_notes = trim($_POST['status_notes'] ?? '');

        if (!in_array($ojt_status, $allowed_ojt_statuses, true)) {
            $ojt_status = 'pending';
        }

        // Notes required when stopping / withdrawing
        if (in_array($ojt_status, ['on_hold', 'withdrawn'], true) && $status_notes === '') {
            $error = 'Please add a reason/notes when setting status to On Hold or Withdrawn.';
        } else {
            if (!in_array($ojt_status, ['on_hold', 'withdrawn'], true)) {
                $status_notes = $status_notes !== '' ? $status_notes : null;
            }

            query(
                "UPDATE students SET company_id=?, ojt_status=?, status_notes=?, required_hours=?, ojt_start_date=?, ojt_end_date=? WHERE id=?",
                [$company_id, $ojt_status, $status_notes, $req_hours, $start_date, $end_date, $sid],
                'ississi'
            );

            log_activity($user['id'], 'Student Updated', "Updated details for {$stu['name']} (ID: $sid) → $ojt_status");
            $hours_complete = ($stu['required_hours'] ?? 0) > 0 && ((float)($stu['rendered_hours'] ?? 0)) >= (float)$stu['required_hours'];
            if ($ojt_status === 'completed' && $hours_complete) {
                create_notification(
                    $stu['user_id'],
                    "Congratulations! Your OJT has been completed and your rendered hours are finished. Your Certificate of Recognition is now available.",
                    'success',
                    '/ojtrack/student/certificate.php'
                );
            } else {
                create_notification(
                    $stu['user_id'],
                    "Your OJT status was updated to " . str_replace('_', ' ', $ojt_status) . " by your coordinator.",
                    'info',
                    '/ojtrack/student/dashboard.php'
                );
            }

            if ($company_id && (int)($stu['company_id'] ?? 0) !== $company_id) {
                $comp = query_one("SELECT user_id FROM companies WHERE id=?", [$company_id], 'i');
                if ($comp) {
                    create_notification(
                        $comp['user_id'],
                        "Student {$stu['name']} has been assigned to your company.",
                        'info',
                        '/ojtrack/company/students.php'
                    );
                }
            }

            $success = 'Student details updated successfully.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where  = "s.coordinator_id=? AND (s.is_archived = 0 AND u.status != 'archived')";
$params = [$coord['id']];
$types  = 'i';
if ($search) {
    $where .= " AND (u.name LIKE ? OR s.student_id_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}
if ($status !== '' && in_array($status, $allowed_ojt_statuses, true)) {
    $where .= " AND s.ojt_status=?";
    $params[] = $status;
    $types .= 's';
}

$students = query(
    "SELECT s.*, u.name, u.email, co.company_name FROM students s
     JOIN users u ON u.id=s.user_id
     LEFT JOIN companies co ON co.id=s.company_id
     WHERE $where ORDER BY u.name ASC",
    $params,
    $types
) ?: [];

$companies_list = query("SELECT id, company_name FROM companies WHERE status='active' ORDER BY company_name ASC", [], '') ?: [];

$detail = null;
if (isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    $detail = query_one(
        "SELECT s.*, u.name, u.email, co.company_name, cu.name AS supervisor FROM students s
         JOIN users u ON u.id=s.user_id
         LEFT JOIN companies co ON co.id=s.company_id
         LEFT JOIN users cu ON cu.id=co.user_id
         WHERE s.id=? AND s.coordinator_id=?",
        [$sid, $coord['id']],
        'ii'
    );
}

$page_title = 'My Students';
require_once __DIR__ . '/../includes/header.php';

$status_labels = [
    'pending'     => 'Pending',
    'not_started' => 'Not Started',
    'ongoing'     => 'Ongoing',
    'completed'   => 'Completed',
    'on_hold'     => 'On Hold (temporary stop)',
    'withdrawn'   => 'Withdrawn (stopped)',
];
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">My Students</div>
    <div class="page-sub">Assign companies, update OJT status, and manage student records under your department</div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($detail): ?>
<div class="mb-4 flex-between">
  <a href="/ojtrack/coordinator/students.php" class="btn btn-ghost btn-sm">← Back to Students</a>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-secondary btn-sm" onclick="openModal('editStudentModal')">Edit Assignment &amp; Status</button>
    <a href="/ojtrack/coordinator/monitoring.php?student=<?= $detail['id'] ?>" class="btn btn-primary btn-sm">Open in OJT Monitoring</a>
  </div>
</div>

<div class="grid" style="grid-template-columns:1fr 1.4fr;gap:20px">
  <div class="card card-body text-center">
    <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1d4ed8);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#fff"><?= strtoupper(substr($detail['name'],0,2)) ?></div>
    <div style="font-weight:700;font-size:16px"><?= e($detail['name']) ?></div>
    <div class="text-xs text-muted mb-2"><?= e($detail['email']) ?></div>
    <?= status_badge($detail['ojt_status']) ?>
    <?php if (!empty($detail['status_notes']) && in_array($detail['ojt_status'], ['on_hold', 'withdrawn'], true)): ?>
      <div class="alert alert-warn mt-3" style="text-align:left;font-size:12px">
        <strong>Status notes:</strong> <?= e($detail['status_notes']) ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card card-body">
    <div class="section-title mb-3">Academic &amp; OJT Assignment</div>
    <?php
    $pct = $detail['required_hours'] > 0 ? min(100, round(($detail['rendered_hours'] / $detail['required_hours']) * 100)) : 0;
    $info = [
        'Student ID'       => $detail['student_id_no'] ?? '—',
        'Program'          => $detail['program'] ?? '—',
        'Department'       => $detail['department'] ?? '—',
        'Year Level'       => $detail['year_level'] ?? '—',
        'Contact Number'   => $detail['contact_number'] ?? '—',
        'Assigned Company' => $detail['company_name'] ?? 'Not Assigned',
        'Supervisor'       => $detail['supervisor'] ?? '—',
        'Rendered Hours'   => number_format((float)$detail['rendered_hours'], 1) . ' / ' . $detail['required_hours'] . 'h (' . $pct . '%)',
        'OJT Start Date'   => format_date($detail['ojt_start_date']),
        'OJT End Date'     => format_date($detail['ojt_end_date']),
    ];
    foreach ($info as $k => $v): ?>
    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-light)">
      <span class="text-xs text-muted"><?= $k ?></span>
      <span class="text-xs font-bold"><?= e($v) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="mt-4" style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="/ojtrack/coordinator/attendance.php?q=<?= urlencode($detail['name']) ?>" class="btn btn-ghost btn-sm">Attendance →</a>
      <a href="/ojtrack/coordinator/requirements.php" class="btn btn-ghost btn-sm">Requirements →</a>
    </div>
  </div>
</div>

<!-- Edit Student Modal -->
<div class="modal-overlay" id="editStudentModal">
  <div class="modal">
    <div class="modal-title">Edit Student OJT Assignment</div>
    <p class="modal-sub"><?= e($detail['name']) ?> (<?= e($detail['student_id_no'] ?? '') ?>)</p>
    <form method="POST"><?= csrf_field() ?>
      <input type="hidden" name="action" value="update_student">
      <input type="hidden" name="student_id" value="<?= $detail['id'] ?>">
      <div class="form-group">
        <label class="form-label">Assigned Partner Company</label>
        <select name="company_id" class="form-control">
          <option value="">-- No Company Assigned --</option>
          <?php foreach ($companies_list as $co): ?>
            <option value="<?= $co['id'] ?>" <?= (int)$detail['company_id'] === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">OJT Status</label>
          <select name="ojt_status" id="editOjtStatus" class="form-control" onchange="toggleStatusNotes(this.value)">
            <?php foreach ($status_labels as $val => $label): ?>
              <option value="<?= $val ?>" <?= $detail['ojt_status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-xs text-muted mt-1">Use <strong>On Hold</strong> for temporary stop (illness, company issue). Use <strong>Withdrawn</strong> if the student stopped OJT.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Required Hours</label>
          <input type="number" name="required_hours" class="form-control" value="<?= e($detail['required_hours']) ?>" min="1" required>
        </div>
      </div>
      <div class="form-group" id="statusNotesGroup">
        <label class="form-label">Status Notes / Reason</label>
        <textarea name="status_notes" id="editStatusNotes" class="form-control" rows="2" placeholder="e.g. Medical leave until March 15 / Company closed branch / Student withdrew"><?= e($detail['status_notes'] ?? '') ?></textarea>
        <div class="text-xs text-muted mt-1">Required for On Hold or Withdrawn.</div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">OJT Start Date</label>
          <input type="date" name="ojt_start_date" class="form-control" value="<?= e($detail['ojt_start_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">OJT End Date</label>
          <input type="date" name="ojt_end_date" class="form-control" value="<?= e($detail['ojt_end_date'] ?? '') ?>">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleStatusNotes(status) {
  const group = document.getElementById('statusNotesGroup');
  const notes = document.getElementById('editStatusNotes');
  const needs = (status === 'on_hold' || status === 'withdrawn');
  if (group) group.style.outline = needs ? '1px solid #fed7aa' : 'none';
  if (notes) notes.required = needs;
}
document.addEventListener('DOMContentLoaded', function () {
  const sel = document.getElementById('editOjtStatus');
  if (sel) toggleStatusNotes(sel.value);
});
</script>

<?php else: ?>

<div class="card mb-4">
  <div class="card-header flex-between">
    <div style="display:flex;gap:8px">
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <div class="search-wrap" style="width:240px">
          <input type="text" name="q" class="form-control search-input" placeholder="Search name or ID..." value="<?= e($search) ?>">
        </div>
        <select name="status" class="form-control" style="width:180px" onchange="this.form.submit()">
          <option value="">All Status</option>
          <?php foreach ($status_labels as $val => $label): ?>
            <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= e(explode(' (', $label)[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($search || $status): ?><a href="/ojtrack/coordinator/students.php" class="btn btn-ghost">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="text-sm text-muted"><?= count($students) ?> students under supervision</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Student ID</th>
          <th>Program</th>
          <th>Assigned Company</th>
          <th>Hours</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= strtoupper(substr($s['name'],0,2)) ?></div>
              <div>
                <div class="font-bold text-sm"><?= e($s['name']) ?></div>
                <div class="text-xs text-muted"><?= e($s['email']) ?></div>
              </div>
            </div>
          </td>
          <td class="td-mono text-sm"><?= e($s['student_id_no'] ?? '—') ?></td>
          <td class="text-sm"><?= e($s['program'] ?? '—') ?></td>
          <td class="text-sm"><?= e($s['company_name'] ?? '—') ?></td>
          <td class="td-mono"><?= number_format((float)$s['rendered_hours'], 1) ?> / <?= (int)$s['required_hours'] ?>h</td>
          <td>
            <?= status_badge($s['ojt_status']) ?>
            <?php if (!empty($s['status_notes']) && in_array($s['ojt_status'], ['on_hold', 'withdrawn'], true)): ?>
              <div class="text-xs text-muted" title="<?= e($s['status_notes']) ?>"><?= e(substr($s['status_notes'], 0, 40)) ?><?= strlen($s['status_notes']) > 40 ? '…' : '' ?></div>
            <?php endif; ?>
          </td>
          <td><a href="?id=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Manage</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
          <tr><td colspan="7" class="text-center text-muted py-6">No students found under your supervision.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
