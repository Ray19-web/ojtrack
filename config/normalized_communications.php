<?php

function normalized_public_upload_meta(string $storageKey,string $originalFilename,int $uploadedBy): array
{
    if (!preg_match('~^(announcements|cert_logos)/[A-Za-z0-9][A-Za-z0-9_.-]*$~D',$storageKey)) {
        throw new DomainException('Invalid public asset path.');
    }
    $root=realpath(__DIR__.'/../uploads');
    if (!$root) throw new DomainException('Public upload directory is unavailable.');
    $candidate=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$storageKey);
    if (is_link(dirname($candidate)) || is_link($candidate) || !file_exists($candidate)) {
        throw new DomainException('Public asset could not be found.');
    }
    $path=realpath($candidate);
    $normalizedRoot=rtrim(str_replace('\\','/',$root),'/').'/';
    $normalizedPath=str_replace('\\','/',$path ?: '');
    if (!$path || !str_starts_with($normalizedPath,$normalizedRoot) || !is_file($path) || !is_readable($path)) {
        throw new DomainException('Public asset is invalid or unreadable.');
    }
    $size=filesize($path);
    $sha=hash_file('sha256',$path);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($size===false || $sha===false || !$mime) throw new DomainException('Public asset metadata could not be read.');
    return [
        'storage_key'=>$storageKey,
        'original_filename'=>$originalFilename ?: basename($storageKey),
        'detected_mime'=>(string)$mime,
        'size_bytes'=>(int)$size,
        'sha256'=>$sha,
        'uploaded_by'=>$uploadedBy,
    ];
}

function normalized_register_public_attachment(string $storageKey,string $originalFilename,int $uploadedBy): int
{
    $meta=normalized_public_upload_meta($storageKey,$originalFilename,$uploadedBy);
    $existing=query_one(
        "SELECT id,sha256,size_bytes FROM attachments WHERE storage_key=? LIMIT 1",
        [$storageKey],
        's'
    );
    if ($existing) {
        if (!hash_equals((string)$existing['sha256'],$meta['sha256']) ||
            (int)$existing['size_bytes']!==$meta['size_bytes']) {
            throw new DomainException('Public asset storage key collision detected.');
        }
        return (int)$existing['id'];
    }
    return (int)insert(
        "INSERT INTO attachments(storage_key,original_filename,detected_mime,size_bytes,sha256,uploaded_by)
         VALUES(?,?,?,?,?,?)",
        [
            $meta['storage_key'],$meta['original_filename'],$meta['detected_mime'],
            $meta['size_bytes'],$meta['sha256'],$meta['uploaded_by']
        ],
        'sssisi'
    );
}

function normalized_announcement_recipient_rows(int $creatorUserId,string $targetRole): array
{
    $creator=query_one("SELECT id,role FROM users WHERE id=? AND status='active'",[$creatorUserId],'i');
    if (!$creator) throw new DomainException('Announcement author is not active.');
    $role=$creator['role'];
    $termId=normalized_active_term_id();
    $rows=[];

    $add=function(array $userRows,string $basis) use (&$rows) {
        foreach ($userRows as $row) {
            $uid=(int)$row['id'];
            if ($uid<=0) continue;
            $rows[$uid]=[
                'user_id'=>$uid,
                'role'=>$row['role'],
                'basis'=>$basis,
            ];
        }
    };

    if ($role==='admin') {
        if ($targetRole==='all') {
            $add(query("SELECT id,role FROM users WHERE status='active' AND role IN ('student','coordinator','company') ORDER BY id") ?: [],'admin_role_scope');
        } elseif (in_array($targetRole,['student','coordinator','company'],true)) {
            $add(query("SELECT id,role FROM users WHERE status='active' AND role=? ORDER BY id",[$targetRole],'s') ?: [],'admin_role_scope');
        }
    } elseif ($role==='coordinator') {
        $coord=query_one("SELECT id FROM coordinators WHERE user_id=?",[$creatorUserId],'i');
        if ($coord && in_array($targetRole,['student','all'],true)) {
            $add(query(
                "SELECT DISTINCT u.id,u.role
                 FROM ojt_enrollments oe
                 JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
                 JOIN students s ON s.id=oe.student_id
                 JOIN users u ON u.id=s.user_id
                 WHERE oe.academic_term_id=? AND ec.coordinator_id=? AND u.status='active'
                 ORDER BY u.id",
                [$termId,(int)$coord['id']],
                'ii'
            ) ?: [],'coordinator_current_enrollment_scope');
        }
    } elseif ($role==='company') {
        $company=query_one("SELECT id FROM companies WHERE user_id=?",[$creatorUserId],'i');
        if ($company) {
            if (in_array($targetRole,['student','all'],true)) {
                $add(query(
                    "SELECT DISTINCT u.id,u.role
                     FROM placements p
                     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
                     JOIN students s ON s.id=oe.student_id
                     JOIN users u ON u.id=s.user_id
                     WHERE oe.academic_term_id=? AND p.company_id=?
                       AND p.status IN ('active','on_hold','planned','completed')
                       AND u.status='active'
                     ORDER BY u.id",
                    [$termId,(int)$company['id']],
                    'ii'
                ) ?: [],'company_current_placement_scope');
            }
            if (in_array($targetRole,['coordinator','all'],true)) {
                $add(query(
                    "SELECT DISTINCT u.id,u.role
                     FROM placements p
                     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
                     JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
                     JOIN coordinators c ON c.id=ec.coordinator_id
                     JOIN users u ON u.id=c.user_id
                     WHERE oe.academic_term_id=? AND p.company_id=?
                       AND p.status IN ('active','on_hold','planned','completed')
                       AND u.status='active'
                     ORDER BY u.id",
                    [$termId,(int)$company['id']],
                    'ii'
                ) ?: [],'company_current_coordinator_scope');
            }
        }
    }

    unset($rows[$creatorUserId]);
    ksort($rows);
    return array_values($rows);
}

function normalized_announcement_materialize_recipients(int $postId,int $creatorUserId,string $targetRole): int
{
    query("DELETE FROM announcement_recipients WHERE announcement_post_id=?",[$postId],'i');
    $count=0;
    foreach (normalized_announcement_recipient_rows($creatorUserId,$targetRole) as $recipient) {
        insert(
            "INSERT INTO announcement_recipients(announcement_post_id,user_id,role_snapshot,snapshot_basis)
             VALUES(?,?,?,?)",
            [$postId,$recipient['user_id'],$recipient['role'],$recipient['basis']],
            'iiss'
        );
        $count++;
    }
    return $count;
}

function normalized_announcement_create(
    int $creatorUserId,string $title,string $body,string $tag,string $targetRole,
    bool $pinned=false,?string $expiresAt=null,?string $attachmentPath=null,?string $attachmentName=null
): int {
    $author=query_one("SELECT role FROM users WHERE id=? AND status='active'",[$creatorUserId],'i');
    if (!$author || !in_array($author['role'],['admin','coordinator','company'],true)) {
        throw new DomainException('You cannot create announcements.');
    }
    if (!in_array($targetRole,['all','student','coordinator','company'],true)) throw new DomainException('Invalid announcement audience.');

    db()->begin_transaction();
    try {
        $postId=(int)insert(
            "INSERT INTO announcement_posts
             (title,body,tag,target_role,created_by,author_role_snapshot,is_active,is_pinned,expires_at,created_at)
             VALUES(?,?,?,?,?,?,1,?,?,NOW())",
            [$title,$body,$tag ?: 'General',$targetRole,$creatorUserId,$author['role'],$pinned?1:0,$expiresAt],
            'ssssisi'
        );
        if ($attachmentPath) {
            $attachmentId=normalized_register_public_attachment(
                $attachmentPath,$attachmentName ?: basename($attachmentPath),$creatorUserId
            );
            insert(
                "INSERT INTO announcement_attachments(announcement_post_id,attachment_id) VALUES(?,?)",
                [$postId,$attachmentId],
                'ii'
            );
        }
        normalized_announcement_materialize_recipients($postId,$creatorUserId,$targetRole);
        db()->commit();
        return $postId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_announcement_update(
    int $postId,int $actorUserId,string $title,string $body,string $tag,string $targetRole,
    bool $pinned,?string $expiresAt,?string $attachmentPath=null,?string $attachmentName=null,bool $removeAttachment=false
): bool {
    $actor=query_one("SELECT role FROM users WHERE id=? AND status='active'",[$actorUserId],'i');
    if (!$actor) return false;
    $where=$actor['role']==='admin' ? 'id=?' : 'id=? AND created_by=?';
    $params=$actor['role']==='admin' ? [$postId] : [$postId,$actorUserId];
    $types=$actor['role']==='admin' ? 'i' : 'ii';
    $existing=query_one("SELECT * FROM announcement_posts WHERE $where LIMIT 1",$params,$types);
    if (!$existing) return false;

    db()->begin_transaction();
    try {
        query(
            "UPDATE announcement_posts
             SET title=?,body=?,tag=?,target_role=?,is_pinned=?,expires_at=?
             WHERE id=?",
            [$title,$body,$tag ?: 'General',$targetRole,$pinned?1:0,$expiresAt,$postId],
            'ssssisi'
        );
        if ($removeAttachment) {
            query("DELETE FROM announcement_attachments WHERE announcement_post_id=?",[$postId],'i');
        }
        if ($attachmentPath) {
            $attachmentId=normalized_register_public_attachment(
                $attachmentPath,$attachmentName ?: basename($attachmentPath),$actorUserId
            );
            query("DELETE FROM announcement_attachments WHERE announcement_post_id=?",[$postId],'i');
            insert(
                "INSERT INTO announcement_attachments(announcement_post_id,attachment_id) VALUES(?,?)",
                [$postId,$attachmentId],
                'ii'
            );
        }
        normalized_announcement_materialize_recipients($postId,(int)$existing['created_by'],$targetRole);
        db()->commit();
        return true;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_announcement_soft_delete(int $postId,int $actorUserId): bool
{
    $actor=query_one("SELECT role FROM users WHERE id=?",[$actorUserId],'i');
    if (!$actor) return false;
    if ($actor['role']==='admin') {
        query("UPDATE announcement_posts SET is_active=0 WHERE id=?",[$postId],'i');
        return true;
    }
    query("UPDATE announcement_posts SET is_active=0 WHERE id=? AND created_by=?",[$postId,$actorUserId],'ii');
    return true;
}

function normalized_announcement_toggle_pin(int $postId,int $actorUserId): bool
{
    $actor=query_one("SELECT role FROM users WHERE id=?",[$actorUserId],'i');
    if (!$actor) return false;
    $post=$actor['role']==='admin'
        ? query_one("SELECT id,is_pinned FROM announcement_posts WHERE id=?",[$postId],'i')
        : query_one("SELECT id,is_pinned FROM announcement_posts WHERE id=? AND created_by=?",[$postId,$actorUserId],'ii');
    if (!$post) return false;
    query("UPDATE announcement_posts SET is_pinned=? WHERE id=?",[(int)!$post['is_pinned'],$postId],'ii');
    return true;
}

function normalized_announcement_select_sql(): string
{
    return "SELECT ap.*,u.name author,u.name author_name,u.role author_role,
                   (SELECT a.storage_key FROM announcement_attachments aa
                    JOIN attachments a ON a.id=aa.attachment_id
                    WHERE aa.announcement_post_id=ap.id ORDER BY a.id LIMIT 1) attachment_file,
                   (SELECT a.original_filename FROM announcement_attachments aa
                    JOIN attachments a ON a.id=aa.attachment_id
                    WHERE aa.announcement_post_id=ap.id ORDER BY a.id LIMIT 1) attachment_name
            FROM announcement_posts ap
            JOIN users u ON u.id=ap.created_by";
}

function normalized_announcements_for_user(int $userId,bool $includeOwn=false,string $tag='',string $search=''): array
{
    $where="ap.is_active=1 AND (ap.expires_at IS NULL OR ap.expires_at>=CURDATE())
            AND ".($includeOwn
                ? "(ap.created_by=? OR EXISTS (SELECT 1 FROM announcement_recipients ar WHERE ar.announcement_post_id=ap.id AND ar.user_id=?))"
                : "EXISTS (SELECT 1 FROM announcement_recipients ar WHERE ar.announcement_post_id=ap.id AND ar.user_id=?)");
    $params=$includeOwn ? [$userId,$userId] : [$userId];
    $types=$includeOwn ? 'ii' : 'i';
    if ($tag!=='') {
        $where.=" AND ap.tag=?";
        $params[]=$tag;
        $types.='s';
    }
    if ($search!=='') {
        $where.=" AND (ap.title LIKE ? OR ap.body LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like);
        $types.='ss';
    }
    return query(
        normalized_announcement_select_sql()." WHERE $where ORDER BY ap.is_pinned DESC,ap.created_at DESC",
        $params,
        $types
    ) ?: [];
}

function normalized_announcements_authored(int $userId): array
{
    return query(
        normalized_announcement_select_sql().
        " WHERE ap.created_by=? ORDER BY ap.created_at DESC",
        [$userId],
        'i'
    ) ?: [];
}

function normalized_announcements_admin(string $search=''): array
{
    $where='1=1';
    $params=[];
    $types='';
    if ($search!=='') {
        $where.=" AND (ap.title LIKE ? OR ap.body LIKE ? OR u.name LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like,$like);
        $types='sss';
    }
    return query(
        normalized_announcement_select_sql()." WHERE $where ORDER BY ap.is_pinned DESC,ap.created_at DESC",
        $params,
        $types
    ) ?: [];
}

function normalized_announcement_tags_for_user(int $userId): array
{
    return query(
        "SELECT DISTINCT ap.tag
         FROM announcement_posts ap
         JOIN announcement_recipients ar ON ar.announcement_post_id=ap.id
         WHERE ar.user_id=? AND ap.is_active=1
           AND (ap.expires_at IS NULL OR ap.expires_at>=CURDATE())
           AND ap.tag IS NOT NULL AND ap.tag!=''
         ORDER BY ap.tag",
        [$userId],
        'i'
    ) ?: [];
}

function normalized_certificate_template_for_company(int $companyId): array
{
    $company=query_one("SELECT * FROM companies WHERE id=?",[$companyId],'i') ?: [];
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
    $row=query_one(
        "SELECT ct.*,a.storage_key logo_path
         FROM certificate_templates ct
         LEFT JOIN attachments a ON a.id=ct.logo_attachment_id
         WHERE ct.company_id=? AND ct.is_active=1
         ORDER BY ct.id DESC LIMIT 1",
        [$companyId],
        'i'
    );
    if (!$row) return $defaults;
    $body=json_decode((string)$row['template_body'],true);
    if (!is_array($body)) $body=[];
    $merged=array_merge($defaults,$body);
    if (!empty($row['logo_path'])) $merged['logo']=$row['logo_path'];
    $merged['_template_id']=(int)$row['id'];
    return $merged;
}

function normalized_certificate_save_template(
    int $companyId,int $actorUserId,array $template,?string $newLogoPath=null,?string $originalLogoName=null
): int {
    $current=normalized_certificate_template_for_company($companyId);
    if (!$newLogoPath && !empty($current['logo'])) $template['logo']=$current['logo'];
    $attachmentId=null;
    if (!empty($template['logo'])) {
        $attachmentId=normalized_register_public_attachment(
            $template['logo'],$originalLogoName ?: basename($template['logo']),$actorUserId
        );
    }
    $clean=[
        'logo'=>$template['logo'] ?? '',
        'org_name'=>$template['org_name'] ?? '',
        'org_address'=>$template['org_address'] ?? '',
        'cert_title'=>$template['cert_title'] ?? 'Certificate of Recognition',
        'body_text'=>$template['body_text'] ?? '',
        'signatory_name'=>$template['signatory_name'] ?? '',
        'signatory_title'=>$template['signatory_title'] ?? '',
        'footer_text'=>$template['footer_text'] ?? '',
    ];
    db()->begin_transaction();
    try {
        query("UPDATE certificate_templates SET is_active=0,updated_at=NOW() WHERE company_id=? AND is_active=1",[$companyId],'i');
        $id=(int)insert(
            "INSERT INTO certificate_templates
             (company_id,name,template_body,logo_attachment_id,is_active,created_by,created_at,updated_at)
             VALUES(?,'Company certificate template',?,?,1,?,NOW(),NOW())",
            [$companyId,json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$attachmentId,$actorUserId],
            'isii'
        );
        db()->commit();
        return $id;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_certificate_eligibility(int $studentId): array
{
    $student=normalized_student_context($studentId);
    if (!$student || empty($student['_enrollment_id'])) return ['eligible'=>false,'reasons'=>['OJT enrollment is missing.']];
    $reasons=[];
    if (($student['ojt_status'] ?? '')!=='completed') $reasons[]='OJT status must be completed.';
    $requiredMinutes=(int)$student['required_hours']*60;
    $actualMinutes=normalized_official_minutes_for_enrollment((int)$student['_enrollment_id']);
    if ($actualMinutes<$requiredMinutes) {
        $reasons[]='Required OJT hours are not yet complete.';
    }
    if (empty($student['_placement_id'])) $reasons[]='A completed company placement is required.';
    return [
        'eligible'=>empty($reasons),
        'reasons'=>$reasons,
        'student'=>$student,
        'official_minutes'=>$actualMinutes,
    ];
}

function normalized_certificate_for_student(int $studentId): ?array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return null;
    return query_one(
        "SELECT c.*,p.ojt_enrollment_id
         FROM certificates c
         JOIN placements p ON p.id=c.placement_id
         WHERE p.ojt_enrollment_id=?
         ORDER BY c.id DESC LIMIT 1",
        [(int)$enrollment['id']],
        'i'
    );
}

function normalized_issue_certificate(int $studentId,int $issuerUserId): array
{
    $eligibility=normalized_certificate_eligibility($studentId);
    if (!$eligibility['eligible']) throw new DomainException(implode(' ',$eligibility['reasons']));
    $student=$eligibility['student'];
    $placement=normalized_current_placement_for_enrollment((int)$student['_enrollment_id']);
    if (!$placement) throw new DomainException('Company placement is missing.');

    $issuer=query_one("SELECT id,role,name FROM users WHERE id=? AND status='active'",[$issuerUserId],'i');
    if (!$issuer) throw new DomainException('Certificate issuer is unavailable.');
    if ($issuer['role']==='company' && !normalized_company_user((int)$placement['company_id'],$issuerUserId)) {
        throw new DomainException('You cannot issue a certificate for this placement.');
    }
    if (!in_array($issuer['role'],['company','admin'],true)) {
        throw new DomainException('Only the placement company or an administrator can issue the certificate.');
    }

    $existing=normalized_certificate_for_student($studentId);
    if ($existing) return $existing;

    $template=normalized_certificate_template_for_company((int)$placement['company_id']);
    $coord=normalized_current_coordinator_for_enrollment((int)$student['_enrollment_id']);
    $replace=[
        '{student_name}'=>$student['name'],
        '{program}'=>$student['program'] ?? '',
        '{company_name}'=>$placement['company_name'] ?? '',
        '{rendered_hours}'=>number_format($eligibility['official_minutes']/60,0).' hours',
        '{required_hours}'=>number_format((int)$student['required_hours'],0).' hours',
        '{coordinator_name}'=>$coord['name'] ?? '',
        '{date}'=>date('F d, Y'),
    ];
    $snapshot=[
        'schema_version'=>1,
        'student_id'=>$studentId,
        'student_name'=>$student['name'],
        'student_id_no'=>$student['student_id_no'] ?? '',
        'program'=>$student['program'] ?? '',
        'company_id'=>(int)$placement['company_id'],
        'company_name'=>$placement['company_name'] ?? '',
        'placement_id'=>(int)$placement['id'],
        'official_rendered_minutes'=>$eligibility['official_minutes'],
        'required_hours'=>(int)$student['required_hours'],
        'coordinator_name'=>$coord['name'] ?? '',
        'template'=>$template,
        'rendered_body'=>strtr($template['body_text'] ?? '', $replace),
        'issued_by'=>$issuerUserId,
        'issuer_name'=>$issuer['name'],
    ];

    db()->begin_transaction();
    try {
        $tmp='TMP-'.bin2hex(random_bytes(12));
        $templateId=(int)($template['_template_id'] ?? 0);
        $certId=(int)insert(
            "INSERT INTO certificates
             (placement_id,certificate_template_id,certificate_no,issued_by,issued_at,rendered_snapshot)
             VALUES(?,?,?,?,NOW(),?)",
            [(int)$placement['id'],$templateId ?: null,$tmp,$issuerUserId,json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
            'iisis'
        );
        $certificateNo='OJTR-'.date('Y').'-'.str_pad((string)$certId,6,'0',STR_PAD_LEFT);
        query("UPDATE certificates SET certificate_no=? WHERE id=?",[$certificateNo,$certId],'si');
        db()->commit();
        return query_one("SELECT * FROM certificates WHERE id=?",[$certId],'i');
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_certificate_snapshot(array $certificate): array
{
    $snapshot=json_decode((string)($certificate['rendered_snapshot'] ?? ''),true);
    return is_array($snapshot) ? $snapshot : [];
}
