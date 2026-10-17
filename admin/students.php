<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';
if (isset($_SESSION['student_created'])) {
    $success = $_SESSION['student_created'];
    unset($_SESSION['student_created']);
}

$allowed_ojt_statuses = ['pending', 'not_started', 'ongoing', 'completed', 'on_hold', 'withdrawn'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_student') {
        $field = static fn($key, $default = '') => is_string($_POST[$key] ?? $default) ? trim($_POST[$key] ?? $default) : '';
        $name = $field('name');
        $email = $field('email');
        $student_id_mode = $field('student_id_mode', 'manual');
        $student_id_no = $field('student_id_no');
        $program_id = (int)$field('program_id');
        $year = $field('year_level', '4th Year');
        $hours = filter_var($field('required_hours', '486'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]);
        $program = query_one("SELECT id, code, name FROM programs WHERE id=? AND status='active'", [$program_id], 'i');

        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'Enter a full name of up to 100 characters.';
        } elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) {
            $error = 'Enter a valid email address or leave it blank.';
        } elseif ($email !== '' && query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } elseif (!in_array($student_id_mode, ['manual', 'auto'], true)) {
            $error = 'Choose how to assign the student ID.';
        } elseif ($student_id_mode === 'manual' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,19}$/D', $student_id_no)) {
            $error = 'Enter a student ID of up to 20 letters, numbers or hyphens.';
        } elseif ($student_id_mode === 'manual' && query_one("SELECT id FROM students WHERE student_id_no=?", [$student_id_no], 's')) {
            $error = 'That student ID is already assigned, including archived accounts.';
        } elseif (!$program) {
            $error = 'Please select a valid active academic program.';
        } elseif ($year === '' || mb_strlen($year) > 20 || $hours === false) {
            $error = 'Enter a year level and required hours between 1 and 9999.';
        } else {
            db()->begin_transaction();
            try {
                if ($student_id_mode === 'auto') $student_id_no = generate_student_id();
                $uid = insert("INSERT INTO users (name, email, role, password, status) VALUES (?,?,'student',?,'active')",
                    [$name, $email ?: null, password_hash($student_id_no, PASSWORD_DEFAULT)], 'sss');
                $coord_id = find_coordinator_for_program($program);
                insert("INSERT INTO students (user_id, student_id_no, program_id, program, coordinator_id, year_level, required_hours, ojt_status) VALUES (?,?,?,?,?,?,?,'pending')",
                    [$uid, $student_id_no, $program_id, $program['code'], $coord_id, $year, $hours], 'isisisi');
                log_activity($user['id'], 'Student Added', "$name ({$program['code']}) ID: $student_id_no");
                db()->commit();
            } catch (Throwable $exception) {
                db()->rollback();
                $error = $exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062
                    ? 'The email or student ID is already in use. Please choose a unique value.'
                    : 'Unable to create the student. Please try again.';
                error_log('OJTrack student creation failed (code ' . $exception->getCode() . ').');
            }
            if ($error === '') {
                $_SESSION['student_created'] = "Student $name created. Student ID: $student_id_no.";
                http_response_code(303);
                redirect('admin/students.php');
            }
        }
    } elseif ($action === 'assign_student') {
        $sid        = (int)($_POST['student_id'] ?? 0);
        $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
        $company_id = array_key_exists('company_id', $_POST) && $_POST['company_id'] !== ''
            ? (int)$_POST['company_id'] : null;
        $ojt_status = $_POST['ojt_status'] ?? 'pending';
        $req_hours  = (int)($_POST['required_hours'] ?? 486);

        if (!in_array($ojt_status, $allowed_ojt_statuses, true)) {
            $ojt_status = 'pending';
        }
        if ($req_hours < 1) {
            $req_hours = 486;
        }

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
        } else {
            // Prefer submitted program; fall back to the student's existing program
            if (!$program_id && !empty($student['program_id'])) {
                $program_id = (int)$student['program_id'];
            }

            $prog_code = trim((string)($student['program'] ?? ''));
            $prog_name = '';

            if ($program_id) {
                $pr = query_one("SELECT id, code, name FROM programs WHERE id=?", [$program_id], 'i');
                if ($pr) {
                    $prog_code = $pr['code'];
                    $prog_name = $pr['name'];
                } else {
                    request_error(422, 'Please select a valid academic program.');
                }
            }

            if (!$program_id && $prog_code !== '') {
                $pr = query_one("SELECT id, code, name FROM programs WHERE code=? OR name=? LIMIT 1", [$prog_code, $prog_code], 'ss');
                if ($pr) {
                    $program_id = (int)$pr['id'];
                    $prog_code  = $pr['code'];
                    $prog_name  = $pr['name'];
                }
            }

            // The selected program takes precedence over any legacy department.
            $dept = $program_id ? $prog_code : trim((string)($student['department'] ?? ''));

            if ($prog_code === '' && $dept === '') {
                $error = 'This student has no academic program set. Assign a program first.';
            } else {
                $coord_id = $program_id
                    ? find_coordinator_for_program(['code' => $prog_code, 'name' => $prog_name])
                    : find_coordinator_for_department($prog_code ?: $dept);

                if (!$coord_id) {
                    $label = $prog_code !== '' ? $prog_code : $dept;
                    if ($prog_name) $label .= " - $prog_name";
                    $error = "No active OJT Coordinator is configured for \"{$label}\". Assign a coordinator to this program first, then try again.";
                } else {
                    try {
                        if (!normalized_update_training_assignment(
                            $sid,(int)$user['id'],(int)$coord_id,$company_id,(int)$program_id,
                            $ojt_status,$req_hours,$student['ojt_start_date'] ?? null,
                            $student['ojt_end_date'] ?? null,$student['status_notes'] ?? null
                        )) throw new DomainException('Select a valid academic program and required hours.');
                    } catch (DomainException $exception) {
                        $error=$exception->getMessage();
                    }
                    if (!$error) {
                        $coord_name  = query_one("SELECT u.name FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=?", [$coord_id], 'i');
                        $coord_label = $coord_name['name'] ?? 'Coordinator';
                        $prog_label  = $prog_code !== '' ? $prog_code : $dept;

                        log_activity($user['id'], 'Student Assignment Updated', "Updated assignments for {$student['name']} → Program: {$prog_label}, Coord: {$coord_label}");
                        $hours_complete = ($student['required_hours'] ?? 0) > 0 && ((float)($student['rendered_hours'] ?? 0)) >= (float)$student['required_hours'];
                        if ($ojt_status === 'completed' && $hours_complete) {
                            create_notification($student['user_id'], "Congratulations! Your OJT has been completed and your rendered hours are finished. Your Certificate of Recognition is now available.", 'success', '/ojtrack/student/certificate.php');
                        } else {
                            create_notification($student['user_id'], "Your coordinator/company assignment was updated by the administrator.", 'info', '/ojtrack/student/dashboard.php');
                        }
                        $success = "Student {$student['name']} assigned under {$prog_label} with coordinator {$coord_label}.";
                    }
                }
            }
        }
    } elseif ($action === 'archive_student') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $s_row = query_one("SELECT s.id, s.user_id, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?", [$sid], 'i');
        if ($s_row) {
            query("UPDATE users SET status='archived' WHERE id=?", [$s_row['user_id']], 'i');
            query("UPDATE students SET is_archived=1 WHERE id=?", [$sid], 'i');
            log_activity($user['id'], 'Student Archived', "Archived student {$s_row['name']} (ID: $sid)");
            $success = "Student {$s_row['name']} has been archived. All academic and OJT records remain safely preserved.";
        }
    } elseif ($action === 'restore_student') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $s_row = query_one("SELECT s.id, s.user_id, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?", [$sid], 'i');
        if ($s_row) {
            query("UPDATE users SET status='active' WHERE id=?", [$s_row['user_id']], 'i');
            query("UPDATE students SET is_archived=0 WHERE id=?", [$sid], 'i');
            log_activity($user['id'], 'Student Restored', "Restored student {$s_row['name']} (ID: $sid)");
            $success = "Student {$s_row['name']} has been restored to active status.";
        }
    }
}

$search         = trim($_GET['q'] ?? '');
$status         = $_GET['status'] ?? '';
$program_filter = $_GET['program_id'] ?? '';
$archive_filter = $_GET['archive'] ?? 'active'; // 'active', 'archived', 'all'

$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR s.student_id_no LIKE ? OR u.email LIKE ? OR p.code LIKE ? OR p.name LIKE ? OR s.program LIKE ? OR s.department LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sssssss';
}

if ($status !== '' && in_array($status, $allowed_ojt_statuses, true)) {
    $where .= " AND s.ojt_status=?";
    $params[] = $status;
    $types .= 's';
}

if ($program_filter !== '') {
    $where .= " AND (s.program_id = ? OR (s.program_id IS NULL AND p.id = ?))";
    $params[] = (int)$program_filter;
    $params[] = (int)$program_filter;
    $types .= 'ii';
}

if ($archive_filter === 'archived') {
    $where .= " AND (s.is_archived = 1 OR u.status = 'archived')";
} elseif ($archive_filter === 'all') {
    // Show all
} else {
    $where .= " AND (s.is_archived = 0 AND u.status != 'archived')";
}

$students = query(
    "SELECT s.*, u.name, u.email, u.avatar, u.status AS user_status, co.company_name, cu.name AS coordinator_name,
            p.id AS prog_id, p.code AS program_code, p.name AS program_name
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN companies co ON co.id = s.company_id
     LEFT JOIN coordinators c ON c.id = s.coordinator_id
     LEFT JOIN users cu ON cu.id = c.user_id
     LEFT JOIN programs p ON (p.id = s.program_id OR (s.program_id IS NULL AND p.code = s.program))
     WHERE $where
     ORDER BY u.name ASC",
    $params,
    $types
);
if ($students === false) {
    $students = [];
    $error = $error ?: 'Unable to load student records. Please try again.';
}

$coordinators        = query("SELECT c.id, u.name, c.department, c.coordinator_id_no, c.contact_number FROM coordinators c JOIN users u ON u.id=c.user_id WHERE u.status='active' ORDER BY u.name", [], '') ?: [];
$companies           = query("SELECT id, company_name FROM companies WHERE status='active' ORDER BY company_name", [], '') ?: [];
$programs            = query("SELECT id, code, name FROM programs WHERE status='active' ORDER BY code ASC", [], '') ?: [];
$all_programs_filter = query("SELECT id, code, name FROM programs ORDER BY code ASC", [], '') ?: [];

// Program options drive auto-assignment of the matching department coordinator
$program_options = [];
foreach ($programs as $pr) {
    $program_options[] = [
        'id'    => (int)$pr['id'],
        'code'  => $pr['code'],
        'name'  => $pr['name'],
        'label' => $pr['code'] . ' - ' . $pr['name'],
        'coordinator_id' => find_coordinator_for_program($pr),
    ];
}

$coord_dept_map = [];
foreach ($coordinators as $c) {
    $coord_dept_map[] = [
        'id' => (int)$c['id'],
        'name' => $c['name'],
        'department' => $c['department'] ?? '',
    ];
}

$counts = [
    'active'   => (int)(query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.is_archived=0 AND u.status!='archived'")['c'] ?? 0),
    'archived' => (int)(query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.is_archived=1 OR u.status='archived'")['c'] ?? 0),
];

$add_student_failed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student' && $error !== '');
$add_values = $add_student_failed ? array_filter($_POST, 'is_scalar') : [];
$next_student_id = generate_student_id();
$page_title = 'All Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Students</div>
    <div class="page-sub">Programs, coordinators, and placements</div>
  </div>
  <div class="page-heading-actions">
    <a href="/ojtrack/admin/programs.php" class="btn btn-secondary">Manage Programs</a>
    <button type="button" class="btn btn-primary" onclick="openModal('addStudentModal')">+ Add Student</button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <?php require __DIR__ . '/../includes/student-filters.php'; ?>

  <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records">
    <table>
      <thead>
        <tr>
          <th>Student</th>
          <th>Student ID</th>
          <th>Program</th>
          <th>Assigned Company</th>
          <th>Coordinator</th>
          <th>Hours</th>
          <th>Progress</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s):
          $req = (float)($s['required_hours'] ?? 0);
          $ren = (float)($s['rendered_hours'] ?? 0);
          $pct = $req > 0 ? min(100, round(($ren / $req) * 100)) : 0;
          $is_arch = (!empty($s['is_archived']) || ($s['user_status'] ?? '') === 'archived');
          $modal_payload = [
              'id' => (int)$s['id'],
              'name' => $s['name'],
              'email' => $s['email'] ?? '',
              'student_id_no' => $s['student_id_no'] ?? '',
              'program_id' => $s['program_id'] ?? null,
              'prog_id' => $s['prog_id'] ?? null,
              'program_code' => $s['program_code'] ?? ($s['program'] ?? ''),
              'program_name' => $s['program_name'] ?? '',
              'department' => $s['department'] ?? '',
              'company_id' => $s['company_id'] ?? null,
              'company_name' => $s['company_name'] ?? '',
              'coordinator_name' => $s['coordinator_name'] ?? '',
              'ojt_status' => $s['ojt_status'] ?? 'pending',
              'required_hours' => $s['required_hours'] ?? 486,
              'rendered_hours' => $s['rendered_hours'] ?? 0,
          ];
        ?>
        <tr <?= !$is_arch ? 'class="row-clickable" onclick=\'openAssignModal(' . htmlspecialchars(json_encode($modal_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . ')\' title="Click to assign / edit"' : 'title="Archived student"' ?>>
          <td>
            <div class="table-person">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)">
                <?= user_avatar_content($s['avatar'] ?? null, $s['name']) ?>
              </div>
              <div>
                <div class="font-bold text-sm">
                  <?= e($s['name']) ?>
                  <?php if ($is_arch): ?>
                    <span class="badge badge-archived" style="font-size:10px;margin-left:4px">Archived</span>
                  <?php endif; ?>
                </div>
                <div class="text-xs text-muted"><?= e($s['email']) ?></div>
              </div>
            </div>
          </td>
          <td class="td-mono text-xs"><?= e($s['student_id_no'] ?? '—') ?></td>
          <td>
            <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
              <span class="badge" style="background:var(--primary-light);color:var(--primary);font-weight:700" title="<?= e($s['program_name'] ?? $s['program'] ?? '') ?>">
                <?= e($s['program_code'] ?? $s['program'] ?? $s['department'] ?? '—') ?>
              </span>
            </div>
          </td>
          <td class="text-sm"><?= e($s['company_name'] ?? '—') ?></td>
          <td class="text-sm"><?= e($s['coordinator_name'] ?? '—') ?></td>
          <td class="td-mono text-sm"><?= number_format($ren, 0) ?> / <?= (int)$req ?>h</td>
          <td>
            <div class="progress-cell">
              <div class="progress-track sm">
                <div class="progress-fill <?= $pct>=100?'green':($pct>=50?'blue':'amber') ?>" data-pct="<?= $pct ?>"></div>
              </div>
            </div>
          </td>
          <td><?= status_badge($s['ojt_status']) ?></td>
          <td onclick="stopRowClick(event)">
            <div class="table-actions">
              <?php if ($is_arch): ?>
                <form method="POST" data-confirm-message="Restore this student to active status?"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore_student">
                  <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="table-action-icon is-success" title="Restore student" aria-label="Restore student">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                  </button>
                </form>
              <?php else: ?>
                <button type="button" class="table-action-icon is-primary"
                        title="Assign or edit student placement" aria-label="Assign or edit student placement"
                        onclick='openAssignModal(<?= htmlspecialchars(json_encode($modal_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
                <form method="POST" data-confirm-message="Are you sure you want to archive this student? All historical logs, hours, and evaluations will be preserved."><?= csrf_field() ?>
                  <input type="hidden" name="action" value="archive_student">
                  <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="table-action-icon is-warning" title="Archive student" aria-label="Archive student">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9M9 13h6"/></svg>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
          <tr><td colspan="9" class="text-center text-muted py-6">No students found matching your criteria.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create student on this page -->
<div class="modal-overlay" id="addStudentModal">
  <div class="modal">
    <div class="modal-title">Add Student</div>
    <?php if ($add_student_failed): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="POST" action="/ojtrack/admin/students.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_student">
      <div class="form-group">
        <label class="form-label" for="newStudentName">Full Name <span style="color:red">*</span></label>
        <input class="form-control" id="newStudentName" name="name" maxlength="100" value="<?= e($add_values['name'] ?? '') ?>" autocomplete="off" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="newStudentEmail">Email (optional)</label>
        <input type="email" class="form-control" id="newStudentEmail" name="email" maxlength="100" value="<?= e($add_values['email'] ?? '') ?>" autocomplete="off">
      </div>
      <div class="form-group">
        <label class="form-label" for="newStudentIdMode">Student ID assignment</label>
        <select class="form-control" id="newStudentIdMode" name="student_id_mode" onchange="toggleNewStudentId()">
          <option value="manual">Enter student ID</option>
          <option value="auto" <?= ($add_values['student_id_mode'] ?? '') === 'auto' ? 'selected' : '' ?>>Generate automatically</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="newStudentId">Student ID <span style="color:red">*</span></label>
        <input class="form-control" id="newStudentId" name="student_id_no" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,19}" value="<?= e($add_values['student_id_no'] ?? '') ?>" data-generated-id="<?= e($next_student_id) ?>" placeholder="Enter official student ID" autocomplete="off" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="newStudentProgram">Academic Program <span style="color:red">*</span></label>
        <select class="form-control" id="newStudentProgram" name="program_id" required>
          <option value="">Select program</option>
          <?php foreach ($programs as $pr): ?>
          <option value="<?= (int)$pr['id'] ?>" <?= (string)($add_values['program_id'] ?? '') === (string)$pr['id'] ? 'selected' : '' ?>><?= e($pr['code'] . ' - ' . $pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1">Coordinator is assigned by program.</div>
      </div>
      <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="newStudentYear">Year Level</label>
        <input class="form-control" id="newStudentYear" name="year_level" maxlength="20" value="<?= e($add_values['year_level'] ?? '4th Year') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="newStudentHours">Required Hours</label>
        <input type="number" class="form-control" id="newStudentHours" name="required_hours" min="1" max="9999" value="<?= e($add_values['required_hours'] ?? '486') ?>" required>
      </div>
      </div>
      <p class="text-sm text-muted">Student login and initial password: student ID.</p>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addStudentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Student</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="assignModal">
  <div class="modal">
    <div class="modal-title">Student Details</div>
    <p class="modal-sub" id="assignModalSub"></p>
    <div id="assignDetailSummary" style="background:var(--bg);padding:10px 12px;border-radius:var(--radius);font-size:12px;margin-bottom:14px;display:none"></div>
    <form method="POST" id="assignStudentForm" onsubmit="return validateAssignForm()"><?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_student">
      <input type="hidden" name="student_id" id="assignStudentId">

      <div class="form-group">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
          <label class="form-label mb-0">Academic Program <span class="text-danger">*</span></label>
          <a href="/ojtrack/admin/programs.php" target="_blank" style="font-size:11px;color:var(--primary);font-weight:600">+ Manage Programs</a>
        </div>
        <select name="program_id" id="assignProgramId" class="form-control" onchange="autoAssignCoordinatorFromProgram(this.value)" required>
          <option value="">-- Select Academic Program --</option>
          <?php foreach ($program_options as $po): ?>
            <option value="<?= (int)$po['id'] ?>" data-code="<?= e($po['code']) ?>" data-name="<?= e($po['name']) ?>">
              <?= e($po['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1">Coordinator assigned by program.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Assigned Coordinator <span class="text-muted" style="font-weight:500">(Auto-Assigned)</span></label>
        <select id="assignCoordId" class="form-control" disabled style="background:#f1f5f9;cursor:not-allowed" aria-readonly="true" tabindex="-1">
          <option value="">Select a program</option>
          <?php foreach ($coordinators as $c): ?>
            <option value="<?= (int)$c['id'] ?>" data-dept="<?= e($c['department'] ?? '') ?>"><?= e($c['name']) ?> (<?= e($c['department'] ?? 'General') ?>)</option>
          <?php endforeach; ?>
        </select>
        <div id="assignCoordOk" class="text-xs mt-1" style="display:none;color:#16a34a;font-weight:600"></div>
        <div id="assignCoordWarning" class="text-xs mt-1" style="display:none;color:#b45309;font-weight:600;background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;border-radius:6px"></div>
      </div>

      <div class="form-group">
        <label class="form-label">Partner Company</label>
        <select name="company_id" id="assignCompId" class="form-control">
          <option value="">-- No Company Assigned --</option>
          <?php foreach ($companies as $co): ?>
            <option value="<?= (int)$co['id'] ?>"><?= e($co['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1">Assign the company separately.</div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">OJT Status</label>
          <select name="ojt_status" id="assignStatus" class="form-control">
            <option value="pending">Pending</option>
            <option value="not_started">Not Started</option>
            <option value="ongoing">Ongoing</option>
            <option value="completed">Completed</option>
            <option value="on_hold">On Hold</option>
            <option value="withdrawn">Withdrawn</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Required Hours</label>
          <input type="number" name="required_hours" id="assignReqHours" class="form-control" min="1" required>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="assignSubmitBtn">Save Assignment</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleNewStudentId() {
  const input = document.getElementById('newStudentId');
  const auto = document.getElementById('newStudentIdMode').value === 'auto';
  if (auto && !input.disabled) {
    input.dataset.manualValue = input.value;
    input.value = input.dataset.generatedId;
  } else if (!auto && input.disabled) {
    input.value = input.dataset.manualValue || '';
  }
  input.disabled = auto;
  input.readOnly = auto;
  input.required = !auto;
}
document.addEventListener('DOMContentLoaded', function () {
  toggleNewStudentId();
  <?php if ($add_student_failed): ?>openModal('addStudentModal');<?php endif; ?>
});
const COORD_DEPT_MAP = <?= json_encode($coord_dept_map, JSON_UNESCAPED_UNICODE) ?>;
const PROGRAM_OPTIONS = <?= json_encode($program_options, JSON_UNESCAPED_UNICODE) ?>;

function getProgramById(id) {
  const sid = String(id || '');
  for (let i = 0; i < PROGRAM_OPTIONS.length; i++) {
    if (String(PROGRAM_OPTIONS[i].id) === sid) return PROGRAM_OPTIONS[i];
  }
  return null;
}

function autoAssignCoordinatorFromProgram(programId) {
  const coordSelect = document.getElementById('assignCoordId');
  const warnEl = document.getElementById('assignCoordWarning');
  const okEl = document.getElementById('assignCoordOk');
  const submitBtn = document.getElementById('assignSubmitBtn');
  const program = getProgramById(programId);
  const matched = program
    ? COORD_DEPT_MAP.find(function (c) { return c.id === program.coordinator_id; })
    : null;

  warnEl.style.display = 'none';
  okEl.style.display = 'none';
  warnEl.textContent = '';
  okEl.textContent = '';

  if (!programId) {
    coordSelect.value = '';
    submitBtn.disabled = true;
    return;
  }

  if (matched) {
    coordSelect.value = String(matched.id);
    okEl.style.display = 'block';
    okEl.textContent = 'Auto-assigned: ' + matched.name + ' (' + (matched.department || program.code) + ')';
    submitBtn.disabled = false;
  } else {
    coordSelect.value = '';
    warnEl.style.display = 'block';
    const label = program ? program.label : programId;
    warnEl.textContent = 'No active OJT Coordinator is configured for "' + label + '". Assign a coordinator to this program first.';
    submitBtn.disabled = true;
  }
}

function validateAssignForm() {
  const programId = document.getElementById('assignProgramId').value.trim();
  if (!programId) {
    alert('Academic Program is required.');
    return false;
  }
  const program = getProgramById(programId);
  const matched = program
    ? COORD_DEPT_MAP.find(function (c) { return c.id === program.coordinator_id; })
    : null;
  if (!matched) {
    const label = program ? program.label : programId;
    alert('No active OJT Coordinator is configured for "' + label + '". Assign a coordinator to this program first.');
    return false;
  }
  return true;
}

function openAssignModal(s) {
  document.getElementById('assignStudentId').value = s.id;
  document.getElementById('assignModalSub').textContent = s.name + ' (' + (s.student_id_no || '') + ')';

  const summary = document.getElementById('assignDetailSummary');
  if (summary) {
    const prog = s.program_code || s.program_name || '—';
    const company = s.company_name || 'No company assigned';
    const coord = s.coordinator_name || 'No coordinator assigned';
    const hours = Math.round(Number(s.rendered_hours || 0)) + ' / ' + Math.round(Number(s.required_hours || 0)) + 'h';
    summary.style.display = 'block';
    summary.innerHTML =
      '<div style="display:flex;flex-direction:column;gap:6px">' +
        '<div style="display:flex;justify-content:space-between;gap:12px"><span class="text-muted">Email</span><span class="font-medium">' + escapeHtml(s.email || '—') + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;gap:12px"><span class="text-muted">Program</span><span class="font-medium">' + escapeHtml(prog) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;gap:12px"><span class="text-muted">Company</span><span class="font-medium">' + escapeHtml(company) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;gap:12px"><span class="text-muted">Coordinator</span><span class="font-medium">' + escapeHtml(coord) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;gap:12px"><span class="text-muted">Hours</span><span class="td-mono font-bold">' + escapeHtml(hours) + '</span></div>' +
      '</div>';
  }

  const programId = s.program_id || s.prog_id || '';
  document.getElementById('assignProgramId').value = programId ? String(programId) : '';
  autoAssignCoordinatorFromProgram(programId);

  document.getElementById('assignCompId').value = s.company_id || '';
  document.getElementById('assignStatus').value = s.ojt_status || 'pending';
  document.getElementById('assignReqHours').value = s.required_hours || 486;
  openModal('assignModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
