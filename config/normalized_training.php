<?php
require_once __DIR__ . '/storage.php';

function normalized_register_private_attachment(string $storageKey, string $originalFilename, int $uploadedBy): int
{
    if (!document_path_valid($storageKey)) throw new DomainException('Invalid private document path.');
    $path=resolve_private_document($storageKey);
    if (!$path) throw new DomainException('Uploaded document could not be resolved.');

    $size=filesize($path);
    $sha=hash_file('sha256',$path);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($size===false || $sha===false || !$mime) throw new DomainException('Uploaded document metadata could not be read.');

    $existing=query_one(
        "SELECT id,sha256,size_bytes FROM attachments WHERE storage_key=? LIMIT 1",
        [$storageKey],
        's'
    );
    if ($existing) {
        if (!hash_equals((string)$existing['sha256'],$sha) || (int)$existing['size_bytes']!==(int)$size) {
            throw new DomainException('Document storage key collision detected.');
        }
        return (int)$existing['id'];
    }

    return (int)insert(
        "INSERT INTO attachments(storage_key,original_filename,detected_mime,size_bytes,sha256,uploaded_by)
         VALUES(?,?,?,?,?,?)",
        [$storageKey,$originalFilename,$mime,(int)$size,$sha,$uploadedBy],
        'sssisi'
    );
}

function normalized_attendance_shape(array $day): array
{
    $sessions=query(
        "SELECT * FROM attendance_sessions WHERE attendance_day_id=? ORDER BY COALESCE(time_in,created_at),id",
        [(int)$day['id']],
        'i'
    ) ?: [];

    $first=$sessions[0] ?? null;
    $second=$sessions[1] ?? null;
    $fmt=fn($value)=>$value ? date('H:i:s',strtotime($value)) : null;

    $day['student_id']=(int)($day['student_id'] ?? 0);
    $day['date']=$day['attendance_date'];
    $day['hours_rendered']=round(((int)$day['credited_minutes'])/60,2);
    $day['time_in']=$first ? $fmt($first['time_in']) : null;
    $last=$sessions ? $sessions[count($sessions)-1] : null;
    $day['time_out']=$last ? $fmt($last['time_out']) : null;
    $day['morning_in']=$first ? $fmt($first['time_in']) : null;
    $day['morning_out']=$first ? $fmt($first['time_out']) : null;
    $day['afternoon_in']=$second ? $fmt($second['time_in']) : null;
    $day['afternoon_out']=$second ? $fmt($second['time_out']) : null;
    return $day;
}

function normalized_attendance_rows_for_student(int $studentId, string $month='', int $limit=60): array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return [];
    $params=[(int)$enrollment['id']];
    $types='i';
    $where='p.ojt_enrollment_id=?';
    if ($month!=='') {
        $where.=" AND DATE_FORMAT(ad.attendance_date,'%Y-%m')=?";
        $params[]=$month;
        $types.='s';
    }
    $limit=max(1,min(500,$limit));
    $rows=query(
        "SELECT ad.*,? student_id,p.company_id
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         WHERE $where
         ORDER BY ad.attendance_date DESC,ad.id DESC
         LIMIT $limit",
        array_merge([$studentId],$params),
        'i'.$types
    ) ?: [];
    return array_map('normalized_attendance_shape',$rows);
}

function normalized_attendance_for_student_date(int $studentId,string $date): ?array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return null;
    $day=query_one(
        "SELECT ad.*,? student_id,p.company_id
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id=? AND ad.attendance_date=?
         ORDER BY ad.id DESC LIMIT 1",
        [$studentId,(int)$enrollment['id'],$date],
        'iis'
    );
    return $day ? normalized_attendance_shape($day) : null;
}

function normalized_attendance_rows_for_company(int $companyId,int $studentId=0,string $month=''): array
{
    $termId=normalized_active_term_id();
    if ($termId<=0) return [];
    $where="oe.academic_term_id=? AND p.company_id=?";
    $params=[$termId,$companyId];
    $types='ii';
    if ($studentId>0) {
        $where.=" AND oe.student_id=?";
        $params[]=$studentId;
        $types.='i';
    }
    if ($month!=='') {
        $where.=" AND DATE_FORMAT(ad.attendance_date,'%Y-%m')=?";
        $params[]=$month;
        $types.='s';
    }
    $rows=query(
        "SELECT ad.*,oe.student_id,p.company_id,u.name student_name,s.student_id_no
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         WHERE $where
         ORDER BY ad.attendance_date DESC,u.name",
        $params,
        $types
    ) ?: [];
    return array_map('normalized_attendance_shape',$rows);
}

function normalized_attendance_rows_for_coordinator_date(int $coordinatorId,string $date,string $search=''): array
{
    $students=normalized_students_for_coordinator($coordinatorId,$search);
    $rows=[];
    foreach ($students as $student) {
        $att=normalized_attendance_for_student_date((int)$student['id'],$date);
        $base=[
            'id'=>(int)$student['id'],
            'student_id'=>(int)$student['id'],
            'name'=>$student['name'],
            'student_name'=>$student['name'],
            'student_id_no'=>$student['student_id_no'],
            'company_name'=>$student['company_name'] ?? null,
            'program'=>$student['program'] ?? null,
            'date'=>$date,
            'status'=>null,
            'att_status'=>null,
            'morning_in'=>null,'morning_out'=>null,'afternoon_in'=>null,'afternoon_out'=>null,
            'time_in'=>null,'time_out'=>null,'hours_rendered'=>0,'remarks'=>null,
        ];
        if ($att) {
            $att['att_status']=$att['status'];
            $rows[]=array_merge($base,$att);
        } else {
            $rows[]=$base;
        }
    }
    return $rows;
}

function normalized_attendance_punch(int $studentId,int $companyId,string $date,string $time,int $actorUserId): string
{
    $placement=normalized_current_placement_for_student($studentId,$companyId);
    if (!$placement || !in_array($placement['status'],['active','on_hold','planned'],true)) {
        throw new DomainException('This trainee does not have an active placement with your company.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !preg_match('/^\d{2}:\d{2}:\d{2}$/',$time)) {
        throw new DomainException('Invalid attendance timestamp.');
    }

    $stamp="$date $time";
    $isPm=(int)date('G',strtotime($stamp))>=12;
    db()->begin_transaction();
    try {
        $day=query_one(
            "SELECT * FROM attendance_days WHERE placement_id=? AND attendance_date=? FOR UPDATE",
            [(int)$placement['id'],$date],
            'is'
        );
        if (!$day) {
            $dayId=(int)insert(
                "INSERT INTO attendance_days(placement_id,attendance_date,status,credited_minutes)
                 VALUES(?,?,'present',0)",
                [(int)$placement['id'],$date],
                'is'
            );
            $day=query_one("SELECT * FROM attendance_days WHERE id=?",[$dayId],'i');
        } else {
            $dayId=(int)$day['id'];
        }

        $sessions=query(
            "SELECT * FROM attendance_sessions WHERE attendance_day_id=? ORDER BY COALESCE(time_in,created_at),id FOR UPDATE",
            [$dayId],
            'i'
        ) ?: [];

        $target=null;
        foreach ($sessions as $session) {
            if (empty($session['time_in'])) continue;
            $sessionPm=(int)date('G',strtotime($session['time_in']))>=12;
            if ($sessionPm===$isPm) { $target=$session; break; }
        }

        if (!$target) {
            insert(
                "INSERT INTO attendance_sessions
                 (attendance_day_id,time_in,time_out,credited_minutes,source,recorded_by)
                 VALUES(?,?,NULL,0,'company',?)",
                [$dayId,$stamp,$actorUserId],
                'isi'
            );
            $result='time_in';
        } elseif (empty($target['time_out'])) {
            if (strtotime($stamp)<=strtotime($target['time_in'])) {
                throw new DomainException('Time out must be later than time in.');
            }
            $minutes=max(0,(int)floor((strtotime($stamp)-strtotime($target['time_in']))/60));
            query(
                "UPDATE attendance_sessions
                 SET time_out=?,credited_minutes=?,recorded_by=?
                 WHERE id=?",
                [$stamp,$minutes,$actorUserId,(int)$target['id']],
                'siii'
            );
            $result='time_out';
        } else {
            db()->commit();
            return 'skipped';
        }

        $total=(int)(query_one(
            "SELECT COALESCE(SUM(credited_minutes),0) m FROM attendance_sessions WHERE attendance_day_id=?",
            [$dayId],
            'i'
        )['m'] ?? 0);
        query(
            "UPDATE attendance_days SET status='present',credited_minutes=? WHERE id=?",
            [$total,$dayId],
            'ii'
        );
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_journal_legacy_status(string $status): string
{
    return match($status) {
        'approved'=>'approved',
        'returned'=>'rejected',
        default=>'pending',
    };
}

function normalized_journal_rows_for_student(int $studentId,int $limit=500): array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return [];
    $limit=max(1,min(1000,$limit));
    $rows=query(
        "SELECT jd.id,jd.entry_date,jd.week_number,jr.id revision_id,jr.revision_no,
                jr.activities,jr.learnings,jr.challenges,jr.claimed_minutes,jr.status normalized_status,
                jr.submitted_at,jr.reviewed_by,jr.reviewed_at,jr.review_notes,
                (SELECT a.storage_key
                 FROM journal_revision_attachments jra
                 JOIN attachments a ON a.id=jra.attachment_id
                 WHERE jra.journal_revision_id=jr.id ORDER BY a.id LIMIT 1) proof_image
         FROM journal_days jd
         JOIN placements p ON p.id=jd.placement_id
         JOIN journal_revisions jr ON jr.id=(
            SELECT jr2.id FROM journal_revisions jr2
            WHERE jr2.journal_day_id=jd.id
            ORDER BY jr2.revision_no DESC,jr2.id DESC LIMIT 1
         )
         WHERE p.ojt_enrollment_id=?
         ORDER BY jd.entry_date DESC,jd.id DESC
         LIMIT $limit",
        [(int)$enrollment['id']],
        'i'
    ) ?: [];
    foreach ($rows as &$row) {
        $row['student_id']=$studentId;
        $row['status']=normalized_journal_legacy_status($row['normalized_status']);
        $row['hours_rendered']=round(((int)$row['claimed_minutes'])/60,2);
        $row['coordinator_remarks']=$row['review_notes'];
    }
    unset($row);
    return $rows;
}

function normalized_journal_get(int $journalDayId,int $studentId): ?array
{
    foreach (normalized_journal_rows_for_student($studentId) as $row) {
        if ((int)$row['id']===$journalDayId) return $row;
    }
    return null;
}

function normalized_journal_save(
    int $studentId,int $actorUserId,string $date,int $weekNumber,string $activities,
    string $learnings,string $challenges,float $hours,int $journalDayId=0,
    ?string $proofStorageKey=null,?string $originalFilename=null
): int {
    $placement=normalized_current_placement_for_student($studentId);
    if (!$placement) throw new DomainException('No current OJT placement is available.');
    if ($hours<0 || $hours>24) throw new DomainException('Journal hours must be between 0 and 24.');
    $minutes=max(0,(int)round($hours*60));

    db()->begin_transaction();
    try {
        $previousRevisionId=null;
        if ($journalDayId>0) {
            $existing=query_one(
                "SELECT jd.*,p.ojt_enrollment_id
                 FROM journal_days jd JOIN placements p ON p.id=jd.placement_id
                 WHERE jd.id=? FOR UPDATE",
                [$journalDayId],
                'i'
            );
            $enrollment=normalized_enrollment_for_student($studentId);
            if (!$existing || !$enrollment || (int)$existing['ojt_enrollment_id']!==(int)$enrollment['id']) {
                throw new DomainException('Journal entry not found.');
            }
            $latest=query_one(
                "SELECT * FROM journal_revisions WHERE journal_day_id=?
                 ORDER BY revision_no DESC,id DESC LIMIT 1 FOR UPDATE",
                [$journalDayId],
                'i'
            );
            if (!$latest || $latest['status']==='approved') {
                throw new DomainException('Approved journal entries cannot be changed.');
            }
            if (query_one(
                "SELECT id FROM journal_days WHERE placement_id=? AND entry_date=? AND id<>? LIMIT 1",
                [(int)$existing['placement_id'],$date,$journalDayId],
                'isi'
            )) throw new DomainException('A journal already exists for this date.');
            query(
                "UPDATE journal_days SET entry_date=?,week_number=? WHERE id=?",
                [$date,$weekNumber,$journalDayId],
                'sii'
            );
            $revisionNo=(int)$latest['revision_no']+1;
            $previousRevisionId=(int)$latest['id'];
        } else {
            if (query_one(
                "SELECT id FROM journal_days WHERE placement_id=? AND entry_date=? LIMIT 1",
                [(int)$placement['id'],$date],
                'is'
            )) throw new DomainException('A journal already exists for this date.');
            $journalDayId=(int)insert(
                "INSERT INTO journal_days(placement_id,entry_date,week_number) VALUES(?,?,?)",
                [(int)$placement['id'],$date,$weekNumber],
                'isi'
            );
            $revisionNo=1;
        }

        $revisionId=(int)insert(
            "INSERT INTO journal_revisions
             (journal_day_id,revision_no,activities,learnings,challenges,claimed_minutes,status,submitted_at)
             VALUES(?,?,?,?,?,?,'submitted',NOW())",
            [$journalDayId,$revisionNo,$activities,$learnings,$challenges,$minutes],
            'iisssi'
        );

        if ($proofStorageKey) {
            $attachmentId=normalized_register_private_attachment(
                $proofStorageKey,$originalFilename ?: basename($proofStorageKey),$actorUserId
            );
            if (normalized_lean_schema_ready()) {
                query("UPDATE journal_revisions SET attachment_id=? WHERE id=?",[$attachmentId,$revisionId],'ii');
            } else {
                insert(
                    "INSERT INTO journal_revision_attachments(journal_revision_id,attachment_id) VALUES(?,?)",
                    [$revisionId,$attachmentId],
                    'ii'
                );
            }
        } elseif ($previousRevisionId) {
            if (normalized_lean_schema_ready()) {
                $previousAttachment=query_one("SELECT attachment_id FROM journal_revisions WHERE id=?",[$previousRevisionId],'i');
                if (!empty($previousAttachment['attachment_id'])) {
                    query(
                        "UPDATE journal_revisions SET attachment_id=? WHERE id=?",
                        [(int)$previousAttachment['attachment_id'],$revisionId],
                        'ii'
                    );
                }
            } else {
                query(
                    "INSERT IGNORE INTO journal_revision_attachments(journal_revision_id,attachment_id)
                     SELECT ?,attachment_id FROM journal_revision_attachments WHERE journal_revision_id=?",
                    [$revisionId,$previousRevisionId],
                    'ii'
                );
            }
        }

        db()->commit();
        return $journalDayId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_journal_review(int $journalDayId,int $coordinatorId,int $reviewerUserId,string $decision,string $remarks): ?array
{
    if (!in_array($decision,['approved','returned'],true)) throw new DomainException('Invalid journal decision.');
    $row=query_one(
        "SELECT jd.id,jd.entry_date,oe.student_id,s.user_id,u.name student_name
         FROM journal_days jd
         JOIN placements p ON p.id=jd.placement_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         WHERE jd.id=? AND ec.coordinator_id=? LIMIT 1",
        [$journalDayId,$coordinatorId],
        'ii'
    );
    if (!$row) return null;

    $latest=query_one(
        "SELECT * FROM journal_revisions WHERE journal_day_id=?
         ORDER BY revision_no DESC,id DESC LIMIT 1",
        [$journalDayId],
        'i'
    );
    if (!$latest) return null;
    query(
        "UPDATE journal_revisions
         SET status=?,review_notes=?,reviewed_by=?,reviewed_at=NOW()
         WHERE id=?",
        [$decision,$remarks,$reviewerUserId,(int)$latest['id']],
        'ssii'
    );
    return $row;
}

function normalized_requirement_legacy_status(array $row): string
{
    if (($row['assignment_status'] ?? '')==='waived') return 'approved';
    return match($row['submission_status'] ?? null) {
        'approved'=>'approved',
        'returned'=>'rejected',
        default=>'pending',
    };
}

function normalized_requirement_rows_for_student(int $studentId): array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return [];
    $rows=query(
        "SELECT ra.id,ra.due_date deadline,ra.assignment_note,ra.status assignment_status,
                rd.title document_name,rd.id definition_id,rdv.id definition_version_id,
                rs.id submission_id,rs.version_no submission_version,rs.status submission_status,
                rs.submitted_at,rs.reviewed_by,rs.reviewed_at,rs.review_notes,
                (SELECT a.storage_key
                 FROM requirement_submission_attachments rsa
                 JOIN attachments a ON a.id=rsa.attachment_id
                 WHERE rsa.requirement_submission_id=rs.id ORDER BY a.id LIMIT 1) file_path
         FROM requirement_assignments ra
         JOIN requirement_definition_versions rdv ON rdv.id=ra.requirement_definition_version_id
         JOIN requirement_definitions rd ON rd.id=rdv.requirement_definition_id
         LEFT JOIN requirement_submissions rs ON rs.id=(
            SELECT rs2.id FROM requirement_submissions rs2
            WHERE rs2.requirement_assignment_id=ra.id
            ORDER BY rs2.version_no DESC,rs2.id DESC LIMIT 1
         )
         WHERE ra.ojt_enrollment_id=?
         ORDER BY (ra.due_date IS NULL),ra.due_date,ra.id",
        [(int)$enrollment['id']],
        'i'
    ) ?: [];
    foreach ($rows as &$row) {
        $row['student_id']=$studentId;
        $row['status']=normalized_requirement_legacy_status($row);
        $row['remarks']=$row['review_notes'] ?: $row['assignment_note'];
    }
    unset($row);
    return $rows;
}

function normalized_requirement_get(int $assignmentId,int $studentId): ?array
{
    foreach (normalized_requirement_rows_for_student($studentId) as $row) {
        if ((int)$row['id']===$assignmentId) return $row;
    }
    return null;
}

function normalized_requirement_submit(
    int $assignmentId,int $studentId,int $studentUserId,
    ?string $storageKey=null,?string $originalFilename=null
): int {
    $row=normalized_requirement_get($assignmentId,$studentId);
    if (!$row) throw new DomainException('Requirement assignment not found.');
    if ($row['status']==='approved') throw new DomainException('Approved submissions cannot be replaced.');

    db()->begin_transaction();
    try {
        $latest=query_one(
            "SELECT * FROM requirement_submissions
             WHERE requirement_assignment_id=?
             ORDER BY version_no DESC,id DESC LIMIT 1 FOR UPDATE",
            [$assignmentId],
            'i'
        );
        $version=$latest ? (int)$latest['version_no']+1 : 1;
        $submissionId=(int)insert(
            "INSERT INTO requirement_submissions
             (requirement_assignment_id,version_no,submitted_by,status,submitted_at)
             VALUES(?,?,?,'submitted',NOW())",
            [$assignmentId,$version,$studentUserId],
            'iii'
        );

        if ($storageKey) {
            $attachmentId=normalized_register_private_attachment(
                $storageKey,$originalFilename ?: basename($storageKey),$studentUserId
            );
            if (normalized_lean_schema_ready()) {
                query("UPDATE requirement_submissions SET attachment_id=? WHERE id=?",[$attachmentId,$submissionId],'ii');
            } else {
                insert(
                    "INSERT INTO requirement_submission_attachments(requirement_submission_id,attachment_id)
                     VALUES(?,?)",
                    [$submissionId,$attachmentId],
                    'ii'
                );
            }
        } elseif ($latest) {
            if (normalized_lean_schema_ready()) {
                $previousAttachment=query_one("SELECT attachment_id FROM requirement_submissions WHERE id=?",[(int)$latest['id']],'i');
                if (!empty($previousAttachment['attachment_id'])) {
                    query(
                        "UPDATE requirement_submissions SET attachment_id=? WHERE id=?",
                        [(int)$previousAttachment['attachment_id'],$submissionId],
                        'ii'
                    );
                }
            } else {
                query(
                    "INSERT IGNORE INTO requirement_submission_attachments(requirement_submission_id,attachment_id)
                     SELECT ?,attachment_id FROM requirement_submission_attachments
                     WHERE requirement_submission_id=?",
                    [$submissionId,(int)$latest['id']],
                    'ii'
                );
            }
        }

        $hasAttachment = normalized_lean_schema_ready()
            ? (bool)query_one("SELECT 1 FROM requirement_submissions WHERE id=? AND attachment_id IS NOT NULL",[$submissionId],'i')
            : (bool)query_one(
                "SELECT 1 FROM requirement_submission_attachments WHERE requirement_submission_id=? LIMIT 1",
                [$submissionId],
                'i'
            );
        if (!$hasAttachment) throw new DomainException('Choose a document before submitting this assignment.');

        query("UPDATE requirement_assignments SET status='assigned' WHERE id=?",[$assignmentId],'i');
        db()->commit();
        return $submissionId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_requirement_review(
    int $assignmentId,int $coordinatorId,int $reviewerUserId,string $decision,string $remarks
): ?array {
    if (!in_array($decision,['approved','returned'],true)) throw new DomainException('Invalid requirement decision.');
    $row=query_one(
        "SELECT ra.id,rd.title document_name,oe.student_id,s.user_id,u.name student_name
         FROM requirement_assignments ra
         JOIN requirement_definition_versions rdv ON rdv.id=ra.requirement_definition_version_id
         JOIN requirement_definitions rd ON rd.id=rdv.requirement_definition_id
         JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         WHERE ra.id=? AND ec.coordinator_id=? LIMIT 1",
        [$assignmentId,$coordinatorId],
        'ii'
    );
    if (!$row) return null;
    $latest=query_one(
        "SELECT * FROM requirement_submissions
         WHERE requirement_assignment_id=?
         ORDER BY version_no DESC,id DESC LIMIT 1",
        [$assignmentId],
        'i'
    );
    if (!$latest) throw new DomainException('A submission is required before review.');

    query(
        "UPDATE requirement_submissions
         SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=?
         WHERE id=?",
        [$decision,$reviewerUserId,$remarks,(int)$latest['id']],
        'sisi'
    );
    query(
        "UPDATE requirement_assignments SET status=? WHERE id=?",
        [$decision==='approved'?'closed':'assigned',$assignmentId],
        'si'
    );
    if ($decision==='approved') normalized_set_onboarding_complete_if_ready((int)$row['student_id']);
    return $row;
}

function normalized_requirement_templates_for_coordinator(int $userId): array
{
    return query(
        "SELECT rd.id,rd.title name,rdv.description,rdv.id version_id,rdv.version_no,rd.status
         FROM requirement_definitions rd
         JOIN requirement_definition_versions rdv ON rdv.id=(
            SELECT v.id FROM requirement_definition_versions v
            WHERE v.requirement_definition_id=rd.id
            ORDER BY v.version_no DESC,v.id DESC LIMIT 1
         )
         WHERE rd.created_by=? AND rd.status='active'
         ORDER BY rd.id DESC",
        [$userId],
        'i'
    ) ?: [];
}

function normalized_requirement_add_template(int $userId,string $name,string $description=''): int
{
    db()->begin_transaction();
    try {
        $definitionId=(int)insert(
            "INSERT INTO requirement_definitions(created_by,title,status,created_at)
             VALUES(?,?,'active',NOW())",
            [$userId,$name],
            'is'
        );
        insert(
            "INSERT INTO requirement_definition_versions
             (requirement_definition_id,version_no,description,status,published_at,created_at)
             VALUES(?,1,?,'published',NOW(),NOW())",
            [$definitionId,$description],
            'is'
        );
        db()->commit();
        return $definitionId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_requirement_archive_template(int $userId,int $definitionId): bool
{
    $owned=query_one(
        "SELECT id FROM requirement_definitions WHERE id=? AND created_by=? LIMIT 1",
        [$definitionId,$userId],
        'ii'
    );
    if (!$owned) return false;
    query("UPDATE requirement_definitions SET status='archived' WHERE id=?",[$definitionId],'i');
    query(
        "UPDATE requirement_definition_versions SET status='retired'
         WHERE requirement_definition_id=? AND status!='retired'",
        [$definitionId],
        'i'
    );
    return true;
}

function normalized_requirement_assign(
    int $definitionId,int $studentId,int $coordinatorId,int $assignedBy,?string $deadline
): string {
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return 'invalid';
    if (!query_one(
        "SELECT id FROM enrollment_coordinators
         WHERE ojt_enrollment_id=? AND coordinator_id=? AND ended_at IS NULL LIMIT 1",
        [(int)$enrollment['id'],$coordinatorId],
        'ii'
    )) return 'invalid';

    $version=query_one(
        "SELECT rdv.id,rd.title,rdv.description
         FROM requirement_definitions rd
         JOIN requirement_definition_versions rdv ON rdv.requirement_definition_id=rd.id
         WHERE rd.id=? AND rd.created_by=? AND rd.status='active' AND rdv.status='published'
         ORDER BY rdv.version_no DESC,rdv.id DESC LIMIT 1",
        [$definitionId,$assignedBy],
        'ii'
    );
    if (!$version) return 'invalid';

    $exists=query_one(
        "SELECT ra.id
         FROM requirement_assignments ra
         JOIN requirement_definition_versions rv ON rv.id=ra.requirement_definition_version_id
         WHERE ra.ojt_enrollment_id=? AND rv.requirement_definition_id=? LIMIT 1",
        [(int)$enrollment['id'],$definitionId],
        'ii'
    );
    if ($exists) return 'duplicate';

    insert(
        "INSERT INTO requirement_assignments
         (requirement_definition_version_id,ojt_enrollment_id,assigned_by,due_date,assignment_note,status,assigned_at)
         VALUES(?,?,?,?,?,'assigned',NOW())",
        [(int)$version['id'],(int)$enrollment['id'],$assignedBy,$deadline,$version['description']],
        'iiiss'
    );
    return 'created';
}

function normalized_requirement_rows_for_coordinator(int $coordinatorId,string $status='',string $search=''): array
{
    $termId=normalized_active_term_id();
    if ($termId<=0) return [];
    $params=[$termId,$coordinatorId];
    $types='ii';
    $where="oe.academic_term_id=? AND ec.coordinator_id=? AND ec.ended_at IS NULL";
    if ($search!=='') {
        $where.=" AND (u.name LIKE ? OR s.student_id_no LIKE ? OR rd.title LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like,$like);
        $types.='sss';
    }
    $rows=query(
        "SELECT ra.id,oe.student_id,s.user_id,u.name student_name,s.student_id_no,p.code program,
                rd.title document_name,ra.due_date deadline,ra.assignment_note,ra.status assignment_status,
                rs.id submission_id,rs.status submission_status,rs.submitted_at,rs.reviewed_by,rs.reviewed_at,rs.review_notes,
                (SELECT a.storage_key FROM requirement_submission_attachments rsa
                 JOIN attachments a ON a.id=rsa.attachment_id
                 WHERE rsa.requirement_submission_id=rs.id ORDER BY a.id LIMIT 1) file_path
         FROM requirement_assignments ra
         JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         JOIN programs p ON p.id=oe.program_id
         JOIN requirement_definition_versions rv ON rv.id=ra.requirement_definition_version_id
         JOIN requirement_definitions rd ON rd.id=rv.requirement_definition_id
         LEFT JOIN requirement_submissions rs ON rs.id=(
            SELECT rs2.id FROM requirement_submissions rs2
            WHERE rs2.requirement_assignment_id=ra.id
            ORDER BY rs2.version_no DESC,rs2.id DESC LIMIT 1
         )
         WHERE $where
         ORDER BY rs.submitted_at DESC,ra.id DESC",
        $params,
        $types
    ) ?: [];

    $filtered=[];
    foreach ($rows as $row) {
        $row['student_id']=(int)$row['student_id'];
        $row['status']=normalized_requirement_legacy_status($row);
        $row['remarks']=$row['review_notes'] ?: $row['assignment_note'];
        if ($status==='' || $row['status']===$status) $filtered[]=$row;
    }
    return $filtered;
}

function normalized_report_legacy_status(array $row): string
{
    if (($row['assignment_status'] ?? '')==='waived') return 'approved';
    return match($row['submission_status'] ?? null) {
        'approved'=>'approved',
        'returned'=>'rejected',
        'submitted'=>'for_review',
        default=>'pending',
    };
}

function normalized_report_rows_for_student(int $studentId): array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return [];
    $rows=query(
        "SELECT ra.id,rt.title report_name,rtv.report_type,ra.period_start,ra.period_end,
                ra.due_date deadline,ra.status assignment_status,
                rs.id submission_id,rs.version_no submission_version,rs.status submission_status,
                rs.submitted_at,rs.student_note,rs.reviewed_by,rs.reviewed_at,rs.review_notes,rs.evidence_snapshot,
                (SELECT a.storage_key
                 FROM report_submission_attachments rsa
                 JOIN attachments a ON a.id=rsa.attachment_id
                 WHERE rsa.report_submission_id=rs.id ORDER BY a.id LIMIT 1) file_path
         FROM report_assignments ra
         JOIN report_template_versions rtv ON rtv.id=ra.report_template_version_id
         JOIN report_templates rt ON rt.id=rtv.report_template_id
         LEFT JOIN report_submissions rs ON rs.id=(
            SELECT rs2.id FROM report_submissions rs2
            WHERE rs2.report_assignment_id=ra.id
            ORDER BY rs2.version_no DESC,rs2.id DESC LIMIT 1
         )
         WHERE ra.ojt_enrollment_id=?
         ORDER BY FIELD(rtv.report_type,'initial','midterm','final','monthly','custom'),ra.period_start,ra.id",
        [(int)$enrollment['id']],
        'i'
    ) ?: [];
    foreach ($rows as &$row) {
        $row['student_id']=$studentId;
        $row['status']=normalized_report_legacy_status($row);
        $row['remarks']=$row['review_notes'] ?: $row['student_note'];
    }
    unset($row);
    return $rows;
}

function normalized_report_get(int $assignmentId,int $studentId): ?array
{
    foreach (normalized_report_rows_for_student($studentId) as $row) {
        if ((int)$row['id']===$assignmentId) return $row;
    }
    return null;
}

function normalized_report_submit(
    int $assignmentId,int $studentId,int $studentUserId,string $studentNote='',
    ?string $storageKey=null,?string $originalFilename=null,?string $evidenceSnapshot=null
): int {
    $row=normalized_report_get($assignmentId,$studentId);
    if (!$row) throw new DomainException('Report assignment not found.');
    if ($row['status']==='approved') throw new DomainException('Approved submissions cannot be replaced.');

    db()->begin_transaction();
    try {
        $latest=query_one(
            "SELECT * FROM report_submissions
             WHERE report_assignment_id=?
             ORDER BY version_no DESC,id DESC LIMIT 1 FOR UPDATE",
            [$assignmentId],
            'i'
        );
        $version=$latest ? (int)$latest['version_no']+1 : 1;
        $submissionId=(int)insert(
            "INSERT INTO report_submissions
             (report_assignment_id,version_no,submitted_by,status,submitted_at,student_note,evidence_snapshot)
             VALUES(?,?,?,'submitted',NOW(),?,?)",
            [$assignmentId,$version,$studentUserId,$studentNote,$evidenceSnapshot],
            'iiiss'
        );

        if ($storageKey) {
            $attachmentId=normalized_register_private_attachment(
                $storageKey,$originalFilename ?: basename($storageKey),$studentUserId
            );
            if (normalized_lean_schema_ready()) {
                query("UPDATE report_submissions SET attachment_id=? WHERE id=?",[$attachmentId,$submissionId],'ii');
            } else {
                insert(
                    "INSERT INTO report_submission_attachments(report_submission_id,attachment_id)
                     VALUES(?,?)",
                    [$submissionId,$attachmentId],
                    'ii'
                );
            }
        } elseif ($latest) {
            if (normalized_lean_schema_ready()) {
                $previousAttachment=query_one("SELECT attachment_id FROM report_submissions WHERE id=?",[(int)$latest['id']],'i');
                if (!empty($previousAttachment['attachment_id'])) {
                    query(
                        "UPDATE report_submissions SET attachment_id=? WHERE id=?",
                        [(int)$previousAttachment['attachment_id'],$submissionId],
                        'ii'
                    );
                }
            } else {
                query(
                    "INSERT IGNORE INTO report_submission_attachments(report_submission_id,attachment_id)
                     SELECT ?,attachment_id FROM report_submission_attachments WHERE report_submission_id=?",
                    [$submissionId,(int)$latest['id']],
                    'ii'
                );
            }
        }

        $hasAttachment = normalized_lean_schema_ready()
            ? (bool)query_one("SELECT 1 FROM report_submissions WHERE id=? AND attachment_id IS NOT NULL",[$submissionId],'i')
            : (bool)query_one(
                "SELECT 1 FROM report_submission_attachments WHERE report_submission_id=? LIMIT 1",
                [$submissionId],
                'i'
            );
        if ($row['report_type']!=='monthly' && !$hasAttachment) throw new DomainException('Choose a report file before submitting this assignment.');

        query("UPDATE report_assignments SET status='assigned' WHERE id=?",[$assignmentId],'i');
        db()->commit();
        return $submissionId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_monthly_evidence_snapshot(int $studentId,string $periodStart,string $periodEnd,string $note=''): string
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) throw new DomainException('OJT enrollment not found.');
    $attendance=query(
        "SELECT ad.id attendance_day_id,ad.attendance_date date,ad.status,ad.credited_minutes
         FROM attendance_days ad JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id=? AND ad.attendance_date BETWEEN ? AND ?
         ORDER BY ad.attendance_date,ad.id",
        [(int)$enrollment['id'],$periodStart,$periodEnd],
        'iss'
    ) ?: [];
    $journals=query(
        "SELECT jd.id journal_day_id,jd.entry_date date,jd.week_number,jr.id journal_revision_id,
                jr.revision_no,jr.status,jr.claimed_minutes
         FROM journal_days jd
         JOIN placements p ON p.id=jd.placement_id
         JOIN journal_revisions jr ON jr.id=(
            SELECT jr2.id FROM journal_revisions jr2
            WHERE jr2.journal_day_id=jd.id
            ORDER BY jr2.revision_no DESC,jr2.id DESC LIMIT 1
         )
         WHERE p.ojt_enrollment_id=? AND jd.entry_date BETWEEN ? AND ?
         ORDER BY jd.entry_date,jd.id",
        [(int)$enrollment['id'],$periodStart,$periodEnd],
        'iss'
    ) ?: [];
    return json_encode([
        'schema_version'=>1,
        'basis'=>'submission_time_normalized_records',
        'generated_at'=>date('c'),
        'period_start'=>$periodStart,
        'period_end'=>$periodEnd,
        'student_note'=>$note,
        'attendance'=>$attendance,
        'journals'=>$journals,
        'totals'=>[
            'attendance_days'=>count($attendance),
            'credited_minutes'=>array_sum(array_map(fn($r)=>(int)$r['credited_minutes'],$attendance)),
            'journal_days'=>count($journals),
            'journal_claimed_minutes'=>array_sum(array_map(fn($r)=>(int)$r['claimed_minutes'],$journals)),
        ],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function normalized_report_monthly_submit(int $studentId,int $studentUserId,string $month): int
{
    if (!preg_match('/^\d{4}-\d{2}$/',$month)) throw new DomainException('Choose a valid month.');
    $periodStart=$month.'-01';
    $periodEnd=date('Y-m-t',strtotime($periodStart));
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) throw new DomainException('OJT enrollment not found.');

    $attDays=(int)(query_one(
        "SELECT COUNT(*) c FROM attendance_days ad JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id=? AND ad.attendance_date BETWEEN ? AND ?",
        [(int)$enrollment['id'],$periodStart,$periodEnd],
        'iss'
    )['c'] ?? 0);
    $journalDays=(int)(query_one(
        "SELECT COUNT(*) c FROM journal_days jd JOIN placements p ON p.id=jd.placement_id
         WHERE p.ojt_enrollment_id=? AND jd.entry_date BETWEEN ? AND ?",
        [(int)$enrollment['id'],$periodStart,$periodEnd],
        'iss'
    )['c'] ?? 0);
    if ($attDays===0 && $journalDays===0) throw new DomainException('No journal entries or attendance records exist for that month.');

    $assignment=query_one(
        "SELECT ra.id
         FROM report_assignments ra
         JOIN report_template_versions rv ON rv.id=ra.report_template_version_id
         WHERE ra.ojt_enrollment_id=? AND rv.report_type='monthly'
           AND ra.period_start=? AND ra.period_end=? LIMIT 1",
        [(int)$enrollment['id'],$periodStart,$periodEnd],
        'iss'
    );

    if (!$assignment) {
        $coord=normalized_current_coordinator_for_enrollment((int)$enrollment['id']);
        $creator=$coord ? (int)$coord['user_id'] : null;
        $template=query_one(
            "SELECT rt.id,rv.id version_id
             FROM report_templates rt
             JOIN report_template_versions rv ON rv.report_template_id=rt.id
             WHERE rt.title='Monthly OJT Report' AND rv.report_type='monthly'
               AND ((rt.created_by IS NULL AND ? IS NULL) OR rt.created_by=?)
               AND rt.status='active' AND rv.status='published'
             ORDER BY rv.version_no DESC,rv.id DESC LIMIT 1",
            [$creator,$creator],
            'ii'
        );
        if (!$template) {
            $templateId=(int)insert(
                "INSERT INTO report_templates(created_by,title,status,created_at)
                 VALUES(?,'Monthly OJT Report','active',NOW())",
                [$creator],
                'i'
            );
            $versionId=(int)insert(
                "INSERT INTO report_template_versions
                 (report_template_id,version_no,report_type,instructions,status,published_at,created_at)
                 VALUES(?,1,'monthly','Monthly compilation of normalized DTR and journal evidence.','published',NOW(),NOW())",
                [$templateId],
                'i'
            );
        } else {
            $versionId=(int)$template['version_id'];
        }
        $assignmentId=(int)insert(
            "INSERT INTO report_assignments
             (report_template_version_id,ojt_enrollment_id,assigned_by,period_start,period_end,due_date,status,assigned_at)
             VALUES(?,?,?,?,?,?,'assigned',NOW())",
            [$versionId,(int)$enrollment['id'],$creator,$periodStart,$periodEnd,$periodStart],
            'iiisss'
        );
    } else {
        $assignmentId=(int)$assignment['id'];
    }

    $minutes=(int)(query_one(
        "SELECT COALESCE(SUM(ad.credited_minutes),0) m
         FROM attendance_days ad JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id=? AND ad.attendance_date BETWEEN ? AND ?",
        [(int)$enrollment['id'],$periodStart,$periodEnd],
        'iss'
    )['m'] ?? 0);
    $note='Monthly compilation: '.$attDays.' DTR day(s), '.$journalDays.' journal entr(y/ies), '.round($minutes/60,2).' hour(s).';
    $snapshot=normalized_monthly_evidence_snapshot($studentId,$periodStart,$periodEnd,$note);
    return normalized_report_submit($assignmentId,$studentId,$studentUserId,$note,null,null,$snapshot);
}

function normalized_report_review(
    int $assignmentId,int $coordinatorId,int $reviewerUserId,string $decision,string $remarks
): ?array {
    if (!in_array($decision,['approved','returned'],true)) throw new DomainException('Invalid report decision.');
    $row=query_one(
        "SELECT ra.id,rt.title report_name,oe.student_id,s.user_id,u.name student_name
         FROM report_assignments ra
         JOIN report_template_versions rv ON rv.id=ra.report_template_version_id
         JOIN report_templates rt ON rt.id=rv.report_template_id
         JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         WHERE ra.id=? AND ec.coordinator_id=? LIMIT 1",
        [$assignmentId,$coordinatorId],
        'ii'
    );
    if (!$row) return null;
    $latest=query_one(
        "SELECT * FROM report_submissions WHERE report_assignment_id=?
         ORDER BY version_no DESC,id DESC LIMIT 1",
        [$assignmentId],
        'i'
    );
    if (!$latest) throw new DomainException('A submission is required before review.');
    query(
        "UPDATE report_submissions
         SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=?
         WHERE id=?",
        [$decision,$reviewerUserId,$remarks,(int)$latest['id']],
        'sisi'
    );
    query(
        "UPDATE report_assignments SET status=? WHERE id=?",
        [$decision==='approved'?'closed':'assigned',$assignmentId],
        'si'
    );
    return $row;
}

function normalized_report_rows_for_coordinator(int $coordinatorId,string $status='',string $search=''): array
{
    $termId=normalized_active_term_id();
    if ($termId<=0) return [];
    $params=[$termId,$coordinatorId];
    $types='ii';
    $where="oe.academic_term_id=? AND ec.coordinator_id=? AND ec.ended_at IS NULL";
    if ($search!=='') {
        $where.=" AND (u.name LIKE ? OR s.student_id_no LIKE ? OR rt.title LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like,$like);
        $types.='sss';
    }
    $rows=query(
        "SELECT ra.id,oe.student_id,s.user_id,u.name student_name,s.student_id_no,p.code program,
                rt.title report_name,rv.report_type,ra.due_date deadline,ra.period_start,ra.period_end,
                ra.status assignment_status,rs.status submission_status,rs.submitted_at,rs.student_note,
                rs.reviewed_by,rs.reviewed_at,rs.review_notes,rs.evidence_snapshot,
                (SELECT a.storage_key FROM report_submission_attachments rsa
                 JOIN attachments a ON a.id=rsa.attachment_id
                 WHERE rsa.report_submission_id=rs.id ORDER BY a.id LIMIT 1) file_path
         FROM report_assignments ra
         JOIN report_template_versions rv ON rv.id=ra.report_template_version_id
         JOIN report_templates rt ON rt.id=rv.report_template_id
         JOIN ojt_enrollments oe ON oe.id=ra.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         JOIN programs p ON p.id=oe.program_id
         LEFT JOIN report_submissions rs ON rs.id=(
            SELECT rs2.id FROM report_submissions rs2
            WHERE rs2.report_assignment_id=ra.id
            ORDER BY rs2.version_no DESC,rs2.id DESC LIMIT 1
         )
         WHERE $where
         ORDER BY rs.submitted_at DESC,ra.id DESC",
        $params,
        $types
    ) ?: [];
    $filtered=[];
    foreach ($rows as $row) {
        $row['status']=normalized_report_legacy_status($row);
        $row['remarks']=$row['review_notes'] ?: $row['student_note'];
        if ($status==='' || $row['status']===$status) $filtered[]=$row;
    }
    return $filtered;
}
