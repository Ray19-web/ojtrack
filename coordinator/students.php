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
        "SELECT s.*, u.name, u.email, u.avatar FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.coordinator_id=? AND s.is_archived=0 AND u.status!='archived'",
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

            $enrollment=normalized_enrollment_for_student($sid);
            try {
                if (!normalized_update_training_assignment(
                    $sid,(int)$user['id'],(int)$coord['id'],$company_id,
                    (int)($enrollment['program_id'] ?? $stu['program_id'] ?? 0),
                    $ojt_status,$req_hours,$start_date,$end_date,$status_notes
                )) throw new DomainException('Required hours must be greater than zero.');
            } catch (DomainException $exception) {
                $error=$exception->getMessage();
            }
            if (!$error) {
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
    "SELECT s.*, u.name, u.email, u.avatar, co.company_name FROM students s
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
        "SELECT s.*, u.name, u.email, u.avatar, co.company_name, cu.name AS supervisor FROM students s
         JOIN users u ON u.id=s.user_id
         LEFT JOIN companies co ON co.id=s.company_id
         LEFT JOIN users cu ON cu.id=co.user_id
         WHERE s.id=? AND s.coordinator_id=? AND s.is_archived=0 AND u.status!='archived'",
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
    <div class="page-title"><?= $detail ? 'Student Details' : 'My Students' ?></div>
    <div class="page-sub"><?= $detail ? 'Academic information and OJT placement' : 'Placements, OJT status, and student records' ?></div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php if ($detail): ?>
<?php require __DIR__ . '/../includes/coordinator-student-detail.php'; ?>
<?php require __DIR__ . '/../includes/coordinator-student-edit.php'; ?>

<?php else: ?>

<div class="card mb-4">
  <div class="card-header flex-between">
    <div style="display:flex;gap:8px">
      <form class="filter-toolbar" method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
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
        <tr class="row-clickable" data-row-link="/ojtrack/coordinator/students.php?id=<?= (int)$s['id'] ?>" title="Manage student">
          <td>
            <div class="table-person">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= user_avatar_content($s['avatar'] ?? null, $s['name']) ?></div>
              <div>
                <a href="/ojtrack/coordinator/students.php?id=<?= (int)$s['id'] ?>" class="font-bold text-sm"><?= e($s['name']) ?></a>
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
              <a href="?id=<?= $s['id'] ?>" class="table-action-icon is-primary" title="Manage student" aria-label="Manage student">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h2M10 17h10M14 5v4M8 15v4"/></svg>
              </a>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
