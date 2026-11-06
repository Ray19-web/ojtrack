  </main><!-- .page-body -->
</div><!-- .main-shell -->
</div><!-- .main-content -->
</div><!-- .layout -->

<?php
  require_once __DIR__.'/../../app/Services/ProfileService.php';
  $profile_context = ojtrack_profile_context(['id'=>(int)$uid,'role'=>$role]);
  $profile_user = $profile_context['account'];
  $profile_user['name_extensions'] = user_name_extensions((int)$uid);
  $profile_user['name_base'] = base_person_name($profile_user['name'] ?? '', $profile_user['name_extensions']);
  $ap_avatar = $profile_user['avatar'] ?? '';
  $flash_success = $_SESSION['flash_success'] ?? '';
  $flash_error   = $_SESSION['flash_error'] ?? '';
  unset($_SESSION['flash_success'], $_SESSION['flash_error']);
  $open_profile  = isset($_GET['profile']);
  $open_password = isset($_GET['password']);
  $current_path  = $_SERVER['REQUEST_URI'] ?? ('/ojtrack/' . ($role ?? 'admin') . '/dashboard.php');
  // Strip profile query so redirect stays clean after next save
  $redirect_back = preg_replace('/([?&])(profile|password)=1(&)?/', '$1', $current_path);
  $redirect_back = rtrim($redirect_back, '?&');
  $profile_titles = [
    'admin'       => 'Administrator Profile',
    'student'     => 'My Profile',
    'coordinator' => 'Coordinator Profile',
    'company'     => 'Company Profile',
  ];
  $profile_title = $profile_titles[$role ?? 'admin'] ?? 'Profile';
  $profile_sub = [
    'admin'       => 'Details, photo, and password',
    'student'     => 'Details, photo, and password',
    'coordinator' => 'Details, photo, and password',
    'company'     => 'Company details, photo, and password',
  ];
  $profile_modal_sub = $profile_sub[$role ?? 'admin'] ?? 'Update your account details';

  $student_profile = $profile_context['student'];
  $coord_profile = $profile_context['coordinator'];
  $company_profile = $profile_context['company'];
  if ($role === 'student') {
      // Current-enrollment data is supplied by the same normalized student context as the pages.
  } elseif ($role === 'coordinator') {
      // Coordinator assignment fields are displayed read-only below.
  } elseif ($role === 'company') {
      $company_profile['supervisor_name_extensions'] = !empty($company_profile['id']) ? company_supervisor_name_extensions((int)$company_profile['id']) : [];
      $company_profile['supervisor_name_base'] = base_person_name($company_profile['supervisor_name'] ?? '', $company_profile['supervisor_name_extensions']);
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
          <?php if (user_avatar_url($ap_avatar) !== ''): ?>
            <img src="<?= e(user_avatar_url($ap_avatar)) ?>" alt="<?= e($profile_user['name'] ?? 'Profile') ?> profile photo" class="profile-avatar-preview-img">
          <?php else: ?>
            <div class="profile-avatar-preview-fallback"><?= e($initials) ?></div>
          <?php endif; ?>
        </div>
        <div class="profile-avatar-status"><?= status_badge($profile_user['status'] ?? 'active') ?></div>
      </div>

      <div class="profile-photo-content">
        <div class="profile-photo-title">Profile picture</div>
        <p class="profile-photo-help">Choose a square photo, then save.</p>

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

    <?php foreach ($profile_context['groups'] as $profile_group_title=>$profile_fields): ?>
    <section class="profile-settings-section profile-record-section" aria-label="<?= e($profile_group_title) ?>">
      <div class="profile-record-heading"><h3><?= e($profile_group_title) ?></h3><span class="profile-readonly-label">Read-only</span></div>
      <dl class="profile-record-grid">
        <?php foreach ($profile_fields as $profile_label=>$profile_value): ?>
          <div><dt><?= e($profile_label) ?></dt><dd><?= e($profile_value) ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </section>
    <?php endforeach; ?>
    <p class="profile-managed-note">Account IDs, roles, status, and academic or training assignments are managed by the administration. Official training hours come from attendance records.</p>

    <section class="profile-settings-section profile-account-section">
      <div class="profile-section-heading">
        <div>
          <div class="profile-section-title">Editable profile details</div>
          <p class="profile-section-sub">Update your contact details and the personal information available below.</p>

        </div>
      </div>

      <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php"><?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="redirect" value="<?= e($redirect_back) ?>">
        <div class="form-row">
        <?php if ($role === 'company'): ?>
        <div class="form-group">
          <label class="form-label">Company Name <span class="text-danger">*</span></label>
          <input type="text" name="company_name" class="form-control" maxlength="100" value="<?= e($company_profile['company_name'] ?? '') ?>" data-uppercase-name required>
        </div>
        <?php else: ?>
        <div class="form-group">
          <label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" maxlength="100" value="<?= e($profile_user['name_base'] ?? '') ?>" data-uppercase-name required>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label class="form-label">Email Address <?= $role==='student' ? '<span class="text-muted">(optional)</span>' : '<span class="text-danger">*</span>' ?></label>
          <input type="email" name="email" class="form-control" maxlength="150" value="<?= e($profile_user['email'] ?? '') ?>" <?= $role==='student' ? '' : 'required' ?>>
          <?php if ($role==='student'): ?><p class="profile-section-sub">Contact email only. Use your student ID to sign in.</p><?php endif; ?>
        </div>
      </div>
      <?php if ($role === 'company'): ?>
      <div class="user-form-section-heading">Supervisor / contact person</div>
      <?php $person_name_prefix = 'profileSupervisor'; $person_name_fields = ['first' => 'supervisor_first_name', 'middle' => 'supervisor_middle_name', 'last' => 'supervisor_last_name']; $person_name_values = ['first' => $company_profile['supervisor_first_name'] ?? '', 'middle' => $company_profile['supervisor_middle_name'] ?? '', 'last' => $company_profile['supervisor_last_name'] ?? '']; $person_name_required = false; require __DIR__ . '/../components/person-name-fields.php'; ?>
      <div class="text-xs text-muted mb-3">For older records without saved name parts, leave all three fields blank to keep the current supervisor name.</div>
      <?php $name_extension_prefix = 'profileSupervisor'; $name_extension_type_field = 'supervisor_name_extension_type'; $name_extension_value_field = 'supervisor_name_extension_value'; $name_extension_entries = $company_profile['supervisor_name_extensions'] ?? []; require __DIR__ . '/../components/name-extension-fields.php'; ?>
      <?php else: $name_extension_prefix = 'profileUser'; $name_extension_type_field = 'name_extension_type'; $name_extension_value_field = 'name_extension_value'; $name_extension_entries = $profile_user['name_extensions'] ?? []; require __DIR__ . '/../components/name-extension-fields.php'; endif; ?>
      <?php if ($role === 'student'): ?>
      <div class="form-group">
        <label class="form-label">Contact Number</label>
        <input type="tel" name="contact_number" class="form-control" maxlength="20" placeholder="09XX-XXX-XXXX" value="<?= e($student_profile['contact_number'] ?? '') ?>">
      </div>
      <?php elseif ($role === 'coordinator'): ?>
      <div class="form-group">
        <label class="form-label">Contact Number</label>
        <input type="tel" name="contact_number" class="form-control" maxlength="20" value="<?= e($coord_profile['contact_number'] ?? '') ?>" placeholder="09XX-XXX-XXXX">
      </div>
      <?php elseif ($role === 'company'): ?>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="tel" name="contact_number" class="form-control" maxlength="20" value="<?= e($company_profile['contact_number'] ?? '') ?>" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="form-group">
          <label class="form-label">Office / Plant Location</label>
          <input type="text" name="location" class="form-control" maxlength="200" value="<?= e($company_profile['location'] ?? '') ?>">
        </div>
      </div>
      <?php endif; ?>
        <div class="profile-section-actions profile-section-actions-split">
          <button type="button" class="btn btn-secondary" onclick="openModal('passwordModal')">Update Password</button>
          <div class="profile-section-action-group">
            <button type="button" class="btn btn-secondary" onclick="closeModal('profileModal')">Close</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </div>
      </form>
    </section>
  </div>
</div>

<!-- Password Update Modal -->
<div class="modal-overlay" id="passwordModal" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle">
  <div class="modal profile-modal password-update-modal">
    <div class="profile-modal-header">
      <div>
        <div class="modal-title" id="passwordModalTitle">Password &amp; security</div>
        <p class="modal-sub profile-modal-sub">Current password required.</p>
      </div>
      <button type="button" class="profile-modal-close" onclick="closeModal('passwordModal')" aria-label="Close password update">×</button>
    </div>
    <?php if ($open_password && $flash_success): ?>
      <div class="alert alert-success profile-modal-alert"><div class="alert-body"><p><?= e($flash_success) ?></p></div></div>
    <?php endif; ?>
    <?php if ($open_password && $flash_error): ?>
      <div class="alert alert-error profile-modal-alert"><div class="alert-body"><p><?= e($flash_error) ?></p></div></div>
    <?php endif; ?>
    <section class="profile-settings-section password-modal-content">
      <form method="POST" action="/ojtrack/<?= e($role) ?>/profile.php"><?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="redirect" value="<?= e($redirect_back) ?>">
        <div class="form-group">
          <label class="form-label">Current Password <span class="text-danger">*</span></label>
          <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">New Password <span class="text-danger">*</span></label>
            <input type="password" name="new_password" class="form-control" minlength="6" autocomplete="new-password" required>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
            <input type="password" name="confirm_password" class="form-control" minlength="6" autocomplete="new-password" required>
          </div>
        </div>
        <div class="profile-section-actions">
          <button type="button" class="btn btn-secondary" onclick="closeModal('passwordModal')">Close</button>
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

    <form method="post" action="/ojtrack/logout.php" class="logout-modal-actions" data-confirm-skip>
      <?= csrf_field() ?>
      <button type="button" class="btn btn-secondary logout-cancel-btn" onclick="closeModal('logoutModal')">Cancel</button>
      <button type="submit" class="btn btn-danger logout-confirm-btn">Sign Out</button>
    </form>
  </div>
</div>

<?php if ($open_password || $open_profile || $flash_success || $flash_error): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof openModal === 'function') openModal(<?= $open_password ? "'passwordModal'" : "'profileModal'" ?>);
});
</script>
<?php endif; ?>

<script src="/ojtrack/assets/js/confirmations.js?v=<?= (int)filemtime(__DIR__ . '/../../assets/js/confirmations.js') ?>"></script>
<script src="/ojtrack/assets/js/attachment-previews.js?v=<?= (int)filemtime(__DIR__ . '/../../assets/js/attachment-previews.js') ?>"></script>
<script src="/ojtrack/assets/js/recipient-search.js?v=20261019-recipient-search-v1"></script>
<script src="/ojtrack/assets/js/feedback-toasts.js?v=<?= (int)filemtime(__DIR__ . '/../../assets/js/feedback-toasts.js') ?>"></script>
<script src="/ojtrack/assets/js/live-activity-toasts.js?v=20261024-live-activity-v2"></script>
<script src="/ojtrack/assets/js/main.js?v=<?= (int)filemtime(__DIR__ . '/../../assets/js/main.js') ?>"></script>
<?php if (($role ?? '') === 'student'): ?><script src="/ojtrack/assets/js/student-guide.js?v=<?= (int)filemtime(__DIR__.'/../../assets/js/student-guide.js') ?>"></script><?php endif; ?>
<script src="/ojtrack/assets/js/onboarding-tour.js?v=<?= (int)filemtime(__DIR__.'/../../assets/js/onboarding-tour.js') ?>"></script>
</body>
</html>
