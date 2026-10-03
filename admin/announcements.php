<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$uid  = (int)$user['id'];
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'post') {
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $target  = $_POST['target_role'] ?? 'all';
        $tag     = trim($_POST['tag'] ?? 'General');
        $pinned  = isset($_POST['is_pinned']) ? 1 : 0;

        // Admin is never a valid audience
        $allowed_targets = ['all', 'student', 'coordinator', 'company'];
        if (!in_array($target, $allowed_targets, true)) {
            $target = 'all';
        }

        $attachment_file = null;
        $attachment_name = null;

        if (!$title || !$body) {
            $error = 'Title and body are required.';
        } else {
            // Optional file / image attachment
            if (!empty($_FILES['attachment']['name']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $orig = $_FILES['attachment']['name'];
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xls', 'xlsx', 'ppt', 'pptx'];
                $max_bytes = 10 * 1024 * 1024; // 10MB

                if (!in_array($ext, $allowed_ext, true)) {
                    $error = 'Invalid attachment type. Allowed: PDF, DOC, images, and common office files.';
                } elseif (($_FILES['attachment']['size'] ?? 0) > $max_bytes) {
                    $error = 'Attachment is too large. Maximum size is 10MB.';
                } else {
                    $dest_dir = __DIR__ . '/../uploads/announcements/';
                    if (!is_dir($dest_dir)) {
                        mkdir($dest_dir, 0755, true);
                    }
                    $new_filename = 'ann_' . bin2hex(random_bytes(16)) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest_dir . $new_filename)) {
                        $attachment_file = 'announcements/' . $new_filename;
                        $attachment_name = $orig;
                    } else {
                        $error = 'Failed to upload the attachment. Please try again.';
                    }
                }
            }

            if (!$error) {
                insert(
                    "INSERT INTO announcements (title, body, tag, target_role, created_by, is_pinned, expires_at, attachment_file, attachment_name, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 1)",
                    [$title, $body, $tag, $target, $uid, $pinned, $attachment_file, $attachment_name],
                    'ssssiiss'
                );

                log_activity($uid, 'Announcement Posted', $title);
                $success = 'Announcement published successfully.';

                // Notify matching non-admin active users
                if ($target === 'all') {
                    $notif_users = query("SELECT id FROM users WHERE status='active' AND role != 'admin' AND id!=?", [$uid], 'i') ?: [];
                } else {
                    $notif_users = query("SELECT id FROM users WHERE status='active' AND role=? AND id!=?", [$target, $uid], 'si') ?: [];
                }
                foreach ($notif_users as $u) {
                    $link = match ($target) {
                        'coordinator' => '/ojtrack/coordinator/announcements.php',
                        'company'     => '/ojtrack/company/announcements.php',
                        default       => '/ojtrack/student/announcements.php',
                    };
                    if ($target === 'all') {
                        $role_row = query_one("SELECT role FROM users WHERE id=?", [$u['id']], 'i');
                        $r = $role_row['role'] ?? 'student';
                        $link = match ($r) {
                            'coordinator' => '/ojtrack/coordinator/announcements.php',
                            'company'     => '/ojtrack/company/announcements.php',
                            default       => '/ojtrack/student/announcements.php',
                        };
                    }
                    create_notification($u['id'], "New announcement: $title", 'info', $link);
                }
            }
        }
    } elseif ($action === 'edit') {
        $id      = (int)($_POST['ann_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $target  = $_POST['target_role'] ?? 'all';
        $tag     = trim($_POST['tag'] ?? 'General');
        $pinned  = isset($_POST['is_pinned']) ? 1 : 0;
        $remove_attachment = isset($_POST['remove_attachment']) ? 1 : 0;

        $allowed_targets = ['all', 'student', 'coordinator', 'company'];
        if (!in_array($target, $allowed_targets, true)) {
            $target = 'all';
        }

        $existing = query_one("SELECT * FROM announcements WHERE id=?", [$id], 'i');
        if (!$existing) {
            $error = 'Announcement not found.';
        } elseif (!$title || !$body) {
            $error = 'Title and body are required.';
        } else {
            $attachment_file = $existing['attachment_file'] ?? null;
            $attachment_name = $existing['attachment_name'] ?? null;

            if ($remove_attachment) {
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
                    if (!is_dir($dest_dir)) {
                        mkdir($dest_dir, 0755, true);
                    }
                    $new_filename = 'ann_' . bin2hex(random_bytes(16)) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest_dir . $new_filename)) {
                        $attachment_file = 'announcements/' . $new_filename;
                        $attachment_name = $orig;
                    } else {
                        $error = 'Failed to upload the attachment. Please try again.';
                    }
                }
            }

            if (!$error) {
                query(
                    "UPDATE announcements SET title=?, body=?, tag=?, target_role=?, is_pinned=?, attachment_file=?, attachment_name=? WHERE id=?",
                    [$title, $body, $tag, $target, $pinned, $attachment_file, $attachment_name, $id],
                    'ssssissi'
                );
                log_activity($uid, 'Announcement Updated', $title);
                $success = 'Announcement updated successfully.';
            }
        }
    } elseif ($action === 'toggle_pin') {
        $id  = (int)($_POST['ann_id'] ?? 0);
        $cur = query_one("SELECT is_pinned FROM announcements WHERE id=?", [$id], 'i');
        if ($cur !== null) {
            $new_pin = $cur['is_pinned'] ? 0 : 1;
            query("UPDATE announcements SET is_pinned=? WHERE id=?", [$new_pin, $id], 'ii');
            $success = 'Pin status updated.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (a.title LIKE ? OR a.body LIKE ? OR a.tag LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ssss';
}

$announcements = query(
    "SELECT a.*, u.name AS author_name, u.role AS author_role
     FROM announcements a
     LEFT JOIN users u ON u.id=a.created_by
     WHERE $where
     ORDER BY a.is_pinned DESC, a.created_at DESC",
    $params,
    $types
) ?: [];

$total_count  = (int)(query_one("SELECT COUNT(*) AS c FROM announcements")['c'] ?? 0);
$pinned_count = (int)(query_one("SELECT COUNT(*) AS c FROM announcements WHERE is_pinned=1")['c'] ?? 0);
$all_count    = (int)(query_one("SELECT COUNT(*) AS c FROM announcements WHERE target_role='all'")['c'] ?? 0);
$with_file    = (int)(query_one("SELECT COUNT(*) AS c FROM announcements WHERE attachment_file IS NOT NULL AND attachment_file != ''")['c'] ?? 0);

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Announcements Management</div>
    <div class="page-sub">Manage system-wide broadcasts and official campus advisories</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('composeModal')">+ Post Announcement</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card">
  <div class="card-header flex-between">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <div class="search-wrap" style="width:280px">
        <input type="text" name="q" class="form-control search-input" placeholder="Search title, body, tag, author..." value="<?= e($search) ?>">
      </div>
      <button type="submit" class="btn btn-secondary">Search</button>
      <?php if ($search !== ''): ?>
        <a href="/ojtrack/admin/announcements.php" class="btn btn-ghost">Clear</a>
      <?php endif; ?>
    </form>
    <span class="text-sm text-muted"><?= count($announcements) ?> notice(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Title &amp; Preview</th>
          <th>Audience</th>
          <th>Tag</th>
          <th>Attachment</th>
          <th>Author</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($announcements as $a):
          $ann_payload = [
              'id' => (int)$a['id'],
              'title' => $a['title'] ?? '',
              'body' => $a['body'] ?? '',
              'target_role' => $a['target_role'] ?? 'all',
              'tag' => $a['tag'] ?? 'General',
              'is_pinned' => (int)($a['is_pinned'] ?? 0),
              'attachment_file' => $a['attachment_file'] ?? '',
              'attachment_name' => $a['attachment_name'] ?? '',
              'author_name' => $a['author_name'] ?? 'OJT Office',
              'created_at' => isset($a['created_at']) ? format_date($a['created_at']) : '',
          ];
        ?>
          <tr class="row-clickable" onclick='openViewEditAnn(<?= htmlspecialchars(json_encode($ann_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="Click to view &amp; edit">
            <td style="max-width:320px">
              <div class="flex-items-center gap-2 mb-1">
                <?php if ($a['is_pinned']): ?>
                  <span class="badge badge-primary font-bold text-xs">PINNED</span>
                <?php endif; ?>
                <strong class="text-sm text-800"><?= e($a['title']) ?></strong>
              </div>
              <div class="text-xs text-muted truncate"><?= e(substr($a['body'], 0, 90)) ?><?= strlen($a['body']) > 90 ? '...' : '' ?></div>
            </td>
            <td>
              <span class="badge badge-secondary"><?= $a['target_role'] === 'all' ? 'All Roles' : ucfirst($a['target_role']) ?></span>
            </td>
            <td>
              <span class="text-xs font-bold text-600"><?= e($a['tag'] ?: 'General') ?></span>
            </td>
            <td class="text-sm" onclick="stopRowClick(event)">
              <?php if (!empty($a['attachment_file'])): ?>
                <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank" class="btn btn-secondary btn-xs">
                  <?= e($a['attachment_name'] ?: 'View file') ?>
                </a>
              <?php else: ?>
                <span class="text-xs text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-sm">
              <?= e($a['author_name'] ?: 'OJT Office') ?>
            </td>
            <td class="td-mono text-xs">
              <?= format_date($a['created_at']) ?>
            </td>
            <td onclick="stopRowClick(event)">
              <form method="POST" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_pin">
                <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm" title="<?= $a['is_pinned'] ? 'Unpin' : 'Pin to top' ?>">
                  <?= $a['is_pinned'] ? 'Unpin' : 'Pin' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?>
          <tr><td colspan="7" class="text-center text-muted py-6">No announcements found<?= $search !== '' ? ' matching your search' : ' yet' ?>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Compose Modal -->
<div class="modal-overlay" id="composeModal">
  <div class="modal modal-lg">
    <div class="modal-title">Post New Announcement</div>
    <p class="modal-sub">Create an official announcement for students, coordinators, or company partners</p>
    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="action" value="post">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Schedule of OJT Clearance and Endorsement" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Target Audience <span class="text-danger">*</span></label>
          <select name="target_role" class="form-control" required>
            <option value="all">All Roles (Students, Coordinators, Companies)</option>
            <option value="student">Students Only</option>
            <option value="coordinator">Coordinators Only</option>
            <option value="company">Company Partners Only</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tag / Category</label>
          <select name="tag" class="form-control">
            <option value="General">General</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Policy">Policy</option>
            <option value="Welcome">Welcome</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Body Content <span class="text-danger">*</span></label>
        <textarea name="body" class="form-control" rows="6" placeholder="Enter announcement content..." required></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Attachment (File or Image)</label>
        <div class="upload-area">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <div class="upload-title">Click to browse or drop file / image here</div>
          <div class="upload-sub">PDF, DOC, JPG, PNG, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="flex-items-center gap-2 text-sm cursor-pointer">
          <input type="checkbox" name="is_pinned" value="1">
          <span class="font-bold">Pin to top</span>
        </label>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish Announcement</button>
      </div>
    </form>
  </div>
</div>

<!-- View / Edit Announcement Modal -->
<div class="modal-overlay" id="viewEditAnnModal">
  <div class="modal modal-lg">
    <div class="modal-title">Announcement Details</div>
    <p class="modal-sub" id="viewEditAnnMeta"></p>
    <form method="POST" enctype="multipart/form-data"><?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="ann_id" id="editAnnId">
      <div class="form-group">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" id="editAnnTitle" class="form-control" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Target Audience <span class="text-danger">*</span></label>
          <select name="target_role" id="editAnnTarget" class="form-control" required>
            <option value="all">All Roles (Students, Coordinators, Companies)</option>
            <option value="student">Students Only</option>
            <option value="coordinator">Coordinators Only</option>
            <option value="company">Company Partners Only</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tag / Category</label>
          <select name="tag" id="editAnnTag" class="form-control">
            <option value="General">General</option>
            <option value="Important">Important</option>
            <option value="Deadline">Deadline</option>
            <option value="Policy">Policy</option>
            <option value="Welcome">Welcome</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Body Content <span class="text-danger">*</span></label>
        <textarea name="body" id="editAnnBody" class="form-control" rows="7" required></textarea>
      </div>

      <div class="form-group" id="editAnnCurrentFileWrap" style="display:none">
        <label class="form-label">Current Attachment</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <a href="#" id="editAnnCurrentFileLink" target="_blank" class="btn btn-secondary btn-xs"></a>
          <label class="flex-items-center gap-2 text-sm cursor-pointer">
            <input type="checkbox" name="remove_attachment" value="1" id="editAnnRemoveFile">
            <span>Remove attachment</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Replace / Add Attachment</label>
        <div class="upload-area">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <div class="upload-title">Click to browse or drop file / image here</div>
          <div class="upload-sub">PDF, DOC, JPG, PNG, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="flex-items-center gap-2 text-sm cursor-pointer">
          <input type="checkbox" name="is_pinned" value="1" id="editAnnPinned">
          <span class="font-bold">Pin to top</span>
        </label>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('viewEditAnnModal')">Cancel</button>
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
  document.getElementById('editAnnTarget').value = a.target_role || 'all';
  document.getElementById('editAnnTag').value = a.tag || 'General';
  document.getElementById('editAnnPinned').checked = !!Number(a.is_pinned);
  document.getElementById('editAnnRemoveFile').checked = false;

  const metaParts = [];
  if (a.author_name) metaParts.push('By ' + a.author_name);
  if (a.created_at) metaParts.push(a.created_at);
  document.getElementById('viewEditAnnMeta').textContent = metaParts.join(' · ');

  const fileWrap = document.getElementById('editAnnCurrentFileWrap');
  const fileLink = document.getElementById('editAnnCurrentFileLink');
  if (a.attachment_file) {
    fileWrap.style.display = 'block';
    fileLink.href = '/ojtrack/uploads/' + a.attachment_file;
    fileLink.textContent = a.attachment_name || 'View file';
  } else {
    fileWrap.style.display = 'none';
    fileLink.href = '#';
    fileLink.textContent = '';
  }

  openModal('viewEditAnnModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
