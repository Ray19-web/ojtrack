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
<div class="modal-overlay" id="profileModal" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
  <div class="modal modal-lg profile-modal">
    <div class="profile-modal-header">
      <div>
        <div class="modal-title" id="profileModalTitle"><?= e($profile_title) ?></div>
        <p class="modal-sub profile-modal-sub"><?= e($profile_modal_sub) ?></p>
      </div>
      <button type="button" class="profile-modal-close" onclick="closeModal('profileModal')" aria-label="Close profile settings">×</button>
    </div>

    <?php if ($flash_success): ?>
      <div class="alert alert-success profile-modal-alert"><div class="alert-body"><p><?= e($flash_success) ?></p></div></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
      <div class="alert alert-error profile-modal-alert"><div class="alert-body"><p><?= e($flash_error) ?></p></div></div>
    <?php endif; ?>

    <section class="profile-photo-card">
      <div class="profile-avatar-column">
        <div class="profile-avatar-preview" id="profileAvatarPreview">
          <?php if (!empty($ap_avatar)): ?>
            <img src="/ojtrack/uploads/<?= e($ap_avatar) ?>" alt="<?= e($profile_user['name'] ?? 'Profile') ?> profile photo" class="profile-avatar-preview-img">
          <?php else: ?>
            <div class="profile-avatar-preview-fallback"><?= e($initials) ?></div>
          <?php endif; ?>
        </div>
        <div class="profile-avatar-status"><?= status_badge($profile_user['status'] ?? 'active') ?></div>
      </div>

      <div class="profile-photo-content">
        <div class="profile-photo-title">Profile picture</div>
        <p class="profile-photo-help">Choose a clear square photo. You’ll see a preview here before anything is saved.</p>

        <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php" enctype="multipart/form-data" class="profile-avatar-form" id="profileAvatarForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_avatar">
          <input type="hidden" name="redirect" value="<?= e($redirect_back) ?>">
          <input type="file" name="avatar" id="profileAvatarInput" class="profile-avatar-input" accept="image/jpeg,image/png,image/gif,image/webp" required>

          <div class="profile-avatar-actions">
            <label for="profileAvatarInput" class="btn btn-secondary btn-sm profile-photo-picker">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 5v14M5 12h14"/>
              </svg>
              Choose Photo
            </label>
            <button type="submit" class="btn btn-primary btn-sm" id="profileAvatarSave" disabled>Save Photo</button>
          </div>

          <div class="profile-avatar-file" id="profileAvatarFileName">JPG, PNG, GIF, or WEBP · Max 5MB</div>
        </form>
      </div>
    </section>

    <section class="profile-settings-section">
      <div class="profile-section-heading">
        <div>
          <div class="profile-section-title">Account details</div>
          <div class="profile-section-sub">Keep your profile information accurate and up to date.</div>
        </div>
      </div>

      <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php"><?= csrf_field() ?>
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
        <div class="profile-section-actions">
          <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
        </div>
      </form>
    </section>

    <section class="profile-settings-section profile-password-section">
      <div class="profile-section-heading">
        <div>
          <div class="profile-section-title">Password & security</div>
          <div class="profile-section-sub">Use your current password to set a new one.</div>
        </div>
      </div>
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
        <div class="profile-section-actions">
          <button type="button" class="btn btn-secondary" onclick="closeModal('profileModal')">Close</button>
          <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
      </form>
    </section>
  </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" aria-describedby="logoutModalDescription">
  <div class="modal logout-modal">
    <div class="logout-modal-head">
      <div class="logout-modal-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 17l5-5-5-5"/>
          <path d="M15 12H3"/>
          <path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>
        </svg>
      </div>
      <div class="logout-modal-copy">
        <div class="modal-title" id="logoutModalTitle">Sign out of OJTrack?</div>
        <p class="modal-sub logout-modal-sub" id="logoutModalDescription">You’ll need to sign in again to continue.</p>
      </div>
    </div>

    <form method="post" action="/ojtrack/logout.php" class="logout-modal-actions">
      <?= csrf_field() ?>
      <button type="button" class="btn btn-secondary logout-cancel-btn" onclick="closeModal('logoutModal')">Cancel</button>
      <button type="submit" class="btn btn-danger logout-confirm-btn">Sign Out</button>
    </form>
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