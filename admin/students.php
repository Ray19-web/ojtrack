<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';
$year_level_options = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate', 'Other'];
$academic_programs = query("SELECT id, code, name FROM programs WHERE status='active' ORDER BY code ASC", [], '') ?: [];
if (isset($_SESSION['student_created'])) {
    $success = $_SESSION['student_created'];
    unset($_SESSION['student_created']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_student') {
        $field = static fn($key, $default = '') => is_string($_POST[$key] ?? $default) ? trim($_POST[$key] ?? $default) : '';
        $first_name = normalize_person_name($field('first_name'));
        $middle_name = normalize_person_name($field('middle_name'));
        $last_name = normalize_person_name($field('last_name'));
        $extension_result = name_extensions_from_post();
        $extensions = $extension_result['extensions'];
        $first_extension = $extensions[0] ?? null;
        $extension_error = $extension_result['error'];
        $name = format_person_name($last_name . ', ' . trim($first_name . ' ' . $middle_name), $extensions);
        $email = $field('email');
        $student_id_no = $field('student_id_no');
        $program_id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $year = $field('year_level', '4th Year');
        $phone = $field('contact_number');
        $program = $program_id ? query_one("SELECT id, code, name FROM programs WHERE id=? AND status='active'", [(int)$program_id], 'i') : null;

        if ($first_name === '' || $last_name === '') {
            $error = 'Enter the student\'s first and last names.';
        } elseif ($extension_error) {
            $error = $extension_error;
        } elseif (mb_strlen($first_name) > 60 || mb_strlen($middle_name) > 60 || mb_strlen($last_name) > 60 || mb_strlen($name) > 100) {
            $error = 'Student name is too long. Check each name part and keep the full name within 100 characters.';
        } elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
            $error = 'Enter a valid email address or leave it blank.';
        } elseif ($email !== '' && query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already registered in the system.';
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,19}$/D', $student_id_no)) {
            $error = 'Enter a student ID of up to 20 letters, numbers or hyphens.';
        } elseif (query_one("SELECT id FROM students WHERE student_id_no=?", [$student_id_no], 's')) {
            $error = 'That student ID is already assigned, including archived accounts.';
        } elseif (!$program) {
            $error = 'Select an active academic program for the student.';
        } elseif ($year === '' || mb_strlen($year) > 20) {
            $error = 'Enter a year level of up to 20 characters.';
        } elseif (strlen($phone) > 20) {
            $error = 'Contact number must be 20 characters or fewer.';
        } else {
            db()->begin_transaction();
            try {
                $uid = insert("INSERT INTO users (name, name_extension_type, name_extension_value, email, role, password, status) VALUES (?,?,?,?,'student',?,'active')",
                    [$name, $first_extension['type'] ?? null, $first_extension['value'] ?? null, $email ?: null, password_hash($student_id_no, PASSWORD_DEFAULT)], 'sssss');
                save_user_name_extensions((int)$uid, $extensions);
                $student_id = (int)insert("INSERT INTO students (user_id, first_name, middle_name, last_name, student_id_no, program_id, program, coordinator_id, company_id, year_level, contact_number, ojt_status) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, 'pending')",
                    [$uid, $first_name, $middle_name ?: null, $last_name, $student_id_no, (int)$program['id'], $program['code'], $year, $phone ?: null], 'issssisss');
                $coordinator_id = find_coordinator_for_program($program);
                $term_enrollment_id = normalized_create_active_term_enrollment($student_id, (int)$user['id'], (int)$program['id'], $year, 486, $coordinator_id ? (int)$coordinator_id : null);
                log_activity($user['id'], 'Student Added', "$name (ID: $student_id_no)");
                db()->commit();
            } catch (Throwable $exception) {
                db()->rollback();
                $error = $exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062
                    ? 'The email or student ID is already in use. Please choose a unique value.'
                    : 'Unable to create the student. Please try again.';
                error_log('OJTrack student creation failed (code ' . $exception->getCode() . ').');
            }
            if ($error === '') {
                $_SESSION['student_created'] = "Student $name created. Student ID: $student_id_no." . ($term_enrollment_id ? ' Enrolled in the active academic term.' : ' No active-term enrollment was created; activate a term before assigning OJT.');
                http_response_code(303);
                redirect('admin/students.php');
            }
        }
    } elseif ($action === 'edit_student') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $field = static fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $first_name = normalize_person_name($field('first_name'));
        $middle_name = normalize_person_name($field('middle_name'));
        $last_name = normalize_person_name($field('last_name'));
        $extension_result = name_extensions_from_post();
        $extensions = $extension_result['extensions'];
        $extension_error = $extension_result['error'];
        $email = $field('email');
        $student_id_no = $field('student_id_no');
        $new_password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $program_id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $year = $field('year_level');
        $phone = $field('contact_number');
        $student = query_one("SELECT s.id, s.user_id, s.student_id_no, s.program_id, s.program, s.first_name, s.middle_name, s.last_name, u.name, u.name_extension_type, u.name_extension_value, u.password FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?", [$sid], 'i');
        $program = $program_id ? query_one("SELECT id, code, name FROM programs WHERE id=? AND status='active'", [(int)$program_id], 'i') : null;
        $student_extensions = $student ? user_name_extensions((int)$student['user_id']) : [];
        $has_name_parts = $first_name !== '' || $middle_name !== '' || $last_name !== '';
        $name = $has_name_parts
            ? $last_name . ', ' . trim($first_name . ' ' . $middle_name)
            : base_person_name($student['name'] ?? '', $student_extensions);
        $name = format_person_name($name, $extensions);

        if (!$student) {
            $error = 'Student not found.';
        } elseif ($has_name_parts && ($first_name === '' || $last_name === '')) {
            $error = 'Enter both the student\'s first and last names, or leave all name fields blank to keep the current name.';
        } elseif ($extension_error) {
            $error = $extension_error;
        } elseif ($has_name_parts && (mb_strlen($first_name) > 60 || mb_strlen($middle_name) > 60 || mb_strlen($last_name) > 60 || mb_strlen($name) > 100)) {
            $error = 'Student name is too long. Check each name part and keep the full name within 100 characters.';
        } elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
            $error = 'Enter a valid email address or leave it blank.';
        } elseif ($email !== '' && query_one("SELECT id FROM users WHERE email=? AND id<>?", [$email, $student['user_id']], 'si')) {
            $error = 'Email is already registered in the system.';
        } elseif ($new_password !== '' && (strlen($new_password) < 12 || strlen($new_password) > 72 || str_contains($new_password, "\0"))) {
            $error = 'New password must be between 12 and 72 characters, or leave it blank to keep the current password.';
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,19}$/D', $student_id_no)) {
            $error = 'Enter a student ID of up to 20 letters, numbers or hyphens.';
        } elseif (query_one("SELECT id FROM students WHERE student_id_no=? AND id<>?", [$student_id_no, $sid], 'si')) {
            $error = 'That student ID is already assigned, including archived accounts.';
        } elseif (!$program) {
            $error = 'Select an active academic program for the student.';
        } elseif ($year === '' || mb_strlen($year) > 20) {
            $error = 'Enter a year level of up to 20 characters.';
        } elseif (strlen($phone) > 20) {
            $error = 'Contact number must be 20 characters or fewer.';
        } else {
            db()->begin_transaction();
            try {
                if ($new_password !== '') {
                    query("UPDATE users SET name=?, email=?, password=? WHERE id=?",
                        [$name, $email ?: null, password_hash($new_password, PASSWORD_DEFAULT), $student['user_id']], 'sssi');
                } elseif ($student_id_no !== $student['student_id_no'] && password_verify($student['student_id_no'], $student['password'])) {
                    query("UPDATE users SET name=?, email=?, password=? WHERE id=?",
                        [$name, $email ?: null, password_hash($student_id_no, PASSWORD_DEFAULT), $student['user_id']], 'sssi');
                } else {
                    query("UPDATE users SET name=?, email=? WHERE id=?",
                        [$name, $email ?: null, $student['user_id']], 'ssi');
                }
                save_user_name_extensions((int)$student['user_id'], $extensions);
                if ($has_name_parts) {
                    query("UPDATE students SET first_name=?, middle_name=?, last_name=? WHERE id=?",
                        [$first_name, $middle_name ?: null, $last_name, $sid], 'sssi');
                }
                query("UPDATE students SET student_id_no=?, program_id=?, program=?, year_level=?, contact_number=? WHERE id=?",
                    [$student_id_no, (int)$program['id'], $program['code'], $year, $phone ?: null, $sid], 'sisssi');
                $program_coordinator_id = find_coordinator_for_program($program);
                normalized_sync_active_enrollment_program(
                    $sid,(int)$user['id'],(int)$program['id'],$year,
                    $program_coordinator_id ? (int)$program_coordinator_id : null
                );
                log_activity($user['id'], 'Student Profile Updated', "$name (ID: $student_id_no)");
                db()->commit();
                $success = 'Student information updated successfully.';
            } catch (Throwable $exception) {
                db()->rollback();
                $error = $exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062
                    ? 'The email or student ID is already in use.'
                    : 'Unable to update student information. Please try again.';
                error_log('OJTrack student profile update failed (code ' . $exception->getCode() . ').');
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
$archive_filter = $_GET['archive'] ?? 'active'; // 'active', 'archived', 'all'

$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR s.student_id_no LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if ($archive_filter === 'archived') {
    $where .= " AND (s.is_archived = 1 OR u.status = 'archived')";
} elseif ($archive_filter === 'all') {
    // Show all
} else {
    $where .= " AND (s.is_archived = 0 AND u.status != 'archived')";
}

$students = query(
    "SELECT s.*, COALESCE(s.program_id, student_program.id) AS resolved_program_id,
            u.name, u.name_extension_type, u.name_extension_value, u.email, u.avatar, u.status AS user_status
     FROM students s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN programs student_program ON (student_program.id=s.program_id OR (s.program_id IS NULL AND student_program.code=s.program))
     WHERE $where
     ORDER BY u.name ASC",
    $params,
    $types
);
if ($students === false) {
    $students = [];
    $error = $error ?: 'Unable to load student records. Please try again.';
}

$counts = [
    'active'   => (int)(query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.is_archived=0 AND u.status!='archived'")['c'] ?? 0),
    'archived' => (int)(query_one("SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id WHERE s.is_archived=1 OR u.status='archived'")['c'] ?? 0),
];

$add_student_failed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student' && $error !== '');
$add_values = $add_student_failed ? array_filter($_POST, 'is_scalar') : [];
$add_values['name_extension_type'] = $add_student_failed && is_array($_POST['name_extension_type'] ?? null) ? $_POST['name_extension_type'] : [];
$add_values['name_extension_value'] = $add_student_failed && is_array($_POST['name_extension_value'] ?? null) ? $_POST['name_extension_value'] : [];
$edit_student_failed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_student' && $error !== '');
$edit_values = $edit_student_failed ? array_filter($_POST, 'is_scalar') : [];
$edit_values['name_extension_type'] = $edit_student_failed && is_array($_POST['name_extension_type'] ?? null) ? $_POST['name_extension_type'] : [];
$edit_values['name_extension_value'] = $edit_student_failed && is_array($_POST['name_extension_value'] ?? null) ? $_POST['name_extension_value'] : [];
$page_title = 'All Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Students</div>
    <div class="page-sub">Student profile information</div>
  </div>
  <div class="page-heading-actions">
    <a href="/ojtrack/admin/assignments.php" class="btn btn-secondary">Manage Assignments</a>
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
          <th>Year Level</th>
          <th>Contact Number</th>
          <th>Account Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s):
          $is_arch = (!empty($s['is_archived']) || ($s['user_status'] ?? '') === 'archived');
          $s['name_extensions'] = user_name_extensions((int)$s['user_id']);
          $modal_payload = [
              'id' => (int)$s['id'],
              'name' => $s['name'],
              'name_extensions' => $s['name_extensions'],
              'first_name' => $s['first_name'] ?? '',
              'middle_name' => $s['middle_name'] ?? '',
              'last_name' => $s['last_name'] ?? '',
              'email' => $s['email'] ?? '',
              'student_id_no' => $s['student_id_no'] ?? '',
              'program_id' => $s['resolved_program_id'] ?? $s['program_id'] ?? '',
              'year_level' => $s['year_level'] ?? '',
              'contact_number' => $s['contact_number'] ?? '',
          ];
          $modal_payload_json = htmlspecialchars(json_encode($modal_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
        <tr class="row-clickable" tabindex="0" aria-label="Edit student <?= e($s['name']) ?>" onclick='openEditStudentModal(<?= $modal_payload_json ?>)' onkeydown="if (event.target === this && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); openEditStudentModal(<?= $modal_payload_json ?>); }">
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
          <td><?= e($s['year_level'] ?: 'Not provided') ?></td>
          <td><?= e($s['contact_number'] ?: 'Not provided') ?></td>
          <td><?= status_badge($s['user_status']) ?></td>
          <td onclick="stopRowClick(event)">
            <div class="table-actions">
              <button type="button" class="table-action-icon is-primary" title="Edit student information" aria-label="Edit <?= e($s['name']) ?>" onclick='openEditStudentModal(<?= $modal_payload_json ?>)'>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <?php if ($is_arch): ?>
                <form method="POST" data-confirm-message="Restore this student to active status?"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore_student">
                  <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="table-action-icon is-success" title="Restore student" aria-label="Restore student">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
          <tr><td colspan="6" class="text-center text-muted py-6">No students found matching your criteria.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create student on this page -->
<div class="modal-overlay" id="addStudentModal">
  <div class="modal modal-name-form student-form-modal">
    <div class="student-form-intro">
      <div class="modal-title">Add Student</div>
      <p>Enter the student’s academic details and profile information.</p>
    </div>
    <?php if ($add_student_failed): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form class="student-form" method="POST" action="/ojtrack/admin/students.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_student">
      <section class="student-form-section" aria-labelledby="newStudentAcademicHeading">
        <div class="student-form-section-heading" id="newStudentAcademicHeading">Academic details</div>
      <div class="form-group">
        <label class="form-label" for="newStudentId">Student ID <span class="text-danger">*</span></label>
        <input class="form-control" id="newStudentId" name="student_id_no" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,19}" value="<?= e($add_values['student_id_no'] ?? '') ?>" placeholder="Enter official student ID" autocomplete="off" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="newStudentProgram">Academic Program <span class="text-danger">*</span></label>
        <select class="form-control" id="newStudentProgram" name="program_id" required>
          <option value="">-- Select Academic Program --</option>
          <?php foreach ($academic_programs as $program_option): ?><option value="<?= (int)$program_option['id'] ?>" <?= (string)($add_values['program_id'] ?? '') === (string)$program_option['id'] ? 'selected' : '' ?>><?= e($program_option['code'] . ' - ' . $program_option['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      </section>
      <section class="student-form-section" aria-labelledby="newStudentNameHeading">
        <div class="student-form-section-heading" id="newStudentNameHeading">Student name</div>
      <?php $person_name_prefix = 'newStudent'; $person_name_values = ['first' => $add_values['first_name'] ?? '', 'middle' => $add_values['middle_name'] ?? '', 'last' => $add_values['last_name'] ?? '']; $person_name_required = true; require __DIR__ . '/../includes/person-name-fields.php'; ?>
      <?php $name_extension_prefix = 'newStudent'; $name_extension_type_field = 'name_extension_type'; $name_extension_value_field = 'name_extension_value'; $name_extension_selected = $add_values['name_extension_type'] ?? ''; $name_extension_value = $add_values['name_extension_value'] ?? ''; $name_extension_entries = null; require __DIR__ . '/../includes/name-extension-fields.php'; ?>
      </section>
      <section class="student-form-section" aria-labelledby="newStudentContactHeading">
        <div class="student-form-section-heading" id="newStudentContactHeading">Contact and year level</div>
      <div class="form-group">
        <label class="form-label" for="newStudentEmail">Email (optional)</label>
        <input type="email" class="form-control" id="newStudentEmail" name="email" maxlength="150" value="<?= e($add_values['email'] ?? '') ?>" autocomplete="off">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="newStudentYear">Year Level</label>
          <select class="form-control" id="newStudentYear" name="year_level" required><?php $new_year = $add_values['year_level'] ?? '4th Year'; if ($new_year !== '' && !in_array($new_year, $year_level_options, true)): ?><option value="<?= e($new_year) ?>" selected><?= e($new_year) ?></option><?php endif; foreach ($year_level_options as $year_option): ?><option value="<?= e($year_option) ?>" <?= $new_year === $year_option ? 'selected' : '' ?>><?= e($year_option) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group">
          <label class="form-label" for="newStudentPhone">Contact Number</label>
          <input class="form-control" id="newStudentPhone" name="contact_number" maxlength="20" value="<?= e($add_values['contact_number'] ?? '') ?>" autocomplete="tel" placeholder="e.g. 0917-123-4567">
        </div>
      </div>
      </section>
      <p class="text-sm text-muted">Student login and initial password: student ID.</p>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addStudentModal')">Cancel</button>
        <button type="submit" class="btn btn-primary student-form-submit">Add Student</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="editStudentModal">
  <div class="modal modal-name-form student-form-modal">
    <div class="student-form-intro">
      <div class="modal-title">Edit Student Information</div>
      <p>Update the student’s academic details and profile information.</p>
    </div>
    <?php if ($edit_student_failed): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form class="student-form" method="POST" action="/ojtrack/admin/students.php"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_student">
      <input type="hidden" name="student_id" id="editStudentId" value="<?= e($edit_values['student_id'] ?? '') ?>">
      <section class="student-form-section" aria-labelledby="editStudentAcademicHeading">
        <div class="student-form-section-heading" id="editStudentAcademicHeading">Academic details</div>
      <div class="form-group"><label class="form-label" for="editStudentNo">Student ID <span class="text-danger">*</span></label><input class="form-control" id="editStudentNo" name="student_id_no" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,19}" value="<?= e($edit_values['student_id_no'] ?? '') ?>" required><div class="text-xs text-muted mt-1">If the initial password is still the old student ID, it will be updated to match this ID.</div></div>
      <div class="form-group"><label class="form-label" for="editStudentProgram">Academic Program <span class="text-danger">*</span></label><select class="form-control" id="editStudentProgram" name="program_id" required><option value="">-- Select Academic Program --</option><?php foreach ($academic_programs as $program_option): ?><option value="<?= (int)$program_option['id'] ?>" <?= (string)($edit_values['program_id'] ?? '') === (string)$program_option['id'] ? 'selected' : '' ?>><?= e($program_option['code'] . ' - ' . $program_option['name']) ?></option><?php endforeach; ?></select></div>
      </section>
      <section class="student-form-section" aria-labelledby="editStudentNameHeading">
        <div class="student-form-section-heading" id="editStudentNameHeading">Student name</div>
      <div class="text-sm text-muted mb-3" id="editStudentCurrentName" style="<?= $edit_student_failed && !($edit_values['first_name'] ?? '') && !($edit_values['middle_name'] ?? '') && !($edit_values['last_name'] ?? '') && !empty(($student ?? [])['name']) ? '' : 'display:none' ?>"><?php if ($edit_student_failed && !($edit_values['first_name'] ?? '') && !($edit_values['middle_name'] ?? '') && !($edit_values['last_name'] ?? '') && !empty(($student ?? [])['name'])): ?>Current name on file: <?= e($student['name']) ?><?php endif; ?></div>
      <?php $person_name_prefix = 'editStudent'; $person_name_values = ['first' => $edit_values['first_name'] ?? '', 'middle' => $edit_values['middle_name'] ?? '', 'last' => $edit_values['last_name'] ?? '']; $person_name_required = false; require __DIR__ . '/../includes/person-name-fields.php'; ?>
      <?php $name_extension_prefix = 'editStudent'; $name_extension_type_field = 'name_extension_type'; $name_extension_value_field = 'name_extension_value'; $name_extension_selected = $edit_values['name_extension_type'] ?? []; $name_extension_value = $edit_values['name_extension_value'] ?? []; $name_extension_entries = $edit_student_failed ? null : []; require __DIR__ . '/../includes/name-extension-fields.php'; ?>
      <div class="text-xs text-muted mb-3">Leave all three name fields blank to keep a legacy name unchanged. First and last name are required when changing it.</div>
      </section>
      <section class="student-form-section" aria-labelledby="editStudentContactHeading">
        <div class="student-form-section-heading" id="editStudentContactHeading">Contact and year level</div>
      <div class="form-group"><label class="form-label" for="editStudentEmail">Email</label><input type="email" class="form-control" id="editStudentEmail" name="email" maxlength="150" value="<?= e($edit_values['email'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label" for="editStudentPassword">New Password</label><input type="password" class="form-control" id="editStudentPassword" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="editStudentPasswordHelp"><div class="text-xs text-muted mt-1" id="editStudentPasswordHelp">Leave blank to keep the current password. A new password must be at least 12 characters.</div></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label" for="editStudentYear">Year Level <span style="color:red">*</span></label><?php $edit_year = $edit_values['year_level'] ?? ''; ?><select class="form-control" id="editStudentYear" name="year_level" required><?php if ($edit_year !== '' && !in_array($edit_year, $year_level_options, true)): ?><option value="<?= e($edit_year) ?>" selected><?= e($edit_year) ?></option><?php endif; foreach ($year_level_options as $year_option): ?><option value="<?= e($year_option) ?>" <?= $edit_year === $year_option ? 'selected' : '' ?>><?= e($year_option) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label" for="editStudentPhone">Contact Number</label><input class="form-control" id="editStudentPhone" name="contact_number" maxlength="20" value="<?= e($edit_values['contact_number'] ?? '') ?>" placeholder="e.g. 0917-123-4567"></div>
      </div>
      </section>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button><button type="submit" class="btn btn-primary student-form-submit">Save Changes</button></div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  <?php if ($add_student_failed): ?>openModal('addStudentModal');<?php endif; ?>
  <?php if ($edit_student_failed): ?>openModal('editStudentModal');<?php endif; ?>
});
function openEditStudentModal(student) {
  document.getElementById('editStudentId').value = student.id;
  document.getElementById('editStudentPassword').value = '';
  document.getElementById('editStudentFirstName').value = student.first_name || '';
  document.getElementById('editStudentMiddleName').value = student.middle_name || '';
  document.getElementById('editStudentLastName').value = student.last_name || '';
  setNameExtensionFields('editStudent', student.name_extensions || []);
  const currentName = document.getElementById('editStudentCurrentName');
  currentName.textContent = student.name ? 'Current name on file: ' + student.name : '';
  currentName.style.display = student.first_name || student.middle_name || student.last_name ? 'none' : (student.name ? 'block' : 'none');
  document.getElementById('editStudentEmail').value = student.email || '';
  document.getElementById('editStudentNo').value = student.student_id_no || '';
  document.getElementById('editStudentProgram').value = student.program_id || '';
  document.getElementById('editStudentYear').value = student.year_level || '';
  document.getElementById('editStudentPhone').value = student.contact_number || '';
  openModal('editStudentModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
