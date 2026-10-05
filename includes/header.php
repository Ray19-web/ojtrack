<?php
if (!defined('OJTRACK')) die('Direct access not allowed.');

$user     = current_user();
$role     = $user['role'];
$name     = $user['name'];
$uid      = (int)$user['id'];

if ($role === 'student' && student_onboarding_required($uid)) {
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $onboarding_path = '/ojtrack/student/onboarding.php';
    if ($request_path !== $onboarding_path) {
        redirect('student/onboarding.php');
    }
}
$initials = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice(explode(' ', trim($name)), 0, 2))));
if (!$initials) $initials = 'U';

// Certificates badge (red number where certificates live)
$cert_count = 0;
if ($uid > 0) {
    if ($role === 'student') {
        $s = query_one("SELECT ojt_status FROM students WHERE user_id=?", [$uid], 'i');
        $cert_count = ($s && ($s['ojt_status'] ?? '') === 'completed') ? 1 : 0;
    } elseif ($role === 'company') {
        $co = query_one("SELECT id FROM companies WHERE user_id=?", [$uid], 'i');
        $cert_count = (int)(query_one("SELECT COUNT(*) AS c FROM students WHERE company_id=? AND ojt_status='completed'", [$co['id'] ?? 0], 'i')['c'] ?? 0);
    } elseif ($role === 'coordinator') {
        $co = query_one("SELECT id FROM coordinators WHERE user_id=?", [$uid], 'i');
        $cert_count = (int)(query_one("SELECT COUNT(*) AS c FROM students WHERE coordinator_id=? AND ojt_status='completed'", [$co['id'] ?? 0], 'i')['c'] ?? 0);
    } elseif ($role === 'admin') {
        $cert_count = (int)(query_one("SELECT COUNT(*) AS c FROM students WHERE ojt_status='completed'")['c'] ?? 0);
    }
}

// Page title from variable set in each page
$page_title = $page_title ?? 'OJTRACK';
$page_sub   = $page_sub   ?? 'USTP Jasaan OJT Management System';

// Calculate live badges
$unread_notifs = 0;
$unread_msgs   = 0;
$pending_action_count = 0;

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
         WHERE m.sender_id != ?
           AND NOT EXISTS (SELECT 1 FROM message_reads mr WHERE mr.message_id = m.id AND mr.user_id = ?)",
        [$uid, $uid, $uid],
        'iii'
    )['c'] ?? 0);

    if ($role === 'coordinator') {
        $coord = query_one("SELECT id FROM coordinators WHERE user_id=?", [$uid], 'i');
        $cid = $coord['id'] ?? 0;
        $pending_rows = normalized_requirement_rows_for_coordinator((int)$cid, 'pending');
        $pending_action_count = count(array_filter($pending_rows, fn($row) => !empty($row['submitted_at'])));
    } elseif ($role === 'company') {
        $comp = query_one("SELECT id FROM companies WHERE user_id=?", [$uid], 'i');
        $cid = $comp['id'] ?? 0;
        $pending_action_count = count(array_filter(
            normalized_eval_requests_for_company((int)$cid),
            fn($row) => ($row['status'] ?? '') === 'pending'
        ));
    } elseif ($role === 'student') {
        $stud = query_one("SELECT id FROM students WHERE user_id=?", [$uid], 'i');
        $sid = $stud['id'] ?? 0;
        $pending_action_count = count(array_filter(
            normalized_requirement_rows_for_student((int)$sid),
            fn($row) => ($row['status'] ?? '') === 'rejected' || empty($row['submitted_at'])
        ));
    }
}

// Fetch top notifications for dropdown
$recent_notifications = query(
    "SELECT id, message, notif_type, is_read, created_at, link FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 6",
    [$uid],
    'i'
) ?: [];

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

// Sidebar nav config per role — grouped under Sidebar Section Headers
$nav_items = [
  'admin' => [
    ['section' => 'OVERVIEW', 'items' => [
      ['page' => 'admin/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'PEOPLE & PARTNERS', 'items' => [
      ['page' => 'admin/students.php', 'label' => 'Students', 'icon' => 'students', 'badge' => 0],
      ['page' => 'admin/coordinators.php', 'label' => 'Coordinators', 'icon' => 'coordinators', 'badge' => 0],
      ['page' => 'admin/companies.php', 'label' => 'Companies', 'icon' => 'companies', 'badge' => 0],
    ]],
    ['section' => 'ACADEMIC SETUP', 'items' => [
      ['page' => 'admin/programs.php', 'label' => 'Programs', 'icon' => 'programs', 'badge' => 0],
    ]],
    ['section' => 'ADMINISTRATION', 'items' => [
      ['page' => 'admin/users.php', 'label' => 'User Accounts', 'icon' => 'users', 'badge' => 0],
      ['page' => 'admin/archived-users.php', 'label' => 'Archived Accounts', 'icon' => 'archived', 'badge' => 0],
      ['page' => 'admin/activity.php', 'label' => 'Activity Log', 'icon' => 'activity', 'badge' => 0],
      ['page' => 'certificate.php', 'label' => 'Certificates', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'COMMUNICATION', 'items' => [
      ['page' => 'admin/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
  'coordinator' => [
    ['section' => 'OVERVIEW', 'items' => [
      ['page' => 'coordinator/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'OJT MANAGEMENT', 'items' => [
      ['page' => 'coordinator/students.php', 'label' => 'My Students', 'icon' => 'students', 'badge' => 0],
      ['page' => 'coordinator/attendance.php', 'label' => 'Attendance', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'certificate.php', 'label' => 'Certificates', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'SUBMISSIONS & REVIEWS', 'items' => [
      ['page' => 'coordinator/requirements.php', 'label' => 'Requirements', 'icon' => 'requirements', 'badge' => $pending_action_count],
      ['page' => 'coordinator/monitoring.php', 'label' => 'Daily Journals', 'icon' => 'journal', 'badge' => 0],
      ['page' => 'coordinator/reports.php', 'label' => 'Reports', 'icon' => 'reports', 'badge' => 0],
      ['page' => 'coordinator/evaluation.php', 'label' => 'Evaluations', 'icon' => 'evaluation', 'badge' => 0],
    ]],
    ['section' => 'COMMUNICATION', 'items' => [
      ['page' => 'coordinator/messages.php', 'label' => 'Messages', 'icon' => 'messages', 'badge' => $unread_msgs],
      ['page' => 'coordinator/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
  'company' => [
    ['section' => 'OVERVIEW', 'items' => [
      ['page' => 'company/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'TRAINING', 'items' => [
      ['page' => 'company/students.php', 'label' => 'My Trainees', 'icon' => 'students', 'badge' => 0],
      ['page' => 'company/attendance.php', 'label' => 'Attendance', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'company/evaluation.php', 'label' => 'Evaluations', 'icon' => 'evaluation', 'badge' => $pending_action_count],
      ['page' => 'company/certificate.php', 'label' => 'Certificates', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'COMMUNICATION', 'items' => [
      ['page' => 'company/messages.php', 'label' => 'Messages', 'icon' => 'messages', 'badge' => $unread_msgs],
      ['page' => 'company/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
  'student' => [
    ['section' => 'OVERVIEW', 'items' => [
      ['page' => 'student/dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'badge' => 0],
    ]],
    ['section' => 'MY OJT', 'items' => [
      ['page' => 'student/requirements.php', 'label' => 'Requirements', 'icon' => 'requirements', 'badge' => $pending_action_count],
      ['page' => 'student/attendance.php', 'label' => 'Attendance', 'icon' => 'attendance', 'badge' => 0],
      ['page' => 'student/journal.php', 'label' => 'Daily Journal', 'icon' => 'journal', 'badge' => 0],
      ['page' => 'student/reports.php', 'label' => 'Reports', 'icon' => 'reports', 'badge' => 0],
      ['page' => 'student/evaluation.php', 'label' => 'Evaluations', 'icon' => 'evaluation', 'badge' => 0],
      ['page' => 'student/certificate.php', 'label' => 'Certificate', 'icon' => 'certificate', 'badge' => 0],
    ]],
    ['section' => 'COMMUNICATION', 'items' => [
      ['page' => 'student/messages.php', 'label' => 'Messages', 'icon' => 'messages', 'badge' => $unread_msgs],
      ['page' => 'student/announcements.php', 'label' => 'Announcements', 'icon' => 'announcements', 'badge' => 0],
    ]],
  ],
];

$current_sections = $nav_items[$role] ?? [];
$current_script = basename(dirname($_SERVER['PHP_SELF'])) . '/' . basename($_SERVER['PHP_SELF']);
$current_file   = basename($_SERVER['PHP_SELF']);

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
$has_avatar = !empty($user_avatar);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title) ?> — OJTRACK</title>
  <link rel="stylesheet" href="/ojtrack/assets/css/style.css?v=20261005-logout-polish-v5">
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
<body class="role-<?= e($role) ?>">

<div class="layout">

<!-- Mobile Sidebar Backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar(false)"></div>

<!-- ── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <!-- Logo -->
  <div class="sidebar-logo">
    <img src="/ojtrack/assets/images/logo.png" alt="OJTrack" class="sidebar-logo-img">
    <div class="sidebar-logo-text">
      <div class="brand"><strong>OJ</strong>TRACK</div>
      <div class="campus">USTP Jasaan</div>
    </div>
    <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" onclick="toggleSidebarCollapse()" aria-label="Collapse sidebar" title="Collapse menu">
      <svg class="collapse-icon-in" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
      <svg class="collapse-icon-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close sidebar">&times;</button>
  </div>

  <!-- Navigation -->
  <nav class="sidebar-nav">
    <?php foreach ($current_sections as $section): ?>
      <div class="nav-section">
        <div class="nav-section-header"><?= e($section['section']) ?></div>
        <div class="nav-section-items">
          <?php foreach ($section['items'] as $item):
            $active = ($current_script === $item['page'] || (strpos($item['page'], '/') === false && $current_file === $item['page'])) ? 'active' : '';
            $badge  = (int)($item['badge'] ?? 0);
            $icon_key = $item['icon'] ?? '';
            $icon_svg = $nav_icons[$icon_key] ?? '';
            $is_profile_item = str_ends_with($item['page'], '/profile.php');
          ?>
          <a href="<?= $is_profile_item ? '#' : '/ojtrack/' . e($item['page']) ?>" class="nav-link <?= $active ?>" title="<?= e($item['label']) ?>" <?php if ($is_profile_item): ?>onclick="event.preventDefault();if(typeof openModal==='function')openModal('profileModal');"<?php endif; ?>>
            <?php if ($icon_svg): ?>
            <span class="nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icon_svg ?></svg>
            </span>
            <?php endif; ?>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <?php if ($badge > 0 && !$active): ?>
              <span class="nav-badge"><?= $badge ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- ── Main content ─────────────────────────────────────── -->
<div class="main-content">
<div class="main-shell">

  <!-- Top bar -->
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-menu-btn" onclick="toggleSidebar(true)" aria-label="Open menu">
        <span class="topbar-menu-icon" aria-hidden="true">&#9776;</span>
      </button>
      <div class="topbar-titles">
        <h1 class="topbar-title"><?= e($page_title) ?></h1>
      
      </div>
    </div>

    <div class="topbar-right">
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
            <img src="/ojtrack/uploads/<?= e($user_avatar) ?>" alt="" class="sidebar-avatar-img topbar-avatar">
          <?php else: ?>
            <span class="sidebar-avatar topbar-avatar"><?= e($initials) ?></span>
          <?php endif; ?>
          <span class="user-chip-name"><?= e($name) ?></span>
          <span class="user-chip-caret" aria-hidden="true">&#9660;</span>
        </button>

        <div class="user-dropdown-menu" id="userDropdownMenu">
          <div class="user-dropdown-header">
            <?php if ($has_avatar): ?>
              <img src="/ojtrack/uploads/<?= e($user_avatar) ?>" alt="" class="sidebar-avatar-img" style="width:40px;height:40px">
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
            <span>Profile</span>
          </button>
          <button type="button" class="user-dropdown-item user-dropdown-logout" onclick="(function(){var m=document.getElementById('userDropdownMenu');var b=document.getElementById('userToggleBtn');if(m)m.classList.remove('open');if(b)b.setAttribute('aria-expanded','false');if(typeof openModal==='function')openModal('logoutModal');})();">
            <span>Sign Out</span>
          </button>
        </div>
      </div>
    </div>
  </header>

  <!-- Page body -->
  <main class="page-body">
<?php


