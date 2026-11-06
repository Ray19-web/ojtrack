<?php
/**
 * OJTrack normalized migration 004: requirements.
 *
 * Additive and data-preserving:
 * - migrates the legacy requirement library into definitions + version 1
 * - migrates every ojt_requirements row into a distinct normalized assignment
 * - creates submission version 1 only when legacy submitted_at exists
 * - registers real files in centralized attachment metadata
 * - preserves duplicate historical assignments instead of merging them
 * - never changes/deletes requirement_templates, ojt_requirements, or files
 *
 * Usage:
 * php bin/migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase4-requirements.php --academic-year=2026-2027 --semester=1st --apply
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
require_once __DIR__ . '/../config/storage.php';

function p4_scalar(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    if (!$r) return 0;
    return (int)reset($r);
}
function p4_ddl(mysqli $conn,string $path): void {
    $sql=file_get_contents($path);
    if ($sql===false || trim($sql)==='') throw new RuntimeException("Missing migration DDL: $path");
    if (!$conn->multi_query($sql)) throw new RuntimeException('DDL failed: '.$conn->error);
    do {
        if ($r=$conn->store_result()) $r->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('DDL failed: '.$conn->error);
}
function p4_snapshot(array $rows): string {
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
}
function p4_submission_status(string $legacy): string {
    return match($legacy) {
        'approved'=>'approved',
        'rejected'=>'returned',
        default=>'submitted',
    };
}
function p4_assignment_status(array $row): string {
    if ($row['status']==='approved') {
        return empty($row['submitted_at']) ? 'waived' : 'closed';
    }
    return 'assigned';
}
function p4_norm_title(string $title): string {
    return strtolower(trim(preg_replace('/\s+/',' ',$title)));
}

foreach (['001_core_identity_and_terms','002_attendance','003_journals_and_attachments'] as $required) {
    if (!query_one("SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1",[$required],'s')) {
        echo json_encode([
            'migration'=>'004_requirements',
            'mode'=>$dryRun?'dry-run':'apply',
            'result'=>'BLOCKED',
            'preflight_problems'=>['missing_required_migration'=>$required],
        ],JSON_PRETTY_PRINT).PHP_EOL;
        exit(1);
    }
}

$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='004_requirements' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'004_requirements',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'ALREADY_APPLIED',
        'applied_at'=>$already['applied_at'],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

$term=query_one(
    "SELECT id FROM academic_terms
     WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",
    [$yearStart,$yearEnd,$semester],'iis'
);
if (!$term) {
    echo json_encode([
        'migration'=>'004_requirements',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$termId=(int)$term['id'];

$legacyTemplates=query(
    "SELECT rt.id,rt.coordinator_id,rt.name,rt.description,rt.created_at,c.user_id coordinator_user_id
     FROM requirement_templates rt
     LEFT JOIN coordinators c ON c.id=rt.coordinator_id
     ORDER BY rt.id"
) ?: [];

$legacyReqs=query(
    "SELECT r.id,r.student_id,r.document_name,r.file_path,r.deadline,r.submitted_at,
            r.status,r.remarks,r.reviewed_by,r.reviewed_at,
            s.user_id student_user_id,s.student_id_no,u.name student_name
     FROM ojt_requirements r
     JOIN students s ON s.id=r.student_id
     JOIN users u ON u.id=s.user_id
     ORDER BY r.id"
) ?: [];

$templateSnapshot=p4_snapshot($legacyTemplates);
$reqSnapshot=p4_snapshot($legacyReqs);

$invalidTemplateOwners=[];
$templateByScope=[];
foreach ($legacyTemplates as $tpl) {
    if (empty($tpl['coordinator_user_id'])) {
        $invalidTemplateOwners[]=[
            'template_id'=>(int)$tpl['id'],
            'coordinator_id'=>(int)$tpl['coordinator_id'],
            'name'=>$tpl['name'],
        ];
        continue;
    }
    $key=(int)$tpl['coordinator_id'].'|'.p4_norm_title($tpl['name']);
    $templateByScope[$key][]=$tpl;
}

$rows=[];
$unmappedEnrollments=[];
$ambiguousTemplateMatches=[];
$invalidReviewerIds=[];
$filesWithoutSubmittedAt=[];
$missingFiles=[];
$invalidPaths=[];
$fileMetadata=[];

foreach ($legacyReqs as $row) {
    $enrollment=query_one(
        "SELECT id FROM ojt_enrollments
         WHERE student_id=? AND academic_term_id=? LIMIT 1",
        [(int)$row['student_id'],$termId],'ii'
    );
    if (!$enrollment) {
        $unmappedEnrollments[]=[
            'requirement_id'=>(int)$row['id'],
            'student_id'=>(int)$row['student_id'],
            'student_id_no'=>$row['student_id_no'],
            'student_name'=>$row['student_name'],
            'document_name'=>$row['document_name'],
        ];
        continue;
    }
    $row['_enrollment_id']=(int)$enrollment['id'];

    $coordRows=query(
        "SELECT ec.coordinator_id,c.user_id
         FROM enrollment_coordinators ec
         JOIN coordinators c ON c.id=ec.coordinator_id
         WHERE ec.ojt_enrollment_id=? AND ec.ended_at IS NULL
         ORDER BY ec.id",
        [(int)$enrollment['id']],'i'
    ) ?: [];
    $coordId=count($coordRows)===1 ? (int)$coordRows[0]['coordinator_id'] : 0;
    $row['_coordinator_id']=$coordId;

    $scopeKey=$coordId.'|'.p4_norm_title($row['document_name']);
    $matches=$templateByScope[$scopeKey] ?? [];
    if (count($matches)>1) {
        $ambiguousTemplateMatches[]=[
            'requirement_id'=>(int)$row['id'],
            'student_name'=>$row['student_name'],
            'document_name'=>$row['document_name'],
            'matching_template_ids'=>array_map(fn($t)=>(int)$t['id'],$matches),
        ];
        continue;
    }
    $row['_legacy_template_id']=count($matches)===1 ? (int)$matches[0]['id'] : 0;
    $row['_definition_scope_key']=count($matches)===1
        ? 'template:'.(int)$matches[0]['id']
        : 'synthetic:'.$coordId.'|'.p4_norm_title($row['document_name']);

    if (!empty($row['reviewed_by']) &&
        !query_one("SELECT id FROM users WHERE id=? LIMIT 1",[(int)$row['reviewed_by']],'i')) {
        $invalidReviewerIds[]=[
            'requirement_id'=>(int)$row['id'],
            'reviewed_by'=>(int)$row['reviewed_by'],
        ];
    }

    if (!empty($row['file_path']) && empty($row['submitted_at'])) {
        $filesWithoutSubmittedAt[]=[
            'requirement_id'=>(int)$row['id'],
            'file_path'=>$row['file_path'],
        ];
    }

    if (!empty($row['file_path'])) {
        $key=(string)$row['file_path'];
        if (!document_path_valid($key)) {
            $invalidPaths[]=['requirement_id'=>(int)$row['id'],'file_path'=>$key];
        } else {
            try {
                $path=resolve_private_document($key);
            } catch (RuntimeException $e) {
                $path=null;
                $missingFiles[]=[
                    'requirement_id'=>(int)$row['id'],
                    'file_path'=>$key,
                    'reason'=>$e->getMessage(),
                ];
            }
            if ($path) {
                $size=filesize($path);
                $sha=hash_file('sha256',$path);
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
                if ($size===false || $sha===false || !$mime) {
                    $missingFiles[]=[
                        'requirement_id'=>(int)$row['id'],
                        'file_path'=>$key,
                        'reason'=>'metadata_unreadable',
                    ];
                } else {
                    $fileMetadata[(int)$row['id']]=[
                        'storage_key'=>$key,
                        'original_filename'=>basename($key),
                        'detected_mime'=>(string)$mime,
                        'size_bytes'=>(int)$size,
                        'sha256'=>$sha,
                        'uploaded_by'=>(int)$row['student_user_id'],
                    ];
                }
            } elseif (!array_filter($missingFiles,fn($x)=>(int)$x['requirement_id']===(int)$row['id'])) {
                $missingFiles[]=[
                    'requirement_id'=>(int)$row['id'],
                    'file_path'=>$key,
                    'reason'=>'file_not_found',
                ];
            }
        }
    }
    $rows[]=$row;
}

$invalidStatus=p4_scalar(
    "SELECT COUNT(*) FROM ojt_requirements
     WHERE status NOT IN ('pending','approved','rejected') OR status IS NULL"
);
$invalidSubmitter=p4_scalar(
    "SELECT COUNT(*)
     FROM ojt_requirements r
     LEFT JOIN students s ON s.id=r.student_id
     LEFT JOIN users u ON u.id=s.user_id
     WHERE s.id IS NULL OR u.id IS NULL OR u.role<>'student'"
);

$problems=[];
if ($invalidTemplateOwners) $problems['invalid_requirement_template_owner']=count($invalidTemplateOwners);
if ($unmappedEnrollments) $problems['requirement_without_normalized_enrollment']=count($unmappedEnrollments);
if ($ambiguousTemplateMatches) $problems['ambiguous_legacy_template_match']=count($ambiguousTemplateMatches);
if ($invalidReviewerIds) $problems['invalid_reviewer_user']=count($invalidReviewerIds);
if ($filesWithoutSubmittedAt) $problems['file_without_submitted_at']=count($filesWithoutSubmittedAt);
if ($missingFiles) $problems['missing_or_unreadable_requirement_files']=count($missingFiles);
if ($invalidPaths) $problems['invalid_requirement_file_paths']=count($invalidPaths);
if ($invalidStatus) $problems['invalid_requirement_status']=$invalidStatus;
if ($invalidSubmitter) $problems['invalid_student_submitter']=$invalidSubmitter;

$submissionCount=count(array_filter($legacyReqs,fn($r)=>!empty($r['submitted_at'])));
$fileCount=count(array_filter($legacyReqs,fn($r)=>!empty($r['file_path'])));
$waivedCount=count(array_filter($legacyReqs,fn($r)=>$r['status']==='approved' && empty($r['submitted_at'])));

$summary=[
    'migration'=>'004_requirements',
    'mode'=>$dryRun?'dry-run':'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy'=>[
        'requirement_templates'=>count($legacyTemplates),
        'requirement_rows'=>count($legacyReqs),
        'rows_with_submission'=>$submissionCount,
        'files_referenced'=>$fileCount,
        'approved_without_submission_to_waive'=>$waivedCount,
    ],
    'preflight_problems'=>$problems,
];

if ($invalidTemplateOwners) $summary['invalid_template_owners']=$invalidTemplateOwners;
if ($unmappedEnrollments) $summary['unmapped_requirements']=$unmappedEnrollments;
if ($ambiguousTemplateMatches) $summary['ambiguous_template_matches']=$ambiguousTemplateMatches;
if ($invalidReviewerIds) $summary['invalid_reviewers']=$invalidReviewerIds;
if ($filesWithoutSubmittedAt) $summary['files_without_submitted_at']=$filesWithoutSubmittedAt;
if ($missingFiles) $summary['missing_requirement_files']=$missingFiles;
if ($invalidPaths) $summary['invalid_requirement_paths']=$invalidPaths;

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='No requirement data was changed. Resolve the listed issues first.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Preflight passed. Re-run with --apply to normalize requirements.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

try {
    p4_ddl($conn,__DIR__.'/../database/migrations/004_requirements.sql');

    if (p4_scalar("SELECT COUNT(*) FROM legacy_requirement_migration_map")>0 ||
        p4_scalar("SELECT COUNT(*) FROM legacy_requirement_template_migration_map")>0) {
        throw new RuntimeException('Requirement migration map already contains rows but migration 004 is not recorded.');
    }

    $conn->begin_transaction();

    $definitionByScope=[];
    $definitionsCreated=0;
    $versionsCreated=0;
    $assignmentsCreated=0;
    $submissionsCreated=0;
    $attachmentLinksCreated=0;

    foreach ($legacyTemplates as $tpl) {
        $creator=(int)$tpl['coordinator_user_id'];
        $title=$tpl['name'];
        $createdAt=$tpl['created_at'];
        $definitionId=insert(
            "INSERT INTO requirement_definitions(created_by,title,status,created_at)
             VALUES(?,?,'active',?)",
            [$creator,$title,$createdAt],
            'iss'
        );
        $definitionsCreated++;

        $description=$tpl['description'];
        $versionId=insert(
            "INSERT INTO requirement_definition_versions
             (requirement_definition_id,version_no,description,instructions,status,published_at,created_at)
             VALUES(?,1,?,NULL,'published',NULL,?)",
            [$definitionId,$description,$createdAt],
            'iss'
        );
        $versionsCreated++;

        insert(
            "INSERT INTO legacy_requirement_template_migration_map
             (legacy_template_id,requirement_definition_id,requirement_definition_version_id)
             VALUES(?,?,?)",
            [(int)$tpl['id'],$definitionId,$versionId],
            'iii'
        );
        $definitionByScope['template:'.(int)$tpl['id']]=[
            'definition_id'=>$definitionId,
            'version_id'=>$versionId,
        ];
    }

    foreach ($rows as $row) {
        $scope=$row['_definition_scope_key'];
        if (!isset($definitionByScope[$scope])) {
            $definitionId=insert(
                "INSERT INTO requirement_definitions(created_by,title,status,created_at)
                 VALUES(NULL,?,'active',NULL)",
                [$row['document_name']],
                's'
            );
            $definitionsCreated++;
            $versionId=insert(
                "INSERT INTO requirement_definition_versions
                 (requirement_definition_id,version_no,description,instructions,status,published_at,created_at)
                 VALUES(?,1,NULL,NULL,'published',NULL,NULL)",
                [$definitionId],
                'i'
            );
            $versionsCreated++;
            $definitionByScope[$scope]=[
                'definition_id'=>$definitionId,
                'version_id'=>$versionId,
            ];
        }

        $versionId=(int)$definitionByScope[$scope]['version_id'];
        $enrollmentId=(int)$row['_enrollment_id'];
        $due=$row['deadline'];
        $assignmentStatus=p4_assignment_status($row);
        $assignmentNote=empty($row['submitted_at']) ? $row['remarks'] : null;

        $assignmentId=insert(
            "INSERT INTO requirement_assignments
             (requirement_definition_version_id,ojt_enrollment_id,assigned_by,due_date,assignment_note,status,assigned_at)
             VALUES(?,?,NULL,?,?,?,NULL)",
            [$versionId,$enrollmentId,$due,$assignmentNote,$assignmentStatus],
            'iisss'
        );
        $assignmentsCreated++;

        $submissionId=null;
        if (!empty($row['submitted_at'])) {
            $submissionStatus=p4_submission_status($row['status']);
            $submitter=(int)$row['student_user_id'];
            $submittedAt=$row['submitted_at'];
            $reviewedBy=empty($row['reviewed_by']) ? null : (int)$row['reviewed_by'];
            $reviewedAt=$row['reviewed_at'];
            $reviewNotes=$row['remarks'];

            $stmt=$conn->prepare(
                "INSERT INTO requirement_submissions
                 (requirement_assignment_id,version_no,submitted_by,status,submitted_at,reviewed_by,reviewed_at,review_notes)
                 VALUES(?,1,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'iississ',
                $assignmentId,$submitter,$submissionStatus,$submittedAt,$reviewedBy,$reviewedAt,$reviewNotes
            );
            $stmt->execute();
            $submissionId=(int)$conn->insert_id;
            $submissionsCreated++;

            $legacyId=(int)$row['id'];
            if (isset($fileMetadata[$legacyId])) {
                $meta=$fileMetadata[$legacyId];
                $existingAttachment=query_one(
                    "SELECT id,sha256,size_bytes FROM attachments WHERE storage_key=? LIMIT 1",
                    [$meta['storage_key']],
                    's'
                );
                if ($existingAttachment) {
                    if (!hash_equals((string)$existingAttachment['sha256'],$meta['sha256']) ||
                        (int)$existingAttachment['size_bytes']!==$meta['size_bytes']) {
                        throw new RuntimeException("Attachment storage key collision for requirement #$legacyId.");
                    }
                    $attachmentId=(int)$existingAttachment['id'];
                } else {
                    $attachmentId=insert(
                        "INSERT INTO attachments
                         (storage_key,original_filename,detected_mime,size_bytes,sha256,uploaded_by)
                         VALUES(?,?,?,?,?,?)",
                        [
                            $meta['storage_key'],$meta['original_filename'],$meta['detected_mime'],
                            $meta['size_bytes'],$meta['sha256'],$meta['uploaded_by']
                        ],
                        'sssisi'
                    );
                }
                insert(
                    "INSERT INTO requirement_submission_attachments(requirement_submission_id,attachment_id)
                     VALUES(?,?)",
                    [$submissionId,$attachmentId],
                    'ii'
                );
                $attachmentLinksCreated++;
            }
        }

        $stmt=$conn->prepare(
            "INSERT INTO legacy_requirement_migration_map
             (legacy_requirement_id,requirement_assignment_id,requirement_submission_id)
             VALUES(?,?,?)"
        );
        $legacyId=(int)$row['id'];
        $stmt->bind_param('iii',$legacyId,$assignmentId,$submissionId);
        $stmt->execute();
    }

    $verification=[
        'missing_assignment_maps'=>p4_scalar(
            "SELECT COUNT(*)
             FROM ojt_requirements r
             LEFT JOIN legacy_requirement_migration_map m ON m.legacy_requirement_id=r.id
             WHERE m.legacy_requirement_id IS NULL"
        ),
        'assignment_count_mismatch'=>abs(
            p4_scalar("SELECT COUNT(*) FROM ojt_requirements") -
            p4_scalar("SELECT COUNT(*) FROM legacy_requirement_migration_map")
        ),
        'submission_count_mismatch'=>abs(
            $submissionCount -
            p4_scalar("SELECT COUNT(*) FROM legacy_requirement_migration_map WHERE requirement_submission_id IS NOT NULL")
        ),
        'attachment_link_count_mismatch'=>abs(
            $fileCount -
            p4_scalar(
                "SELECT COUNT(*)
                 FROM legacy_requirement_migration_map m
                 JOIN requirement_submission_attachments rsa
                   ON rsa.requirement_submission_id=m.requirement_submission_id"
            )
        ),
        'status_mismatches'=>0,
    ];

    foreach ($legacyReqs as $legacy) {
        $mapped=query_one(
            "SELECT ra.status assignment_status,rs.status submission_status
             FROM legacy_requirement_migration_map m
             JOIN requirement_assignments ra ON ra.id=m.requirement_assignment_id
             LEFT JOIN requirement_submissions rs ON rs.id=m.requirement_submission_id
             WHERE m.legacy_requirement_id=?",
            [(int)$legacy['id']],
            'i'
        );
        if (!$mapped || $mapped['assignment_status']!==p4_assignment_status($legacy)) {
            $verification['status_mismatches']++;
            continue;
        }
        if (!empty($legacy['submitted_at']) &&
            $mapped['submission_status']!==p4_submission_status($legacy['status'])) {
            $verification['status_mismatches']++;
        }
        if (empty($legacy['submitted_at']) && $mapped['submission_status']!==null) {
            $verification['status_mismatches']++;
        }
    }

    foreach ($verification as $label=>$count) {
        if ($count!==0) throw new RuntimeException("Verification failed: $label=$count");
    }

    $afterTemplates=query(
        "SELECT rt.id,rt.coordinator_id,rt.name,rt.description,rt.created_at,c.user_id coordinator_user_id
         FROM requirement_templates rt
         LEFT JOIN coordinators c ON c.id=rt.coordinator_id
         ORDER BY rt.id"
    ) ?: [];
    $afterReqs=query(
        "SELECT r.id,r.student_id,r.document_name,r.file_path,r.deadline,r.submitted_at,
                r.status,r.remarks,r.reviewed_by,r.reviewed_at,
                s.user_id student_user_id,s.student_id_no,u.name student_name
         FROM ojt_requirements r
         JOIN students s ON s.id=r.student_id
         JOIN users u ON u.id=s.user_id
         ORDER BY r.id"
    ) ?: [];
    if (p4_snapshot($afterTemplates)!==$templateSnapshot) {
        throw new RuntimeException('Legacy requirement_templates changed during migration.');
    }
    if (p4_snapshot($afterReqs)!==$reqSnapshot) {
        throw new RuntimeException('Legacy ojt_requirements changed during migration.');
    }

    $version='004_requirements';
    $description='Normalize requirement definitions, assignments, submissions and attachment metadata';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    $conn->commit();

    $summary['result']='PASS';
    $summary['normalized']=[
        'requirement_definitions'=>$definitionsCreated,
        'requirement_definition_versions'=>$versionsCreated,
        'requirement_assignments'=>$assignmentsCreated,
        'requirement_submissions'=>$submissionsCreated,
        'attachment_links'=>$attachmentLinksCreated,
        'waived_assignments'=>$waivedCount,
    ];
    $summary['verification']=$verification;
    $summary['legacy_requirement_tables_preserved']=true;
    $summary['files_moved']=false;

    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR,"Migration 004 failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
