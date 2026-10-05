<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p3check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p3count(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    return (int)($r['n'] ?? 0);
}

p3check(p3count("SELECT COUNT(*) n FROM journal_entries")===3,'legacy journals preserved');
p3check(p3count("SELECT COUNT(*) n FROM schema_migrations WHERE version='003_journals_and_attachments'")===1,'phase 3 recorded');
p3check(p3count("SELECT COUNT(*) n FROM journal_days")===3,'one normalized day per legacy journal');
p3check(p3count("SELECT COUNT(*) n FROM journal_revisions")===3,'one initial revision per legacy journal');
p3check(p3count("SELECT COUNT(*) n FROM journal_revision_attachments")===1,'proof linked once');
p3check(p3count("SELECT COUNT(*) n FROM attachments WHERE storage_key='journal_proofs/phase3-proof.png'")===1,'central attachment registered');

$returned=query_one(
    "SELECT jr.status,jr.review_notes,jr.claimed_minutes,jr.submitted_at,jr.reviewed_at
     FROM journal_revisions jr
     JOIN journal_days jd ON jd.id=jr.journal_day_id
     JOIN placements p ON p.id=jd.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.student_id=1 AND jd.entry_date='2026-09-01'"
);
p3check($returned && $returned['status']==='returned','rejected maps to returned');
p3check($returned && $returned['review_notes']==='Please revise','coordinator remarks preserved');
p3check((int)$returned['claimed_minutes']===0,'zero claimed minutes preserved');

$approved=query_one(
    "SELECT jr.status,jr.claimed_minutes
     FROM journal_revisions jr
     JOIN journal_days jd ON jd.id=jr.journal_day_id
     JOIN placements p ON p.id=jd.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.student_id=1 AND jd.entry_date='2026-09-02'"
);
p3check($approved && $approved['status']==='approved','approved status preserved');
p3check((int)$approved['claimed_minutes']===510,'8.5 hours converted to 510 minutes');

$submitted=query_one(
    "SELECT jr.status,jr.claimed_minutes
     FROM journal_revisions jr
     JOIN journal_days jd ON jd.id=jr.journal_day_id
     JOIN placements p ON p.id=jd.placement_id
     JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
     WHERE oe.student_id=2 AND jd.entry_date='2026-09-03'"
);
p3check($submitted && $submitted['status']==='submitted','pending maps to submitted');
p3check((int)$submitted['claimed_minutes']===420,'7 hours converted to 420 minutes');

$attachment=query_one(
    "SELECT a.detected_mime,a.size_bytes,a.sha256,a.uploaded_by
     FROM attachments a
     JOIN journal_revision_attachments jra ON jra.attachment_id=a.id
     JOIN journal_revisions jr ON jr.id=jra.journal_revision_id
     JOIN journal_days jd ON jd.id=jr.journal_day_id
     WHERE jd.entry_date='2026-09-01'"
);
p3check($attachment && $attachment['detected_mime']==='image/png','proof MIME recorded');
p3check($attachment && (int)$attachment['size_bytes']>0 && strlen($attachment['sha256'])===64,'proof size and hash recorded');
p3check($attachment && (int)$attachment['uploaded_by']===4,'student uploader identity preserved');

p3check(
    p3count("SELECT COUNT(*) n FROM journal_entries WHERE id=1 AND status='rejected' AND coordinator_remarks='Please revise'")===1,
    'legacy rejected row unchanged'
);
p3check(
    p3count("SELECT COUNT(*) n FROM journal_entries WHERE proof_image='journal_proofs/phase3-proof.png'")===1,
    'legacy proof path unchanged'
);

echo json_encode(['phase3_journal_checks_passed'=>$checks,'result'=>'PASS']) . PHP_EOL;
