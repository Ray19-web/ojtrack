<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$success = ''; $error = '';

$allowed_ojt_statuses = ['pending', 'not_started', 'ongoing', 'completed', 'on_hold', 'withdrawn'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_student') {
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
                    $program_id = null;
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

            $dept = trim((string)($student['department'] ?? ''));
            if ($dept === '' && $prog_code !== '') {
                $dept = $prog_code;
            }

            if ($prog_code === '' && $dept === '') {
                $error = 'This student has no academic program set. Assign a program first.';
            } else {
                // Try several labels so program codes (BSIT) match coordinator depts (Information Technology)
                $coord_id = null;
                foreach (array_unique(array_filter([$dept, $prog_code, $prog_name])) as $candidate) {
                    $coord_id = find_coordinator_for_department($candidate);
                    if ($coord_id) break;
                }

                if (!$coord_id) {
                    $label = $prog_code !== '' ? $prog_code : $dept;
                    if ($prog_name) $label .= " - $prog_name";
                    $error = "No active OJT Coordinator is configured for \"{$label}\". Assign a coordinator to this program first, then try again.";
                } else {
                    if ($program_id && $prog_code !== '') {
                        if ($company_id === null) {
                            query(
                                "UPDATE students SET coordinator_id=?, company_id=NULL, program_id=?, program=?, department=?, ojt_status=?, required_hours=? WHERE id=?",
                                [$coord_id, $program_id, $prog_code, $dept ?: $prog_code, $ojt_status, $req_hours, $sid],
                                'iisssii'
                            );
                        } else {
                            query(
                                "UPDATE students SET coordinator_id=?, company_id=?, program_id=?, program=?, department=?, ojt_status=?, required_hours=? WHERE id=?",
                                [$coord_id, $company_id, $program_id, $prog_code, $dept ?: $prog_code, $ojt_status, $req_hours, $sid],
                                'iiisssii'
                            );
                        }
                    } else {
                        if ($company_id === null) {
                            query(
                                "UPDATE students SET coordinator_id=?, company_id=NULL, department=?, ojt_status=?, required_hours=? WHERE id=?",
                                [$coord_id, $dept, $ojt_status, $req_hours, $sid],
                                'issii'
                            );
                        } else {
                            query(
                                "UPDATE students SET coordinator_id=?, company_id=?, department=?, ojt_status=?, required_hours=? WHERE id=?",
                                [$coord_id, $company_id, $dept, $ojt_status, $req_hours, $sid],
                                'iissii'
                            );
                        }
                    }

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
    "SELECT s.*, u.name, u.email, u.status AS user_status, co.company_name, cu.name AS coordinator_name,
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

$page_title = 'All Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Student Overview &amp; Assignments</div>
    <div class="page-sub">System-wide view of all registered OJT students, academic programs, and company assignments</div>
  </div>
  <div class="page-heading-actions">
    <a href="/ojtrack/admin/programs.php" class="btn btn-secondary">Manage Programs</a>
    <a href="/ojtrack/admin/users.php?role=student" class="btn btn-primary">+ Add Student Account</a>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
      <div class="search-wrap" style="width:230px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search student, ID, program..." value="<?= e($search) ?>">
      </div>

      <select name="archive" class="form-control" style="width:160px" onchange="this.form.submit()">
        <option value="active" <?= $archive_filter==='active'?'selected':'' ?>>Active (<?= $counts['active'] ?>)</option>
        <option value="archived" <?= $archive_filter==='archived'?'selected':'' ?>>Archived (<?= $counts['archived'] ?>)</option>
        <option value="all" <?= $archive_filter==='all'?'selected':'' ?>>All Students</option>
      </select>

      <select name="program_id" class="form-control" style="width:180px" onchange="this.form.submit()">
        <option value="">All Academic Programs</option>
        <?php foreach ($all_programs_filter as $pf): ?>
          <option value="<?= (int)$pf['id'] ?>" <?= (string)$program_filter === (string)$pf['id'] ? 'selected' : '' ?>>
            <?= e($pf['code']) ?> - <?= e($pf['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-control" style="width:130px" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="pending"     <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="not_started" <?= $status==='not_started'?'selected':'' ?>>Not Started</option>
        <option value="ongoing"     <?= $status==='ongoing'?'selected':'' ?>>Ongoing</option>
        <option value="completed"   <?= $status==='completed'?'selected':'' ?>>Completed</option>
        <option value="on_hold"     <?= $status==='on_hold'?'selected':'' ?>>On Hold</option>
        <option value="withdrawn"   <?= $status==='withdrawn'?'selected':'' ?>>Withdrawn</option>
      </select>

      <button type="submit" class="btn btn-secondary">Filter</button>
      <?php if ($search !== '' || $status !== '' || $program_filter !== '' || $archive_filter !== 'active'): ?>
        <a href="/ojtrack/admin/students.php" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>
    <div class="text-sm text-muted"><?= count($students) ?> student(s) found</div>
  </div>

  <div class="table-wrap">
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
            <div style="display:flex;gap:10px;align-items:center">
              <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)">
                <?= strtoupper(substr($s['name'],0,2)) ?>
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
            <?php if (!empty($s['program_name'])): ?>
              <div class="text-xs text-muted mt-1" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px" title="<?= e($s['program_name']) ?>">
                <?= e($s['program_name']) ?>
              </div>
            <?php endif; ?>
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
            <div style="display:flex;gap:4px;align-items:center">
              <?php if ($is_arch): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Restore this student to active status?')">
                  <input type="hidden" name="action" value="restore_student">
                  <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="btn btn-secondary btn-xs" style="color:var(--success);border-color:var(--success);font-weight:600">Restore</button>
                </form>
              <?php else: ?>
                <button type="button" class="btn btn-secondary btn-xs" onclick='openAssignModal(<?= htmlspecialchars(json_encode($modal_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>Assign / Edit</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to archive this student? All historical logs, hours, and evaluations will be preserved.')">
                  <input type="hidden" name="action" value="archive_student">
                  <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="btn btn-warning btn-xs">Archive</button>
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

<!-- Assign / Edit Modal -->
<div class="modal-overlay" id="assignModal">
  <div class="modal">
    <div class="modal-title">Student Assignment Details</div>
    <p class="modal-sub" id="assignModalSub"></p>
    <div id="assignDetailSummary" style="background:var(--bg);padding:10px 12px;border-radius:var(--radius);font-size:12px;margin-bottom:14px;display:none"></div>
    <form method="POST" id="assignStudentForm" onsubmit="return validateAssignForm()">
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
        <div class="text-xs text-muted mt-1">Selecting a program automatically assigns that program's OJT Coordinator.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Assigned Coordinator <span class="text-muted" style="font-weight:500">(Auto-Assigned)</span></label>
        <select id="assignCoordId" class="form-control" disabled style="background:#f1f5f9;cursor:not-allowed" aria-readonly="true" tabindex="-1">
          <option value="">-- Select a program to auto-assign --</option>
          <?php foreach ($coordinators as $c): ?>
            <option value="<?= (int)$c['id'] ?>" data-dept="<?= e($c['department'] ?? '') ?>"><?= e($c['name']) ?> (<?= e($c['department'] ?? 'General') ?>)</option>
          <?php endforeach; ?>
        </select>
        <div id="assignCoordOk" class="text-xs mt-1" style="display:none;color:#16a34a;font-weight:600"></div>
        <div id="assignCoordWarning" class="text-xs mt-1" style="display:none;color:#b45309;font-weight:600;background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;border-radius:6px"></div>
      </div>

      <div class="form-group">
        <label class="form-label">Assigned Partner Company</label>
        <select name="company_id" id="assignCompId" class="form-control">
          <option value="">-- No Company Assigned --</option>
          <?php foreach ($companies as $co): ?>
            <option value="<?= (int)$co['id'] ?>"><?= e($co['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="text-xs text-muted mt-1">OJT company is assigned manually and is independent of the academic program.</div>
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
const COORD_DEPT_MAP = <?= json_encode($coord_dept_map, JSON_UNESCAPED_UNICODE) ?>;
const PROGRAM_OPTIONS = <?= json_encode($program_options, JSON_UNESCAPED_UNICODE) ?>;

function deptAliases(dept) {
  const raw = (dept || '').trim().toLowerCase();
  if (!raw) return [];
  let aliases = [raw];
  const compact = raw.replace(/\b(department|dept|of|and|&)\b/g, ' ').replace(/\s+/g, ' ').trim();
  if (compact && compact !== raw) aliases.push(compact);

  const map = {
    'it': ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
    'bsit': ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
    'information technology': ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
    'bachelor of science in information technology': ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
    'cs': ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
    'bscs': ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
    'computer science': ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
    'bachelor of science in computer science': ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
    'hr': ['hr', 'human resources', 'human resource'],
    'human resources': ['hr', 'human resources', 'human resource'],
    'finance': ['finance', 'financial'],
    'engineering': ['engineering', 'engineering technology', 'bsmet', 'bset'],
    'engineering technology': ['engineering', 'engineering technology', 'bsmet'],
    'bsmet': ['engineering', 'engineering technology', 'bsmet', 'mechanical engineering technology'],
    'mechanical engineering technology': ['engineering', 'engineering technology', 'bsmet', 'mechanical engineering technology'],
    'bachelor of science in mechanical engineering technology': ['engineering', 'engineering technology', 'bsmet', 'mechanical engineering technology'],
    'bset': ['engineering', 'electrical technology', 'bset'],
    'electrical technology': ['engineering', 'electrical technology', 'bset'],
    'bachelor of science in electrical technology': ['engineering', 'electrical technology', 'bset'],
    'education': ['education', 'ed', 'bsed', 'bachelor of secondary education'],
    'ed': ['education', 'ed', 'bsed', 'bachelor of secondary education'],
    'bsed': ['education', 'ed', 'bsed', 'bachelor of secondary education'],
    'bachelor of secondary education': ['education', 'ed', 'bsed', 'bachelor of secondary education'],
    'technology communication management': ['tcm', 'bstcm', 'technology communication management'],
    'tcm': ['tcm', 'bstcm', 'technology communication management'],
    'bstcm': ['tcm', 'bstcm', 'technology communication management'],
    'bachelor of science in technology communication management': ['tcm', 'bstcm', 'technology communication management']
  };

  Object.keys(map).forEach(function (key) {
    const vals = map[key];
    if (raw === key || compact === key || vals.indexOf(raw) !== -1 || vals.indexOf(compact) !== -1) {
      aliases = aliases.concat(vals);
    }
  });

  return aliases.filter(function (v, i, a) { return a.indexOf(v) === i; });
}

function findCoordinatorForLabels(labels) {
  const list = (labels || []).filter(Boolean);
  for (let L = 0; L < list.length; L++) {
    const dept = list[L];
    const aliases = deptAliases(dept);
    if (!aliases.length) continue;

    for (let i = 0; i < COORD_DEPT_MAP.length; i++) {
      const c = COORD_DEPT_MAP[i];
      const cDept = (c.department || '').trim();
      if (!cDept) continue;
      if (cDept.toLowerCase() === String(dept).trim().toLowerCase()) return c;
    }

    for (let i = 0; i < COORD_DEPT_MAP.length; i++) {
      const c = COORD_DEPT_MAP[i];
      const cAliases = deptAliases(c.department || '');
      for (let j = 0; j < aliases.length; j++) {
        if (cAliases.indexOf(aliases[j]) !== -1) return c;
      }
    }
  }
  return null;
}

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
    ? findCoordinatorForLabels([program.code, program.name, program.label])
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
    ? findCoordinatorForLabels([program.code, program.name, program.label])
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
