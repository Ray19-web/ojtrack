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
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $dept     = trim($_POST['department'] ?? '');
        $phone    = trim($_POST['contact_number'] ?? '');
        $pass     = trim($_POST['password'] ?? '');

        $prog = $dept !== '' ? query_one("SELECT id, code, name FROM programs WHERE code=? AND status='active'", [$dept], 's') : null;

        if (!$name || !$email || strlen($pass) < 12 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Name, a valid email and a password of at least 12 characters are required.';
        } elseif (!$prog) {
            $error = 'Please select a valid assigned academic program.';
        } elseif (query_one("SELECT id FROM users WHERE email=?", [$email], 's')) {
            $error = 'Email is already in use by another account.';
        } else {
            $dept = $prog['code'];
            $coord_id = generate_coordinator_id();
            $uid = insert("INSERT INTO users (name,email,role,password,status) VALUES (?,?,'coordinator',?,'active')",
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT)], 'sss');
            insert("INSERT INTO coordinators (user_id, coordinator_id_no, department, contact_number) VALUES (?,?,?,?)",
                [$uid, $coord_id, $dept, $phone], 'isss');
            assign_unassigned_students_for_program($prog);
            log_activity($user['id'], 'Coordinator Added', "$name ({$prog['code']} - {$prog['name']} - $coord_id)");
            $success = "Coordinator $name created successfully. Assigned ID: $coord_id.";
        }
    } elseif ($action === 'edit_coord') {
        $cid      = (int)($_POST['coord_id'] ?? 0);
        $dept     = trim($_POST['department'] ?? '');
        $phone    = trim($_POST['contact_number'] ?? '');

        $prog = $dept !== '' ? query_one("SELECT id, code, name FROM programs WHERE code=? AND status='active'", [$dept], 's') : null;
        if (!query_one("SELECT id FROM coordinators WHERE id=?", [$cid], 'i')) {
            $error = 'Coordinator not found.';
        } elseif (!$prog) {
            $error = 'Please select a valid assigned academic program.';
        } else {
            $dept = $prog['code'];
            // coordinator_id_no is system-generated and not editable
            query("UPDATE coordinators SET department=?, contact_number=? WHERE id=?",
                [$dept, $phone, $cid], 'ssi');
            assign_unassigned_students_for_program($prog);
            log_activity($user['id'], 'Coordinator Details Updated', "Coord ID: $cid → {$prog['code']}");
            $success = 'Coordinator details updated successfully.';
        }
    } else {
        $error = 'Students are assigned automatically by academic program. Update the student program in Student Management.';
    }
}

require_once __DIR__ . '/../config/coordinator-filters.php';
$coord_filters = coordinator_filters($_GET);
$coordinators = query("SELECT c.*, u.name, u.email, u.avatar, u.status,
    (SELECT COUNT(*) FROM students s JOIN users su ON su.id=s.user_id WHERE s.coordinator_id=c.id AND s.is_archived=0 AND su.status!='archived') AS student_count,
    (SELECT COUNT(*) FROM students s JOIN users su ON su.id=s.user_id WHERE s.coordinator_id=c.id AND s.ojt_status='ongoing' AND s.is_archived=0 AND su.status!='archived') AS active_students
    FROM coordinators c JOIN users u ON u.id=c.user_id WHERE {$coord_filters['where']} ORDER BY u.name", $coord_filters['params'], $coord_filters['types']) ?: [];

$unassigned = query("SELECT s.id, u.name, s.student_id_no FROM students s JOIN users u ON u.id=s.user_id WHERE s.coordinator_id IS NULL AND s.is_archived=0 AND u.status!='archived' ORDER BY u.name", [], '');
$next_coord_id = generate_coordinator_id();
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
