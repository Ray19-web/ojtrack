<?php
/**
 * OJTrack normalized migration 005: reports.
 *
 * Additive and data-preserving:
 * - requires migrations 001-004
 * - creates reusable report templates/version 1 from distinct legacy name+type pairs
 * - migrates every legacy reports row into its own assignment
 * - creates submission version 1 only when submitted_at exists
 * - keeps student notes separate from review feedback when the legacy status tells us which it is
 * - snapshots current normalized DTR/journal evidence for submitted monthly reports
 * - registers real report files in centralized attachment metadata
 * - never changes/deletes legacy reports or files
 *
 * Usage:
 * php bin/migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase5-reports.php --academic-year=2026-2027 --semester=1st --apply
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

function p5_scalar(string $sql,array $params=[],string $types=''): int {
    $row=query_one($sql,$params,$types);
    if (!$row) return 0;
    return (int)reset($row);
}
function p5_ddl(mysqli $conn,string $path): void {
    $sql=file_get_contents($path);
    if ($sql===false || trim($sql)==='') throw new RuntimeException("Missing migration DDL: $path");
    if (!$conn->multi_query($sql)) throw new RuntimeException('DDL failed: '.$conn->error);
    do {
        if ($r=$conn->store_result()) $r->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('DDL failed: '.$conn->error);
}
function p5_snapshot(array $rows): string {
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
}
function p5_norm(string $value): string {
    return strtolower(trim(preg_replace('/\s+/',' ',$value)));
}
function p5_assignment_status(array $row): string {
    if ($row['status']==='approved') {
        return empty($row['submitted_at']) ? 'waived' : 'closed';
    }
    return 'assigned';
}
function p5_submission_status(string $legacy): string {
    return match($legacy) {
        'approved'=>'approved',
        'rejected'=>'returned',
        default=>'submitted',
    };
}
function p5_period(array $row): array {
    if ($row['report_type']!=='monthly') return [null,null];
    $date=$row['deadline'] ?: substr((string)$row['submitted_at'],0,10);
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) return [null,null];
    $start=(new DateTimeImmutable($date))->modify('first day of this month')->format('Y-m-d');
    $end=(new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
    return [$start,$end];
}
function p5_monthly_evidence(int $studentId,int $termId,string $periodStart,string $periodEnd,?string $legacySummary): string {
    $attendance=query(
        "SELECT ad.id,ad.attendance_date,ad.status,ad.credited_minutes
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.student_id=? AND oe.academic_term_id=?
           AND ad.attendance_date BETWEEN ? AND ?
         ORDER BY ad.attendance_date,ad.id",
        [$studentId,$termId,$periodStart,$periodEnd],
        'iiss'
    ) ?: [];

    $journals=query(
        "SELECT jd.id AS journal_day_id,jd.entry_date,jd.week_number,
                jr.id AS journal_revision_id,jr.revision_no,jr.status,jr.claimed_minutes
         FROM journal_days jd
         JOIN placements p ON p.id=jd.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN journal_revisions jr ON jr.journal_day_id=jd.id
         JOIN (
           SELECT journal_day_id,MAX(revision_no) max_revision
           FROM journal_revisions GROUP BY journal_day_id
         ) latest ON latest.journal_day_id=jr.journal_day_id AND latest.max_revision=jr.revision_no
         WHERE oe.student_id=? AND oe.academic_term_id=?
           AND jd.entry_date BETWEEN ? AND ?
         ORDER BY jd.entry_date,jd.id",
        [$studentId,$termId,$periodStart,$periodEnd],
        'iiss'
    ) ?: [];

    return json_encode([
        'schema_version'=>1,
        'basis'=>'migration_current_normalized_records_not_original_submission_snapshot',
        'period_start'=>$periodStart,
        'period_end'=>$periodEnd,
        'legacy_summary_text'=>$legacySummary,
        'attendance'=>array_map(fn($a)=>[
            'attendance_day_id'=>(int)$a['id'],
            'date'=>$a['attendance_date'],
            'status'=>$a['status'],
            'credited_minutes'=>(int)$a['credited_minutes'],
        ],$attendance),
        'journals'=>array_map(fn($j)=>[
            'journal_day_id'=>(int)$j['journal_day_id'],
            'journal_revision_id'=>(int)$j['journal_revision_id'],
            'date'=>$j['entry_date'],
            'week_number'=>$j['week_number']===null?null:(int)$j['week_number'],
            'revision_no'=>(int)$j['revision_no'],
            'status'=>$j['status'],
            'claimed_minutes'=>(int)$j['claimed_minutes'],
        ],$journals),
        'totals'=>[
            'attendance_days'=>count($attendance),
            'credited_minutes'=>array_sum(array_map(fn($a)=>(int)$a['credited_minutes'],$attendance)),
            'journal_days'=>count($journals),
            'journal_claimed_minutes'=>array_sum(array_map(fn($j)=>(int)$j['claimed_minutes'],$journals)),
        ],
    ],JSON_UNESCAPED_SLASHES);
}

foreach (['001_core_identity_and_terms','002_attendance','003_journals_and_attachments','004_requirements'] as $required) {
    if (!query_one("SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1",[$required],'s')) {
        echo json_encode([
            'migration'=>'005_reports',
            'mode'=>$dryRun?'dry-run':'apply',
            'result'=>'BLOCKED',
            'preflight_problems'=>['missing_required_migration'=>$required],
        ],JSON_PRETTY_PRINT).PHP_EOL;
        exit(1);
    }
}

$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='005_reports' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'005_reports',
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
        'migration'=>'005_reports',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$termId=(int)$term['id'];

$legacyRows=query(
    "SELECT r.id,r.student_id,r.report_name,r.report_type,r.file_path,r.deadline,
            r.submitted_at,r.status,r.remarks,r.reviewed_by,r.reviewed_at,
            s.user_id student_user_id,s.student_id_no,u.name student_name
     FROM reports r
     JOIN students s ON s.id=r.student_id
     JOIN users u ON u.id=s.user_id
     ORDER BY r.id"
) ?: [];
$legacySnapshot=p5_snapshot($legacyRows);

$rows=[];
$unmapped=[];
$invalidReviewers=[];
$filesWithoutSubmission=[];
$missingFiles=[];
$invalidPaths=[];
$invalidSubmissionState=[];
$fileMetadata=[];
$templateKeys=[];

foreach ($legacyRows as $row) {
    $enrollment=query_one(
        "SELECT id FROM ojt_enrollments WHERE student_id=? AND academic_term_id=? LIMIT 1",
        [(int)$row['student_id'],$termId],
        'ii'
    );
    if (!$enrollment) {
        $unmapped[]=[
            'report_id'=>(int)$row['id'],
            'student_id'=>(int)$row['student_id'],
            'student_id_no'=>$row['student_id_no'],
            'student_name'=>$row['student_name'],
            'report_name'=>$row['report_name'],
            'report_type'=>$row['report_type'],
        ];
        continue;
    }
    $row['_enrollment_id']=(int)$enrollment['id'];
    $row['_template_key']=p5_norm($row['report_type']).'|'.p5_norm($row['report_name']);
    $templateKeys[$row['_template_key']]=[
        'title'=>$row['report_name'],
        'report_type'=>$row['report_type'],
    ];

    if (in_array($row['status'],['for_review','rejected'],true) && empty($row['submitted_at'])) {
        $invalidSubmissionState[]=[
            'report_id'=>(int)$row['id'],
            'status'=>$row['status'],
            'reason'=>'status_requires_submission_timestamp',
        ];
    }
    if (!empty($row['reviewed_by']) &&
        !query_one("SELECT id FROM users WHERE id=? LIMIT 1",[(int)$row['reviewed_by']],'i')) {
        $invalidReviewers[]=[
            'report_id'=>(int)$row['id'],
            'reviewed_by'=>(int)$row['reviewed_by'],
        ];
    }
    if (!empty($row['file_path']) && empty($row['submitted_at'])) {
        $filesWithoutSubmission[]=[
            'report_id'=>(int)$row['id'],
            'file_path'=>$row['file_path'],
        ];
    }
    if (!empty($row['file_path'])) {
        $key=(string)$row['file_path'];
        if (!document_path_valid($key)) {
            $invalidPaths[]=['report_id'=>(int)$row['id'],'file_path'=>$key];
        } else {
            try {
                $path=resolve_private_document($key);
            } catch (RuntimeException $e) {
                $path=null;
                $missingFiles[]=[
                    'report_id'=>(int)$row['id'],
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
                        'report_id'=>(int)$row['id'],
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
            } elseif (!array_filter($missingFiles,fn($x)=>(int)$x['report_id']===(int)$row['id'])) {
                $missingFiles[]=[
                    'report_id'=>(int)$row['id'],
                    'file_path'=>$key,
                    'reason'=>'file_not_found',
                ];
            }
        }
    }
    $rows[]=$row;
}

$invalidStatus=p5_scalar(
    "SELECT COUNT(*) FROM reports
     WHERE status NOT IN ('pending','approved','rejected','for_review') OR status IS NULL"
);
$invalidType=p5_scalar(
    "SELECT COUNT(*) FROM reports
     WHERE report_type NOT IN ('initial','midterm','final','monthly') OR report_type IS NULL"
);
$invalidSubmitter=p5_scalar(
    "SELECT COUNT(*)
     FROM reports r
     LEFT JOIN students s ON s.id=r.student_id
     LEFT JOIN users u ON u.id=s.user_id
     WHERE s.id IS NULL OR u.id IS NULL OR u.role<>'student'"
);

$problems=[];
if ($unmapped) $problems['report_without_normalized_enrollment']=count($unmapped);
if ($invalidReviewers) $problems['invalid_report_reviewer_user']=count($invalidReviewers);
if ($filesWithoutSubmission) $problems['report_file_without_submitted_at']=count($filesWithoutSubmission);
if ($missingFiles) $problems['missing_or_unreadable_report_files']=count($missingFiles);
if ($invalidPaths) $problems['invalid_report_file_paths']=count($invalidPaths);
if ($invalidSubmissionState) $problems['invalid_report_submission_state']=count($invalidSubmissionState);
if ($invalidStatus) $problems['invalid_report_status']=$invalidStatus;
if ($invalidType) $problems['invalid_report_type']=$invalidType;
if ($invalidSubmitter) $problems['invalid_report_student']=$invalidSubmitter;

$submissionCount=count(array_filter($legacyRows,fn($r)=>!empty($r['submitted_at'])));
$fileCount=count(array_filter($legacyRows,fn($r)=>!empty($r['file_path'])));
$monthlySubmitted=count(array_filter($legacyRows,fn($r)=>$r['report_type']==='monthly' && !empty($r['submitted_at'])));
$waivedCount=count(array_filter($legacyRows,fn($r)=>$r['status']==='approved' && empty($r['submitted_at'])));

$summary=[
    'migration'=>'005_reports',
    'mode'=>$dryRun?'dry-run':'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy'=>[
        'report_rows'=>count($legacyRows),
        'distinct_report_definitions'=>count($templateKeys),
        'rows_with_submission'=>$submissionCount,
        'files_referenced'=>$fileCount,
        'submitted_monthly_reports'=>$monthlySubmitted,
        'approved_without_submission_to_waive'=>$waivedCount,
    ],
    'preflight_problems'=>$problems,
];

if ($unmapped) $summary['unmapped_reports']=$unmapped;
if ($invalidReviewers) $summary['invalid_reviewers']=$invalidReviewers;
if ($filesWithoutSubmission) $summary['files_without_submission']=$filesWithoutSubmission;
if ($missingFiles) $summary['missing_report_files']=$missingFiles;
if ($invalidPaths) $summary['invalid_report_paths']=$invalidPaths;
if ($invalidSubmissionState) $summary['invalid_submission_states']=$invalidSubmissionState;

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='No report data was changed. Resolve the listed issues first.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Preflight passed. Re-run with --apply to normalize reports.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

try {
    p5_ddl($conn,__DIR__.'/../database/migrations/005_reports.sql');
    if (p5_scalar("SELECT COUNT(*) FROM legacy_report_migration_map")>0) {
        throw new RuntimeException('Report migration map already contains rows but migration 005 is not recorded.');
    }

    $conn->begin_transaction();
    $templateByKey=[];
    $templatesCreated=0;
    $versionsCreated=0;
    $assignmentsCreated=0;
    $submissionsCreated=0;
    $attachmentLinksCreated=0;
    $monthlySnapshotsCreated=0;

    foreach ($templateKeys as $key=>$def) {
        $templateId=insert(
            "INSERT INTO report_templates(created_by,title,status,created_at)
             VALUES(NULL,?,'active',NULL)",
            [$def['title']],
            's'
        );
        $templatesCreated++;

        $versionId=insert(
            "INSERT INTO report_template_versions
             (report_template_id,version_no,report_type,instructions,status,published_at,created_at)
             VALUES(?,1,?,NULL,'published',NULL,NULL)",
            [$templateId,$def['report_type']],
            'is'
        );
        $versionsCreated++;
        $templateByKey[$key]=['template_id'=>$templateId,'version_id'=>$versionId];
    }

    foreach ($rows as $row) {
        $template=$templateByKey[$row['_template_key']];
        [$periodStart,$periodEnd]=p5_period($row);
        $assignmentStatus=p5_assignment_status($row);

        $coord=query_one(
            "SELECT c.user_id
             FROM enrollment_coordinators ec
             JOIN coordinators c ON c.id=ec.coordinator_id
             WHERE ec.ojt_enrollment_id=? AND ec.ended_at IS NULL
             ORDER BY ec.id LIMIT 1",
            [(int)$row['_enrollment_id']],
            'i'
        );
        $assignedBy=$coord ? (int)$coord['user_id'] : null;

        $stmt=$conn->prepare(
            "INSERT INTO report_assignments
             (report_template_version_id,ojt_enrollment_id,assigned_by,period_start,period_end,due_date,status,assigned_at)
             VALUES(?,?,?,?,?,?,?,NULL)"
        );
        $versionId=(int)$template['version_id'];
        $enrollmentId=(int)$row['_enrollment_id'];
        $due=$row['deadline'];
        $stmt->bind_param(
            'iiissss',
            $versionId,$enrollmentId,$assignedBy,$periodStart,$periodEnd,$due,$assignmentStatus
        );
        $stmt->execute();
        $assignmentId=(int)$conn->insert_id;
        $assignmentsCreated++;

        $submissionId=null;
        if (!empty($row['submitted_at'])) {
            $submissionStatus=p5_submission_status($row['status']);
            $studentNote=null;
            $reviewNotes=null;
            if (in_array($row['status'],['approved','rejected'],true)) {
                $reviewNotes=$row['remarks'];
            } else {
                $studentNote=$row['remarks'];
            }

            $evidence=null;
            if ($row['report_type']==='monthly' && $periodStart && $periodEnd) {
                $evidence=p5_monthly_evidence(
                    (int)$row['student_id'],$termId,$periodStart,$periodEnd,$row['remarks']
                );
                $monthlySnapshotsCreated++;
            }

            $submitter=(int)$row['student_user_id'];
            $submittedAt=$row['submitted_at'];
            $reviewedBy=empty($row['reviewed_by']) ? null : (int)$row['reviewed_by'];
            $reviewedAt=$row['reviewed_at'];

            $stmt=$conn->prepare(
                "INSERT INTO report_submissions
                 (report_assignment_id,version_no,submitted_by,status,submitted_at,student_note,
                  reviewed_by,reviewed_at,review_notes,evidence_snapshot)
                 VALUES(?,1,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'iisssissss',
                $assignmentId,$submitter,$submissionStatus,$submittedAt,$studentNote,
                $reviewedBy,$reviewedAt,$reviewNotes,$evidence
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
                        throw new RuntimeException("Attachment storage key collision for report #$legacyId.");
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
                    "INSERT INTO report_submission_attachments(report_submission_id,attachment_id)
                     VALUES(?,?)",
                    [$submissionId,$attachmentId],
                    'ii'
                );
                $attachmentLinksCreated++;
            }
        }

        $legacyId=(int)$row['id'];
        $templateId=(int)$template['template_id'];
        $versionId=(int)$template['version_id'];
        $stmt=$conn->prepare(
            "INSERT INTO legacy_report_migration_map
             (legacy_report_id,report_template_id,report_template_version_id,report_assignment_id,report_submission_id)
             VALUES(?,?,?,?,?)"
        );
        $stmt->bind_param('iiiii',$legacyId,$templateId,$versionId,$assignmentId,$submissionId);
        $stmt->execute();
    }

    $verification=[
        'missing_report_maps'=>p5_scalar(
            "SELECT COUNT(*)
             FROM reports r
             LEFT JOIN legacy_report_migration_map m ON m.legacy_report_id=r.id
             WHERE m.legacy_report_id IS NULL"
        ),
        'assignment_count_mismatch'=>abs(
            count($legacyRows)-p5_scalar("SELECT COUNT(*) FROM legacy_report_migration_map")
        ),
        'submission_count_mismatch'=>abs(
            $submissionCount-p5_scalar(
                "SELECT COUNT(*) FROM legacy_report_migration_map WHERE report_submission_id IS NOT NULL"
            )
        ),
        'attachment_link_count_mismatch'=>abs(
            $fileCount-p5_scalar(
                "SELECT COUNT(*)
                 FROM legacy_report_migration_map m
                 JOIN report_submission_attachments rsa
                   ON rsa.report_submission_id=m.report_submission_id"
            )
        ),
        'monthly_snapshot_count_mismatch'=>abs(
            $monthlySubmitted-p5_scalar(
                "SELECT COUNT(*)
                 FROM legacy_report_migration_map m
                 JOIN reports r ON r.id=m.legacy_report_id
                 JOIN report_submissions rs ON rs.id=m.report_submission_id
                 WHERE r.report_type='monthly' AND rs.evidence_snapshot IS NOT NULL"
            )
        ),
        'status_mismatches'=>0,
    ];

    foreach ($legacyRows as $legacy) {
        $mapped=query_one(
            "SELECT ra.status assignment_status,rs.status submission_status,
                    rs.student_note,rs.review_notes
             FROM legacy_report_migration_map m
             JOIN report_assignments ra ON ra.id=m.report_assignment_id
             LEFT JOIN report_submissions rs ON rs.id=m.report_submission_id
             WHERE m.legacy_report_id=?",
            [(int)$legacy['id']],
            'i'
        );
        if (!$mapped || $mapped['assignment_status']!==p5_assignment_status($legacy)) {
            $verification['status_mismatches']++;
            continue;
        }
        if (!empty($legacy['submitted_at'])) {
            if ($mapped['submission_status']!==p5_submission_status($legacy['status'])) {
                $verification['status_mismatches']++;
            }
            if (in_array($legacy['status'],['approved','rejected'],true)) {
                if (($mapped['review_notes'] ?? null)!==($legacy['remarks'] ?? null)) {
                    $verification['status_mismatches']++;
                }
            } else {
                if (($mapped['student_note'] ?? null)!==($legacy['remarks'] ?? null)) {
                    $verification['status_mismatches']++;
                }
            }
        } elseif ($mapped['submission_status']!==null) {
            $verification['status_mismatches']++;
        }
    }

    foreach ($verification as $label=>$count) {
        if ($count!==0) throw new RuntimeException("Verification failed: $label=$count");
    }

    $after=query(
        "SELECT r.id,r.student_id,r.report_name,r.report_type,r.file_path,r.deadline,
                r.submitted_at,r.status,r.remarks,r.reviewed_by,r.reviewed_at,
                s.user_id student_user_id,s.student_id_no,u.name student_name
         FROM reports r
         JOIN students s ON s.id=r.student_id
         JOIN users u ON u.id=s.user_id
         ORDER BY r.id"
    ) ?: [];
    if (p5_snapshot($after)!==$legacySnapshot) {
        throw new RuntimeException('Legacy reports changed during migration.');
    }

    $version='005_reports';
    $description='Normalize report definitions, assignments, submissions, monthly evidence snapshots and attachment metadata';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    $conn->commit();

    $summary['result']='PASS';
    $summary['normalized']=[
        'report_templates'=>$templatesCreated,
        'report_template_versions'=>$versionsCreated,
        'report_assignments'=>$assignmentsCreated,
        'report_submissions'=>$submissionsCreated,
        'attachment_links'=>$attachmentLinksCreated,
        'monthly_evidence_snapshots'=>$monthlySnapshotsCreated,
        'waived_assignments'=>$waivedCount,
    ];
    $summary['verification']=$verification;
    $summary['legacy_reports_preserved']=true;
    $summary['files_moved']=false;
    $summary['monthly_snapshot_basis']='current normalized records at migration time, explicitly labeled as not the original historical submission snapshot';

    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR,"Migration 005 failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
