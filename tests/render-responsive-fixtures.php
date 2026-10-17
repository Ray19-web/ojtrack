<?php
// CLI-only UI fixtures: no database, credentials, or application records.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_dir($argv[1])) exit("Pass an existing fixture output directory.\n");
define('OJTRACK', true);
function current_user() { return ['id'=>0, 'role'=>$GLOBALS['fixture_role'], 'name'=>'Synthetic User With A Long Display Name']; }
function student_onboarding_required($uid) { return false; }
function query($sql, $params=[], $types='') { return []; }
function query_one($sql, $params=[], $types='') { return null; }
function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function csrf_token() { return 'synthetic-ui-token'; }
function csrf_field() { return '<input type="hidden" name="csrf_token" value="synthetic-ui-token">'; }
function status_badge($status) { return '<span class="badge '.($status === 'pending' ? 'badge-pending' : 'badge-approved').'">'.e(ucfirst($status)).'</span>'; }
function format_date($value) { return $value ? date('M d, Y', strtotime($value)) : '—'; }

foreach (['admin','coordinator','company','student'] as $fixture_role) {
    $_SESSION=['avatar'=>getenv('OJTRACK_FIXTURE_AVATAR') ?: '']; $_GET=[];
    $_SERVER['PHP_SELF']="/$fixture_role/dashboard.php";
    $_SERVER['REQUEST_URI']="/ojtrack/ui-fixtures/$fixture_role.html";
    $page_title='Responsive Layout Verification';
    ob_start();
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="page-heading flex-between">
      <div><div class="page-title">Responsive <?= e(ucfirst($fixture_role)) ?> Workspace</div><div class="page-sub">Synthetic content for layout checks across device sizes.</div></div>
      <div style="display:flex;gap:8px"><button class="btn btn-secondary" onclick="openModal('fixtureModal')">Open Details</button><button class="btn btn-primary">Primary Action</button></div>
    </div>
    <?php $user = current_user(); require __DIR__ . '/../includes/dashboard-hero.php'; ?>
    <?php if ($fixture_role === 'admin'): ?>
    <div class="admin-hero"><div class="admin-hero-copy"><div class="admin-hero-kicker">System Control Center</div><h2 class="admin-hero-title">Welcome back, Sample</h2><p class="admin-hero-sub">OJTRACK · USTP Jasaan · October 10, 2026</p></div><div class="admin-hero-actions"><a href="/ojtrack/admin/users.php" class="btn btn-ghost-light btn-sm">Manage Users</a><a href="/ojtrack/admin/announcements.php" class="btn btn-accent btn-sm">+ Post Announcement</a></div></div>
    <?php endif; ?>
    <?php if ($fixture_role === 'admin'):
        $search = 'Sample'; $status = ''; $program_filter = ''; $archive_filter = 'active';
        $counts = ['active'=>10, 'archived'=>8]; $students = range(1,10);
        $all_programs_filter = [['id'=>1,'code'=>'BSIT','name'=>'Bachelor of Science in Information Technology']];
    ?>
    <div class="card mb-4"><?php require __DIR__ . '/../includes/student-filters.php'; ?></div>
    <?php $role_filter = 'student'; $date = '2026-10-10'; require __DIR__ . '/../includes/activity-filters.php'; ?>
    <?php endif; ?>
    <div class="card card-body mb-4" id="buttonFixture">
      <div class="card-title">Shared button styles</div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px">
        <?php foreach (['primary','secondary','accent','success','danger','warning','ghost'] as $variant): ?>
          <button type="button" class="btn btn-<?= $variant ?>"><?= ucfirst($variant) ?></button>
        <?php endforeach; ?>
        <button type="button" class="btn btn-primary" disabled>Disabled</button>
      </div>
      <div class="table-actions" style="margin-top:12px">
        <button type="button" class="btn btn-secondary btn-sm">Compact action</button>
        <button type="button" class="table-action-icon is-primary" aria-label="Sample edit"><svg viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header"><div class="card-title">Student row navigation check</div></div>
      <div class="table-wrap" tabindex="0" role="region" aria-label="Student navigation">
        <table id="studentRowFixture"><thead><tr><th>Student</th><th>Program</th><th>Actions</th></tr></thead><tbody>
          <tr class="row-clickable" data-row-link="#selected-student" title="Manage student">
            <td><a href="#selected-student">Sample Student</a></td><td>BSIT</td>
            <td><button type="button" class="btn btn-secondary btn-sm" onclick="openModal('fixtureModal')">Separate row action</button></td>
          </tr>
        </tbody></table>
      </div>
      <div id="selected-student" class="card-body">Sample student management destination</div>
    </div>
    <div class="stat-grid stat-grid-4 dashboard-stats mb-4">
      <?php foreach (['Total Users','Companies','Pending Requirements','Reports for Review'] as $label): ?>
      <div class="stat-card"><div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8"/></svg></div><div><div class="stat-label"><span class="stat-value">123</span><?= $label ?></div><div class="stat-sub">Synthetic dashboard summary</div></div></div>
      <?php endforeach; ?>
    </div>
    <div class="grid grid-2 mb-4">
      <div class="card">
        <div class="card-header"><div class="card-title">Records &amp; Filters</div>
          <form class="filter-toolbar" method="GET" style="display:flex;gap:8px">
            <div class="search-wrap" style="width:260px"><input aria-label="Search records" class="form-control" placeholder="Search students..."></div>
            <select class="form-control" aria-label="Status" style="width:160px"><option>All statuses</option></select>
            <input aria-label="Date" type="date" class="form-control" style="width:180px">
          </form>
        </div>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Scrollable records"><table><thead><tr><?php foreach (['Student','Student ID','Academic Program','Coordinator','Company','OJT Status','Required Hours','Actions'] as $heading): ?><th><?= $heading ?></th><?php endforeach; ?></tr></thead>
          <tbody><?php foreach (['Ana Sample','Carlo Sample','Juan Sample'] as $fixture_name): ?><tr><td><div class="table-person"><div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--primary)"><?= user_avatar_content(getenv('OJTRACK_FIXTURE_AVATAR') ?: null, $fixture_name) ?></div><div><div class="font-bold text-sm"><?= $fixture_name ?></div><div class="text-xs text-muted">synthetic.student@example.invalid</div></div></div></td><td class="td-mono">S20260001</td><td><span class="badge" title="Bachelor of Science in Information Technology">BSIT</span></td><td>Synthetic Coordinator</td><td>Synthetic Company</td><td>Ongoing</td><td>486</td><td><button class="btn btn-sm">View Details</button></td></tr><?php endforeach; ?></tbody></table></div>
      </div>
      <div class="card card-body"><div class="card-title">Forms</div><div class="form-row"><div class="form-group"><label class="form-label">Program</label><select class="form-control"><option>Bachelor of Science in Information Technology</option></select></div><div class="form-group"><label class="form-label">Date</label><input type="date" class="form-control"></div></div><p><?= str_repeat('LongFilename', 12) ?>.pdf</p></div>
    </div>
    <?php foreach (['student-detail-grid','trainee-detail-grid','journal-detail-grid'] as $grid): ?>
      <div class="grid <?= $grid ?> mb-4"><div class="card card-body">Student summary</div><div class="card card-body">Detailed training information and journal content.</div></div>
    <?php endforeach; ?>
    <div class="grid evaluation-form-grid mb-4"><div class="card card-body"><div class="eval-section"><div class="flex-between"><div><form class="evaluation-builder-form" style="display:flex;gap:6px"><input class="form-control" style="width:260px" aria-label="Section title"><button class="btn btn-sm">Rename</button></form></div><button class="btn btn-sm">Remove</button></div></div></div></div>
    <div class="chat-container mb-4">
      <div class="chat-threads"><div class="chat-threads-header">Messages <button class="btn btn-sm">New</button></div><div class="chat-threads-list"><?php for($i=0;$i<8;$i++): ?><a class="chat-thread-item" href="#"><div class="chat-thread-meta"><div class="chat-thread-title">Synthetic Conversation <?= $i ?></div><div class="chat-thread-preview">A preview of the conversation.</div></div></a><?php endfor; ?></div></div>
      <div class="chat-main"><div class="chat-header">Synthetic Conversation</div><div class="chat-messages-box"><?php for($i=0;$i<12;$i++): ?><div class="chat-bubble-row <?= $i%2?'mine':'theirs' ?>"><div class="chat-bubble-wrap"><div class="chat-bubble"><?= str_repeat('LongMessage',18) ?></div></div></div><?php endfor; ?></div><div class="chat-footer"><div class="chat-input-row"><textarea class="chat-input" aria-label="Message" placeholder="Type a message"></textarea><button class="btn-send" aria-label="Send message">➤</button></div></div></div>
    </div>
    <div id="certificate" style="background:white;border:8px double #103b78;padding:56px 48px;text-align:center;max-width:1100px;min-height:560px;margin:0 auto"><div style="font-size:30px;letter-spacing:2px">Certificate of Recognition</div><p>Synthetic Student</p><div class="certificate-signatures" style="display:flex;justify-content:space-between;max-width:640px;margin:0 auto"><div><div style="border-top:1px solid;width:200px"></div>Signatory</div><div><div style="border-top:1px solid;width:200px"></div>Date Issued</div></div></div>
    <div class="modal-overlay" id="fixtureModal"><div class="modal modal-lg"><div class="modal-title">Record Details</div><div class="form-row"><?php for($i=0;$i<18;$i++): ?><div class="form-group"><label class="form-label">Field <?= $i+1 ?></label><input class="form-control" aria-label="Field <?= $i+1 ?>"></div><?php endfor; ?></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal('fixtureModal')">Cancel</button><button class="btn btn-primary">Save Changes</button></div></div></div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    file_put_contents($argv[1].DIRECTORY_SEPARATOR.$fixture_role.'.html', ob_get_clean());
}
$onboardingSource=file_get_contents(__DIR__.'/../student/onboarding.php');
preg_match('~<style>(.*?)</style>~s', $onboardingSource, $styles);
$onboarding='<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/ojtrack/assets/css/style.css"><style>'.$styles[1].'</style></head><body class="ojt-onboarding"><div class="onboarding-shell"><div class="onboarding-brand"><img src="/ojtrack/assets/images/logo.png" alt="OJTrack"><div><strong>OJTrack</strong><span>Student Activation</span></div><button class="signout">Sign Out</button></div><div class="card"><div class="onboarding-head"><h1 class="onboarding-title">Activate your student account</h1></div><div class="stepper">';
foreach (['Account Details','Academic Program','OJT Requirements','Coordinator Approval'] as $step) $onboarding.='<div class="step"><div class="step-dot">1</div><div class="step-label">'.$step.'</div><div class="step-state">Pending</div></div>';
$onboarding.='</div><div class="onboarding-body"><div class="account-summary"><div class="summary-item"><div class="summary-label">Name</div><div class="summary-value">Synthetic Student With A Long Name</div></div><div class="summary-item"><div class="summary-value">'.str_repeat('LongEmail',12).'@example.invalid</div></div><div class="summary-item">Information Technology</div></div><div class="requirement"><div><div class="req-name">Required internship document</div><div class="req-remarks">Upload your completed requirements for coordinator review.</div></div><div class="req-actions"><span class="status unsubmitted">Not submitted</span><form class="upload-form"><label class="file-label">'.str_repeat('LongFilename',8).'.pdf</label><button class="btn btn-primary">Upload Document</button></form></div></div></div></div></div></body></html>';
file_put_contents($argv[1].DIRECTORY_SEPARATOR.'onboarding.html', $onboarding);
$fixture_role = 'admin';
$_SERVER['PHP_SELF'] = '/admin/coordinators.php';
$page_title = 'Coordinators';
$programs_list = [
    ['code'=>'BSIT','name'=>'Bachelor of Science in Information Technology'],
    ['code'=>'BSTCM','name'=>'Bachelor of Science in Technology Communication Management'],
];
$next_coord_id = 'C20260003';
$filter_programs = $programs_list;
$coord_filters = ['search'=>'','program'=>'','status'=>''];
$coordinators = [
    ['id'=>1,'name'=>"Dr. Sample O'Neil, MIT",'email'=>null,'avatar'=>null,'status'=>'active','department'=>'BSIT','coordinator_id_no'=>'C20260001','contact_number'=>null,'student_count'=>5,'active_students'=>0],
    ['id'=>2,'name'=>'Sample Coordinator Two','email'=>'sample@example.invalid','avatar'=>null,'status'=>'active','department'=>'BSTCM','coordinator_id_no'=>'C20260002','contact_number'=>null,'student_count'=>5,'active_students'=>0],
];
ob_start();
require __DIR__ . '/../includes/header.php';
echo '<div class="page-heading flex-between"><div class="page-title">Coordinators</div><button class="btn btn-primary" onclick="openModal(\'addCoordModal\')">+ Add Coordinator</button></div>';
require __DIR__ . '/../includes/coordinator-table.php';
require __DIR__ . '/../includes/coordinator-modals.php';
require __DIR__ . '/../includes/footer.php';
file_put_contents($argv[1].DIRECTORY_SEPARATOR.'coordinators.html', ob_get_clean());
$fixture_role = 'coordinator';
$_SERVER['PHP_SELF'] = '/coordinator/students.php';
$page_title = 'My Students';
$detail = [
    'id'=>1,'name'=>"Sample Student With A Long Name",'avatar'=>null,'student_id_no'=>'S20260001',
    'program'=>'BSIT','department'=>'BSIT','year_level'=>'4th Year','email'=>null,'contact_number'=>null,
    'company_id'=>null,'company_name'=>null,'supervisor'=>null,'ojt_status'=>'pending','status_notes'=>null,
    'required_hours'=>486,'rendered_hours'=>0,'ojt_start_date'=>null,'ojt_end_date'=>null,
];
$companies_list = [];
$status_labels = ['pending'=>'Pending','not_started'=>'Not Started','ongoing'=>'Ongoing','completed'=>'Completed','on_hold'=>'On Hold','withdrawn'=>'Withdrawn'];
ob_start();
require __DIR__ . '/../includes/header.php';
echo '<div class="page-heading"><div class="page-title">Student Details</div></div>';
require __DIR__ . '/../includes/coordinator-student-detail.php';
require __DIR__ . '/../includes/coordinator-student-edit.php';
require __DIR__ . '/../includes/footer.php';
file_put_contents($argv[1].DIRECTORY_SEPARATOR.'student-detail.html', ob_get_clean());
echo "Rendered role, onboarding, coordinator and student detail fixtures. No database access.\n";

// Use the production messaging panel and script with synthetic conversations only.
function time_ago($value) { return '10:24 AM'; }
foreach (['coordinator', 'student', 'company'] as $fixture_role) {
    foreach (['list', 'open', 'empty', 'archived', 'archived-open'] as $view) {
        $_SERVER['PHP_SELF'] = "/$fixture_role/messages.php";
        $page_title = 'Messages'; $page_class = 'messages-page';
        $contacts = [['id'=>2, 'name'=>'Sample Coordinator']];
        $archive_view = str_starts_with($view, 'archived');
        $conversation_counts = ['inbox'=>15, 'archived'=>1];
        $uid = 0; $active_id = $view === 'open' ? 1 : ($view === 'archived-open' ? 16 : 0);
        $threads = [];
        for ($i=1; $i<=16; $i++) $threads[] = ['id'=>$i, 'archived_at'=>$i===16 ? '2026-10-10 10:00:00' : null, 'name'=>$i===1 ? 'OJT Program Group' : "Sample Conversation $i", 'thread_type'=>'group', 'description'=>'Students and coordinator', 'avatar'=>null, 'last_msg_time'=>'2026-10-10 10:24:00', 'last_message'=>'Please review the updated internship schedule.', 'unread'=>0];
        $active_thread = $active_id ? $threads[$active_id - 1] : null;
        $threads = array_values(array_filter($threads, fn($thread) => !empty($thread['archived_at']) === $archive_view));
        if ($view === 'empty') { $threads = []; $conversation_counts = ['inbox'=>0, 'archived'=>0]; }
        $messages_list = [];
        for ($i=1; $i<=24; $i++) $messages_list[] = ['id'=>$i, 'sender_id'=>$i%2 ? 2 : 0, 'sender_name'=>'Sample Coordinator', 'sender_role'=>'coordinator', 'sender_avatar'=>null, 'message'=>$i===9 ? str_repeat('LongUnbrokenMessage',25) : ($i%2 ? "Please review the internship schedule.\nLet me know if you have any questions." : 'Thank you. I will check the schedule today.'), 'sent_at'=>'2026-10-10 10:24:00'];
        ob_start();
        require __DIR__ . '/../includes/header.php';
        echo '<div class="page-heading flex-between"><div><div class="page-title">Messages</div><div class="page-sub">Your conversations</div></div><button class="btn btn-primary" onclick="openModal(\'newThreadModal\')">+ New Conversation</button></div>';
        require __DIR__ . '/../includes/messages-panel.php';
        echo '<div class="modal-overlay" id="newThreadModal"><div class="modal"><div class="modal-title">New Conversation</div><label class="form-label" for="fixtureTitle">Title</label><input id="fixtureTitle" class="form-control"><div class="modal-footer"><button class="btn btn-secondary" onclick="closeModal(\'newThreadModal\')">Cancel</button></div></div></div>';
        // Isolate send UI tests from the application API and database.
        echo '<script>const fixtureFetch = window.fetch; window.fetch = (url, options) => url === \'/ojtrack/api/messages.php\' ? Promise.resolve({ok: true, json: async () => ({ok: true})}) : fixtureFetch(url, options);</script>';
        require __DIR__ . '/../includes/messages-script.php';
        require __DIR__ . '/../includes/footer.php';
        $html = str_replace(['href="messages.php', "'messages.php"], ['href="messages-'.$fixture_role.'.html', "'messages-".$fixture_role.".html"], ob_get_clean());
        file_put_contents($argv[1].DIRECTORY_SEPARATOR.'messages-'.$fixture_role.'-'.$view.'.html', $html);
    }
}

// Render the real announcement templates with synthetic data and no database access.
foreach (['company', 'student'] as $fixture_role) {
    $_SERVER['PHP_SELF'] = "/$fixture_role/announcements.php";
    $_SERVER['REQUEST_URI'] = "/ojtrack/ui-fixtures/announcements-$fixture_role.html";
    $page_title = 'Announcements';
    $page_class = '';
    $success = $error = $search = $tag_filter = '';
    $my_notices = [];
    $all_tags = [['tag'=>'General'], ['tag'=>'Important'], ['tag'=>'Welcome']];
    $announcements = [['id'=>1, 'title'=>'OJT orientation', 'body'=>'Review the orientation schedule and bring your required documents.', 'tag'=>'Important', 'is_pinned'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'attachment_file'=>null, 'author_name'=>'OJT Office', 'author_role'=>'coordinator']];
    ob_start();
    require __DIR__.'/../includes/header.php';
    $announcement_source = file_get_contents(__DIR__.'/../'.$fixture_role.'/announcements.php');
    $announcement_markup = substr($announcement_source, strpos($announcement_source, '<div class="page-heading'));
    $announcement_markup = str_replace("require_once __DIR__ . '/../includes/footer.php'", "require __DIR__ . '/../includes/footer.php'", $announcement_markup);
    // eval uses this test file's directory, which matches the templates' relative include depth.
    eval('?>'.$announcement_markup);
    file_put_contents($argv[1].DIRECTORY_SEPARATOR.'announcements-'.$fixture_role.'.html', ob_get_clean());
}

// Real onboarding markup for completion and empty-selection UI checks.
foreach (['complete','pending'] as $onboarding_view) {
    $ready = $onboarding_view === 'complete';
    $success = $error = '';
    $student = ['name'=>'Synthetic Student','student_id_no'=>'SYNTHETIC-001','program_code'=>'BSIT','program'=>'BSIT'];
    $total=10; $approved=$ready ? 10 : 0; $submitted=$approved; $pending_review=0;
    $details_complete=true; $documents_complete=$ready; $current_step=$ready ? 4 : 2;
    $requirements=[];
    for ($i=1;$i<=10;$i++) $requirements[]=['id'=>$i,'status'=>$ready ? 'approved' : 'pending','document_name'=>'Required document '.$i,'submitted_at'=>null,'deadline'=>null,'remarks'=>null,'file_path'=>null];
    $onboarding_source=file_get_contents(__DIR__.'/../student/onboarding.php');
    ob_start();
    eval('?>'.substr($onboarding_source,strpos($onboarding_source,'<!DOCTYPE html>')));
    file_put_contents($argv[1].DIRECTORY_SEPARATOR.'onboarding-'.$onboarding_view.'.html',ob_get_clean());
}

// Real admin account form with synthetic programs and no account writes.
$fixture_role='admin'; $_SERVER['PHP_SELF']='/admin/users.php';
$_SERVER['REQUEST_URI']='/ojtrack/ui-fixtures/users.html'; $page_title='User Accounts'; $page_class='';
$success=$error=$search=$status_filter=''; $role_filter='student'; $users=[];
$counts=['student'=>0,'coordinator'=>0,'company'=>0,'admin'=>0]; $archived_count=0;
$programs_list=[['id'=>1,'code'=>'BSIT','name'=>'Information Technology'],['id'=>2,'code'=>'BSTCM','name'=>'Technology Communication Management']];
$next_student_id='S20990001'; $next_coord_id='C20990001'; $add_user_failed=false; $add_values=[]; $add_role='student'; $add_id_mode='manual';
ob_start(); require __DIR__.'/../includes/header.php';
$users_source=file_get_contents(__DIR__.'/../admin/users.php');
$users_markup=substr($users_source,strpos($users_source,'<div class="page-heading'));
$users_markup=str_replace("require_once __DIR__ . '/../includes/footer.php'", "require __DIR__ . '/../includes/footer.php'", $users_markup);
eval('?>'.$users_markup);
file_put_contents($argv[1].DIRECTORY_SEPARATOR.'users.html',ob_get_clean());

// Real Students page and creation modal with synthetic program options.
$fixture_role='admin'; $_SERVER['PHP_SELF']='/admin/students.php';
$_SERVER['REQUEST_URI']='/ojtrack/ui-fixtures/students.html'; $page_title='All Students';
$students=$companies=$coordinators=$coord_dept_map=$program_options=[];
$programs=$all_programs_filter=$programs_list; $counts=['active'=>0,'archived'=>0];
$search=$status=$program_filter=''; $archive_filter='active'; $add_student_failed=false; $add_values=[];
ob_start(); require __DIR__.'/../includes/header.php';
$students_source=file_get_contents(__DIR__.'/../admin/students.php');
$students_markup=substr($students_source,strpos($students_source,'<div class="page-heading'));
$students_markup=str_replace("require_once __DIR__ . '/../includes/footer.php'", "require __DIR__ . '/../includes/footer.php'", $students_markup);
eval('?>'.$students_markup);
file_put_contents($argv[1].DIRECTORY_SEPARATOR.'students.html',ob_get_clean());

// Production record filters on all three requested pages, with synthetic data.
// Company retry state: visible validation inside the real modal, no credentials.
$fixture_role='admin'; $_SERVER['PHP_SELF']='/admin/companies.php';
$_SERVER['REQUEST_URI']='/ojtrack/ui-fixtures/company-registration.html'; $page_title='Companies';
$companies=[]; $search=$status=$success=''; $error='Initial password must be between 12 and 72 characters.';
$add_company_failed=true;
$add_values=['company_name'=>'Sample Partner Company','location'=>'Sample Workplace','supervisor_name'=>'','contact_number'=>'','email'=>'partner@example.invalid'];
ob_start(); require __DIR__.'/../includes/header.php';
$company_source=file_get_contents(__DIR__.'/../admin/companies.php');
$company_markup=substr($company_source,strpos($company_source,'<div class="page-heading'));
$company_markup=str_replace("require_once __DIR__ . '/../includes/footer.php'","require __DIR__ . '/../includes/footer.php'",$company_markup);
eval('?>'.$company_markup);
file_put_contents($argv[1].DIRECTORY_SEPARATOR.'company-registration.html',ob_get_clean());

require_once __DIR__.'/../config/record-filters.php';
$fixture_role='admin'; $page_class=''; $success=$error='';
foreach (['archived-users','announcements-admin','certificates'] as $record_view) {
    $_GET=[];
    $_SERVER['REQUEST_URI']='/ojtrack/ui-fixtures/'.$record_view.'.html';
    if ($record_view==='archived-users') {
        $_SERVER['PHP_SELF']='/admin/archived-users.php'; $page_title='Archived Accounts';
        $filters=archived_account_filters([]); $search=$role_filter='';
        $archived_users=[['id'=>1,'name'=>'Sample Archived Student','email'=>null,'role'=>'student','avatar'=>null,'created_at'=>'2026-10-01','status'=>'archived']];
        $total_archived=1;
        $record_source=file_get_contents(__DIR__.'/../admin/archived-users.php');
        $record_markup=substr($record_source,strpos($record_source,'<div class="page-heading'));
    } elseif ($record_view==='announcements-admin') {
        $_SERVER['PHP_SELF']='/admin/announcements.php'; $page_title='Announcements';
        $filters=announcement_management_filters([]); $search=''; $announcements=[];
        $tag_options=[''=>'All Categories','General'=>'General','Welcome'=>'Welcome','Reminder'=>'Reminder'];
        $record_source=file_get_contents(__DIR__.'/../admin/announcements.php');
        $record_markup=substr($record_source,strpos($record_source,'<div class="page-heading'));
    } else {
        $_SERVER['PHP_SELF']='/certificate.php'; $page_title='Certificates'; $user=['role'=>'admin'];
        $filters=issued_certificate_filters([]);
        $available=[['id'=>1,'name'=>'Sample Student','certificate_no'=>'SAMPLE-001','issued_at'=>'2026-10-01']];
        $record_source=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../certificate.php'));
        $record_start=strpos($record_source,'<div class="page-heading">');
        $record_end=strpos($record_source,"    <?php\n    require_once",$record_start);
        $record_markup=substr($record_source,$record_start,$record_end-$record_start);
        $record_markup=str_replace("__DIR__.'/includes/record-filters.php'","__DIR__.'/../includes/record-filters.php'",$record_markup);
        $record_markup.="<?php require __DIR__.'/../includes/footer.php'; ?>";
    }
    $record_markup=str_replace("require_once __DIR__ . '/../includes/footer.php'","require __DIR__ . '/../includes/footer.php'",$record_markup);
    ob_start(); require __DIR__.'/../includes/header.php'; eval('?>'.$record_markup);
    file_put_contents($argv[1].DIRECTORY_SEPARATOR.$record_view.'.html',ob_get_clean());
}
