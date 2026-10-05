<?php
/**
 * OJTrack normalized migration 007: certificate templates + announcements.
 *
 * - requires migrations 001-006
 * - creates one effective normalized certificate template per legacy company
 * - registers certificate logos in centralized attachment metadata
 * - does NOT invent issued certificate records because legacy OJTrack did not persist issuance
 * - migrates announcements into collision-safe announcement_posts
 * - materializes current legacy visibility into announcement_recipients
 * - registers legacy announcement files in centralized attachment metadata
 * - never changes/deletes legacy companies.cert_template, announcements, or files
 *
 * Usage:
 * php bin/migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase7-certificates-announcements.php --academic-year=2026-2027 --semester=1st --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$options=getopt('', ['academic-year:','semester:','apply','dry-run']);
$academicYear=trim((string)($options['academic-year'] ?? ''));
$semesterInput=strtolower(trim((string)($options['semester'] ?? '')));
$apply=array_key_exists('apply',$options);
$dryRun=array_key_exists('dry-run',$options) || !$apply;

if ($apply && array_key_exists('dry-run',$options)) {
    fwrite(STDERR,"Choose either --apply or --dry-run, not both.\n");
    exit(2);
}
if (!preg_match('/^(\d{4})-(\d{4})$/',$academicYear,$m)) {
    fwrite(STDERR,"Use --academic-year=YYYY-YYYY.\n");
    exit(2);
}
$yearStart=(int)$m[1];
$yearEnd=(int)$m[2];
if ($yearEnd!==$yearStart+1) {
    fwrite(STDERR,"Academic year end must be exactly one year after the start.\n");
    exit(2);
}
$aliases=['1'=>'1st','1st'=>'1st','first'=>'1st','2'=>'2nd','2nd'=>'2nd','second'=>'2nd','summer'=>'summer'];
if (!isset($aliases[$semesterInput])) {
    fwrite(STDERR,"Use --semester=1st, --semester=2nd, or --semester=summer.\n");
    exit(2);
}
$semester=$aliases[$semesterInput];

require __DIR__ . '/../config/db.php';

function p7_scalar(string $sql,array $params=[],string $types=''): int {
    $row=query_one($sql,$params,$types);
    if (!$row) return 0;
    return (int)reset($row);
}
function p7_ddl(mysqli $conn,string $path): void {
    $sql=file_get_contents($path);
    if ($sql===false || trim($sql)==='') throw new RuntimeException("Missing migration DDL: $path");
    if (!$conn->multi_query($sql)) throw new RuntimeException('DDL failed: '.$conn->error);
    do {
        if ($r=$conn->store_result()) $r->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('DDL failed: '.$conn->error);
}
function p7_hash(array $rows): string {
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
}
function p7_within(string $path,string $root): bool {
    $path=str_replace('\\','/',$path);
    $root=rtrim(str_replace('\\','/',$root),'/');
    if (PHP_OS_FAMILY==='Windows') { $path=strtolower($path); $root=strtolower($root); }
    return $path===$root || str_starts_with($path,$root.'/');
}
function p7_public_file(string $relative): ?string {
    if (!preg_match('~^(announcements|cert_logos)/[A-Za-z0-9][A-Za-z0-9_.-]*$~D',$relative)) return null;
    $root=realpath(__DIR__.'/../uploads');
    if (!$root) return null;
    $candidate=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    if (is_link(dirname($candidate)) || is_link($candidate) || !file_exists($candidate)) return null;
    $real=realpath($candidate);
    if (!$real || !p7_within($real,$root) || !is_file($real) || !is_readable($real)) return null;
    return $real;
}
function p7_file_meta(string $relative,int $uploadedBy,?string $originalName=null): ?array {
    $path=p7_public_file($relative);
    if (!$path) return null;
    $size=filesize($path);
    $sha=hash_file('sha256',$path);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($size===false || $sha===false || !$mime) return null;
    return [
        'storage_key'=>$relative,
        'original_filename'=>$originalName ?: basename($relative),
        'detected_mime'=>(string)$mime,
        'size_bytes'=>(int)$size,
        'sha256'=>$sha,
        'uploaded_by'=>$uploadedBy,
    ];
}
function p7_effective_template(array $company): ?array {
    $defaults=[
        'logo'=>'',
        'org_name'=>$company['company_name'] ?? '',
        'org_address'=>$company['location'] ?? '',
        'cert_title'=>'Certificate of Recognition',
        'body_text'=>'This is to certify that {student_name} of {program} has successfully completed the required OJT training hours at {company_name}, with a total of {rendered_hours} rendered hours.',
        'signatory_name'=>$company['supervisor_name'] ?? '',
        'signatory_title'=>'Training Supervisor',
        'footer_text'=>'In recognition of dedication, commitment, and performance during the On-the-Job Training program.',
    ];
    if (empty($company['cert_template'])) return $defaults;
    $saved=json_decode((string)$company['cert_template'],true);
    if (!is_array($saved)) return null;
    foreach ($saved as $key=>$value) {
        if (array_key_exists($key,$defaults) && !is_scalar($value) && $value!==null) return null;
    }
    foreach ($defaults as $key=>$value) {
        if (array_key_exists($key,$saved) && $saved[$key]!==null) $defaults[$key]=(string)$saved[$key];
    }
    return $defaults;
}
function p7_recipients(array $ann,array $creator,string $today): array {
    if ((int)$ann['is_active']!==1) return [];
    if (!empty($ann['expires_at']) && $ann['expires_at']<$today) return [];

    $target=$ann['target_role'];
    $creatorId=(int)$ann['created_by'];
    $creatorRole=$creator['role'];
    $out=[];

    if (in_array($target,['all','student'],true)) {
        $students=query(
            "SELECT u.id user_id,u.role
             FROM users u
             JOIN students s ON s.user_id=u.id
             LEFT JOIN coordinators c ON c.id=s.coordinator_id
             LEFT JOIN companies co ON co.id=s.company_id
             WHERE u.status='active'
               AND (?='admin' OR c.user_id=? OR co.user_id=?)
             ORDER BY u.id",
            [$creatorRole,$creatorId,$creatorId],
            'sii'
        ) ?: [];
        foreach ($students as $u) {
            $out[(int)$u['user_id']]=[
                'user_id'=>(int)$u['user_id'],
                'role'=>'student',
                'basis'=>'legacy_student_visibility_at_migration',
            ];
        }
    }

    if (in_array($target,['all','coordinator'],true)) {
        if ($creatorRole==='admin') {
            $coords=query("SELECT id user_id,role FROM users WHERE role='coordinator' AND status='active' ORDER BY id") ?: [];
        } else {
            $coords=query(
                "SELECT DISTINCT cu.id user_id,cu.role
                 FROM users cu
                 JOIN coordinators c ON c.user_id=cu.id
                 JOIN students s ON s.coordinator_id=c.id
                 JOIN companies co ON co.id=s.company_id
                 WHERE cu.status='active' AND co.user_id=?
                 ORDER BY cu.id",
                [$creatorId],
                'i'
            ) ?: [];
        }
        foreach ($coords as $u) {
            $out[(int)$u['user_id']]=[
                'user_id'=>(int)$u['user_id'],
                'role'=>'coordinator',
                'basis'=>'legacy_coordinator_visibility_at_migration',
            ];
        }
    }

    if (in_array($target,['all','company'],true)) {
        $companies=query("SELECT id user_id,role FROM users WHERE role='company' AND status='active' ORDER BY id") ?: [];
        foreach ($companies as $u) {
            $out[(int)$u['user_id']]=[
                'user_id'=>(int)$u['user_id'],
                'role'=>'company',
                'basis'=>'legacy_company_page_visibility_at_migration',
            ];
        }
    }

    ksort($out);
    return array_values($out);
}

foreach ([
    '001_core_identity_and_terms',
    '002_attendance',
    '003_journals_and_attachments',
    '004_requirements',
    '005_reports',
    '006_evaluations'
] as $required) {
    if (!query_one("SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1",[$required],'s')) {
        echo json_encode([
            'migration'=>'007_certificates_and_announcements',
            'mode'=>$dryRun?'dry-run':'apply',
            'result'=>'BLOCKED',
            'preflight_problems'=>['missing_required_migration'=>$required],
        ],JSON_PRETTY_PRINT).PHP_EOL;
        exit(1);
    }
}

$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='007_certificates_and_announcements' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'007_certificates_and_announcements',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'ALREADY_APPLIED',
        'applied_at'=>$already['applied_at'],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

$term=query_one(
    "SELECT id FROM academic_terms
     WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",
    [$yearStart,$yearEnd,$semester],
    'iis'
);
if (!$term) {
    echo json_encode([
        'migration'=>'007_certificates_and_announcements',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$termId=(int)$term['id'];

$today=(query_one("SELECT CURDATE() today")['today'] ?? date('Y-m-d'));

$companies=query(
    "SELECT c.id,c.user_id,c.company_name,c.supervisor_name,c.location,c.contact_number,c.status,c.cert_template,
            u.role user_role,u.status user_status
     FROM companies c
     LEFT JOIN users u ON u.id=c.user_id
     ORDER BY c.id"
) ?: [];
$announcements=query(
    "SELECT a.*,u.role author_role,u.status author_status
     FROM announcements a
     LEFT JOIN users u ON u.id=a.created_by
     ORDER BY a.id"
) ?: [];

$sourceHash=p7_hash(['companies'=>$companies,'announcements'=>$announcements]);

$invalidCompanies=[];
$invalidTemplates=[];
$missingCertificateLogos=[];
$certificateTemplates=[];
$certificateLogoMetadata=[];

foreach ($companies as $company) {
    if (empty($company['user_id']) || $company['user_role']!=='company') {
        $invalidCompanies[]=[
            'company_id'=>(int)$company['id'],
            'company_name'=>$company['company_name'],
            'reason'=>'company_primary_user_missing_or_wrong_role',
        ];
        continue;
    }

    $effective=p7_effective_template($company);
    if ($effective===null) {
        $invalidTemplates[]=[
            'company_id'=>(int)$company['id'],
            'company_name'=>$company['company_name'],
            'reason'=>'invalid_cert_template_json',
        ];
        continue;
    }

    if (!empty($effective['logo'])) {
        $meta=p7_file_meta($effective['logo'],(int)$company['user_id'],basename($effective['logo']));
        if (!$meta) {
            $missingCertificateLogos[]=[
                'company_id'=>(int)$company['id'],
                'company_name'=>$company['company_name'],
                'logo'=>$effective['logo'],
            ];
        } else {
            $certificateLogoMetadata[(int)$company['id']]=$meta;
        }
    }

    $certificateTemplates[(int)$company['id']]=$effective;
}

$invalidAnnouncements=[];
$missingAnnouncementFiles=[];
$announcementFileMetadata=[];
$recipientSnapshot=[];
$totalRecipients=0;

foreach ($announcements as $ann) {
    if (empty($ann['created_by']) || !in_array($ann['author_role'],['admin','coordinator','company'],true)) {
        $invalidAnnouncements[]=[
            'announcement_id'=>(int)$ann['id'],
            'reason'=>'unsupported_or_missing_author',
            'created_by'=>$ann['created_by'],
            'author_role'=>$ann['author_role'],
        ];
        continue;
    }
    if (!in_array($ann['target_role'],['all','student','coordinator','company','admin'],true)) {
        $invalidAnnouncements[]=[
            'announcement_id'=>(int)$ann['id'],
            'reason'=>'invalid_target_role',
            'target_role'=>$ann['target_role'],
        ];
        continue;
    }

    if (!empty($ann['attachment_file'])) {
        $meta=p7_file_meta(
            (string)$ann['attachment_file'],
            (int)$ann['created_by'],
            !empty($ann['attachment_name']) ? (string)$ann['attachment_name'] : basename((string)$ann['attachment_file'])
        );
        if (!$meta) {
            $missingAnnouncementFiles[]=[
                'announcement_id'=>(int)$ann['id'],
                'attachment_file'=>$ann['attachment_file'],
            ];
        } else {
            $announcementFileMetadata[(int)$ann['id']]=$meta;
        }
    }

    $creator=['role'=>$ann['author_role']];
    $recipients=p7_recipients($ann,$creator,$today);
    $recipientSnapshot[(int)$ann['id']]=$recipients;
    $totalRecipients+=count($recipients);
}

$problems=[];
if ($invalidCompanies) $problems['invalid_company_certificate_owner']=count($invalidCompanies);
if ($invalidTemplates) $problems['invalid_certificate_template_json']=count($invalidTemplates);
if ($missingCertificateLogos) $problems['missing_or_unreadable_certificate_logos']=count($missingCertificateLogos);
if ($invalidAnnouncements) $problems['invalid_announcement_rows']=count($invalidAnnouncements);
if ($missingAnnouncementFiles) $problems['missing_or_unreadable_announcement_files']=count($missingAnnouncementFiles);

$completedPlacements=p7_scalar(
    "SELECT COUNT(*)
     FROM placements p
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.academic_term_id=? AND (p.status='completed' OR oe.status='completed')",
    [$termId],
    'i'
);
$announcementFiles=count(array_filter($announcements,fn($a)=>!empty($a['attachment_file'])));
$certificateLogos=count(array_filter($certificateTemplates,fn($t)=>!empty($t['logo'])));

$summary=[
    'migration'=>'007_certificates_and_announcements',
    'mode'=>$dryRun?'dry-run':'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy'=>[
        'companies'=>count($companies),
        'company_certificate_templates_effective'=>count($certificateTemplates),
        'certificate_logos_referenced'=>$certificateLogos,
        'historical_certificate_issue_records'=>0,
        'completed_placements_without_persisted_issue_history'=>$completedPlacements,
        'announcements'=>count($announcements),
        'announcement_files_referenced'=>$announcementFiles,
        'recipient_snapshot_rows'=>$totalRecipients,
    ],
    'preflight_problems'=>$problems,
];

if ($invalidCompanies) $summary['invalid_companies']=$invalidCompanies;
if ($invalidTemplates) $summary['invalid_certificate_templates']=$invalidTemplates;
if ($missingCertificateLogos) $summary['missing_certificate_logos']=$missingCertificateLogos;
if ($invalidAnnouncements) $summary['invalid_announcements']=$invalidAnnouncements;
if ($missingAnnouncementFiles) $summary['missing_announcement_files']=$missingAnnouncementFiles;

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='No certificate/announcement data was changed. Resolve the listed issues first.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Preflight passed. Re-run with --apply to normalize certificate templates and announcements.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

try {
    p7_ddl($conn,__DIR__.'/../database/migrations/007_certificates_and_announcements.sql');

    foreach ([
        'legacy_certificate_template_migration_map',
        'legacy_announcement_migration_map'
    ] as $mapTable) {
        if (p7_scalar("SELECT COUNT(*) FROM $mapTable")>0) {
            throw new RuntimeException("$mapTable already contains rows but migration 007 is not recorded.");
        }
    }
    if (p7_scalar("SELECT COUNT(*) FROM certificates")>0) {
        throw new RuntimeException('certificates already contains rows but no legacy issuance source exists.');
    }

    $conn->begin_transaction();

    $certificateTemplatesCreated=0;
    $certificateLogoLinks=0;
    $announcementPostsCreated=0;
    $announcementAttachmentLinks=0;
    $announcementRecipientsCreated=0;

    $ensureAttachment=function(array $meta) {
        $existing=query_one(
            "SELECT id,sha256,size_bytes,uploaded_by FROM attachments WHERE storage_key=? LIMIT 1",
            [$meta['storage_key']],
            's'
        );
        if ($existing) {
            if (!hash_equals((string)$existing['sha256'],$meta['sha256']) ||
                (int)$existing['size_bytes']!==$meta['size_bytes']) {
                throw new RuntimeException('Attachment storage key collision: '.$meta['storage_key']);
            }
            return (int)$existing['id'];
        }
        return insert(
            "INSERT INTO attachments
             (storage_key,original_filename,detected_mime,size_bytes,sha256,uploaded_by)
             VALUES(?,?,?,?,?,?)",
            [
                $meta['storage_key'],$meta['original_filename'],$meta['detected_mime'],
                $meta['size_bytes'],$meta['sha256'],$meta['uploaded_by']
            ],
            'sssisi'
        );
    };

    foreach ($companies as $company) {
        $cid=(int)$company['id'];
        $template=$certificateTemplates[$cid];
        $logoAttachmentId=null;
        if (isset($certificateLogoMetadata[$cid])) {
            $logoAttachmentId=$ensureAttachment($certificateLogoMetadata[$cid]);
            $certificateLogoLinks++;
        }

        $templateJson=json_encode($template,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $active=$company['status']==='active' ? 1 : 0;
        $createdBy=(int)$company['user_id'];

        $stmt=$conn->prepare(
            "INSERT INTO certificate_templates
             (company_id,name,template_body,logo_attachment_id,is_active,created_by,created_at,updated_at)
             VALUES(?,'Migrated effective certificate template',?,?,?,?,NULL,NULL)"
        );
        $stmt->bind_param('isiii',$cid,$templateJson,$logoAttachmentId,$active,$createdBy);
        $stmt->execute();
        $templateId=(int)$conn->insert_id;
        $certificateTemplatesCreated++;

        insert(
            "INSERT INTO legacy_certificate_template_migration_map(company_id,certificate_template_id)
             VALUES(?,?)",
            [$cid,$templateId],
            'ii'
        );
    }

    foreach ($announcements as $ann) {
        $stmt=$conn->prepare(
            "INSERT INTO announcement_posts
             (title,body,tag,target_role,created_by,author_role_snapshot,is_active,is_pinned,expires_at,created_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)"
        );
        $active=(int)$ann['is_active'];
        $pinned=(int)$ann['is_pinned'];
        $creator=(int)$ann['created_by'];
        $stmt->bind_param(
            'ssssisiiss',
            $ann['title'],$ann['body'],$ann['tag'],$ann['target_role'],$creator,
            $ann['author_role'],$active,$pinned,$ann['expires_at'],$ann['created_at']
        );
        $stmt->execute();
        $postId=(int)$conn->insert_id;
        $announcementPostsCreated++;

        $legacyId=(int)$ann['id'];
        insert(
            "INSERT INTO legacy_announcement_migration_map(legacy_announcement_id,announcement_post_id)
             VALUES(?,?)",
            [$legacyId,$postId],
            'ii'
        );

        if (isset($announcementFileMetadata[$legacyId])) {
            $attachmentId=$ensureAttachment($announcementFileMetadata[$legacyId]);
            insert(
                "INSERT INTO announcement_attachments(announcement_post_id,attachment_id) VALUES(?,?)",
                [$postId,$attachmentId],
                'ii'
            );
            $announcementAttachmentLinks++;
        }

        foreach ($recipientSnapshot[$legacyId] ?? [] as $recipient) {
            insert(
                "INSERT INTO announcement_recipients
                 (announcement_post_id,user_id,role_snapshot,snapshot_basis)
                 VALUES(?,?,?,?)",
                [$postId,$recipient['user_id'],$recipient['role'],$recipient['basis']],
                'iiss'
            );
            $announcementRecipientsCreated++;
        }
    }

    $verification=[
        'certificate_template_map_mismatch'=>abs(
            count($companies)-p7_scalar("SELECT COUNT(*) FROM legacy_certificate_template_migration_map")
        ),
        'certificate_template_count_mismatch'=>abs(count($companies)-$certificateTemplatesCreated),
        'certificate_logo_link_mismatch'=>abs(
            $certificateLogos-p7_scalar("SELECT COUNT(*) FROM certificate_templates WHERE logo_attachment_id IS NOT NULL")
        ),
        'fabricated_certificate_issue_records'=>p7_scalar("SELECT COUNT(*) FROM certificates"),
        'announcement_map_mismatch'=>abs(
            count($announcements)-p7_scalar("SELECT COUNT(*) FROM legacy_announcement_migration_map")
        ),
        'announcement_post_count_mismatch'=>abs(count($announcements)-$announcementPostsCreated),
        'announcement_attachment_link_mismatch'=>abs(
            $announcementFiles-p7_scalar("SELECT COUNT(*) FROM announcement_attachments")
        ),
        'announcement_recipient_snapshot_mismatch'=>abs(
            $totalRecipients-p7_scalar("SELECT COUNT(*) FROM announcement_recipients")
        ),
        'announcement_field_mismatches'=>0,
        'certificate_template_mismatches'=>0,
    ];

    foreach ($companies as $company) {
        $cid=(int)$company['id'];
        $mapped=query_one(
            "SELECT ct.template_body,ct.is_active
             FROM legacy_certificate_template_migration_map m
             JOIN certificate_templates ct ON ct.id=m.certificate_template_id
             WHERE m.company_id=?",
            [$cid],
            'i'
        );
        $expected=json_encode($certificateTemplates[$cid],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $expectedActive=$company['status']==='active' ? 1 : 0;
        if (!$mapped || $mapped['template_body']!==$expected || (int)$mapped['is_active']!==$expectedActive) {
            $verification['certificate_template_mismatches']++;
        }
    }

    foreach ($announcements as $ann) {
        $mapped=query_one(
            "SELECT ap.*
             FROM legacy_announcement_migration_map m
             JOIN announcement_posts ap ON ap.id=m.announcement_post_id
             WHERE m.legacy_announcement_id=?",
            [(int)$ann['id']],
            'i'
        );
        if (!$mapped ||
            $mapped['title']!==$ann['title'] ||
            $mapped['body']!==$ann['body'] ||
            ($mapped['tag'] ?? null)!==($ann['tag'] ?? null) ||
            $mapped['target_role']!==$ann['target_role'] ||
            (int)$mapped['created_by']!==(int)$ann['created_by'] ||
            (int)$mapped['is_active']!==(int)$ann['is_active'] ||
            (int)$mapped['is_pinned']!==(int)$ann['is_pinned'] ||
            ($mapped['expires_at'] ?? null)!==($ann['expires_at'] ?? null) ||
            $mapped['created_at']!==$ann['created_at']) {
            $verification['announcement_field_mismatches']++;
        }
    }

    foreach ($verification as $label=>$count) {
        if ($count!==0) throw new RuntimeException("Verification failed: $label=$count");
    }

    $afterCompanies=query(
        "SELECT c.id,c.user_id,c.company_name,c.supervisor_name,c.location,c.contact_number,c.status,c.cert_template,
                u.role user_role,u.status user_status
         FROM companies c LEFT JOIN users u ON u.id=c.user_id ORDER BY c.id"
    ) ?: [];
    $afterAnnouncements=query(
        "SELECT a.*,u.role author_role,u.status author_status
         FROM announcements a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.id"
    ) ?: [];
    if (p7_hash(['companies'=>$afterCompanies,'announcements'=>$afterAnnouncements])!==$sourceHash) {
        throw new RuntimeException('Legacy certificate template or announcement source data changed during migration.');
    }

    $version='007_certificates_and_announcements';
    $description='Normalize effective certificate templates, public asset metadata, announcements and migration-time recipient snapshots';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    $conn->commit();

    $summary['result']='PASS';
    $summary['normalized']=[
        'certificate_templates'=>$certificateTemplatesCreated,
        'certificate_logo_attachments'=>$certificateLogoLinks,
        'certificates_issued'=>0,
        'announcement_posts'=>$announcementPostsCreated,
        'announcement_attachment_links'=>$announcementAttachmentLinks,
        'announcement_recipient_snapshots'=>$announcementRecipientsCreated,
    ];
    $summary['verification']=$verification;
    $summary['legacy_certificate_templates_preserved']=true;
    $summary['legacy_announcements_preserved']=true;
    $summary['public_files_moved']=false;
    $summary['certificate_issue_history_note']='Legacy OJTrack rendered certificates on demand and did not persist issuance events, so no historical issued-certificate rows were fabricated.';
    $summary['announcement_recipient_snapshot_basis']='Current legacy visibility rules and current assignments at migration time; not a claim about original historical recipients.';

    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR,"Migration 007 failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
