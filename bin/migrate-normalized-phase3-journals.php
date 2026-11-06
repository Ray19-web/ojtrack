<?php
/**
 * OJTrack normalized migration 003: journals and attachments.
 *
 * Additive and data-preserving:
 * - requires migrations 001 and 002
 * - creates placement-scoped journal_days plus immutable journal_revisions
 * - registers existing proof files in centralized attachment metadata
 * - never changes/deletes legacy journal_entries or proof files
 *
 * Usage:
 * php bin/migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase3-journals.php --academic-year=2026-2027 --semester=1st --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

$options = getopt('', ['academic-year:', 'semester:', 'apply', 'dry-run']);
$academicYear = trim((string)($options['academic-year'] ?? ''));
$semesterInput = strtolower(trim((string)($options['semester'] ?? '')));
$apply = array_key_exists('apply', $options);
$dryRun = array_key_exists('dry-run', $options) || !$apply;

if ($apply && array_key_exists('dry-run', $options)) {
    fwrite(STDERR, "Choose either --apply or --dry-run, not both.\n");
    exit(2);
}
if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m)) {
    fwrite(STDERR, "Use --academic-year=YYYY-YYYY.\n");
    exit(2);
}
$yearStart = (int)$m[1];
$yearEnd = (int)$m[2];
if ($yearEnd !== $yearStart + 1) {
    fwrite(STDERR, "Academic year end must be exactly one year after the start.\n");
    exit(2);
}
$aliases = ['1'=>'1st','1st'=>'1st','first'=>'1st','2'=>'2nd','2nd'=>'2nd','second'=>'2nd','summer'=>'summer'];
if (!isset($aliases[$semesterInput])) {
    fwrite(STDERR, "Use --semester=1st, --semester=2nd, or --semester=summer.\n");
    exit(2);
}
$semester = $aliases[$semesterInput];

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/storage.php';

function phase3_scalar(string $sql, array $params = [], string $types = ''): int
{
    $row = query_one($sql, $params, $types);
    if (!$row) return 0;
    return (int)reset($row);
}

function phase3_ddl(mysqli $conn, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') throw new RuntimeException("Missing migration DDL: $path");
    if (!$conn->multi_query($sql)) throw new RuntimeException('DDL failed: ' . $conn->error);
    do {
        if ($result = $conn->store_result()) $result->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('DDL failed: ' . $conn->error);
}

function phase3_snapshot(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}

function phase3_status(string $legacy): string
{
    return match ($legacy) {
        'approved' => 'approved',
        'rejected' => 'returned',
        default => 'submitted',
    };
}

function phase3_find_placement(int $studentId, int $termId, string $entryDate): array
{
    $dated = query(
        "SELECT p.id
         FROM placements p
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.student_id=? AND oe.academic_term_id=?
           AND (p.starts_on IS NULL OR p.starts_on<=?)
           AND (p.ends_on IS NULL OR p.ends_on>=?)
         ORDER BY p.id",
        [$studentId, $termId, $entryDate, $entryDate],
        'iiss'
    ) ?: [];
    if (count($dated) === 1) return ['id'=>(int)$dated[0]['id'], 'count'=>1, 'method'=>'date_range'];
    if (count($dated) > 1) return ['id'=>0, 'count'=>count($dated), 'method'=>'ambiguous_date_range'];

    $all = query(
        "SELECT p.id
         FROM placements p
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.student_id=? AND oe.academic_term_id=?
         ORDER BY p.id",
        [$studentId, $termId],
        'ii'
    ) ?: [];
    if (count($all) === 1) return ['id'=>(int)$all[0]['id'], 'count'=>1, 'method'=>'only_placement'];
    return ['id'=>0, 'count'=>count($all), 'method'=>'unresolved'];
}

$requiredMigrations = ['001_core_identity_and_terms','002_attendance'];
foreach ($requiredMigrations as $required) {
    if (!query_one("SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1", [$required], 's')) {
        echo json_encode([
            'migration'=>'003_journals_and_attachments',
            'mode'=>$dryRun ? 'dry-run' : 'apply',
            'result'=>'BLOCKED',
            'preflight_problems'=>['missing_required_migration'=>$required],
        ], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(1);
    }
}

$already = query_one("SELECT applied_at FROM schema_migrations WHERE version='003_journals_and_attachments' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'003_journals_and_attachments',
        'mode'=>$dryRun ? 'dry-run' : 'apply',
        'result'=>'ALREADY_APPLIED',
        'applied_at'=>$already['applied_at']
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$term = query_one(
    "SELECT id FROM academic_terms
     WHERE academic_year_start=? AND academic_year_end=? AND semester=? LIMIT 1",
    [$yearStart, $yearEnd, $semester],
    'iis'
);
if (!$term) {
    echo json_encode([
        'migration'=>'003_journals_and_attachments',
        'mode'=>$dryRun ? 'dry-run' : 'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
$termId = (int)$term['id'];

$legacyRows = query(
    "SELECT j.id, j.student_id, j.entry_date, j.week_number,
            j.activities, j.learnings, j.challenges, j.hours_rendered,
            j.status, j.coordinator_remarks, j.submitted_at, j.reviewed_at,
            j.proof_image, s.user_id, s.student_id_no, u.name student_name
     FROM journal_entries j
     JOIN students s ON s.id=j.student_id
     JOIN users u ON u.id=s.user_id
     ORDER BY j.id"
) ?: [];

$legacySnapshotRows = array_map(fn($r)=>[
    'id'=>$r['id'],
    'student_id'=>$r['student_id'],
    'entry_date'=>$r['entry_date'],
    'week_number'=>$r['week_number'],
    'activities'=>$r['activities'],
    'learnings'=>$r['learnings'],
    'challenges'=>$r['challenges'],
    'hours_rendered'=>$r['hours_rendered'],
    'status'=>$r['status'],
    'coordinator_remarks'=>$r['coordinator_remarks'],
    'submitted_at'=>$r['submitted_at'],
    'reviewed_at'=>$r['reviewed_at'],
    'proof_image'=>$r['proof_image'],
], $legacyRows);
$legacySnapshot = phase3_snapshot($legacySnapshotRows);

$mappedRows = [];
$unmapped = [];
$missingFiles = [];
$invalidProofPaths = [];
$fileMetadata = [];

foreach ($legacyRows as $row) {
    $placement = phase3_find_placement((int)$row['student_id'], $termId, $row['entry_date']);
    if ($placement['id'] <= 0) {
        $unmapped[] = [
            'journal_id'=>(int)$row['id'],
            'student_id'=>(int)$row['student_id'],
            'student_id_no'=>$row['student_id_no'],
            'student_name'=>$row['student_name'],
            'entry_date'=>$row['entry_date'],
            'placement_count'=>$placement['count'],
            'resolution'=>$placement['method'],
        ];
        continue;
    }
    $row['_placement_id'] = $placement['id'];
    $mappedRows[] = $row;

    if (!empty($row['proof_image'])) {
        $key = (string)$row['proof_image'];
        if (!document_path_valid($key)) {
            $invalidProofPaths[] = ['journal_id'=>(int)$row['id'], 'proof_image'=>$key];
            continue;
        }
        try {
            $path = resolve_private_document($key);
        } catch (RuntimeException $e) {
            $missingFiles[] = ['journal_id'=>(int)$row['id'], 'proof_image'=>$key, 'reason'=>$e->getMessage()];
            continue;
        }
        if (!$path) {
            $missingFiles[] = ['journal_id'=>(int)$row['id'], 'proof_image'=>$key, 'reason'=>'file_not_found'];
            continue;
        }
        $size = filesize($path);
        $sha = hash_file('sha256', $path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($size === false || $sha === false || !$mime) {
            $missingFiles[] = ['journal_id'=>(int)$row['id'], 'proof_image'=>$key, 'reason'=>'metadata_unreadable'];
            continue;
        }
        $fileMetadata[(int)$row['id']] = [
            'storage_key'=>$key,
            'original_filename'=>basename($key),
            'detected_mime'=>(string)$mime,
            'size_bytes'=>(int)$size,
            'sha256'=>$sha,
            'uploaded_by'=>(int)$row['user_id'],
        ];
    }
}

$invalidStatus = phase3_scalar(
    "SELECT COUNT(*) FROM journal_entries
     WHERE status NOT IN ('pending','approved','rejected') OR status IS NULL"
);
$invalidHours = phase3_scalar(
    "SELECT COUNT(*) FROM journal_entries
     WHERE hours_rendered < 0 OR hours_rendered > 24 OR hours_rendered IS NULL"
);
$duplicates = phase3_scalar(
    "SELECT COUNT(*) FROM (
       SELECT student_id, entry_date
       FROM journal_entries
       GROUP BY student_id, entry_date
       HAVING COUNT(*)>1
     ) x"
);

$problems = [];
if ($unmapped) $problems['journal_without_exactly_one_resolvable_placement'] = count($unmapped);
if ($missingFiles) $problems['missing_or_unreadable_proof_files'] = count($missingFiles);
if ($invalidProofPaths) $problems['invalid_proof_paths'] = count($invalidProofPaths);
if ($invalidStatus) $problems['invalid_journal_status'] = $invalidStatus;
if ($invalidHours) $problems['invalid_journal_hours'] = $invalidHours;
if ($duplicates) $problems['duplicate_student_date_journals'] = $duplicates;

$proofCount = count(array_filter($legacyRows, fn($r)=>!empty($r['proof_image'])));
$claimedMinutes = array_sum(array_map(
    fn($r)=>max(0, (int)round(((float)$r['hours_rendered']) * 60)),
    $legacyRows
));

$summary = [
    'migration'=>'003_journals_and_attachments',
    'mode'=>$dryRun ? 'dry-run' : 'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy'=>[
        'journal_rows'=>count($legacyRows),
        'proof_files_referenced'=>$proofCount,
        'claimed_minutes'=>$claimedMinutes,
    ],
    'preflight_problems'=>$problems,
];

if ($unmapped) $summary['unmapped_journals']=$unmapped;
if ($missingFiles) $summary['missing_proof_files']=$missingFiles;
if ($invalidProofPaths) $summary['invalid_proof_paths']=$invalidProofPaths;

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='No journal data was changed. Resolve the listed issues first.';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Preflight passed. Re-run with --apply to migrate journals and proof metadata.';
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

try {
    phase3_ddl($conn, __DIR__ . '/../database/migrations/003_journals_and_attachments.sql');

    $existing = phase3_scalar(
        "SELECT COUNT(*)
         FROM journal_days jd
         JOIN placements p ON p.id=jd.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.academic_term_id=?",
        [$termId],
        'i'
    );
    if ($existing > 0) {
        throw new RuntimeException(
            "Normalized journals already contain $existing day(s) for this term but migration 003 is not recorded."
        );
    }

    $conn->begin_transaction();
    $daysCreated=0;
    $revisionsCreated=0;
    $attachmentLinksCreated=0;

    $insertDay=$conn->prepare(
        "INSERT INTO journal_days(placement_id,entry_date,week_number,created_at)
         VALUES(?,?,?,?)"
    );
    $insertRevision=$conn->prepare(
        "INSERT INTO journal_revisions
          (journal_day_id,revision_no,activities,learnings,challenges,claimed_minutes,
           status,submitted_at,reviewed_by,reviewed_at,review_notes,created_at)
         VALUES(?,1,?,?,?,?,?,?,NULL,?,?,?)"
    );
    $insertAttachment=$conn->prepare(
        "INSERT INTO attachments
          (storage_key,original_filename,detected_mime,size_bytes,sha256,uploaded_by)
         VALUES(?,?,?,?,?,?)"
    );

    foreach ($mappedRows as $row) {
        $placementId=(int)$row['_placement_id'];
        $entryDate=$row['entry_date'];
        $week=$row['week_number'] === null ? null : (int)$row['week_number'];
        $createdAt=$row['submitted_at'];
        $insertDay->bind_param('isis',$placementId,$entryDate,$week,$createdAt);
        $insertDay->execute();
        $dayId=(int)$conn->insert_id;
        $daysCreated++;

        $claimed=max(0,(int)round(((float)$row['hours_rendered'])*60));
        $status=phase3_status($row['status']);
        $submittedAt=$row['submitted_at'];
        $reviewedAt=$row['reviewed_at'];
        $notes=$row['coordinator_remarks'];
        $activities=$row['activities'];
        $learnings=$row['learnings'];
        $challenges=$row['challenges'];
        $insertRevision->bind_param(
            'isssisssss',
            $dayId,$activities,$learnings,$challenges,$claimed,$status,
            $submittedAt,$reviewedAt,$notes,$createdAt
        );
        $insertRevision->execute();
        $revisionId=(int)$conn->insert_id;
        $revisionsCreated++;

        $journalId=(int)$row['id'];
        if (isset($fileMetadata[$journalId])) {
            $meta=$fileMetadata[$journalId];
            $existingAttachment=query_one(
                "SELECT id,sha256,size_bytes FROM attachments WHERE storage_key=? LIMIT 1",
                [$meta['storage_key']],
                's'
            );
            if ($existingAttachment) {
                if (!hash_equals((string)$existingAttachment['sha256'], $meta['sha256']) ||
                    (int)$existingAttachment['size_bytes'] !== $meta['size_bytes']) {
                    throw new RuntimeException("Attachment storage key collision for journal #$journalId.");
                }
                $attachmentId=(int)$existingAttachment['id'];
            } else {
                $storageKey=$meta['storage_key'];
                $original=$meta['original_filename'];
                $mime=$meta['detected_mime'];
                $size=$meta['size_bytes'];
                $sha=$meta['sha256'];
                $uploader=$meta['uploaded_by'];
                $insertAttachment->bind_param('sssisi',$storageKey,$original,$mime,$size,$sha,$uploader);
                $insertAttachment->execute();
                $attachmentId=(int)$conn->insert_id;
            }
            insert(
                "INSERT INTO journal_revision_attachments(journal_revision_id,attachment_id)
                 VALUES(?,?)",
                [$revisionId,$attachmentId],
                'ii'
            );
            $attachmentLinksCreated++;
        }
    }

    $verification=[
        'missing_days'=>0,
        'missing_revisions'=>0,
        'status_mismatches'=>0,
        'claimed_minute_mismatches'=>0,
        'missing_attachment_links'=>0,
    ];

    foreach ($mappedRows as $legacy) {
        $day=query_one(
            "SELECT jd.id
             FROM journal_days jd
             WHERE jd.placement_id=? AND jd.entry_date=? LIMIT 1",
            [(int)$legacy['_placement_id'],$legacy['entry_date']],
            'is'
        );
        if (!$day) {
            $verification['missing_days']++;
            continue;
        }
        $revision=query_one(
            "SELECT id,status,claimed_minutes,review_notes,submitted_at,reviewed_at
             FROM journal_revisions
             WHERE journal_day_id=? AND revision_no=1 LIMIT 1",
            [(int)$day['id']],
            'i'
        );
        if (!$revision) {
            $verification['missing_revisions']++;
            continue;
        }
        if ($revision['status'] !== phase3_status($legacy['status'])) $verification['status_mismatches']++;
        if ((int)$revision['claimed_minutes'] !== (int)round(((float)$legacy['hours_rendered'])*60)) {
            $verification['claimed_minute_mismatches']++;
        }
        if (!empty($legacy['proof_image'])) {
            $linked=phase3_scalar(
                "SELECT COUNT(*)
                 FROM journal_revision_attachments jra
                 JOIN attachments a ON a.id=jra.attachment_id
                 WHERE jra.journal_revision_id=? AND a.storage_key=?",
                [(int)$revision['id'],$legacy['proof_image']],
                'is'
            );
            if ($linked !== 1) $verification['missing_attachment_links']++;
        }
    }

    if ($daysCreated !== count($legacyRows)) {
        throw new RuntimeException("Verification failed: journal days=$daysCreated legacy=" . count($legacyRows));
    }
    if ($revisionsCreated !== count($legacyRows)) {
        throw new RuntimeException("Verification failed: revisions=$revisionsCreated legacy=" . count($legacyRows));
    }
    if ($attachmentLinksCreated !== $proofCount) {
        throw new RuntimeException("Verification failed: proof links=$attachmentLinksCreated expected=$proofCount");
    }
    foreach ($verification as $label=>$count) {
        if ($count !== 0) throw new RuntimeException("Verification failed: $label=$count");
    }

    $afterRows=query(
        "SELECT id, student_id, entry_date, week_number, activities, learnings, challenges,
                hours_rendered, status, coordinator_remarks, submitted_at, reviewed_at, proof_image
         FROM journal_entries ORDER BY id"
    ) ?: [];
    if (phase3_snapshot($afterRows) !== $legacySnapshot) {
        throw new RuntimeException('Legacy journal_entries changed during migration.');
    }

    $version='003_journals_and_attachments';
    $description='Normalize journal days/revisions and register existing journal proof files as attachments';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    $conn->commit();

    $summary['result']='PASS';
    $summary['normalized']=[
        'journal_days'=>$daysCreated,
        'journal_revisions'=>$revisionsCreated,
        'attachment_links'=>$attachmentLinksCreated,
        'claimed_minutes'=>phase3_scalar(
            "SELECT COALESCE(SUM(jr.claimed_minutes),0)
             FROM journal_revisions jr
             JOIN journal_days jd ON jd.id=jr.journal_day_id
             JOIN placements p ON p.id=jd.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
             WHERE oe.academic_term_id=?",
            [$termId],
            'i'
        ),
    ];
    $summary['verification']=$verification;
    $summary['legacy_journals_preserved']=true;
    $summary['proof_files_moved']=false;

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR, "Migration 003 failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
