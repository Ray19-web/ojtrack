<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_coord') {
        $first_name = normalize_person_name_part($_POST['first_name'] ?? '');
        $middle_name = normalize_person_name_part($_POST['middle_name'] ?? '');
        $last_name = normalize_person_name_part($_POST['last_name'] ?? '');
        $base_name = person_name_from_parts($first_name, $middle_name, $last_name);
        $extension_result = name_extensions_from_post();
        $extensions = $extension_result['extensions'];
        $first_extension = $extensions[0] ?? null;
        $extension_error = $extension_result['error'];
        $name = format_person_name($base_name, $extensions);
        $email    = trim($_POST['email'] ?? '');
        $coord_id = is_string($_POST['coordinator_id_no'] ?? null) ? trim($_POST['coordinator_id_no']) : '';
        $dept     = trim($_POST['department'] ?? '');
        $phone    = trim($_POST['contact_number'] ?? '');
        $pass     = trim($_POST['password'] ?? '');

        $prog = $dept !== '' ? query_one("SELECT id, code, name FROM programs WHERE code=? AND status='active'", [$dept], 's') : null;

        if (!$first_name || !$last_name || mb_strlen($first_name) > 60 || mb_strlen($middle_name) > 60 || mb_strlen($last_name) > 60 || mb_strlen($name) > 100 || !$email || strlen($email) > 150 || strlen($pass) < 12 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'First name, last name, a valid email and a password of at least 12 characters are required.';
        } elseif ($extension_error) {
            $error = $extension_error;
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,49}$/D', $coord_id)) {
            $error = 'Enter a coordinator ID using up to 50 letters, numbers or hyphens.';
        } elseif (query_one("SELECT id FROM coordinators WHERE coordinator_id_no=?", [$coord_id], 's')) {
            $error = 'That coordinator ID is already assigned.';
        } elseif (strlen($phone) > 30) {
            $error = 'Phone or contact number must be 30 characters or fewer.';
        } elseif (!$prog) {
            $error = 'Please select a valid assigned academic program.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already in use by another account.';
        } else {
            $dept = $prog['code'];
            $uid = insert("INSERT INTO users (name,name_extension_type,name_extension_value,email,role,password,status) VALUES (?,?,?,?,'coordinator',?,'active')",
                [$name, $first_extension['type'] ?? null, $first_extension['value'] ?? null, $email, password_hash($pass, PASSWORD_DEFAULT)], 'sssss');
            save_user_name_extensions((int)$uid, $extensions);
            insert("INSERT INTO coordinators (user_id, coordinator_id_no, first_name, middle_name, last_name, department, contact_number) VALUES (?,?,?,?,?,?,?)",
                [$uid, $coord_id, $first_name, $middle_name ?: null, $last_name, $dept, $phone], 'issssss');
            assign_unassigned_students_for_program($prog, (int)$user['id']);
            log_activity($user['id'], 'Coordinator Added', "$name ({$prog['code']} - {$prog['name']} - $coord_id)");
            $success = "Coordinator $name created successfully. Assigned ID: $coord_id.";
        }
    } elseif ($action === 'edit_coord') {
        $cid = (int)($_POST['coord_id'] ?? 0);
        $posted_text = static fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $first_name = normalize_person_name_part($posted_text('first_name'));
        $middle_name = normalize_person_name_part($posted_text('middle_name'));
        $last_name = normalize_person_name_part($posted_text('last_name'));
        $has_name_parts = $first_name !== '' || $middle_name !== '' || $last_name !== '';
        $extension_result = name_extensions_from_post();
        $extensions = $extension_result['extensions'];
        $first_extension = $extensions[0] ?? null;
        $extension_error = $extension_result['error'];
        $email = $posted_text('email');
        $coord_id = $posted_text('coordinator_id_no');
        $dept = $posted_text('department');
        $phone = $posted_text('contact_number');
        $pass = $posted_text('password');

        $coordinator = query_one(
            "SELECT c.*, u.name FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=? AND u.role='coordinator'",
            [$cid],
            'i'
        );
        $existing_extensions = $coordinator ? user_name_extensions((int)$coordinator['user_id']) : [];
        $base_name = $has_name_parts
            ? person_name_from_parts($first_name, $middle_name, $last_name)
            : ($coordinator ? base_person_name($coordinator['name'], $existing_extensions) : '');
        $name = format_person_name($base_name, $extensions);
        $prog = $dept !== '' ? query_one("SELECT id, code, name FROM programs WHERE code=? AND status='active'", [$dept], 's') : null;
        if (!$coordinator) {
            $error = 'Coordinator not found.';
        } elseif (($has_name_parts && (!$first_name || !$last_name)) || $base_name === '' || mb_strlen($first_name) > 60 || mb_strlen($middle_name) > 60 || mb_strlen($last_name) > 60 || mb_strlen($name) > 100) {
            $error = 'Enter first and last name, with each name part no longer than 60 characters and the complete name no longer than 100 characters.';
        } elseif ($extension_error) {
            $error = $extension_error;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $error = 'Enter a valid email address of up to 150 characters.';
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,49}$/D', $coord_id)) {
            $error = 'Enter a coordinator ID using up to 50 letters, numbers or hyphens.';
        } elseif (query_one("SELECT id FROM users WHERE email=? AND id<>?", [$email, $coordinator['user_id']], 'si')) {
            $error = 'Email is already in use by another account.';
        } elseif (query_one("SELECT id FROM coordinators WHERE coordinator_id_no=? AND id<>?", [$coord_id, $cid], 'si')) {
            $error = 'That coordinator ID is already assigned.';
        } elseif (strlen($phone) > 30) {
            $error = 'Phone or contact number must be 30 characters or fewer.';
        } elseif ($pass !== '' && strlen($pass) < 12) {
            $error = 'A new password must be at least 12 characters.';
        } elseif (!$prog) {
            $error = 'Please select a valid assigned academic program.';
        } else {
            db()->begin_transaction();
            try {
                if ($pass !== '') {
                    query("UPDATE users SET name=?, email=?, password=? WHERE id=?",
                        [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $coordinator['user_id']], 'sssi');
                } else {
                    query("UPDATE users SET name=?, email=? WHERE id=?",
                        [$name, $email, $coordinator['user_id']], 'ssi');
                }
                save_user_name_extensions((int)$coordinator['user_id'], $extensions);
                if ($has_name_parts) {
                    query("UPDATE coordinators SET coordinator_id_no=?, first_name=?, middle_name=?, last_name=?, department=?, contact_number=? WHERE id=?",
                        [$coord_id, $first_name, $middle_name ?: null, $last_name, $prog['code'], $phone, $cid], 'ssssssi');
                } else {
                    query("UPDATE coordinators SET coordinator_id_no=?, department=?, contact_number=? WHERE id=?",
                        [$coord_id, $prog['code'], $phone, $cid], 'sssi');
                }
                assign_unassigned_students_for_program($prog, (int)$user['id']);
                log_activity($user['id'], 'Coordinator Details Updated', "{$name} ({$prog['code']}) ID: {$coord_id}");
                db()->commit();
                $success = 'Coordinator details updated successfully.';
            } catch (Throwable $exception) {
                db()->rollback();
                if ($exception instanceof mysqli_sql_exception && (int)$exception->getCode() === 1062) {
                    $error = 'The email or coordinator ID is already in use.';
                } else {
                    error_log('OJTrack coordinator update failed (code ' . $exception->getCode() . ').');
                    $error = 'Unable to update coordinator details. Please try again.';
                }
            }
        }
    } elseif ($action === 'archive_coord') {
        $cid = (int)($_POST['coord_id'] ?? 0);
        $coordinator = query_one(
            "SELECT c.user_id, u.name FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=? AND u.role='coordinator' AND u.status!='archived'",
            [$cid],
            'i'
        );
        if (!$coordinator) {
            $error = 'Coordinator not found or already archived.';
        } else {
            db()->begin_transaction();
            try {
                query("UPDATE users SET status='archived' WHERE id=?", [$coordinator['user_id']], 'i');
                log_activity($user['id'], 'Coordinator Archived', "Archived coordinator {$coordinator['name']} (ID: $cid)");
                db()->commit();
                $success = "Coordinator {$coordinator['name']} archived successfully. Their records are preserved; restore the account from Archived Accounts.";
            } catch (Throwable $exception) {
                db()->rollback();
                error_log('OJTrack coordinator archive failed (code ' . $exception->getCode() . ').');
                $error = 'Unable to archive this coordinator. Please try again.';
            }
        }
    } else {
        $error = 'Students are assigned automatically by academic program. Update the student program in Student Management.';
    }
}

require_once __DIR__ . '/../config/coordinator-filters.php';
$coord_filters = coordinator_filters($_GET);
$coordinators = query("SELECT c.*, u.name, u.name_extension_type, u.name_extension_value, u.email, u.avatar, u.status,
    (SELECT COUNT(DISTINCT oe.id) FROM ojt_enrollments oe JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
     JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
     JOIN students s ON s.id=oe.student_id JOIN users su ON su.id=s.user_id
     WHERE ec.coordinator_id=c.id AND s.is_archived=0 AND su.status!='archived') AS student_count,
    (SELECT COUNT(DISTINCT oe.id) FROM ojt_enrollments oe JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
     JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
     JOIN students s ON s.id=oe.student_id JOIN users su ON su.id=s.user_id
     WHERE ec.coordinator_id=c.id AND oe.status='ongoing' AND s.is_archived=0 AND su.status!='archived') AS active_students
    FROM coordinators c JOIN users u ON u.id=c.user_id WHERE {$coord_filters['where']} ORDER BY u.name", $coord_filters['params'], $coord_filters['types']) ?: [];
foreach ($coordinators as &$coordinator_row) {
    $coordinator_row['name_extensions'] = user_name_extensions((int)$coordinator_row['user_id']);
    $coordinator_row['name_base'] = base_person_name($coordinator_row['name'], $coordinator_row['name_extensions']);
}
unset($coordinator_row);

$unassigned = query("SELECT s.id, u.name, s.student_id_no FROM ojt_enrollments oe
    JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
    JOIN students s ON s.id=oe.student_id JOIN users u ON u.id=s.user_id
    LEFT JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
    WHERE ec.id IS NULL AND s.is_archived=0 AND u.status!='archived' ORDER BY u.name", [], '') ?: [];
$programs_list = query("SELECT id, code, name FROM programs WHERE status='active' ORDER BY code ASC", [], '') ?: [];
$filter_programs = $programs_list;

$page_title = 'Coordinators';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Coordinators</div>
    <div class="page-sub">Students are assigned by program.</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addCoordModal')">+ Add Coordinator</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if (!empty($unassigned)): ?>
<div class="alert alert-warn mb-4">
  <div class="alert-body">
    <p><strong><?= count($unassigned) ?> non-archived student(s)</strong> currently have no assigned OJT coordinator. Check their academic program in <a href="/ojtrack/admin/students.php">Student Management</a> and ensure an active coordinator is configured for that program.</p>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/coordinator-table.php'; ?>

<?php require __DIR__ . '/../includes/coordinator-modals.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
