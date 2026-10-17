<?php
// Real queries against connection-scoped temporary tables. No application writes.
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/../config/db.php';
require_once __DIR__.'/../config/record-filters.php';
$tables=[
 'users'=>'id INT, name VARCHAR(100), email VARCHAR(150), role VARCHAR(20), status VARCHAR(20), created_at DATETIME',
 'students'=>'id INT, user_id INT, student_id_no VARCHAR(30)',
 'companies'=>'id INT, user_id INT', 'coordinators'=>'id INT, user_id INT',
 'certificates'=>'id INT, placement_id INT, certificate_no VARCHAR(50), issued_at DATETIME',
 'placements'=>'id INT, ojt_enrollment_id INT, company_id INT',
 'ojt_enrollments'=>'id INT, student_id INT',
 'enrollment_coordinators'=>'ojt_enrollment_id INT, coordinator_id INT, ended_at DATETIME',
 'announcement_posts'=>'id INT, title VARCHAR(100), body TEXT, tag VARCHAR(50), target_role VARCHAR(20), created_by INT, is_pinned INT, created_at DATETIME',
 'announcement_attachments'=>'announcement_post_id INT, attachment_id INT',
 'attachments'=>'id INT, storage_key VARCHAR(100), original_filename VARCHAR(100)',
];
foreach ($tables as $name=>$columns) db()->query("CREATE TEMPORARY TABLE $name ($columns)");
db()->query("INSERT INTO users VALUES (1,'Alice','alice@example.invalid','student','archived','2026-10-01 23:59:59'),(2,'Bob',NULL,'coordinator','archived','2026-10-02 00:00:00'),(3,'Alice Active',NULL,'student','active','2026-10-01 12:00:00'),(4,'100%_Exact!',NULL,'company','archived','2026-10-01 00:00:00')");
db()->query("INSERT INTO students VALUES (1,1,'001234'),(2,2,'002345')");
db()->query("INSERT INTO companies VALUES (10,100),(20,200)");
db()->query("INSERT INTO coordinators VALUES (30,300),(40,400)");
db()->query("INSERT INTO certificates VALUES (1,1,'CERT-001','2026-10-01 23:59:59'),(2,2,'CERT-002','2026-10-02 00:00:00')");
db()->query("INSERT INTO placements VALUES (1,1,10),(2,2,20)");
db()->query("INSERT INTO ojt_enrollments VALUES (1,1),(2,2)");
db()->query("INSERT INTO enrollment_coordinators VALUES (1,30,NULL),(2,30,'2026-10-01'),(2,40,NULL)");
db()->query("INSERT INTO announcement_posts VALUES (1,'Welcome Alice','Orientation','Welcome','all',1,1,'2026-10-01'),(2,'Deadline','Submit now','Reminder','student',2,0,'2026-10-02'),(3,'100%_Exact!','Notice',NULL,'company',1,0,'2026-10-03')");
$checks=0;
function record_check($actual,$expected,$label) { global $checks; if ($actual!==$expected) throw new RuntimeException('FAIL: '.$label); $checks++; }
function archive_ids($input) {
    $f=archived_account_filters($input);
    return array_map('intval',array_column(query("SELECT id FROM users WHERE {$f['where']} ORDER BY id",$f['params'],$f['types']),'id'));
}
record_check(archive_ids([]),[1,2,4],'archive scope');
record_check(archive_ids(['q'=>'alice']),[1],'archive search excludes active');
record_check(archive_ids(['q'=>'example.invalid']),[1],'email search');
record_check(archive_ids(['role'=>'coordinator']),[2],'role filter');
record_check(archive_ids(['created'=>'2026-10-01']),[1,4],'inclusive creation day boundaries');
record_check(archive_ids(['q'=>'alice','role'=>'student','created'=>'2026-10-01']),[1],'combined archive filters');
record_check(archive_ids(['q'=>"' OR 1=1 --"]),[],'SQL injection stays text');
record_check(archive_ids(['q'=>'%_']),[4],'wildcards literal');
record_check(archive_ids(['q'=>[],'role'=>[],'created'=>[]]),[1,2,4],'array inputs safe');
record_check(archive_ids(['created'=>'2026-02-30']),[1,2,4],'invalid dates ignored');
function notice_ids($input) { return array_map('intval',array_column(normalized_announcements_admin(record_filter_text($input,'q'),$input),'id')); }
record_check(notice_ids([]),[1,3,2],'pin ordering preserved');
record_check(notice_ids(['q'=>'orientation']),[1],'body search');
record_check(notice_ids(['q'=>'Bob']),[2],'author search');
record_check(notice_ids(['tag'=>'Reminder']),[2],'existing custom categories');
record_check(notice_ids(['tag'=>'General']),[3],'null category fallback');
record_check(notice_ids(['audience'=>'all']),[1],'all roles distinct from any audience');
record_check(notice_ids(['q'=>'Deadline','tag'=>'Reminder','audience'=>'student']),[2],'combined announcement filters');
record_check(notice_ids(['q'=>'%_']),[3],'notice wildcard literal');
record_check(notice_ids(['tag'=>"' OR 1=1 --"]),[],'bound category');
record_check(notice_ids(['q'=>[],'tag'=>[],'audience'=>[]]),[1,3,2],'malformed notice filters');
// Execute the actual certificate list controller, preserving its role scope checks.
function require_login($roles) {}
function current_user() { return $GLOBALS['test_user']; }
$source=file_get_contents(__DIR__.'/../certificate.php');
$controller=substr($source,5,strpos($source,"    \$page_title='Certificates';")-5)."\n}";
$controller=preg_replace("~require_once __DIR__ \. '/config/(?:db|auth)\.php';~",'',$controller);
$controller=str_replace(["define('OJTRACK', true);","require_once __DIR__.'/config/record-filters.php';"],'',$controller);
function certificate_ids($input=[],$role='admin',$uid=999) {
    global $controller;
    $_GET=$input; $GLOBALS['test_user']=['id'=>$uid,'role'=>$role];
    eval($controller);
    return array_column($available,'certificate_no');
}
record_check(certificate_ids(),['CERT-002','CERT-001'],'all certificates listed');
record_check(certificate_ids(['q'=>'001234']),['CERT-001'],'student ID search');
record_check(certificate_ids(['q'=>'Alice']),['CERT-001'],'student name search');
record_check(certificate_ids(['q'=>'CERT-002']),['CERT-002'],'certificate number search');
record_check(certificate_ids(['from'=>'2026-10-01','to'=>'2026-10-01']),['CERT-001'],'end date includes entire day');
record_check(certificate_ids(['from'=>'2026-10-02']),['CERT-002'],'from date');
record_check(certificate_ids(['from'=>'2026-10-02','to'=>'2026-10-01']),[],'reversed date range shows no results');
record_check(certificate_ids(['q'=>"' OR 1=1 --"]),[],'certificate bound search');
record_check(certificate_ids(['q'=>[],'from'=>[],'to'=>[]]),['CERT-002','CERT-001'],'malformed certificate inputs');
record_check(certificate_ids([],'company',100),['CERT-001'],'company scope retained');
record_check(certificate_ids(['q'=>'CERT-002'],'company',100),[],'search cannot cross company scope');
record_check(certificate_ids([],'company',999),[],'missing company denied');
record_check(certificate_ids([],'coordinator',300),['CERT-001'],'current coordinator scope retained');
record_check(certificate_ids(['q'=>'CERT-002'],'coordinator',300),[],'ended assignment not exposed by search');
record_check(certificate_ids([],'coordinator',999),[],'missing coordinator denied');
record_check(record_filter_date(['from'=>"2026-10-01\0"],'from'),'2026-10-01','trimmed date normalized safely');
foreach (array_keys($tables) as $name) db()->query("DROP TEMPORARY TABLE $name");
echo json_encode(['record_filter_checks_passed'=>$checks,'application_records_changed'=>false,'result'=>'PASS']).PHP_EOL;
