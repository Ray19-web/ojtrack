  </main><!-- .page-body -->
</div><!-- .main-shell -->
</div><!-- .main-content -->
</div><!-- .layout -->

<?php
  $profile_user = query_one("SELECT id, name, email, avatar, status FROM users WHERE id=?", [$uid], 'i') ?: [
    'id' => $uid,
    'name' => $name,
    'email' => '',
    'avatar' => $user_avatar ?? '',
    'status' => 'active',
  ];
  $ap_avatar = $profile_user['avatar'] ?? '';
  $flash_success = $_SESSION['flash_success'] ?? '';
  $flash_error   = $_SESSION['flash_error'] ?? '';
  unset($_SESSION['flash_success'], $_SESSION['flash_error']);
  $open_profile  = isset($_GET['profile']);
  $current_path  = $_SERVER['REQUEST_URI'] ?? ('/ojtrack/' . ($role ?? 'admin') . '/dashboard.php');
  // Strip profile query so redirect stays clean after next save
  $redirect_back = preg_replace('/([?&])profile=1(&)?/', '$1', $current_path);
  $redirect_back = rtrim($redirect_back, '?&');
  $profile_titles = [
    'admin'       => 'Administrator Profile',
    'student'     => 'My Profile',
    'coordinator' => 'Coordinator Profile',
    'company'     => 'Company Profile',
  ];
  $profile_title = $profile_titles[$role ?? 'admin'] ?? 'Profile';
  $profile_sub = [
    'admin'       => 'Update your account details, profile picture, and password',
    'student'     => 'Update your personal information, profile picture, and password',
    'coordinator' => 'Update your account details, profile picture, and password',
    'company'     => 'Update your company details, profile picture, and password',
  ];
  $profile_modal_sub = $profile_sub[$role ?? 'admin'] ?? 'Update your account details';

  $student_profile = [];
  $coord_profile   = [];
  $company_profile = [];
  if ($role === 'student') {
      $student_profile = query_one("SELECT contact_number FROM students WHERE user_id=?", [$uid], 'i') ?: [];
  } elseif ($role === 'coordinator') {
      $coord_profile = query_one("SELECT department FROM coordinators WHERE user_id=?", [$uid], 'i') ?: [];
  } elseif ($role === 'company') {
      $company_profile = query_one("SELECT company_name, supervisor_name, location, contact_number FROM companies WHERE user_id=?", [$uid], 'i') ?: [];
  }
?>

<?php if ($flash_success): ?>
<script>document.addEventListener('DOMContentLoaded', function(){ /* flash shown in modal */ });</script>
<?php endif; ?>

<!-- Profile Modal -->
<div class="modal-overlay" id="profileModal">
  <div class="modal modal-lg">
    <div class="modal-title"><?= e($profile_title) ?></div>
    <p class="modal-sub"><?= e($profile_modal_sub) ?></p>

    <?php if ($flash_success): ?>
      <div class="alert alert-success mb-3"><div class="alert-body"><p><?= e($flash_success) ?></p></div></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
      <div class="alert alert-error mb-3"><div class="alert-body"><p><?= e($flash_error) ?></p></div></div>
    <?php endif; ?>

    <div style="display:flex;gap:20px;align-items:flex-start;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--border-light)">
      <div style="text-align:center">
        <?php if (!empty($ap_avatar)): ?>
          <img src="/ojtrack/uploads/<?= e($ap_avatar) ?>" alt="Profile" class="sidebar-avatar-img" style="width:72px;height:72px;margin:0 auto 8px;display:block">
        <?php else: ?>
          <div class="avatar avatar-xl mb-2 mx-auto" style="width:72px;height:72px;font-size:22px"><?= e($initials) ?></div>
        <?php endif; ?>
        <div class="text-xs text-muted"><?= status_badge($profile_user['status'] ?? 'active') ?></div>
      </div>
      <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php" enctype="multipart/form-data" style="flex:1"><?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_avatar">
        <input type="hidden" name="redirect" value="<?= e($redirect_back) ?>">
        <label class="form-label">Profile Picture</label>
        <input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" required>
        <div class="text-xs text-muted mt-1 mb-2">JPG, PNG, GIF, or WEBP · Max 5MB</div>
        <button type="submit" class="btn btn-secondary btn-sm">Upload Photo</button>
      </form>
    </div>

    <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php" class="mb-4"><?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">
      <input type="hidden" name="redirect" value="<?= e($redirect_back) ?>">
      <div class="form-row">
        <?php if ($role === 'company'): ?>
        <div class="form-group">
          <label class="form-label">Company Name <span class="text-danger">*</span></label>
          <input type="text" name="company_name" class="form-control" value="<?= e($company_profile['company_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Supervisor / Contact Person</label>
          <input type="text" name="supervisor_name" class="form-control" value="<?= e($company_profile['supervisor_name'] ?? '') ?>">
        </div>
        <?php else: ?>
        <div class="form-group">
          <label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($profile_user['name'] ?? '') ?>" required>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label class="form-label">Email Address <span class="text-danger">*</span></label>
          <input type="email" name="email" class="form-control" value="<?= e($profile_user['email'] ?? '') ?>" required>
        </div>
      </div>
      <?php if ($role === 'student'): ?>
      <div class="form-group">
        <label class="form-label">Contact Number</label>
        <input type="text" name="contact_number" class="form-control" placeholder="09XX-XXX-XXXX" value="<?= e($student_profile['contact_number'] ?? '') ?>">
      </div>
      <?php elseif ($role === 'coordinator'): ?>
      <div class="form-group">
        <label class="form-label">Department / College</label>
        <input type="text" name="department" class="form-control" value="<?= e($coord_profile['department'] ?? '') ?>" placeholder="e.g. College of Engineering and Technology">
      </div>
      <?php elseif ($role === 'company'): ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="contact_number" class="form-control" value="<?= e($company_profile['contact_number'] ?? '') ?>" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="form-group">
          <label class="form-label">Office / Plant Location</label>
          <input type="text" name="location" class="form-control" value="<?= e($company_profile['location'] ?? '') ?>">
        </div>
      </div>
      <?php endif; ?>
      <div class="form-actions-right">
        <button type="submit" class="btn btn-primary btn-sm">Save Profile</button>
      </div>
    </form>

    <div style="border-top:1px solid var(--border-light);padding-top:14px">
      <div class="section-title mb-2" style="font-size:14px">Change Password</div>
      <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php"><?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="redirect" value="<?= e($redirect_back) ?>">
        <div class="form-group">
          <label class="form-label">Current Password <span class="text-danger">*</span></label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">New Password <span class="text-danger">*</span></label>
            <input type="password" name="new_password" class="form-control" minlength="6" required>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
            <input type="password" name="confirm_password" class="form-control" minlength="6" required>
          </div>
        </div>
        <div class="modal-footer" style="padding-left:0;padding-right:0">
          <button type="button" class="btn btn-secondary" onclick="closeModal('profileModal')">Close</button>
          <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($open_profile || $flash_success || $flash_error): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof openModal === 'function') openModal('profileModal');
});
</script>
<?php endif; ?>

<script src="/ojtrack/assets/js/main.js?v=20261003-phase1"></script>
</body>
</html>