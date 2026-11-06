<?php
if (!defined('OJTRACK')) die('Direct access not allowed.');
require_once __DIR__ . '/../../app/Support/avatars.php';

$user     = current_user();
$role     = $user['role'];
$name     = $user['name'];
$uid      = (int)$user['id'];
$header_academic_term = null;
try { $header_academic_term = normalized_active_term(); } catch (Throwable $term_error) { $header_academic_term = null; }
$student_term_enrollment_missing = false;
if ($role === 'student' && $header_academic_term) {
    try {
        $student_term_context = normalized_student_context_by_user($uid);
        $student_term_enrollment_missing = (bool)($student_term_context['_active_term_enrollment_missing'] ?? false);
    } catch (Throwable $term_context_error) { $student_term_enrollment_missing = false; }
}

if ($role === 'student' && student_onboarding_required($uid)) {
    // The app's rewrite rules serve the extensionless URL as onboarding.php.
    // Treat both URL forms as the same page so the header guard cannot redirect
    // the onboarding screen back to itself indefinitely.
    $request_path = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
    $onboarding_paths = ['/ojtrack/student/onboarding', '/ojtrack/student/onboarding.php'];
    if (!in_array($request_path, $onboarding_paths, true)) {
        redirect('student/onboarding.php');
    }
}
$initials = user_avatar_initials($name);

// Certificates badge (red number where certificates live)
$cert_count = 0;
if ($uid > 0) {
    if ($role === 'student') {
        $student_context = normalized_student_context_by_user($uid);
        $has_issued_certificate = $student_context ? normalized_certificate_for_student((int)$student_context['id']) : null;
        $eligible_now = $student_context && !empty($student_context['_enrollment_id'])
            ? normalized_certificate_eligibility((int)$student_context['id'])['eligible'] : false;
        $cert_count = ($has_issued_certificate || $eligible_now) ? 1 : 0;
    } elseif ($role === 'company') {
        $cert_count = (int)(query_one(
            "SELECT COUNT(DISTINCT oe.id) AS c FROM ojt_enrollments oe
             JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
             JOIN placements p ON p.ojt_enrollment_id=oe.id AND p.status='completed'
             WHERE p.company_id=(SELECT id FROM companies WHERE user_id=? LIMIT 1) AND oe.status='completed'",
            [$uid], 'i'
        )['c'] ?? 0);
    } elseif ($role === 'coordinator') {
        $cert_count = (int)(query_one(
            "SELECT COUNT(DISTINCT oe.id) AS c FROM ojt_enrollments oe
             JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active'
             JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
             WHERE ec.coordinator_id=(SELECT id FROM coordinators WHERE user_id=? LIMIT 1) AND oe.status='completed'",
            [$uid], 'i'
        )['c'] ?? 0);
    } elseif ($role === 'admin') {
        $cert_count = (int)(query_one("SELECT COUNT(*) AS c FROM ojt_enrollments oe JOIN academic_terms at ON at.id=oe.academic_term_id AND at.status='active' WHERE oe.status='completed'")['c'] ?? 0);
    }
}

// Page title from variable set in each page
$page_title = $page_title ?? 'OJTRACK';
$page_sub   = $page_sub   ?? 'USTP Jasaan OJT Management System';

// Calculate live badges
$unread_notifs = 0;
$unread_msgs   = 0;
$pending_action_count = 0;
$pending_journal_count = 0;
$pending_report_review_count = 0;
$latest_incoming_message_id = 0;

// Create a one-time reminder for active administrators on the day before the
// active academic term ends. It is generated during an admin page request so
// the notification uses the existing in-app bell without a scheduled job.
if ($role === 'admin' && $header_academic_term && !empty($header_academic_term['ends_on'])) {
    try {
        $term_end = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            (string)$header_academic_term['ends_on'],
            new DateTimeZone(date_default_timezone_get())
        );
        $date_errors = DateTimeImmutable::getLastErrors();
        if ($term_end && (!$date_errors || (!$date_errors['warning_count'] && !$date_errors['error_count']))) {
            $tomorrow = (new DateTimeImmutable('today'))->modify('+1 day');
            if ($term_end->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
                $term_label = sprintf(
                    '%d–%d %s',
                    (int)$header_academic_term['academic_year_start'],
                    (int)$header_academic_term['academic_year_end'],
                    $header_academic_term['semester'] === 'summer' ? 'Summer' : $header_academic_term['semester'] . ' Semester'
                );
                $end_label = $term_end->format('M j, Y');
                $term_reminder = "The {$term_label} academic term ends tomorrow ({$end_label}). Create the next term as a draft, or activate it now if it is already prepared.";
                $admins = query("SELECT id FROM users WHERE role='admin' AND status='active'") ?: [];
                foreach ($admins as $admin) {
                    $already_notified = query_one(
                        'SELECT id FROM notifications WHERE user_id=? AND message=? LIMIT 1',
                        [(int)$admin['id'], $term_reminder],
                        'is'
                    );
                    if (!$already_notified) {
                        create_notification(
                            (int)$admin['id'],
                            $term_reminder,
                            'warning',
                            '/ojtrack/admin/academic-terms.php'
                        );
                    }
                }
            }
        }
    } catch (Throwable $term_reminder_error) {
        error_log('OJTRACK academic term end reminder could not be generated.');
    }
}

if ($uid > 0) {
    $unread_notifs = (int)(query_one(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0",
        [$uid],
        'i'
    )['c'] ?? 0);

    $unread_msgs = (int)(query_one(
        "SELECT COUNT(DISTINCT m.id) AS c
         FROM messages m
         JOIN thread_members tm ON tm.thread_id = m.thread_id AND tm.user_id = ?
         WHERE tm.archived_at IS NULL AND m.sender_id != ?
           AND NOT EXISTS (SELECT 1 FROM message_reads mr WHERE mr.message_id = m.id AND mr.user_id = ?)",
        [$uid, $uid, $uid],
        'iii'
    )['c'] ?? 0);

    $latest_incoming_message_id = (int)(query_one(
        "SELECT COALESCE(MAX(m.id), 0) AS c
         FROM messages m
         JOIN thread_members tm ON tm.thread_id=m.thread_id AND tm.user_id=?
         WHERE tm.archived_at IS NULL AND m.sender_id!=?",
        [$uid, $uid],
        'ii'
    )['c'] ?? 0);

    if ($role === 'coordinator') {
        $pending_action_count = normalized_pending_requirement_review_count_for_user($uid);
        $pending_journal_count = normalized_pending_journal_student_count_for_user($uid);
        $pending_report_review_count = normalized_pending_report_review_count_for_user($uid);
    } elseif ($role === 'company') {
        $pending_action_count = normalized_pending_eval_count_for_company_user($uid);
    } elseif ($role === 'student') {
        $pending_action_count = normalized_pending_requirement_count_for_student_user($uid);
    }
}

// Fetch top notifications for dropdown
$recent_notifications = query(
    "SELECT id, message, notif_type, is_read, created_at, link FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 6",
    [$uid],
    'i'
) ?: [];
$notification_cursor = 0;
foreach ($recent_notifications as $recent_notification) {
    $notification_cursor = max($notification_cursor, (int)$recent_notification['id']);
}

// Sidebar nav icons (SVG paths) — design kept separate from labels
$nav_icons = [
  'dashboard'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  'requirements'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
  'attendance'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/>',
  'progress'      => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
  'journal'       => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
  'reports'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>',
  'evaluation'    => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
  'certificate'   => '<circle cx="12" cy="8" r="6"/><path d="M15.5 13l1.5 8-5-3-5 3 1.5-8"/>',
  'messages'      => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  'announcements' => '<path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3z"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
  'profile'       => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  'students'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'monitoring'    => '<circle cx="12" cy="12" r="3"/><path d="M22 12c-2.667 4.667-6 7-10 7s-7.333-2.333-10-7c2.667-4.667 6-7 10-7s7.333 2.333 10 7z"/>',
  'users'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  'archived'      => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
  'programs'      => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
  'coordinators'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/>',
  'companies'     => '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01"/><path d="M9 12v.01"/><path d="M9 15v.01"/><path d="M9 18v.01"/>',
  'activity'      => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
];

// Sidebar nav config per role — single destinations plus expandable page groups
$nav_items = [
  'admin' => [
    ['section' => 'Overview', 'items' => [
      ['page' => 'admin/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'People & Partners', 'items' => [
      ['page' => 'admin/students.php', 'label' => 'Students', 'icon' => 'students', 'badge' => 0],
      ['page' => 'admin/coordinators.php', 'label' => 'Coordinators', 'icon' => 'coordinators', 'badge' => 0],
      ['page' => 'admin/companies.php', 'label' => 'Companies', 'icon' => 'companies', 'badge' => 0],
    ]],
    ['section' => 'Academic Setup', 'items' => [
      ['page' => 'admin/academic-terms.php', 'label' => 'Academic Terms', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'admin/programs.php', 'label' => 'Programs', 'icon' => 'programs', 'badge' => 0],
    ]],
    ['section' => 'Administration', 'items' => [
      ['page' => 'admin/assignments.php', 'label' => 'Student Assignments', 'icon' => 'monitoring', 'badge' => 0],
      ['page' => 'admin/users.php', 'label' => 'User Accounts', 'icon' => 'users', 'badge' => 0],
      ['page' => 'admin/archived-users.php', 'label' => 'Archived Accounts', 'icon' => 'archived', 'badge' => 0],
      ['page' => 'admin/activity.php', 'label' => 'Activity Log', 'icon' => 'activity', 'badge' => 0],
    ]],
    ['section' => 'Communication', 'items' => [
      ['page' => 'admin/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
  'coordinator' => [
    ['section' => 'Overview', 'items' => [
      ['page' => 'coordinator/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'OJT Management', 'items' => [
      ['page' => 'coordinator/students.php', 'label' => 'My Students', 'icon' => 'students', 'badge' => 0],
      ['page' => 'coordinator/attendance.php', 'label' => 'Attendance', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'certificate.php', 'label' => 'Certificates', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'Submissions & Reviews', 'items' => [
      ['page' => 'coordinator/requirements.php', 'label' => 'Requirements', 'icon' => 'requirements', 'badge' => $pending_action_count],
      ['page' => 'coordinator/monitoring.php', 'label' => 'Daily Journals', 'icon' => 'journal', 'badge' => $pending_journal_count, 'show_badge_active' => true],
      ['page' => 'coordinator/reports.php', 'label' => 'Reports', 'icon' => 'reports', 'badge' => $pending_report_review_count],
      ['page' => 'coordinator/evaluation.php', 'label' => 'Evaluations', 'icon' => 'evaluation', 'badge' => 0],
    ]],
    ['section' => 'Communication', 'items' => [
      ['page' => 'coordinator/messages.php', 'label' => 'Messages', 'icon' => 'messages', 'badge' => $unread_msgs],
      ['page' => 'coordinator/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
  'company' => [
    ['section' => 'Overview', 'items' => [
      ['page' => 'company/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'Training', 'items' => [
      ['page' => 'company/students.php', 'label' => 'My Trainees', 'icon' => 'students', 'badge' => 0],
      ['page' => 'company/attendance.php', 'label' => 'Attendance', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'company/evaluation.php', 'label' => 'Evaluations', 'icon' => 'evaluation', 'badge' => $pending_action_count],
      ['page' => 'company/certificate.php', 'label' => 'Certificates', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'Communication', 'items' => [
      ['page' => 'company/messages.php', 'label' => 'Messages', 'icon' => 'messages', 'badge' => $unread_msgs],
      ['page' => 'company/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
  'student' => [
    ['section' => 'Overview', 'items' => [
      ['page' => 'student/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'My OJT', 'items' => [
      ['page' => 'student/requirements.php', 'label' => 'Requirements', 'icon' => 'requirements', 'badge' => $pending_action_count],
      ['page' => 'student/attendance.php', 'label' => 'Attendance', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'student/journal.php', 'label' => 'Daily Journal', 'icon' => 'journal', 'badge' => 0],
      ['page' => 'student/reports.php', 'label' => 'Reports', 'icon' => 'reports', 'badge' => 0],
      ['page' => 'student/evaluation.php', 'label' => 'Evaluations', 'icon' => 'evaluation', 'badge' => 0],
      ['page' => 'student/certificate.php', 'label' => 'Certificate', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'Communication', 'items' => [
      ['page' => 'student/messages.php', 'label' => 'Messages', 'icon' => 'messages', 'badge' => $unread_msgs],
      ['page' => 'student/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
];

$current_sections = $nav_items[$role] ?? [];
$nav_section_icons = [
    'People & Partners' => 'users',
    'Academic Setup' => 'programs',
    'Administration' => 'activity',
    'Communication' => 'messages',
    'OJT Management' => 'students',
    'Submissions & Reviews' => 'reports',
    'Training' => 'students',
    'My OJT' => 'requirements',
];
$current_script = basename(dirname($_SERVER['PHP_SELF'])) . '/' . basename($_SERVER['PHP_SELF']);
$current_script = preg_replace('/\.php$/i', '', $current_script);
$current_file = preg_replace('/\.php$/i', '', basename($_SERVER['PHP_SELF']));
$nav_item_is_active = static function (array $item) use ($current_script, $current_file): bool {
    $item_route = preg_replace('/\.php$/i', '', $item['page']);
    return $current_script === $item_route
        || (strpos($item_route, '/') === false && $current_file === $item_route)
        || ($current_file === 'certificate' && ($item['icon'] ?? '') === 'certificate');
};

$profile_links = [
    'student'     => '/ojtrack/student/profile.php',
    'coordinator' => '/ojtrack/coordinator/profile.php',
    'company'     => '/ojtrack/company/profile.php',
    'admin'       => '#',
];
$my_profile_url = $profile_links[$role] ?? '#';

// Avatar for topbar (users.avatar)
$user_avatar = $_SESSION['avatar'] ?? null;
if ($user_avatar === null && $uid > 0) {
    $av_row = query_one("SELECT avatar FROM users WHERE id=?", [$uid], 'i');
    $user_avatar = $av_row['avatar'] ?? '';
    $_SESSION['avatar'] = $user_avatar;
}
$user_avatar_url = user_avatar_url($user_avatar);
$has_avatar = $user_avatar_url !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title) ?> — OJTRACK</title>
<link rel="stylesheet" href="/ojtrack/assets/css/style.css?v=<?= (int)filemtime(__DIR__.'/../../assets/css/style.css') ?>">
  <link rel="stylesheet" href="/ojtrack/assets/css/responsive.css?v=20261030-company-trainee-row-details-v1">
  <link rel="stylesheet" href="/ojtrack/assets/css/onboarding-tour.css?v=<?= (int)filemtime(__DIR__.'/../../assets/css/onboarding-tour.css') ?>">
  <link rel="stylesheet" href="/ojtrack/assets/css/confirmations.css?v=20261013">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap"></noscript>
  <script>
    (function () {
      try {
        if (localStorage.getItem('ojtrack_sidebar_collapsed') === '1') {
          document.documentElement.classList.add('sidebar-collapsed');
        }
      } catch (e) {}
    })();
  </script>
</head>
<body class="role-<?= e($role) ?> <?= e($page_class ?? '') ?>" data-tour-user-id="<?= $uid ?>" data-tour-role="<?= e($role) ?>" data-tour-page="<?= e($current_script) ?>" data-notification-cursor="<?= $notification_cursor ?>" data-message-cursor="<?= $latest_incoming_message_id ?>" data-unread-messages="<?= $unread_msgs ?>">

<div class="layout">

<!-- Mobile Sidebar Backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar(false)"></div>

<!-- ── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <!-- Logo -->
  <div class="sidebar-logo">
    <a class="sidebar-brand-link" href="/ojtrack/<?= e($role) ?>/dashboard.php" aria-label="OJTrack dashboard" title="Go to dashboard">
    <img src="/ojtrack/assets/images/logo.png" alt="OJTrack" class="sidebar-logo-img">
    <div class="sidebar-logo-text">
      <div class="brand"><strong>OJ</strong>TRACK</div>
      <div class="campus">USTP Jasaan</div>
    </div>
    </a>
    <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" onclick="toggleSidebarCollapse()" aria-label="Collapse sidebar" title="Collapse menu">
      <svg class="collapse-icon-in" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
      <svg class="collapse-icon-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close sidebar">&times;</button>
  </div>

  <!-- Navigation -->
  <nav class="sidebar-nav" aria-label="Main navigation">
    <?php foreach ($current_sections as $section_index => $section):
      $section_id = 'nav-section-' . $role . '-' . $section_index;
      $section_is_group = count($section['items']) > 1;
      $section_has_active = count(array_filter($section['items'], $nav_item_is_active)) > 0;
      $section_badge = ($role === 'coordinator' && $section['section'] === 'Submissions & Reviews')
        ? array_sum(array_map(static fn(array $item): int => (int)($item['badge'] ?? 0), $section['items']))
        : 0;
      $section_icon_key = $nav_section_icons[$section['section']] ?? ($section['items'][0]['icon'] ?? 'dashboard');
      $section_icon_svg = $nav_icons[$section_icon_key] ?? '';
    ?>
      <div class="nav-section <?= $section_is_group ? 'nav-section-group' : 'nav-section-single' ?> <?= $section_has_active ? 'contains-active' : '' ?>" data-section-key="<?= e($section['section']) ?>">
        <?php if ($section_is_group): ?>
        <button type="button" class="nav-section-header nav-section-toggle" aria-expanded="true" aria-controls="<?= e($section_id) ?>">
          <span class="nav-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $section_icon_svg ?></svg></span>
          <span class="nav-section-label"><?= e($section['section']) ?></span>
          <?php if ($section_badge > 0): ?><span class="nav-badge nav-section-badge" aria-label="<?= $section_badge ?> pending items"><?= $section_badge > 99 ? '99+' : $section_badge ?></span><?php endif; ?>
          <svg class="nav-section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <?php endif; ?>
        <div class="nav-section-items" id="<?= e($section_id) ?>">
          <?php foreach ($section['items'] as $item):
            $active = $nav_item_is_active($item) ? 'active' : '';
            $badge  = (int)($item['badge'] ?? 0);
            $icon_key = $item['icon'] ?? '';
            $icon_svg = $nav_icons[$icon_key] ?? '';
            $is_profile_item = str_ends_with($item['page'], '/profile.php');
          ?>
          <a href="<?= $is_profile_item ? '#' : '/ojtrack/' . e(preg_replace('/\.php$/i', '', $item['page'])) ?>" class="nav-link <?= $section_is_group ? 'nav-link-child' : 'nav-link-top-level' ?> <?= $active ?>" title="<?= e($item['label']) ?>" <?= $active ? 'aria-current="page"' : '' ?> <?php if ($is_profile_item): ?>onclick="event.preventDefault();if(typeof openModal==='function')openModal('profileModal');"<?php endif; ?>>
            <?php if ($icon_svg): ?>
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icon_svg ?></svg>
            </span>
            <?php endif; ?>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <?php if ($badge > 0 || $icon_key === 'messages'): ?>
              <span class="nav-badge"<?= $icon_key === 'messages' ? ' data-live-message-badge' : '' ?><?= $badge > 0 ? '' : ' hidden' ?>><?= $badge > 0 ? $badge : '' ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </nav>
  <?php
    $sidebar_term_label = $header_academic_term
      ? ((int)$header_academic_term['academic_year_start'] . '–' . (int)$header_academic_term['academic_year_end'] . ' · ' . ($header_academic_term['semester'] === 'summer' ? 'Summer' : $header_academic_term['semester'] . ' Semester'))
      : 'Not set';
  ?>
  <div class="sidebar-term-footer" title="Academic term used for current OJT assignments and activity">
    <?php if ($role === 'admin'): ?><a class="sidebar-term-card" href="/ojtrack/admin/academic-terms.php" title="Manage academic terms: <?= e($sidebar_term_label) ?>" aria-label="Academic term: <?= e($sidebar_term_label) ?>. Manage academic terms."><?php else: ?><div class="sidebar-term-card" role="note" title="Academic term: <?= e($sidebar_term_label) ?>" aria-label="Academic term: <?= e($sidebar_term_label) ?>"><?php endif; ?>
      <span class="sidebar-term-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3.5 10h17M8 14h3M8 17h7"></path></svg></span>
      <span class="sidebar-term-copy"><span class="sidebar-term-label">Academic term</span><strong><?= e($sidebar_term_label) ?></strong></span>
    <?php if ($role === 'admin'): ?></a><?php else: ?></div><?php endif; ?>
  </div>
</aside>

<!-- ── Main content ─────────────────────────────────────── -->
<div class="main-content">
<div class="main-shell">

  <!-- Top bar -->
  <header class="topbar">
    <div class="topbar-left">
      <button type="button" class="topbar-menu-btn" onclick="toggleSidebar(true)" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
        <span class="topbar-menu-icon" aria-hidden="true">&#9776;</span>
      </button>
    </div>

    <div class="topbar-right">
      <button type="button" class="topbar-guide-btn" data-tour-open aria-label="Open page guide" title="Open page guide">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M9.6 9a2.5 2.5 0 1 1 4.5 1.5c-.9 1-2.1 1.2-2.1 2.8"></path><path d="M12 17h.01"></path></svg>
        <span>Guide</span>
      </button>
      <!-- Notification Dropdown Container -->
      <div class="notif-dropdown-wrap" id="notifDropdownWrap">
        <button class="topbar-notif-btn" id="notifToggleBtn" onclick="toggleNotifDropdown(event)" title="Notifications" aria-expanded="false">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($unread_notifs > 0): ?>
            <span class="notif-counter" id="notifCounterBadge"><?= $unread_notifs > 99 ? '99+' : $unread_notifs ?></span>
          <?php endif; ?>
        </button>

        <!-- Dropdown Menu -->
        <div class="notif-dropdown-menu" id="notifDropdownMenu">
          <div class="notif-header">
            <div class="notif-header-title">Notifications</div>
            <div class="notif-header-actions">
              <button type="button" class="notif-action-link" onclick="markAllNotificationsRead(event)">Mark all read</button>
            </div>
          </div>
          <div class="notif-body" id="notifListContainer">
            <?php if (empty($recent_notifications)): ?>
              <div class="notif-empty">
                <p>No notifications yet.</p>
              </div>
            <?php else: ?>
              <?php foreach ($recent_notifications as $n): ?>
                <div class="notif-item <?= $n['is_read'] ? 'read' : 'unread' ?>" onclick="handleNotificationClick(<?= (int)$n['id'] ?>, '<?= e($n['link'] ?? '') ?>', this)" style="cursor:pointer">
                  <div class="notif-item-content">
                    <p class="notif-text"><?= e($n['message']) ?></p>
                    <span class="notif-time"><?= time_ago($n['created_at']) ?></span>
                  </div>
                  <?php if (!$n['is_read']): ?>
                    <span class="notif-unread-dot"></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- User Dropdown -->
      <div class="user-dropdown-wrap" id="userDropdownWrap">
        <button class="topbar-user-chip" id="userToggleBtn" onclick="toggleUserDropdown(event)" title="Account" aria-expanded="false">
          <?php if ($has_avatar): ?>
            <img src="<?= e($user_avatar_url) ?>" alt="" class="sidebar-avatar-img topbar-avatar">
          <?php else: ?>
            <span class="sidebar-avatar topbar-avatar"><?= e($initials) ?></span>
          <?php endif; ?>
          <span class="user-chip-name"><?= e($name) ?></span>
          <span class="user-chip-caret" aria-hidden="true">&#9660;</span>
        </button>

        <div class="user-dropdown-menu" id="userDropdownMenu">
          <div class="user-dropdown-header">
            <?php if ($has_avatar): ?>
              <img src="<?= e($user_avatar_url) ?>" alt="" class="sidebar-avatar-img" style="width:40px;height:40px">
            <?php else: ?>
              <div class="sidebar-avatar" style="width:40px;height:40px;font-size:14px"><?= e($initials) ?></div>
            <?php endif; ?>
            <div>
              <div class="user-dropdown-name"><?= e($name) ?></div>
              <div class="user-dropdown-role"><?= ucfirst(e($role)) ?></div>
            </div>
          </div>
          <div class="user-dropdown-divider"></div>
          <button type="button" class="user-dropdown-item" onclick="(function(){var m=document.getElementById('userDropdownMenu');var b=document.getElementById('userToggleBtn');if(m)m.classList.remove('open');if(b)b.setAttribute('aria-expanded','false');if(typeof openModal==='function')openModal('profileModal');})();">
            <span>Update Profile</span>
          </button>
          <button type="button" class="user-dropdown-item user-dropdown-logout" onclick="(function(){var m=document.getElementById('userDropdownMenu');var b=document.getElementById('userToggleBtn');if(m)m.classList.remove('open');if(b)b.setAttribute('aria-expanded','false');if(typeof openModal==='function')openModal('logoutModal');})();">
            <span>Sign Out</span>
          </button>
        </div>
      </div>
    </div>
  </header>

  <?php if ($student_term_enrollment_missing): ?>
    <div class="active-term-notice" role="status">
      <span><strong>You are not enrolled in the current academic term.</strong> Your previous OJT records are saved. Ask your administrator to add your enrollment for this term.</span>
    </div>
  <?php elseif (!$header_academic_term): ?>
    <div class="active-term-notice active-term-notice-warn" role="status">
      <span><strong>No academic term is active.</strong> New OJT assignments and term-based activity are unavailable until an administrator activates a term.</span>
    </div>
  <?php endif; ?>

  <!-- Page body -->
  <main class="page-body">
<?php


