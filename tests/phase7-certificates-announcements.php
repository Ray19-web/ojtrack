<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p7check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p7count(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    return (int)($r['n'] ?? 0);
}

p7check(p7count("SELECT COUNT(*) n FROM companies")===2,'legacy companies preserved');
p7check(p7count("SELECT COUNT(*) n FROM announcements")===6,'legacy announcements preserved');
p7check(p7count("SELECT COUNT(*) n FROM schema_migrations WHERE version='007_certificates_and_announcements'")===1,'phase 7 recorded');

p7check(p7count("SELECT COUNT(*) n FROM certificate_templates")===2,'one effective certificate template per company');
p7check(p7count("SELECT COUNT(*) n FROM legacy_certificate_template_migration_map")===2,'all company certificate templates mapped');
p7check(p7count("SELECT COUNT(*) n FROM certificates")===0,'no certificate issuance history fabricated');
p7check(p7count("SELECT COUNT(*) n FROM certificate_templates WHERE logo_attachment_id IS NOT NULL")===1,'certificate logo linked');
p7check(p7count("SELECT COUNT(*) n FROM attachments WHERE storage_key='cert_logos/phase7-logo.png'")===1,'certificate logo registered centrally');

$cert1=query_one(
    "SELECT ct.template_body,a.detected_mime,a.sha256
     FROM legacy_certificate_template_migration_map m
     JOIN certificate_templates ct ON ct.id=m.certificate_template_id
     LEFT JOIN attachments a ON a.id=ct.logo_attachment_id
     WHERE m.company_id=1"
);
$t1=json_decode($cert1['template_body'] ?? '',true);
p7check(is_array($t1) && $t1['cert_title']==='Certificate of Completion','saved certificate template preserved');
p7check(($t1['logo'] ?? '')==='cert_logos/phase7-logo.png','certificate logo key preserved');
p7check($cert1 && $cert1['detected_mime']==='image/png' && strlen($cert1['sha256'])===64,'certificate logo MIME/hash recorded');

$cert2=query_one(
    "SELECT ct.template_body,ct.logo_attachment_id
     FROM legacy_certificate_template_migration_map m
     JOIN certificate_templates ct ON ct.id=m.certificate_template_id
     WHERE m.company_id=2"
);
$t2=json_decode($cert2['template_body'] ?? '',true);
p7check(is_array($t2) && $t2['org_name']==='Other Company','effective default certificate organization preserved');
p7check(($t2['signatory_name'] ?? '')==='Other Supervisor','effective default signatory preserved');
p7check($cert2 && $cert2['logo_attachment_id']===null,'default certificate has no fake logo');

p7check(p7count("SELECT COUNT(*) n FROM announcement_posts")===6,'all announcements migrated');
p7check(p7count("SELECT COUNT(*) n FROM legacy_announcement_migration_map")===6,'all announcements mapped');
p7check(p7count("SELECT COUNT(*) n FROM announcement_attachments")===1,'announcement attachment linked');
p7check(p7count("SELECT COUNT(*) n FROM attachments WHERE storage_key='announcements/phase7-announcement.png'")===1,'announcement attachment registered');

$annFile=query_one(
    "SELECT a.original_filename,a.detected_mime,a.sha256
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_attachments aa ON aa.announcement_post_id=m.announcement_post_id
     JOIN attachments a ON a.id=aa.attachment_id
     WHERE old.title='VISIBLE ADMIN'"
);
p7check($annFile && $annFile['original_filename']==='Original Announcement.png','announcement original filename preserved');
p7check($annFile && $annFile['detected_mime']==='image/png' && strlen($annFile['sha256'])===64,'announcement MIME/hash preserved');

$assigned=p7count(
    "SELECT COUNT(*) n
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_recipients ar ON ar.announcement_post_id=m.announcement_post_id
     WHERE old.title='VISIBLE ASSIGNED'"
);
p7check($assigned===3,'coordinator student audience snapshotted');

$hiddenOther=p7count(
    "SELECT COUNT(*) n
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_recipients ar ON ar.announcement_post_id=m.announcement_post_id
     WHERE old.title='HIDDEN OTHER'"
);
p7check($hiddenOther===1,'other coordinator audience snapshotted separately');

$adminGlobal=p7count(
    "SELECT COUNT(*) n
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_recipients ar ON ar.announcement_post_id=m.announcement_post_id
     WHERE old.title='VISIBLE ADMIN'"
);
p7check($adminGlobal===8,'admin all-role audience snapshotted');

$companyStudents=p7count(
    "SELECT COUNT(*) n
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_recipients ar ON ar.announcement_post_id=m.announcement_post_id
     WHERE old.title='COMPANY TO STUDENTS'"
);
p7check($companyStudents===2,'company trainee audience snapshotted');

$companyCoord=p7count(
    "SELECT COUNT(*) n
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_recipients ar ON ar.announcement_post_id=m.announcement_post_id
     WHERE old.title='COMPANY TO COORDINATOR'"
);
p7check($companyCoord===1,'company coordinator audience snapshotted');

$inactive=p7count(
    "SELECT COUNT(*) n
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_recipients ar ON ar.announcement_post_id=m.announcement_post_id
     WHERE old.title='INACTIVE GLOBAL'"
);
p7check($inactive===0,'inactive announcement has no current recipient snapshot');

p7check(p7count("SELECT COUNT(*) n FROM announcement_recipients")===15,'recipient snapshot total matches legacy visibility rules');

$post=query_one(
    "SELECT ap.title,ap.body,ap.tag,ap.target_role,ap.author_role_snapshot,ap.is_pinned
     FROM legacy_announcement_migration_map m
     JOIN announcements old ON old.id=m.legacy_announcement_id
     JOIN announcement_posts ap ON ap.id=m.announcement_post_id
     WHERE old.title='COMPANY TO COORDINATOR'"
);
p7check($post && $post['target_role']==='coordinator' && $post['author_role_snapshot']==='company','announcement target/author snapshots preserved');
p7check($post && (int)$post['is_pinned']===1,'announcement pin state preserved');

p7check(
    p7count("SELECT COUNT(*) n FROM companies WHERE id=1 AND cert_template IS NOT NULL")===1,
    'legacy company cert_template unchanged'
);
p7check(
    p7count("SELECT COUNT(*) n FROM announcements WHERE attachment_file='announcements/phase7-announcement.png'")===1,
    'legacy announcement attachment path unchanged'
);

echo json_encode(['phase7_certificate_announcement_checks_passed'=>$checks,'result'=>'PASS']) . PHP_EOL;
