<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['coordinator']);

$user  = current_user();
$uid   = (int)$user['id'];
$coord = query_one("SELECT * FROM coordinators WHERE user_id=?", [$uid], 'i');
$success = '';
$error   = '';

// Department notices only — notify students under this coordinator (no general/campus-wide messaging)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'post') {
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $tag     = trim($_POST['tag'] ?? 'Department');
        $pinned  = isset($_POST['is_pinned']) ? 1 : 0;
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        // Always scoped to students in this coordinator's department
        $target  = 'student';

        if (!$title || !$body) {
            $error = 'Title and body are required.';
        } else {
            $attachment_file = null;
            $attachment_name = null;

            if (!empty($_FILES['attachment']['name']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $orig = $_FILES['attachment']['name'];
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xls', 'xlsx', 'ppt', 'pptx'];
                $max_bytes = 10 * 1024 * 1024;

                if (!in_array($ext, $allowed_ext, true)) {
                    $error = 'Invalid attachment type. Allowed: PDF, DOC, images, and common office files.';
                } elseif (($_FILES['attachment']['size'] ?? 0) > $max_bytes) {
                    $error = 'Attachment is too large. Maximum size is 10MB.';
                } else {
                    $dest_dir = __DIR__ . '/../uploads/announcements/';
                    if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }
                    $new_filename = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest_dir . $new_filename)) {
                        $attachment_file = 'announcements/' . $new_filename;
                        $attachment_name = $orig;
                    } else {
                        $error = 'Failed to upload the attachment. Please try again.';
                    }
                }
            }
        }

        if (!$error) {
            insert(
                "INSERT INTO announcements (title, body, tag, target_role, created_by, is_pinned, expires_at, attachment_file, attachment_name, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [$title, $body, $tag, $target, $uid, $pinned, $expires, $attachment_file, $attachment_name],
                'ssssiisss'
            );

            log_activity($uid, 'Department Notice Posted', $title);
            $success = 'Department notice published. Students under your supervision were notified.';

            $students = query(
                "SELECT s.user_id FROM students s WHERE s.coordinator_id=? AND s.is_archived=0",
                [$coord['id']],
                'i'
            ) ?: [];
            foreach ($students as $st) {
                create_notification($st['user_id'], "Department notice: $title", 'info', '/ojtrack/student/announcements.php');
            }
        }
    } elseif ($action === 'edit') {
        $id      = (int)($_POST['ann_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $tag     = trim($_POST['tag'] ?? 'Department');
        $pinned  = isset($_POST['is_pinned']) ? 1 : 0;
        $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

        $existing = query_one("SELECT * FROM announcements WHERE id=? AND created_by=?", [$id, $uid], 'ii');
        if (!$existing) {
            $error = 'You can only edit notices you created.';
        } elseif (!$title || !$body) {
            $error = 'Title and body are required.';
        } else {
            $attachment_file = $existing['attachment_file'] ?? null;
            $attachment_name = $existing['attachment_name'] ?? null;

            if (!empty($_POST['remove_attachment'])) {
                $attachment_file = null;
                $attachment_name = null;
            }

            if (!empty($_FILES['attachment']['name']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $orig = $_FILES['attachment']['name'];
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xls', 'xlsx', 'ppt', 'pptx'];
                $max_bytes = 10 * 1024 * 1024;

                if (!in_array($ext, $allowed_ext, true)) {
                    $error = 'Invalid attachment type. Allowed: PDF, DOC, images, and common office files.';
                } elseif (($_FILES['attachment']['size'] ?? 0) > $max_bytes) {
                    $error = 'Attachment is too large. Maximum size is 10MB.';
                } else {
                    $dest_dir = __DIR__ . '/../uploads/announcements/';
                    if (!is_dir($dest_dir)) { mkdir($dest_dir, 0755, true); }
                    $new_filename = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest_dir . $new_filename)) {
                        $attachment_file = 'announcements/' . $new_filename;
                        $attachment_name = $orig;
                    } else {
                        $error = 'Failed to upload the attachment. Please try again.';
                    }
                }
            }
        }

        if (!$error) {
            query(
                "UPDATE announcements SET title=?, body=?, tag=?, target_role='student', is_pinned=?, expires_at=?, attachment_file=?, attachment_name=? WHERE id=? AND created_by=?",
                [$title, $body, $tag, $pinned, $expires, $attachment_file, $attachment_name, $id, $uid],
                'sssisissi'
            );
            log_activity($uid, 'Department Notice Updated', $title);
            $success = 'Notice updated successfully.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['ann_id'] ?? 0);
        query("DELETE FROM announcements WHERE id=? AND created_by=?", [$id, $uid], 'ii');
        log_activity($uid, 'Department Notice Deleted', "ID: $id");
        $success = 'Notice deleted.';
    } elseif ($action === 'toggle_pin') {
        $id  = (int)($_POST['ann_id'] ?? 0);
        $cur = query_one("SELECT is_pinned FROM announcements WHERE id=? AND created_by=?", [$id, $uid], 'ii');
        if ($cur !== null) {
            $new_pin = $cur['is_pinned'] ? 0 : 1;
            query("UPDATE announcements SET is_pinned=? WHERE id=?", [$new_pin, $id], 'ii');
            $success = 'Pin status updated.';
        }
    }
}

// Only notices created by this coordinator (department-scoped)
$announcements = query(
    "SELECT a.*, u.name AS author_name, u.role AS author_role
     FROM announcements a
     LEFT JOIN users u ON u.id=a.created_by
     WHERE a.created_by=?
     ORDER BY a.is_pinned DESC, a.created_at DESC",
    [$uid],
    'i'
) ?: [];

$dept_student_count = (int)(query_one(
    "SELECT COUNT(*) AS c FROM students s JOIN users u ON u.id=s.user_id
     WHERE s.coordinator_id=? AND s.is_archived=0 AND u.status!='archived'",
    [$coord['id']],
    'i'
)['c'] ?? 0);

$page_title = 'Department Notices';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Department Notices</div>
    <div class="page-sub">Notify only students under your department (<?= e($coord['department'] ?? 'your program') ?>) · <?= $dept_student_count ?> recipients</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('composeModal')">+ Post Notice</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header">
    <div class="card-title">Notices you posted</div>
    <span class="text-sm text-muted"><?= count($announcements) ?> items</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Title &amp; Preview</th>
          <th>Tag</th>
          <th>Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($announcements as $a):
          $ann_payload = [
              'id' => (int)$a['id'],
              'title' => $a['title'] ?? '',
              'body' => $a['body'] ?? '',
              'tag' => $a['tag'] ?? 'Department',
              'is_pinned' => (int)($a['is_pinned'] ?? 0),
              'expires_at' => !empty($a['expires_at']) ? date('Y-m-d', strtotime($a['expires_at'])) : '',
              'created_at' => isset($a['created_at']) ? format_date($a['created_at']) : '',
              'attachment_file' => $a['attachment_file'] ?? '',
              'attachment_name' => $a['attachment_name'] ?? '',
          ];
        ?>
          <tr class="row-clickable" onclick='openViewEditAnn(<?= htmlspecialchars(json_encode($ann_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
            <td style="max-width:360px">
              <div class="flex-items-center gap-2 mb-1">
                <?php if ($a['is_pinned']): ?>
                  <span class="badge badge-primary font-bold text-xs">PINNED</span>
                <?php endif; ?>
                <strong class="text-sm text-800"><?= e($a['title']) ?></strong>
              </div>
              <div class="text-xs text-muted truncate"><?= e(substr($a['body'], 0, 90)) ?>...</div>
              <?php if (!empty($a['attachment_file'])): ?>
                <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" onclick="event.stopPropagation()" class="text-xs">📎 <?= e($a['attachment_name'] ?: 'Attachment') ?></a>
              <?php endif; ?>
            </td>
            <td><span class="text-xs font-bold text-600"><?= e($a['tag'] ?: 'Department') ?></span></td>
            <td class="td-mono text-xs"><?= format_date($a['created_at']) ?></td>
            <td>
              <?php if ($a['expires_at'] && strtotime($a['expires_at']) < strtotime('today')): ?>
                <span class="badge badge-rejected">Expired</span>
              <?php else: ?>
                <span class="badge badge-approved">Active</span>
              <?php endif; ?>
            </td>
            <td onclick="stopRowClick(event)">
              <div class="flex-items-center gap-1">
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="toggle_pin">
                  <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="btn btn-secondary btn-sm"><?= $a['is_pinned'] ? 'Unpin' : 'Pin' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this notice?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?>
          <tr><td colspan="5" class="text-center text-muted py-6">No department notices yet. Post one to notify your students.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Compose Notice Modal -->
<div class="modal-overlay" id="composeModal">
  <div class="modal modal-lg">
    <div class="modal-title">Post Department Notice</div>
    <p class="modal-sub">This notifies only students assigned to you — not campus-wide</p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="post">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Midterm narrative report deadline" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category / Tag</label>
          <select name="tag" class="form-control">
            <option value="Department">Department</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Reminder">Reminder</option>
            <option value="Requirements">Requirements</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Expiration Date (Optional)</label>
          <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="form-group flex-items-center">
        <label class="flex-items-center gap-2 text-sm cursor-pointer">
          <input type="checkbox" name="is_pinned" value="1">
          <span class="font-bold">Pin this notice to top</span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" class="form-control" rows="6" placeholder="Write the notice for your department students..." required></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Attachment (image or file, optional)</label>
        <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
        <div class="text-xs text-muted mt-1">PDF, DOC, images, office files · Max 10MB</div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish to Department</button>
      </div>
    </form>
  </div>
</div>

<!-- View / Edit Notice Modal -->
<div class="modal-overlay" id="viewEditAnnModal">
  <div class="modal modal-lg">
    <div class="modal-title">View &amp; Edit Notice</div>
    <p class="modal-sub" id="viewEditAnnMeta"></p>
    <form method="POST" id="viewEditAnnForm" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="ann_id" id="editAnnId">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="editAnnTitle" class="form-control" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category / Tag</label>
          <select name="tag" id="editAnnTag" class="form-control">
            <option value="Department">Department</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Reminder">Reminder</option>
            <option value="Requirements">Requirements</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Expiration Date (Optional)</label>
          <input type="date" name="expires_at" id="editAnnExpires" class="form-control">
        </div>
      </div>

      <div class="form-group flex-items-center">
        <label class="flex-items-center gap-2 text-sm cursor-pointer">
          <input type="checkbox" name="is_pinned" value="1" id="editAnnPinned">
          <span class="font-bold">Pin this notice to top</span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" id="editAnnBody" class="form-control" rows="7" required></textarea>
      </div>

      <div class="form-group" id="editAnnCurrentFileWrap" style="display:none">
        <label class="form-label">Current Attachment</label>
        <a id="editAnnCurrentFile" href="#" target="_blank" class="btn btn-secondary btn-sm">View file</a>
        <label class="flex-items-center gap-2 text-sm cursor-pointer mt-2">
          <input type="checkbox" name="remove_attachment" value="1">
          <span>Remove attachment</span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Replace / Add Attachment (optional)</label>
        <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('viewEditAnnModal')">Close</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openViewEditAnn(a) {
  document.getElementById('editAnnId').value = a.id;
  document.getElementById('editAnnTitle').value = a.title || '';
  document.getElementById('editAnnBody').value = a.body || '';
  document.getElementById('editAnnTag').value = a.tag || 'Department';
  document.getElementById('editAnnExpires').value = a.expires_at || '';
  document.getElementById('editAnnPinned').checked = !!Number(a.is_pinned);
  document.getElementById('viewEditAnnMeta').textContent = a.created_at ? ('Posted · ' + a.created_at) : '';
  var fw = document.getElementById('editAnnCurrentFileWrap');
  var fa = document.getElementById('editAnnCurrentFile');
  if (a.attachment_file) {
    fw.style.display = '';
    fa.href = '/ojtrack/uploads/' + a.attachment_file;
    fa.textContent = a.attachment_name || 'View file';
  } else {
    fw.style.display = 'none';
  }
  openModal('viewEditAnnModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
