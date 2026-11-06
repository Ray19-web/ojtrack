<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
try {
    $active_academic_term = normalized_active_term();
} catch (Throwable $term_storage_error) {
    $active_academic_term = null;
}
$success = '';
$error = '';
$allowed_ojt_statuses = ['pending', 'not_started', 'ongoing', 'completed', 'on_hold', 'withdrawn'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_student') {
    $sid = (int)($_POST['student_id'] ?? 0);
    // Program is owned by the student profile. Never trust an editable form value here.
    $program_id = null;
    $company_id = array_key_exists('company_id', $_POST) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : null;
    $ojt_status = is_string($_POST['ojt_status'] ?? null) ? $_POST['ojt_status'] : 'pending';
    $req_hours = filter_var($_POST['required_hours'] ?? 486, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]);
    $student = query_one(
        "SELECT s.*, u.name, u.id AS user_id, u.status AS user_status
         FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?",
        [$sid],
        'i'
    );

    if (!$student) {
        $error = 'Student not found.';
    } elseif (!empty($student['is_archived']) || ($student['user_status'] ?? '') === 'archived') {
        $error = 'Cannot update assignments for an archived student. Restore the student first.';
    } elseif (!in_array($ojt_status, $allowed_ojt_statuses, true) || $req_hours === false) {
        $error = 'Choose a valid OJT status and required hours between 1 and 9999.';
    } else {
        $current_term_enrollment = normalized_enrollment_for_student($sid);
        $current_term_placement = $current_term_enrollment
            ? normalized_current_placement_for_enrollment((int)$current_term_enrollment['id']) : null;
        $program = !empty($student['program_id'])
            ? query_one("SELECT id, code, name FROM programs WHERE id=? AND status='active'", [(int)$student['program_id']], 'i')
            : null;
        $legacy_program_code = trim((string)($student['program'] ?? ''));
        if (!$program && $legacy_program_code !== '') {
            $program = query_one("SELECT id, code, name FROM programs WHERE (code=? OR name=?) AND status='active' LIMIT 1", [$legacy_program_code, $legacy_program_code], 'ss');
        }
        if ($program) {
            $program_id = (int)$program['id'];
            $prog_code = $program['code'];
            $prog_name = $program['name'];
        } else {
            $prog_code = '';
            $prog_name = '';
            $error = 'Set an active academic program in Student Information before assigning this student.';
        }

        if ($error === '') {
            if ($program_id && $prog_code !== '') {
                $coord_id = find_coordinator_for_program(['code' => $prog_code, 'name' => $prog_name]);
                if (!$coord_id) {
                    $selected_coordinator_id = filter_var($_POST['coordinator_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $selected_coordinator = $selected_coordinator_id
                        ? query_one("SELECT c.id FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=? AND u.role='coordinator' AND u.status='active'", [(int)$selected_coordinator_id], 'i')
                        : null;
                    if (!$selected_coordinator) {
                        $label = $prog_code . ($prog_name !== '' ? ' - ' . $prog_name : '');
                        $error = "No coordinator is mapped to \"{$label}\". Choose an active coordinator in this assignment form.";
                    } else $coord_id = (int)$selected_coordinator['id'];
                }
                if ($error === '') {
                    try {
                        if (!normalized_update_training_assignment(
                            $sid, (int)$user['id'], (int)$coord_id, $company_id, $program_id,
                            $ojt_status, (int)$req_hours,
                            $current_term_placement['starts_on'] ?? null,
                            $current_term_placement['ends_on'] ?? null,
                            $current_term_enrollment['status_notes'] ?? null
                        )) throw new DomainException('Select a valid academic program and required hours.');
                    } catch (DomainException $exception) {
                        $error = $exception->getMessage();
                    }
                    if ($error === '') {
                        $coord_name = query_one("SELECT u.name FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=?", [$coord_id], 'i');
                        $coord_label = $coord_name['name'] ?? 'Coordinator';
                        log_activity($user['id'], 'Student Assignment Updated', "Updated assignments for {$student['name']} → Program: {$prog_code}, Coord: {$coord_label}");
                        $saved_term_enrollment = normalized_enrollment_for_student($sid);
                        $hours_complete = $saved_term_enrollment
                            && (int)$saved_term_enrollment['required_hours'] > 0
                            && normalized_official_minutes_for_enrollment((int)$saved_term_enrollment['id']) >= (int)$saved_term_enrollment['required_hours'] * 60;
                        if ($ojt_status === 'completed' && $hours_complete) {
                            create_notification($student['user_id'], 'Congratulations! Your OJT has been completed and your rendered hours are finished. Your Certificate of Recognition is now available.', 'success', '/ojtrack/student/certificate.php');
                        } else {
                            create_notification($student['user_id'], 'Your student program, company, or OJT assignment was updated by the administrator.', 'info', '/ojtrack/student/dashboard.php');
                        }
                        $success = "Assignment updated for {$student['name']} ({$prog_code}; coordinator: {$coord_label}).";
                    }
                }
            }
        }
    }
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], $allowed_ojt_statuses, true) ? $_GET['status'] : '';
$program_filter = is_string($_GET['program_id'] ?? null) ? $_GET['program_id'] : '';
$where = "s.is_archived=0 AND u.status!='archived'";
$params = [];
$types = '';
if ($search !== '') {
    $where .= ' AND (u.name LIKE ? OR s.student_id_no LIKE ? OR u.email LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
    $types .= 'sss';
}
if ($status !== '') {
    $where .= ' AND oe.status=?';
    $params[] = $status;
    $types .= 's';
}
if ($program_filter !== '' && ctype_digit($program_filter)) {
    $where .= ' AND (COALESCE(oe.program_id,s.program_id)=? OR (s.program_id IS NULL AND p.id=?))';
    array_push($params, (int)$program_filter, (int)$program_filter);
    $types .= 'ii';
}
$coordinator_name_sql = normalized_lean_schema_ready()
    ? "(SELECT cu2.name FROM ojt_enrollments oe2 JOIN coordinators c2 ON c2.id=oe2.coordinator_id JOIN users cu2 ON cu2.id=c2.user_id WHERE oe2.id=oe.id LIMIT 1)"
    : "(SELECT cu2.name FROM enrollment_coordinators ec2 JOIN coordinators c2 ON c2.id=ec2.coordinator_id JOIN users cu2 ON cu2.id=c2.user_id WHERE ec2.ojt_enrollment_id=oe.id AND ec2.ended_at IS NULL ORDER BY ec2.id DESC LIMIT 1)";
$students = query(
    "SELECT s.*, u.name, u.email, u.avatar, co.company_name, $coordinator_name_sql AS coordinator_name,
            COALESCE(oe.program_id,p.id) AS prog_id, COALESCE(op.code,p.code,s.program) AS program_code,
            COALESCE(op.name,p.name) AS program_name,
            COALESCE(oe.required_hours,0) AS active_required_hours, oe.status AS active_ojt_status,
            COALESCE((SELECT SUM(ad.credited_minutes)/60 FROM attendance_days ad JOIN placements hp ON hp.id=ad.placement_id WHERE hp.ojt_enrollment_id=oe.id),0) AS active_rendered_hours,
            oe.id AS _enrollment_id, oe.status_notes AS active_status_notes,
            pl.company_id AS active_company_id, pl.starts_on AS active_start_date, pl.ends_on AS active_end_date
     FROM students s JOIN users u ON u.id=s.user_id
     LEFT JOIN ojt_enrollments oe ON oe.student_id=s.id AND oe.academic_term_id=(SELECT id FROM academic_terms WHERE status='active' ORDER BY id DESC LIMIT 1)
     LEFT JOIN placements pl ON pl.id=(SELECT p2.id FROM placements p2 WHERE p2.ojt_enrollment_id=oe.id AND p2.status IN ('active','on_hold','planned','completed') ORDER BY FIELD(p2.status,'active','on_hold','planned','completed'),p2.id DESC LIMIT 1)
     LEFT JOIN companies co ON co.id=pl.company_id
     LEFT JOIN programs p ON (p.id=s.program_id OR (s.program_id IS NULL AND p.code=s.program))
     LEFT JOIN programs op ON op.id=oe.program_id
     WHERE $where ORDER BY u.name ASC",
    $params,
    $types
) ?: [];
$programs = query("SELECT id, code, name FROM programs WHERE status='active' ORDER BY code ASC", [], '') ?: [];
$all_programs = query("SELECT id, code, name FROM programs ORDER BY code ASC", [], '') ?: [];
$coordinators = query("SELECT c.id, u.name, c.department FROM coordinators c JOIN users u ON u.id=c.user_id WHERE u.status='active' ORDER BY u.name", [], '') ?: [];
$companies = query("SELECT id, company_name FROM companies WHERE status='active' ORDER BY company_name", [], '') ?: [];
$program_options = [];
foreach ($programs as $program) {
    $program_options[] = [
        'id' => (int)$program['id'], 'code' => $program['code'], 'name' => $program['name'],
        'label' => $program['code'] . ' - ' . $program['name'],
        'coordinator_id' => find_coordinator_for_program($program),
    ];
}
$coord_dept_map = array_map(static fn($coordinator) => [
    'id' => (int)$coordinator['id'], 'name' => $coordinator['name'], 'department' => $coordinator['department'] ?? '',
], $coordinators);
$page_title = 'Student Assignments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div><div class="page-title">Student Assignments</div><div class="page-sub">Manage academic programs, coordinators, placements, and OJT status.</div></div>
  <a class="btn btn-secondary" href="/ojtrack/admin/students.php">Student Information</a>
</div>
<div class="assignment-term-context" data-tour-assignment-term>
  <span class="assignment-term-context-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v15H4zM8 3v4M16 3v4M4 10h16M8 14h3m-3 3h6"/></svg></span>
  <span><strong><?= $active_academic_term ? 'Current term for assignment changes' : 'No active academic term' ?></strong><small><?php if ($active_academic_term): ?><?= (int)$active_academic_term['academic_year_start'] ?>–<?= (int)$active_academic_term['academic_year_end'] ?> · <?= e($active_academic_term['semester'] === 'summer' ? 'Summer' : $active_academic_term['semester'] . ' Semester') ?><?php else: ?>Activate a term before saving OJT assignments.<?php endif; ?></small></span>
  <a href="/ojtrack/admin/academic-terms.php">Manage terms <span aria-hidden="true">→</span></a>
</div>
<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header student-filter-panel">
    <form class="student-filters" method="GET" data-live-filter>
      <div class="student-filter-search"><label class="form-label" for="assignmentSearch">Search students</label><input type="search" id="assignmentSearch" name="q" class="form-control" placeholder="Search student, ID, email..." value="<?= e($search) ?>"></div>
      <div><label class="form-label" for="assignmentProgram">Academic program</label><select id="assignmentProgram" name="program_id" class="form-control"><option value="">All Programs</option><?php foreach ($all_programs as $program): ?><option value="<?= (int)$program['id'] ?>" <?= (string)$program_filter === (string)$program['id'] ? 'selected' : '' ?>><?= e($program['code'] . ' - ' . $program['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label" for="assignmentStatus">OJT status</label><select id="assignmentStatus" name="status" class="form-control"><option value="">All Statuses</option><?php foreach (['pending'=>'Pending','not_started'=>'Not Started','ongoing'=>'Ongoing','completed'=>'Completed','on_hold'=>'On Hold','withdrawn'=>'Withdrawn'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <?php if ($search !== '' || $status !== '' || $program_filter !== ''): ?><a href="/ojtrack/admin/assignments.php" class="btn btn-secondary">Clear</a><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Student assignments">
    <table class="assignment-table"><thead><tr><th>Student</th><th>Student ID</th><th>Program</th><th>Assigned Company</th><th>Coordinator</th><th>Hours</th><th>Progress</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($students as $student):
      $required = (float)($student['active_required_hours'] ?? 0); $rendered = (float)($student['active_rendered_hours'] ?? 0); $percent = $required > 0 ? min(100, round($rendered / $required * 100)) : 0;
      $payload = [
        'id'=>(int)$student['id'], 'name'=>$student['name'], 'student_id_no'=>$student['student_id_no'] ?? '',
        'program_id'=>$student['program_id'] ?? null, 'prog_id'=>$student['prog_id'] ?? null,
        'program_code'=>$student['program_code'] ?? ($student['program'] ?? ''), 'program_name'=>$student['program_name'] ?? '',
        'company_id'=>$student['active_company_id'] ?? null, 'company_name'=>$student['company_name'] ?? '',
        'coordinator_name'=>$student['coordinator_name'] ?? '', 'ojt_status'=>$student['active_ojt_status'] ?: 'pending',
        'required_hours'=>$student['active_required_hours'] ?: 486, 'rendered_hours'=>$student['active_rendered_hours'] ?? 0,
      ];
      $payload_json = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    ?>
        <tr class="assignment-edit-row" data-assignment="<?= $payload_json ?>" tabindex="0" aria-label="Edit assignment for <?= e($student['name']) ?>" title="Click to edit this assignment"><td><div class="table-person"><div class="font-bold text-sm"><?= e($student['name']) ?></div><div class="text-xs text-muted"><?= e($student['email'] ?? '') ?></div></div></td><td class="td-mono text-xs"><?= e($student['student_id_no'] ?? '—') ?></td><td><?= e($student['program_code'] ?? $student['program'] ?? '—') ?></td><td><?= e($student['company_name'] ?? '—') ?></td><td><?= e($student['coordinator_name'] ?? '—') ?></td><td class="td-mono"><?= number_format($rendered, 0) ?> / <?= (int)$required ?>h</td><td><div class="progress-cell"><div class="progress-track sm"><div class="progress-fill <?= $percent>=100?'green':($percent>=50?'blue':'amber') ?>" data-pct="<?= $percent ?>"></div></div><span class="text-xs text-muted"><?= $percent ?>%</span></div></td><td><?= !empty($student['active_ojt_status']) ? status_badge($student['active_ojt_status']) : '<span class="text-xs text-muted">Not enrolled</span>' ?></td><td><button type="button" class="table-action-icon is-primary" title="Edit student assignment" aria-label="Edit assignment for <?= e($student['name']) ?>" onclick='openAssignModal(<?= $payload_json ?>)'><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button></td></tr>
    <?php endforeach; ?>
    <?php if (!$students): ?><tr><td colspan="9" class="text-center text-muted py-6">No active student assignments match these filters.</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

<div class="modal-overlay" id="assignModal"><div class="modal">
  <div class="modal-title">Student Assignment</div><p class="modal-sub" id="assignModalSub"></p>
  <form method="POST" id="assignStudentForm" onsubmit="return validateAssignForm()">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="assign_student"><input type="hidden" name="student_id" id="assignStudentId">
    <div class="user-form-section-heading">Academic placement</div>
    <div class="form-group"><label class="form-label" for="assignProgramId">Academic Program</label><select id="assignProgramId" class="form-control" disabled aria-disabled="true"><option value="">Set program in Student Information</option><?php foreach ($program_options as $option): ?><option value="<?= (int)$option['id'] ?>"><?= e($option['label']) ?></option><?php endforeach; ?></select><div class="text-xs text-muted mt-1">Automatically taken from the student profile. Update it in Student Information.</div></div>
     <div class="form-group"><label class="form-label" for="assignCoordId">Assigned Coordinator</label><select name="coordinator_id" id="assignCoordId" class="form-control" required><option value="">Select a program first</option><?php foreach ($coordinators as $coordinator): ?><option value="<?= (int)$coordinator['id'] ?>" data-dept="<?= e($coordinator['department'] ?? '') ?>"><?= e($coordinator['name']) ?> (<?= e($coordinator['department'] ?? 'General') ?>)</option><?php endforeach; ?></select><div id="assignCoordOk" class="text-xs mt-1" style="display:none;color:#16a34a;font-weight:600"></div><div id="assignCoordWarning" class="text-xs mt-1" style="display:none;color:#b45309;font-weight:600"></div></div>
    <div class="form-group"><label class="form-label" for="assignCompId">Partner Company</label><select name="company_id" id="assignCompId" class="form-control"><option value="">-- No Company Assigned --</option><?php foreach ($companies as $company): ?><option value="<?= (int)$company['id'] ?>"><?= e($company['company_name']) ?></option><?php endforeach; ?></select></div>
    <div class="user-form-section-heading">OJT progress</div>
    <div class="form-row"><div class="form-group"><label class="form-label" for="assignStatus">OJT Status</label><select name="ojt_status" id="assignStatus" class="form-control"><option value="pending">Pending</option><option value="not_started">Not Started</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option><option value="on_hold">On Hold</option><option value="withdrawn">Withdrawn</option></select></div><div class="form-group"><label class="form-label" for="assignReqHours">Required Hours</label><input type="number" name="required_hours" id="assignReqHours" class="form-control" min="1" max="9999" required></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button><button type="submit" class="btn btn-primary" id="assignSubmitBtn">Save Assignment</button></div>
  </form>
</div></div>

<script>
const COORD_DEPT_MAP = <?= json_encode($coord_dept_map, JSON_UNESCAPED_UNICODE) ?>;
const PROGRAM_OPTIONS = <?= json_encode($program_options, JSON_UNESCAPED_UNICODE) ?>;
function getProgramById(id) { return PROGRAM_OPTIONS.find(program => String(program.id) === String(id || '')) || null; }
function autoAssignCoordinatorFromProgram(programId) {
  const select = document.getElementById('assignCoordId'), warning = document.getElementById('assignCoordWarning'), ok = document.getElementById('assignCoordOk'), submit = document.getElementById('assignSubmitBtn');
  const program = getProgramById(programId), match = program ? COORD_DEPT_MAP.find(coordinator => coordinator.id === program.coordinator_id) : null;
  warning.style.display = 'none'; ok.style.display = 'none'; warning.textContent = ''; ok.textContent = '';
  if (!programId) { select.value = ''; select.disabled = true; submit.disabled = true; return; }
  if (match) { select.value = String(match.id); select.disabled = true; ok.style.display = 'block'; ok.textContent = 'Auto-assigned: ' + match.name + ' (' + (match.department || program.code) + ')'; submit.disabled = false; }
  else { select.disabled = false; select.value = ''; warning.style.display = 'block'; warning.textContent = 'No coordinator is mapped to ' + program.label + '. Choose an active coordinator below.'; submit.disabled = true; }
}
function validateAssignForm() {
  const program = getProgramById(document.getElementById('assignProgramId').value);
  if (!program) { window.showAppToast?.('Set an academic program in Student Information first.', {type: 'error'}); return false; }
  if (!document.getElementById('assignCoordId').value) { window.showAppToast?.('Choose an active coordinator for this student.', {type: 'error'}); return false; }
  return true;
}
document.getElementById('assignCoordId').addEventListener('change', function () {
  if (!this.disabled) document.getElementById('assignSubmitBtn').disabled = !this.value;
});
function openAssignModal(student) {
  document.getElementById('assignStudentId').value = student.id;
  document.getElementById('assignModalSub').textContent = student.name + ' (' + (student.student_id_no || '') + ')';
  const programId = student.program_id || student.prog_id || '';
  document.getElementById('assignProgramId').value = programId ? String(programId) : '';
  document.getElementById('assignCompId').value = student.company_id || '';
  document.getElementById('assignStatus').value = student.ojt_status || 'pending';
  document.getElementById('assignReqHours').value = student.required_hours || 486;
  autoAssignCoordinatorFromProgram(programId);
  openModal('assignModal');
}
const assignmentTable = document.querySelector('.assignment-table tbody');
if (assignmentTable) {
  assignmentTable.addEventListener('click', event => {
    if (event.target.closest('button, a, input, select, textarea, label, [contenteditable="true"]')) return;
    const row = event.target.closest('tr.assignment-edit-row');
    if (!row || !assignmentTable.contains(row)) return;
    try { openAssignModal(JSON.parse(row.dataset.assignment)); } catch (error) { console.error('Unable to open this student assignment.', error); }
  });
  assignmentTable.addEventListener('keydown', event => {
    if (!['Enter', ' '].includes(event.key) || event.target !== event.target.closest('tr.assignment-edit-row')) return;
    event.preventDefault();
    try { openAssignModal(JSON.parse(event.target.dataset.assignment)); } catch (error) { console.error('Unable to open this student assignment.', error); }
  });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
