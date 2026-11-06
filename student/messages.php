<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);
require_once __DIR__ . '/../config/student-conversations.php';

$user    = current_user();
$uid     = (int)$user['id'];
$student = normalized_student_context_by_user($uid);
if ($student && !empty($student['_enrollment_id'])) {
    $student_coordinator = normalized_current_coordinator_for_enrollment((int)$student['_enrollment_id']);
    $student_placement = normalized_current_placement_for_enrollment((int)$student['_enrollment_id']);
    $student_company = $student_placement
        ? query_one("SELECT c.user_id AS company_user_id,c.supervisor_name,c.company_name FROM companies c WHERE c.id=?",[(int)$student_placement['company_id']],'i')
        : null;
    $student['company_user_id'] = $student_company['company_user_id'] ?? null;
    $student['supervisor_name'] = $student_company['supervisor_name'] ?? null;
    $student['company_name'] = $student_company['company_name'] ?? null;
    $student['coord_user_id'] = $student_coordinator['user_id'] ?? null;
    $student['coordinator_name'] = $student_coordinator['name'] ?? null;
} elseif ($student) {
    $student['company_user_id'] = $student['coord_user_id'] = null;
    $student['supervisor_name'] = $student['company_name'] = $student['coordinator_name'] = null;
}

$success = ''; $error = '';

// Both contacts are checked again on the server, including for group discussions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_thread') {
    try {
        $memberIds = $_POST['member_ids'] ?? (isset($_POST['recipient_id']) ? [$_POST['recipient_id']] : []);
        $thread_id = student_start_conversation($user, $student ?? [], $memberIds, $_POST['message'] ?? '');
        header('Location: /ojtrack/student/messages.php?thread='.$thread_id);
        exit;
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('OJTrack student conversation failed: '.$exception->getMessage());
        $error = 'Your conversation could not be started. Please try again.';
    }
}

// Get threads the student is part of
require __DIR__ . '/../includes/messages-load.php';

// Available contacts for student to message
$contacts = [];
if (!empty($student['coord_user_id'])) {
    $contacts[] = [
        'id'   => $student['coord_user_id'],
        'name' => $student['coordinator_name'] ?: 'OJT Coordinator',
        'role' => 'Coordinator'
    ];
}
if (!empty($student['company_user_id'])) {
    $contacts[] = [
        'id'   => $student['company_user_id'],
        'name' => ($student['supervisor_name'] ?: 'Company Supervisor') . ' (' . ($student['company_name'] ?: 'Company') . ')',
        'role' => 'Company Supervisor'
    ];
}

$page_title = 'Messages';
$page_class = 'messages-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-heading flex-between">
  <div>
    <div class="page-title">Messages</div>
    <div class="page-sub">Your coordinator and supervisor</div>
  </div>
  <?php if (!empty($contacts)): ?>
    <button class="btn btn-primary" onclick="openModal('newThreadModal')">+ New Conversation</button>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><div class="alert-body"><p><?= e($error) ?></p></div></div><?php endif; ?>

<?php require __DIR__ . '/../includes/messages-panel.php'; ?>

<!-- New Message Modal -->
<div class="modal-overlay" id="newThreadModal">
  <div class="modal modal-lg message-create-modal">
    <div class="modal-title">New Conversation</div>
    <p class="modal-sub">Start a discussion with your coordinator, company supervisor, or both</p>
    <button type="button" class="message-create-close" onclick="closeModal('newThreadModal')" aria-label="Close new conversation">&times;</button>
    <form method="POST" class="message-create-form" data-require-participants><?= csrf_field() ?>
      <input type="hidden" name="action" value="start_thread">
      <div class="message-create-body">
      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <div class="form-group">
        <div class="send-requirements-group-heading">
          <label class="form-label" id="studentParticipantLabel">Select Participants <span class="text-danger">*</span></label>
          <span class="send-requirements-hint">Choose one or both assigned contacts.</span>
        </div>
        <details class="send-multiselect message-participant-select recipient-picker" data-recipient-picker>
          <summary class="send-multiselect-trigger" aria-labelledby="studentParticipantLabel studentParticipantSummary">
            <span class="send-multiselect-summary" id="studentParticipantSummary" data-message-participant-summary>Choose participants</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <div class="send-multiselect-panel">
            <div class="send-multiselect-tools">
              <input type="search" id="messageParticipantSearch" class="form-control send-multiselect-search recipient-search-input" data-recipient-filter="messageParticipantList" placeholder="Search by name, role, or company..." aria-label="Search message participants" autocomplete="off">
              <label class="send-multiselect-select-all"><input type="checkbox" data-message-select-visible> Select all matching</label>
            </div>
            <div class="recipient-picker-summary message-participant-count" aria-live="polite"><span data-recipient-selected>0 selected</span><span data-recipient-matches></span></div>
            <div class="send-selection-list send-students-list message-participant-list" id="messageParticipantList">
              <?php foreach ($contacts as $c): ?>
              <section class="recipient-group">
                <div class="message-recipient-group-title" data-recipient-group-title><?= e($c['role']) ?></div>
                <label class="send-student-option message-recipient-option recipient-option" data-recipient-option>
                  <input type="checkbox" name="member_ids[]" value="<?= (int)$c['id'] ?>" <?= is_array($_POST['member_ids'] ?? null) && in_array((string)$c['id'], $_POST['member_ids'], true) ? 'checked' : '' ?>>
                  <span class="message-recipient-copy"><span><?= e($c['name']) ?></span><small><?= e($c['role']) ?></small></span>
                </label>
              </section>
              <?php endforeach; ?>
              <div class="recipient-no-results send-selection-empty" data-recipient-no-results hidden>No matching participants.</div>
            </div>
          </div>
        </details>
      </div>
      <div class="form-group">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="message" class="form-control" rows="4" placeholder="Your message" required><?= e(is_string($_POST['message'] ?? null) ? $_POST['message'] : '') ?></textarea>
      </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newThreadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" <?= !$contacts ? 'disabled' : '' ?>>Start Conversation</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/messages-script.php'; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => openModal('newThreadModal'));</script><?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
