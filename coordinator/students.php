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

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$status_filter = $status !== '' && in_array($status, $allowed_ojt_statuses, true) ? $status : null;

$available_students = [];
$available_companies = [];
if ($coord) {
    $active_term_id = normalized_active_term_id();
    $candidate_students = query(
        "SELECT s.id,s.student_id_no,u.name,u.email,s.program_id,s.program,p.code program_code,p.name program_name
         FROM students s
         JOIN users u ON u.id=s.user_id
         LEFT JOIN programs p ON p.id=s.program_id
         LEFT JOIN ojt_enrollments oe ON oe.student_id=s.id AND oe.academic_term_id=?
         WHERE s.is_archived=0 AND u.status='active' AND oe.id IS NULL
         ORDER BY u.name",
        [$active_term_id], 'i'
    ) ?: [];
    foreach ($candidate_students as $candidate) {
        $program = [
            'id' => (int)($candidate['program_id'] ?? 0),
            'code' => (string)($candidate['program_code'] ?? $candidate['program'] ?? ''),
            'name' => (string)($candidate['program_name'] ?? ''),
        ];
        if ($program['id'] > 0 && (int)find_coordinator_for_program($program) === (int)$coord['id']) {
            $available_students[] = $candidate;
        }
    }
    $available_companies = query("SELECT id,company_name FROM companies WHERE status='active' ORDER BY company_name") ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['assign_student', 'update_assignment'], true)) {
    require_csrf();
    $assignment_action = $_POST['action'];
    $sid = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $company_id = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $assignment_status = is_string($_POST['ojt_status'] ?? null) ? $_POST['ojt_status'] : 'pending';
    $required_hours = filter_var($_POST['required_hours'] ?? 486, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]);
    $candidate = $sid ? query_one(
        "SELECT s.id,s.program_id,s.program,u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.is_archived=0 AND u.status='active'",
        [$sid], 'i'
    ) : null;
    try {
        if (!$coord || !$candidate) throw new DomainException('Select an active student.');
        $program = $candidate['program_id']
            ? query_one("SELECT id,code,name FROM programs WHERE id=? AND status='active'", [(int)$candidate['program_id']], 'i')
            : null;
        if (!$program || (int)find_coordinator_for_program($program) !== (int)$coord['id']) {
            throw new DomainException('This student is not mapped to your coordinator program.');
        }
        $existing_enrollment = normalized_enrollment_for_student((int)$sid);
        if ($assignment_action === 'assign_student' && $existing_enrollment) {
            throw new DomainException('This student already has an active-term assignment.');
        }
        if ($assignment_action === 'update_assignment') {
            $existing_coord = $existing_enrollment ? normalized_current_coordinator_for_enrollment((int)$existing_enrollment['id']) : null;
            if (!$existing_coord || (int)$existing_coord['coordinator_id'] !== (int)$coord['id']) {
                throw new DomainException('You can only manage students assigned to your coordinator account.');
            }
        }
        if (!in_array($assignment_status, $allowed_ojt_statuses, true) || $required_hours === false) {
            throw new DomainException('Choose a valid OJT status and required hours between 1 and 9999.');
        }
        normalized_update_training_assignment(
            (int)$sid, (int)$user['id'], (int)$coord['id'], $company_id, (int)$program['id'],
            $assignment_status, (int)$required_hours, null, null, null
        );
        log_activity((int)$user['id'], $assignment_action === 'assign_student' ? 'Student Assignment Created' : 'Student Assignment Updated', ($assignment_action === 'assign_student' ? 'Assigned ' : 'Updated ') . $candidate['name'] . ' (' . $program['code'] . ').');
        $success = $assignment_action === 'assign_student' ? 'Student assigned successfully.' : 'Student assignment updated successfully.';
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    }
}

$students = $coord ? normalized_students_for_coordinator((int)$coord['id'], $search, $status_filter) : [];

$detail = null;
if (isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    $candidate = $coord ? normalized_student_context($sid) : null;
    $enrollment = $candidate && !empty($candidate['_enrollment_id'])
        ? normalized_current_coordinator_for_enrollment((int)$candidate['_enrollment_id']) : null;
    if ($candidate && $enrollment && (int)$enrollment['coordinator_id'] === (int)$coord['id']
        && empty($candidate['is_archived']) && ($candidate['user_status'] ?? '') !== 'archived') {
        $detail = $candidate;
        $company = !empty($detail['company_id'])
            ? query_one("SELECT supervisor_name FROM companies WHERE id=? LIMIT 1", [(int)$detail['company_id']], 'i') : null;
        $detail['supervisor'] = $company['supervisor_name'] ?? null;
    }
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
    <div class="page-sub">Placements, OJT status, and student records</div>
  </div>
  <?php if ($available_students): ?><button type="button" class="btn btn-primary" onclick="openModal('coordinatorAssignStudentModal')">Assign Student</button><?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($available_students): ?>
<div class="modal-overlay" id="coordinatorAssignStudentModal">
  <section class="modal modal-lg" role="dialog" aria-modal="true" aria-labelledby="coordinatorAssignTitle">
    <header class="modal-header"><div><h2 class="modal-title" id="coordinatorAssignTitle">Student Assignment</h2><p class="modal-sub">Assign an unassigned student from your program.</p></div><button type="button" class="modal-close" onclick="closeModal('coordinatorAssignStudentModal')" aria-label="Close">&times;</button></header>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="assign_student">
      <div class="modal-body">
        <label class="form-label" for="coordinatorAssignStudent">Student</label>
        <select class="form-control" id="coordinatorAssignStudent" name="student_id" required>
          <option value="">Select a student</option>
          <?php foreach ($available_students as $candidate): ?><option value="<?= (int)$candidate['id'] ?>"><?= e($candidate['name']) ?> (<?= e($candidate['student_id_no']) ?>) · <?= e($candidate['program_code'] ?? $candidate['program']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label mt-4" for="coordinatorAssignCompany">Partner Company</label>
        <select class="form-control" id="coordinatorAssignCompany" name="company_id">
          <option value="">No company assigned yet</option>
          <?php foreach ($available_companies as $company): ?><option value="<?= (int)$company['id'] ?>"><?= e($company['company_name']) ?></option><?php endforeach; ?>
        </select>
        <div class="form-row mt-4"><div class="form-group"><label class="form-label" for="coordinatorAssignStatus">OJT Status</label><select class="form-control" id="coordinatorAssignStatus" name="ojt_status"><?php foreach ($allowed_ojt_statuses as $assignment_status_option): ?><option value="<?= e($assignment_status_option) ?>" <?= $assignment_status_option === 'pending' ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $assignment_status_option))) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label" for="coordinatorAssignHours">Required Hours</label><input class="form-control" id="coordinatorAssignHours" name="required_hours" type="number" min="1" max="9999" value="486" required></div></div>
        <p class="text-xs text-muted mt-2">The academic program and coordinator are taken from the student record and program mapping.</p>
      </div>
      <footer class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('coordinatorAssignStudentModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Assignment</button></footer>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="coordinator-student-filter-source">
    <form class="filter-toolbar" method="GET" data-live-filter>
      <div class="search-wrap" style="width:240px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search name or ID..." value="<?= e($search) ?>">
      </div>
      <select name="status" class="form-control" style="width:180px">
        <option value="">All Status</option>
        <?php foreach ($status_labels as $val => $label): ?>
          <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= e(explode(' (', $label)[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($search || $status): ?><a href="/ojtrack/coordinator/students.php" class="btn btn-ghost">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
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
        <?php
          $student_modal_payload = [
            'id' => (int)$s['id'],
            'name' => (string)$s['name'],
            'initials' => user_avatar_initials((string)$s['name']),
            'avatar_url' => user_avatar_url($s['avatar'] ?? ''),
            'student_id_no' => (string)($s['student_id_no'] ?? ''),
            'program' => (string)($s['program'] ?? ''),
            'department' => (string)($s['department'] ?? ''),
            'year_level' => (string)($s['year_level'] ?? ''),
            'email' => (string)($s['email'] ?? ''),
            'contact_number' => (string)($s['contact_number'] ?? ''),
            'company_name' => (string)($s['company_name'] ?? ''),
            'company_id' => (int)($s['company_id'] ?? 0),
            'supervisor' => (string)($s['supervisor_name'] ?? ''),
            'start_date' => format_date($s['ojt_start_date'] ?? null),
            'end_date' => format_date($s['ojt_end_date'] ?? null),
            'rendered_hours' => (float)($s['rendered_hours'] ?? 0),
            'required_hours' => (int)($s['required_hours'] ?? 0),
            'status' => (string)($s['ojt_status'] ?? 'not_started'),
            'status_notes' => (string)($s['status_notes'] ?? ''),
          ];
          $student_modal_json = json_encode($student_modal_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
        ?>
        <tr class="row-clickable coordinator-student-row" tabindex="0" aria-haspopup="dialog" aria-label="View details for <?= e($s['name']) ?>" data-student-details="<?= e($student_modal_json ?: '{}') ?>" title="View student details">
          <td>
            <div class="table-person">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= user_avatar_content($s['avatar'] ?? null, $s['name']) ?></div>
              <div>
                <a href="/ojtrack/coordinator/students.php?id=<?= (int)$s['id'] ?>" class="font-bold text-sm" onclick="event.preventDefault();openCoordinatorStudentDetails(this.closest('tr'));"><?= e($s['name']) ?></a>
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
          <td>
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary" title="View student details" aria-label="View details for <?= e($s['name']) ?>" onclick="openCoordinatorStudentDetails(this.closest('tr'))">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h2M10 17h10M14 5v4M8 15v4"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
          <tr><td colspan="7" class="text-center text-muted py-6">No students found under your supervision.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay coordinator-student-details-overlay" id="coordinatorStudentDetailsModal">
  <section class="modal modal-lg coordinator-student-details-modal" role="dialog" aria-modal="true" aria-labelledby="coordinatorStudentModalTitle" tabindex="-1">
    <header class="coordinator-student-modal-header">
      <div>
        <span class="coordinator-student-modal-eyebrow">Student record</span>
        <h2 class="modal-title" id="coordinatorStudentModalTitle">Student Details</h2>
        <p>Academic information and current OJT placement</p>
      </div>
      <button type="button" class="coordinator-student-modal-close" onclick="closeModal('coordinatorStudentDetailsModal')" aria-label="Close student details">&times;</button>
    </header>
    <div class="coordinator-student-modal-body">
      <section class="card card-body student-record-summary" aria-label="Student summary">
        <div class="student-record-identity">
          <div class="student-record-avatar coordinator-student-modal-avatar" id="coordinatorStudentAvatar" aria-hidden="true"></div>
          <div class="student-record-name">
            <h2 id="coordinatorStudentName">Student</h2>
            <div class="student-record-meta"><span class="td-mono" id="coordinatorStudentId">—</span><span class="badge badge-pending" id="coordinatorStudentStatus">Not Started</span></div>
          </div>
        </div>
        <div class="student-record-progress">
          <div class="student-record-hours"><span>OJT Hours</span><strong id="coordinatorStudentHours">0 / 0h</strong></div>
          <div class="progress-track coordinator-student-progress-circle" id="coordinatorStudentProgress" role="progressbar" aria-label="OJT completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <span class="progress-fill blue" id="coordinatorStudentPercent">0%</span>
          </div>
        </div>
      </section>
      <div class="alert alert-warn coordinator-student-status-notes" id="coordinatorStudentStatusNotes" hidden><div class="alert-body"><p><strong>Status notes:</strong> <span id="coordinatorStudentStatusNotesText"></span></p></div></div>
      <div class="student-record-grid">
        <section class="card card-body" aria-label="Academic and contact details">
          <h3 class="section-title mb-3">Academic &amp; Contact Details</h3>
          <dl class="student-record-fields">
            <div><dt>Program</dt><dd data-student-field="program">—</dd></div>
            <div data-student-department-row hidden><dt>Department</dt><dd data-student-field="department">—</dd></div>
            <div><dt>Year Level</dt><dd data-student-field="year_level">—</dd></div>
            <div><dt>Email</dt><dd data-student-field="email">—</dd></div>
            <div><dt>Contact Number</dt><dd data-student-field="contact_number">—</dd></div>
          </dl>
        </section>
        <section class="card card-body" aria-label="OJT placement">
          <h3 class="section-title mb-3">OJT Placement</h3>
          <dl class="student-record-fields">
            <div><dt>Company</dt><dd data-student-field="company_name">—</dd></div>
            <div><dt>Supervisor</dt><dd data-student-field="supervisor">—</dd></div>
            <div><dt>Start Date</dt><dd data-student-field="start_date">—</dd></div>
            <div><dt>End Date</dt><dd data-student-field="end_date">—</dd></div>
          </dl>
          <div class="student-record-links coordinator-student-modal-links">
            <button class="btn btn-secondary" id="coordinatorStudentAttendance" type="button">Attendance</button>
            <button class="btn btn-secondary" id="coordinatorStudentRequirements" type="button">Requirements</button>
            <a class="btn btn-secondary" id="coordinatorStudentMonitoring" href="#">Daily Journals</a>
            <button class="btn btn-primary coordinator-manage-assignment-btn" id="coordinatorStudentAssignment" type="button">Manage Assignment</button>
          </div>
        </section>
      </div>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../includes/coordinator-attendance-modal.php'; ?>

<div class="modal-overlay coordinator-requirements-overlay" id="coordinatorStudentRequirementsModal">
  <section class="modal coordinator-requirements-modal" role="dialog" aria-modal="true" aria-labelledby="coordinatorStudentRequirementsTitle" tabindex="-1">
    <header class="coordinator-requirements-header">
      <div>
        <span class="coordinator-student-modal-eyebrow">Student documents</span>
        <h2 class="modal-title" id="coordinatorStudentRequirementsTitle">Submitted Requirements</h2>
        <p id="coordinatorStudentRequirementsStudent">Requirements submitted by this student</p>
      </div>
      <button type="button" class="coordinator-student-modal-close" onclick="closeModal('coordinatorStudentRequirementsModal')" aria-label="Close submitted requirements">&times;</button>
    </header>
    <div class="coordinator-requirements-toolbar">
      <label class="search-wrap coordinator-requirements-search">
        <span class="sr-only">Search requirements and files</span>
        <input type="search" class="form-control search-input" id="coordinatorRequirementsSearch" placeholder="Search requirement or file..." autocomplete="off">
      </label>
      <label class="coordinator-requirements-status-filter">
        <span class="sr-only">Filter requirements by status</span>
        <select class="form-control" id="coordinatorRequirementsStatus">
          <option value="">All statuses</option>
          <option value="pending">For review</option>
          <option value="approved">Approved</option>
          <option value="rejected">Returned</option>
        </select>
      </label>
      <span class="coordinator-requirements-count" id="coordinatorRequirementsCount" aria-live="polite"></span>
    </div>
    <div class="coordinator-requirements-table-wrap">
      <table class="coordinator-requirements-table">
        <thead><tr><th>Requirement</th><th>Submitted</th><th>Status</th><th>File</th><th>Actions</th></tr></thead>
        <tbody id="coordinatorRequirementsRows">
          <tr><td colspan="5" class="text-center text-muted py-6">Loading submitted requirements…</td></tr>
        </tbody>
      </table>
    </div>
    <footer class="coordinator-requirements-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('coordinatorStudentRequirementsModal')">Close</button>
    </footer>
  </section>
</div>

<div class="modal-overlay" id="coordinatorManageAssignmentModal">
  <section class="modal modal-lg coordinator-assignment-modal" role="dialog" aria-modal="true" aria-labelledby="coordinatorManageAssignmentTitle">
    <header class="coordinator-student-modal-header coordinator-assignment-header"><div><span class="coordinator-student-modal-eyebrow">Academic placement</span><h2 class="modal-title" id="coordinatorManageAssignmentTitle">Student Assignment</h2><p id="coordinatorManageAssignmentStudent"></p></div><button type="button" class="coordinator-student-modal-close" onclick="closeModal('coordinatorManageAssignmentModal')" aria-label="Close">&times;</button></header>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_assignment"><input type="hidden" name="student_id" id="coordinatorManageStudentId">
      <div class="modal-body coordinator-assignment-body">
        <div class="coordinator-assignment-section-title">Academic Placement</div>
        <label class="form-label">Academic Program</label><input class="form-control" id="coordinatorManageProgram" readonly>
        <label class="form-label mt-4">Assigned Coordinator</label><input class="form-control" value="<?= e($coord['name'] ?? 'Current coordinator') ?>" readonly>
        <label class="form-label mt-4" for="coordinatorManageCompany">Partner Company</label>
        <select class="form-control" id="coordinatorManageCompany" name="company_id"><option value="">No company assigned</option><?php foreach ($available_companies as $company): ?><option value="<?= (int)$company['id'] ?>"><?= e($company['company_name']) ?></option><?php endforeach; ?></select>
        <div class="coordinator-assignment-section-title mt-5">OJT Progress</div><div class="form-row"><div class="form-group"><label class="form-label" for="coordinatorManageStatus">OJT Status</label><select class="form-control" id="coordinatorManageStatus" name="ojt_status"><?php foreach ($allowed_ojt_statuses as $assignment_status_option): ?><option value="<?= e($assignment_status_option) ?>"><?= e(ucwords(str_replace('_', ' ', $assignment_status_option))) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label" for="coordinatorManageHours">Required Hours</label><input class="form-control" id="coordinatorManageHours" name="required_hours" type="number" min="1" max="9999" required></div></div>
      </div>
      <footer class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('coordinatorManageAssignmentModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Assignment</button></footer>
    </form>
  </section>
</div>

<script>
function openCoordinatorAssignment(student) {
  closeModal('coordinatorStudentDetailsModal');
  document.getElementById('coordinatorManageStudentId').value = student.id || '';
  document.getElementById('coordinatorManageAssignmentStudent').textContent = (student.name || 'Student') + (student.student_id_no ? ' (' + student.student_id_no + ')' : '');
  document.getElementById('coordinatorManageProgram').value = student.program || '—';
  document.getElementById('coordinatorManageCompany').value = student.company_id ? String(student.company_id) : '';
  document.getElementById('coordinatorManageStatus').value = student.status || 'pending';
  document.getElementById('coordinatorManageHours').value = student.required_hours || 486;
  openModal('coordinatorManageAssignmentModal');
}
function openCoordinatorStudentDetails(rowOrData) {
  let student;
  try {
    student = typeof rowOrData === 'string' ? JSON.parse(rowOrData) : (rowOrData?.dataset?.studentDetails ? JSON.parse(rowOrData.dataset.studentDetails) : rowOrData);
  } catch (error) { return; }
  if (!student || !student.id) return;

  const setField = (key, value) => {
    const field = document.querySelector('[data-student-field="' + key + '"]');
    if (field) field.textContent = value || 'Not provided';
  };
  document.getElementById('coordinatorStudentName').textContent = student.name || 'Student';
  document.getElementById('coordinatorStudentId').textContent = student.student_id_no || '—';
  const avatar = document.getElementById('coordinatorStudentAvatar');
  avatar.replaceChildren();
  if (student.avatar_url) {
    const image = document.createElement('img');
    image.src = student.avatar_url;
    image.alt = '';
    image.className = 'user-avatar-photo';
    image.decoding = 'async';
    avatar.appendChild(image);
  } else {
    avatar.textContent = student.initials || 'S';
  }
  const labels = {pending:'Pending',not_started:'Not Started',ongoing:'Ongoing',completed:'Completed',on_hold:'On Hold',withdrawn:'Withdrawn'};
  const badgeClasses = {pending:'badge-pending',not_started:'badge-pending',ongoing:'badge-ongoing',completed:'badge-completed',on_hold:'badge-review',withdrawn:'badge-rejected'};
  const status = String(student.status || 'not_started').toLowerCase();
  const statusBadge = document.getElementById('coordinatorStudentStatus');
  statusBadge.className = 'badge ' + (badgeClasses[status] || 'badge-pending');
  statusBadge.textContent = labels[status] || status.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());

  ['program','department','year_level','email','contact_number','company_name','supervisor','start_date','end_date'].forEach(key => setField(key, student[key]));
  const departmentRow = document.querySelector('[data-student-department-row]');
  departmentRow.hidden = !student.department;
  const notes = document.getElementById('coordinatorStudentStatusNotes');
  notes.hidden = !student.status_notes || !['on_hold','withdrawn'].includes(status);
  document.getElementById('coordinatorStudentStatusNotesText').textContent = student.status_notes || '';

  const hours = Math.max(0, Number(student.rendered_hours) || 0);
  const requiredHours = Math.max(0, Number(student.required_hours) || 0);
  const percent = requiredHours > 0 ? Math.max(0, Math.min(100, Math.round(hours / requiredHours * 100))) : 0;
  document.getElementById('coordinatorStudentHours').textContent = hours.toFixed(1) + ' / ' + requiredHours.toLocaleString() + 'h';
  const progressCircle = document.getElementById('coordinatorStudentProgress');
  progressCircle.style.setProperty('--pct', percent);
  progressCircle.setAttribute('aria-valuenow', String(percent));
  document.getElementById('coordinatorStudentPercent').textContent = percent + '%';
  document.getElementById('coordinatorStudentPercent').classList.toggle('green', percent >= 100);
  document.getElementById('coordinatorStudentPercent').classList.toggle('blue', percent < 100);
  document.getElementById('coordinatorStudentAttendance').onclick = () => window.openCoordinatorAttendanceDetails(student.id);
  document.getElementById('coordinatorStudentRequirements').onclick = () => openCoordinatorStudentRequirements(student);
  document.getElementById('coordinatorStudentMonitoring').href = '/ojtrack/coordinator/monitoring.php?student=' + encodeURIComponent(student.id);
  document.getElementById('coordinatorStudentAssignment').onclick = () => openCoordinatorAssignment(student);
  openModal('coordinatorStudentDetailsModal');
}

let coordinatorRequirementItems = [];

function coordinatorRequirementFileSize(bytes) {
  const size = Number(bytes) || 0;
  if (!size) return '';
  return size >= 1048576 ? (size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(size / 1024)) + ' KB';
}

function renderCoordinatorStudentRequirements() {
  const body = document.getElementById('coordinatorRequirementsRows');
  const search = (document.getElementById('coordinatorRequirementsSearch').value || '').trim().toLowerCase();
  const status = document.getElementById('coordinatorRequirementsStatus').value;
  const matching = coordinatorRequirementItems.filter(item => {
    const matchesSearch = !search || (item.requirement + ' ' + item.file_name).toLowerCase().includes(search);
    return matchesSearch && (!status || item.status === status);
  });
  document.getElementById('coordinatorRequirementsCount').textContent = matching.length + ' of ' + coordinatorRequirementItems.length + ' file' + (coordinatorRequirementItems.length === 1 ? '' : 's');
  body.replaceChildren();

  if (!matching.length) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 5;
    cell.className = 'text-center text-muted py-6';
    cell.textContent = coordinatorRequirementItems.length ? 'No files match these search and filter settings.' : 'This student has not submitted any requirement files yet.';
    row.append(cell);
    body.append(row);
    return;
  }

  matching.forEach(item => {
    const row = document.createElement('tr');
    row.className = 'coordinator-requirements-file-row';
    row.tabIndex = 0;
    row.setAttribute('aria-label', 'View ' + item.file_name);
    const fileUrl = '/ojtrack/download.php?file=' + encodeURIComponent(item.file_path);
    const openFile = () => window.openAttachmentViewer?.({name:item.file_name,url:fileUrl,size:item.size_bytes});
    const requirementCell = document.createElement('td');
    const requirementName = document.createElement('strong');
    requirementName.textContent = item.requirement;
    requirementCell.append(requirementName);

    const submittedCell = document.createElement('td');
    submittedCell.textContent = item.submitted_at || '—';

    const statusCell = document.createElement('td');
    const statusBadge = document.createElement('span');
    statusBadge.className = 'badge ' + ({pending:'badge-pending',approved:'badge-approved',rejected:'badge-rejected'}[item.status] || 'badge-pending');
    statusBadge.textContent = ({pending:'For review',approved:'Approved',rejected:'Returned'}[item.status] || 'Submitted');
    statusCell.append(statusBadge);

    const fileCell = document.createElement('td');
    fileCell.className = 'coordinator-requirements-file-name';
    fileCell.textContent = item.file_name;
    const size = coordinatorRequirementFileSize(item.size_bytes);
    if (size) fileCell.title = size;

    const actionCell = document.createElement('td');
    const actions = document.createElement('div');
    actions.className = 'coordinator-requirements-actions';
    const view = document.createElement('button');
    view.type = 'button';
    view.className = 'btn btn-secondary btn-sm coordinator-requirement-icon-action';
    view.title = 'View ' + item.file_name;
    view.setAttribute('aria-label', view.title);
    view.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>';
    view.addEventListener('click', openFile);
    const download = document.createElement('a');
    download.className = 'btn btn-primary btn-sm coordinator-requirement-icon-action';
    download.href = fileUrl;
    download.download = item.file_name;
    download.title = 'Download ' + item.file_name;
    download.setAttribute('aria-label', download.title);
    download.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v11m0 0 4-4m-4 4-4-4"></path><path d="M5 15v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"></path></svg>';
    actions.append(view, download);
    actionCell.append(actions);
    row.append(requirementCell, submittedCell, statusCell, fileCell, actionCell);
    row.addEventListener('click', event => {
      if (event.target.closest('a, button, input, select, textarea, label')) return;
      openFile();
    });
    row.addEventListener('keydown', event => {
      if ((event.key === 'Enter' || event.key === ' ') && event.target === row) {
        event.preventDefault();
        openFile();
      }
    });
    body.append(row);
  });
}

async function openCoordinatorStudentRequirements(student) {
  const overlay = document.getElementById('coordinatorStudentRequirementsModal');
  const body = document.getElementById('coordinatorRequirementsRows');
  document.getElementById('coordinatorStudentRequirementsStudent').textContent = (student.name || 'Student') + ' · ' + (student.student_id_no || '');
  document.getElementById('coordinatorRequirementsSearch').value = '';
  document.getElementById('coordinatorRequirementsStatus').value = '';
  document.getElementById('coordinatorRequirementsCount').textContent = 'Loading…';
  body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-6">Loading submitted requirements…</td></tr>';
  coordinatorRequirementItems = [];
  openModal('coordinatorStudentRequirementsModal');
  try {
    const response = await fetch('/ojtrack/api/coordinator-requirements.php?student_id=' + encodeURIComponent(student.id), {credentials:'same-origin',headers:{'Accept':'application/json'}});
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Could not load this student’s requirements.');
    coordinatorRequirementItems = Array.isArray(result.items) ? result.items : [];
    renderCoordinatorStudentRequirements();
  } catch (error) {
    body.replaceChildren();
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 5;
    cell.className = 'text-center text-danger py-6';
    cell.textContent = error.message || 'Could not load this student’s requirements. Please try again.';
    row.append(cell);
    body.append(row);
    document.getElementById('coordinatorRequirementsCount').textContent = '';
  }
}

document.getElementById('coordinatorRequirementsSearch').addEventListener('input', renderCoordinatorStudentRequirements);
document.getElementById('coordinatorRequirementsStatus').addEventListener('change', renderCoordinatorStudentRequirements);

document.addEventListener('click', function (event) {
  const row = event.target.closest('tr.coordinator-student-row');
  if (!row || event.defaultPrevented || event.button !== 0 || event.target.closest('a, button, input, select, textarea, label, form')) return;
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || window.getSelection()?.toString()) return;
  openCoordinatorStudentDetails(row);
});
document.addEventListener('keydown', function (event) {
  const row = event.target.closest('tr.coordinator-student-row');
  if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault();
    openCoordinatorStudentDetails(row);
  }
});
<?php if ($detail): ?>
document.addEventListener('DOMContentLoaded', function () {
openCoordinatorStudentDetails(<?= json_encode([
  'id' => (int)$detail['id'],
  'name' => (string)$detail['name'],
  'initials' => user_avatar_initials((string)$detail['name']),
  'avatar_url' => user_avatar_url($detail['avatar'] ?? ''),
  'student_id_no' => (string)($detail['student_id_no'] ?? ''),
  'program' => (string)($detail['program'] ?? ''),
  'department' => (string)($detail['department'] ?? ''),
  'year_level' => (string)($detail['year_level'] ?? ''),
  'email' => (string)($detail['email'] ?? ''),
  'contact_number' => (string)($detail['contact_number'] ?? ''),
  'company_name' => (string)($detail['company_name'] ?? ''),
  'company_id' => (int)($detail['company_id'] ?? 0),
  'supervisor' => (string)($detail['supervisor'] ?? ''),
  'start_date' => format_date($detail['ojt_start_date'] ?? null),
  'end_date' => format_date($detail['ojt_end_date'] ?? null),
  'rendered_hours' => (float)($detail['rendered_hours'] ?? 0),
  'required_hours' => (int)($detail['required_hours'] ?? 0),
  'status' => (string)($detail['ojt_status'] ?? 'not_started'),
  'status_notes' => (string)($detail['status_notes'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
