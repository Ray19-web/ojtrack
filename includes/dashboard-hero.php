<?php
$dashboard_hero_roles = [
    'admin' => ['System Control Center', 'Manage Users', '/ojtrack/admin/users.php', '+ Post Announcement', '/ojtrack/admin/announcements.php'],
    'coordinator' => ['Coordinator Dashboard', 'View Students', '/ojtrack/coordinator/students.php', 'Review Requirements', '/ojtrack/coordinator/requirements.php'],
    'company' => ['Company Dashboard', 'View Trainees', '/ojtrack/company/students.php', 'Evaluations', '/ojtrack/company/evaluation.php'],
    'student' => ['My OJT', 'View Requirements', '/ojtrack/student/requirements.php', 'Attendance', '/ojtrack/student/attendance.php'],
];
$dashboard_hero_role = $user['role'];
$dashboard_hero_config = $dashboard_hero_roles[$dashboard_hero_role];
$dashboard_hero_name = $dashboard_hero_role === 'company'
    ? ($company['company_name'] ?? $user['name'])
    : ($dashboard_hero_role === 'admin' ? explode(' ', trim($user['name']))[0] : $user['name']);
?>
<section class="dashboard-hero" aria-label="Welcome">
  <div class="dashboard-hero-copy">
    <div class="dashboard-hero-kicker"><?= e($dashboard_hero_config[0]) ?></div>
    <h2 class="dashboard-hero-title">Welcome back, <?= e($dashboard_hero_name) ?></h2>
    <p class="dashboard-hero-sub">OJTRACK · USTP Jasaan · <?= date('l, F j, Y') ?></p>
  </div>
  <div class="dashboard-hero-actions">
    <a href="<?= e($dashboard_hero_config[2]) ?>" class="btn btn-ghost-light"><?= e($dashboard_hero_config[1]) ?></a>
    <a href="<?= e($dashboard_hero_config[4]) ?>" class="btn btn-accent"><?= e($dashboard_hero_config[3]) ?></a>
  </div>
</section>
