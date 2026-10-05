<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['admin']);

$user = current_user();
$uid  = (int)$user['id'];
$success = ''; $error = '';

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
            $target=$_POST['target_role'] ?? 'all';
            $tag=trim($_POST['tag'] ?? 'General');
            $pinned=isset($_POST['is_pinned']);
            if (!in_array($target,['all','student','coordinator','company'],true)) $target='all';

            if (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                $postId=normalized_announcement_create(
                    $uid,$title,$body,$tag,$target,$pinned,null,$attachmentFile,$attachmentName
                );
                foreach (normalized_announcement_recipient_rows($uid,$target) as $recipient) {
                    $link=match($recipient['role']) {
                        'coordinator'=>'/ojtrack/coordinator/announcements.php',
                        'company'=>'/ojtrack/company/announcements.php',
                        default=>'/ojtrack/student/announcements.php',
                    };
                    create_notification((int)$recipient['user_id'],"New announcement: $title",'info',$link);
                }
                log_activity($uid,'Announcement Posted',"Post ID: $postId · $title");
                $success='Announcement published successfully.';
            }
        } elseif ($action==='edit') {
            $id=(int)($_POST['ann_id'] ?? 0);
            $title=trim($_POST['title'] ?? '');
            $body=trim($_POST['body'] ?? '');
            $target=$_POST['target_role'] ?? 'all';
            $tag=trim($_POST['tag'] ?? 'General');
            $pinned=isset($_POST['is_pinned']);
            if (!in_array($target,['all','student','coordinator','company'],true)) $target='all';

            $existing=query_one("SELECT * FROM announcement_posts WHERE id=?",[$id],'i');
            if (!$existing) {
                $error='Announcement not found.';
            } elseif (!$title || !$body) {
                $error='Title and body are required.';
            } else {
                [$attachmentFile,$attachmentName]=$save_announcement_upload();
                normalized_announcement_update(
                    $id,$uid,$title,$body,$tag,$target,$pinned,$existing['expires_at'],
                    $attachmentFile,$attachmentName,!empty($_POST['remove_attachment'])
                );
                log_activity($uid,'Announcement Updated',$title);
                $success='Announcement updated successfully.';
            }
        } elseif ($action==='toggle_pin') {
            $id=(int)($_POST['ann_id'] ?? 0);
            if (normalized_announcement_toggle_pin($id,$uid)) $success='Pin status updated.';
        }
    } catch (DomainException $exception) {
        $error=$exception->getMessage();
    }
}

$search=trim($_GET['q'] ?? '');
$announcements=normalized_announcements_admin($search);
$total_count=(int)(query_one("SELECT COUNT(*) c FROM announcement_posts")['c'] ?? 0);
$pinned_count=(int)(query_one("SELECT COUNT(*) c FROM announcement_posts WHERE is_pinned=1")['c'] ?? 0);
$all_count=(int)(query_one("SELECT COUNT(*) c FROM announcement_posts WHERE target_role='all'")['c'] ?? 0);
$with_file=(int)(query_one("SELECT COUNT(DISTINCT announcement_post_id) c FROM announcement_attachments")['c'] ?? 0);

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

<div class="card announcement-management-card">
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
                <div class="table-actions">
                  <a href="/ojtrack/uploads/<?= e($a['attachment_file']) ?>" target="_blank"
                     class="table-action-icon is-primary"
                     title="View attachment: <?= e($a['attachment_name'] ?: 'Announcement file') ?>"
                     aria-label="View announcement attachment">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                  </a>
                </div>
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
              <div class="table-actions">
                <form method="POST"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_pin">
                  <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="table-action-icon <?= $a['is_pinned'] ? 'is-primary' : '' ?>"
                          title="<?= $a['is_pinned'] ? 'Unpin announcement' : 'Pin announcement to top' ?>"
                          aria-label="<?= $a['is_pinned'] ? 'Unpin announcement' : 'Pin announcement to top' ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 4 6 6-3 1-4 4-1 5-4-4 5-1 4-4 1-3Z"/><path d="m4 20 5-5"/></svg>
                  </button>
                </form>
              </div>
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
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Post New Announcement</div>
        <p class="modal-sub">Create an official announcement for students, coordinators, or company partners</p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('composeModal')" aria-label="Close announcement form">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
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
        <div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Browse or drop a file here</div>
          <div class="upload-sub">PDF, DOC, JPG, PNG, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1">
          <span>
            <strong>Pin to top</strong>
            <small>Keep this announcement above regular notices.</small>
          </span>
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
  <div class="modal modal-lg announcement-modal">
    <div class="announcement-modal-header">
      <div>
        <div class="modal-title">Announcement Details</div>
        <p class="modal-sub" id="viewEditAnnMeta"></p>
      </div>
      <button type="button" class="announcement-modal-close" onclick="closeModal('viewEditAnnModal')" aria-label="Close announcement details">×</button>
    </div>
    <form method="POST" enctype="multipart/form-data" class="announcement-form"><?= csrf_field() ?>
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

      <div class="form-group announcement-current-file" id="editAnnCurrentFileWrap" style="display:none">
        <label class="form-label">Current Attachment</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <a href="#" id="editAnnCurrentFileLink" target="_blank" class="btn btn-secondary btn-xs"></a>
          <label class="announcement-remove-control">
            <input type="checkbox" name="remove_attachment" value="1" id="editAnnRemoveFile">
            <span>Remove attachment</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Replace / Add Attachment</label>
        <div class="upload-area announcement-upload" role="button" tabindex="0">
          <input type="file" name="attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.xls,.xlsx,.ppt,.pptx,image/*">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
          <div class="upload-title">Browse or drop a file here</div>
          <div class="upload-sub">PDF, DOC, JPG, PNG, and office files · Max 10MB</div>
        </div>
      </div>

      <div class="form-group">
        <label class="announcement-pin-control">
          <input type="checkbox" name="is_pinned" value="1" id="editAnnPinned">
          <span>
            <strong>Pin to top</strong>
            <small>Keep this announcement above regular notices.</small>
          </span>
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
