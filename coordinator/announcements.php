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

$save_announcement_upload = function() {
    if (empty($_FILES['attachment']['name']) || ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null,null];
    }
    if (($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('Failed to upload the attachment. Please try again.');
    }
    $orig=$_FILES['attachment']['name'];
    $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
    $allowed=['pdf','doc','docx','jpg','jpeg','png','gif','webp','xls','xlsx','ppt','pptx'];
    if (!in_array($ext,$allowed,true)) throw new DomainException('Invalid attachment type. Allowed: PDF, DOC, images, and common office files.');
    if ((int)($_FILES['attachment']['size'] ?? 0)>10*1024*1024) throw new DomainException('Attachment is too large. Maximum size is 10MB.');
    $dir=__DIR__.'/../uploads/announcements/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $filename='ann_'.bin2hex(random_bytes(16)).'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'],$dir.$filename)) {
        throw new DomainException('Failed to upload the attachment. Please try again.');
    }
    return ['announcements/'.$filename,$orig];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action=$_POST['action'] ?? '';
    try {
        if ($action==='post') {
            $title=trim($_POST['title'] ?? '');
            $body=trim($_POST['body'] ?? '');
            $tag=trim($_POST['tag'] ?? 'Department');
            $pinned=isset($_POST['is_pinned']);
            $expires=!empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            if (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                $postId=normalized_announcement_create(
                    $uid,$title,$body,$tag,'student',$pinned,$expires,$attachmentFile,$attachmentName
                );
                foreach (normalized_announcement_recipient_rows($uid,'student') as $recipient) {
                    create_notification((int)$recipient['user_id'],"Announcement: $title",'info','/ojtrack/student/announcements.php');
                }
                log_activity($uid,'Department Notice Posted',"Post ID: $postId · $title");
                $success='Announcement published. Students under your supervision were notified.';
            }
        } elseif ($action==='edit') {
            $id=(int)($_POST['ann_id'] ?? 0);
            $title=trim($_POST['title'] ?? '');
            $body=trim($_POST['body'] ?? '');
            $tag=trim($_POST['tag'] ?? 'Department');
            $pinned=isset($_POST['is_pinned']);
            $expires=!empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
            if (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                $updated=normalized_announcement_update(
                    $id,$uid,$title,$body,$tag,'student',$pinned,$expires,
                    $attachmentFile,$attachmentName,!empty($_POST['remove_attachment'])
                );
                if (!$updated) $error='You can only edit notices you created.';
                else {
                    log_activity($uid,'Department Notice Updated',$title);
                    $success='Notice updated successfully.';
                }
            }
        } elseif ($action==='delete') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_soft_delete($id,$uid)) {
                log_activity($uid,'Department Notice Archived',"ID: $id");
                $success='Notice archived.';
            }
        } elseif ($action==='toggle_pin') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_toggle_pin($id,$uid)) $success='Pin status updated.';
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

$announcements = normalized_announcements_for_user($uid, true);
$dept_student_count = count(normalized_students_for_coordinator((int)$coord['id']));

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Announcements</div>
    <div class="page-sub">Notify only students under your department (<?= e($coord['department'] ?? 'your program') ?>) · <?= $dept_student_count ?> recipients</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('composeModal')">+ Post Notice</button>
</div>

<?php if ($success): ?><div class="alert alert-success mb-4"><div class="alert-body"><p><?= e($success) ?></p></div></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<div class="card announcement-management-card">
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
              'editable' => (int)$a['created_by'] === $uid,
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
          <tr class="row-clickable" onclick='openViewEditAnn(<?= htmlspecialchars(json_encode($ann_payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)' title="View announcement">
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
              <?php if ((int)$a['created_by'] === $uid): ?><div class="table-actions">
                <form method="POST"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_pin">
                  <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="table-action-icon <?= $a['is_pinned'] ? 'is-primary' : '' ?>"
                          title="<?= $a['is_pinned'] ? 'Unpin notice' : 'Pin notice to top' ?>"
                          aria-label="<?= $a['is_pinned'] ? 'Unpin notice' : 'Pin notice to top' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6-3 1-4 4-1 5-4-4 5-1 4-4 1-3Z"/><path d="m4 20 5-5"/></svg>
                  </button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this notice?')"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="table-action-icon is-danger" title="Delete notice" aria-label="Delete notice">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6"/></svg>
                  </button>
                </form>
              </div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?>
          <tr><td colspan="5" class="text-center text-muted py-6">No announcements yet. Post one to notify your students.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Compose Notice Modal -->
<div class="modal-overlay" id="composeModal">
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Post Department Notice</div>
        <p class="modal-sub">This notifies only students assigned to you — not campus-wide</p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('composeModal')" aria-label="Close notice form">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
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
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1">
          <span>
            <strong>Pin this notice to top</strong>
            <small>Keep it visible above regular department notices.</small>
          </span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" class="form-control" rows="6" placeholder="Write the notice for your department students..." required></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Attachment (image or file, optional)</label>
<div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Browse or drop a file here</div>
          <div class="upload-sub">PDF, DOC, images, and office files · Max 10MB</div>
        </div>
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
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">View &amp; Edit Notice</div>
        <p class="modal-sub" id="viewEditAnnMeta"></p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('viewEditAnnModal')" aria-label="Close notice details">×</button>
    </div>
    <form method="POST" id="viewEditAnnForm" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
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
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1" id="editAnnPinned">
          <span>
            <strong>Pin this notice to top</strong>
            <small>Keep it visible above regular department notices.</small>
          </span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="body" id="editAnnBody" class="form-control" rows="7" required></textarea>
      </div>

      <div class="form-group announcement-current-file" id="editAnnCurrentFileWrap" style="display:none">
        <label class="form-label">Current Attachment</label>
        <a id="editAnnCurrentFile" href="#" target="_blank" class="btn btn-secondary btn-sm">View file</a>
        <label class="announcement-remove-control mt-2">
          <input type="checkbox" name="remove_attachment" value="1">
          <span>Remove attachment</span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Replace / Add Attachment (optional)</label>
<div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Choose a replacement file</div>
          <div class="upload-sub">PDF, DOC, images, and office files · Max 10MB</div>
        </div>
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
  document.querySelectorAll('#viewEditAnnModal input:not([type=hidden]), #viewEditAnnModal textarea, #viewEditAnnModal select, #viewEditAnnModal button[type=submit]').forEach(el => el.disabled = !a.editable);
  openModal('viewEditAnnModal');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
