<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

if (!query_one("SELECT 1 FROM schema_migrations WHERE version='006_evaluations'")) {
    exit("Migration 006 must be applied first.\n");
}

$logoDir=__DIR__.'/../uploads/cert_logos';
$annDir=__DIR__.'/../uploads/announcements';
if (!is_dir($logoDir)) mkdir($logoDir,0755,true);
if (!is_dir($annDir)) mkdir($annDir,0755,true);

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
file_put_contents($logoDir.'/phase7-logo.png',$png);
file_put_contents($annDir.'/phase7-announcement.png',$png);

$template=json_encode([
    'logo'=>'cert_logos/phase7-logo.png',
    'org_name'=>'Synthetic Company',
    'org_address'=>'Synthetic City',
    'cert_title'=>'Certificate of Completion',
    'body_text'=>'{student_name} completed {rendered_hours} at {company_name}.',
    'signatory_name'=>'Synthetic Supervisor',
    'signatory_title'=>'Training Supervisor',
    'footer_text'=>'Synthetic footer',
]);
$stmt=$conn->prepare("UPDATE companies SET location='Synthetic City',supervisor_name='Synthetic Supervisor',cert_template=? WHERE id=1");
$stmt->bind_param('s',$template);
$stmt->execute();
query("UPDATE companies SET location='Other City',supervisor_name='Other Supervisor',cert_template=NULL WHERE id=2");

query("UPDATE announcements
       SET attachment_file='announcements/phase7-announcement.png',
           attachment_name='Original Announcement.png'
       WHERE title='VISIBLE ADMIN'");

query("INSERT INTO announcements(title,body,tag,target_role,created_by,is_active,is_pinned,expires_at)
       VALUES
       ('COMPANY TO STUDENTS','Student network notice','Reminder','student',3,1,0,NULL),
       ('COMPANY TO COORDINATOR','Coordinator network notice','General','coordinator',3,1,1,NULL),
       ('INACTIVE GLOBAL','Inactive notice','General','all',1,0,0,NULL)");

echo "Synthetic phase 7 certificate/announcement fixtures created.\n";
