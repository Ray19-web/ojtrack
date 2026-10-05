<?php

function normalized_eval_form_row(int $versionId, ?int $ownerUserId=null): ?array
{
    $params=[$versionId];
    $types='i';
    $ownerSql='';
    if ($ownerUserId!==null) {
        $ownerSql=' AND ed.created_by=?';
        $params[]=$ownerUserId;
        $types.='i';
    }
    $row=query_one(
        "SELECT edv.id,ed.id definition_id,ed.created_by,ed.title,ed.description,
                CASE
                  WHEN ed.status='archived' OR edv.status='retired' THEN 'archived'
                  WHEN edv.status='published' THEN 'active'
                  ELSE 'draft'
                END status,
                edv.version_no version,ed.id parent_id,edv.score_mode,edv.rating_max,
                edv.published_at,edv.created_at
         FROM evaluation_definition_versions edv
         JOIN evaluation_definitions ed ON ed.id=edv.evaluation_definition_id
         WHERE edv.id=? $ownerSql LIMIT 1",
        $params,
        $types
    );
    return $row ?: null;
}

function normalized_eval_forms_for_owner(int $ownerUserId): array
{
    return query(
        "SELECT edv.id,ed.id definition_id,ed.created_by,ed.title,ed.description,
                CASE
                  WHEN ed.status='archived' OR edv.status='retired' THEN 'archived'
                  WHEN edv.status='published' THEN 'active'
                  ELSE 'draft'
                END status,
                edv.version_no version,ed.id parent_id,edv.score_mode,edv.rating_max,
                edv.published_at,edv.created_at,
                (SELECT COUNT(*) FROM evaluation_requests er
                 WHERE er.evaluation_definition_version_id=edv.id) sub_count,
                (SELECT COUNT(*) FROM evaluation_requests er
                 JOIN evaluation_submissions es ON es.evaluation_request_id=er.id
                 WHERE er.evaluation_definition_version_id=edv.id) done_count
         FROM evaluation_definitions ed
         JOIN evaluation_definition_versions edv ON edv.id=(
            SELECT v.id FROM evaluation_definition_versions v
            WHERE v.evaluation_definition_id=ed.id
            ORDER BY v.version_no DESC,v.id DESC LIMIT 1
         )
         WHERE ed.created_by=?
         ORDER BY ed.id DESC",
        [$ownerUserId],
        'i'
    ) ?: [];
}

function normalized_eval_sections(int $versionId): array
{
    return query(
        "SELECT id,evaluation_definition_version_id form_id,title,sort_order
         FROM evaluation_version_sections
         WHERE evaluation_definition_version_id=?
         ORDER BY sort_order,id",
        [$versionId],
        'i'
    ) ?: [];
}

function normalized_eval_criteria(int $sectionId): array
{
    return query(
        "SELECT id,evaluation_version_section_id section_id,criterion_code,label,description,weight,sort_order
         FROM evaluation_version_criteria
         WHERE evaluation_version_section_id=?
         ORDER BY sort_order,id",
        [$sectionId],
        'i'
    ) ?: [];
}

function normalized_eval_rules(int $versionId): array
{
    return query(
        "SELECT id,evaluation_definition_version_id form_id,score_min,score_max,equivalent,description
         FROM evaluation_version_rating_rules
         WHERE evaluation_definition_version_id=?
         ORDER BY score_min DESC,id",
        [$versionId],
        'i'
    ) ?: [];
}

function normalized_eval_assert_draft_owned(int $versionId,int $ownerUserId): array
{
    $form=normalized_eval_form_row($versionId,$ownerUserId);
    if (!$form) throw new DomainException('Form not found or not owned by you.');
    if ($form['status']!=='draft') throw new DomainException('Published or assigned forms are read-only. Create a new draft version.');
    if (query_one(
        "SELECT id FROM evaluation_requests WHERE evaluation_definition_version_id=? LIMIT 1",
        [$versionId],
        'i'
    )) throw new DomainException('Assigned forms are read-only. Create a new draft version.');
    return $form;
}

function normalized_eval_create_form(int $ownerUserId,string $title,string $description,string $mode='percentage'): int
{
    if ($mode!=='percentage') throw new DomainException('Only percentage scoring is currently supported.');
    db()->begin_transaction();
    try {
        $definitionId=(int)insert(
            "INSERT INTO evaluation_definitions(created_by,title,description,status,created_at)
             VALUES(?,?,?,'active',NOW())",
            [$ownerUserId,$title,$description],
            'iss'
        );
        $versionId=(int)insert(
            "INSERT INTO evaluation_definition_versions
             (evaluation_definition_id,version_no,score_mode,rating_max,status,created_at)
             VALUES(?,1,'percentage',100,'draft',NOW())",
            [$definitionId],
            'i'
        );
        db()->commit();
        return $versionId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_eval_update_form(int $versionId,int $ownerUserId,string $title,string $description): bool
{
    $form=normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    query(
        "UPDATE evaluation_definitions SET title=?,description=? WHERE id=?",
        [$title,$description,(int)$form['definition_id']],
        'ssi'
    );
    return true;
}

function normalized_eval_clone_version(int $versionId,int $ownerUserId,bool $asCopy=false): int
{
    $form=normalized_eval_form_row($versionId,$ownerUserId);
    if (!$form) throw new DomainException('Form not found.');

    db()->begin_transaction();
    try {
        if ($asCopy) {
            $definitionId=(int)insert(
                "INSERT INTO evaluation_definitions(created_by,title,description,status,created_at)
                 VALUES(?,?,?,'active',NOW())",
                [$ownerUserId,$form['title'].' (Copy)',$form['description']],
                'iss'
            );
            $newVersionNo=1;
        } else {
            $definitionId=(int)$form['definition_id'];
            $newVersionNo=(int)(query_one(
                "SELECT COALESCE(MAX(version_no),0)+1 n
                 FROM evaluation_definition_versions WHERE evaluation_definition_id=?",
                [$definitionId],
                'i'
            )['n'] ?? ((int)$form['version']+1));
            query("UPDATE evaluation_definitions SET status='active' WHERE id=?",[$definitionId],'i');
        }

        $newVersionId=(int)insert(
            "INSERT INTO evaluation_definition_versions
             (evaluation_definition_id,version_no,score_mode,rating_max,status,created_at)
             VALUES(?,?,?,?,'draft',NOW())",
            [$definitionId,$newVersionNo,$form['score_mode'],(int)$form['rating_max']],
            'iisi'
        );

        foreach (normalized_eval_sections($versionId) as $section) {
            $newSectionId=(int)insert(
                "INSERT INTO evaluation_version_sections
                 (evaluation_definition_version_id,title,sort_order) VALUES(?,?,?)",
                [$newVersionId,$section['title'],(int)$section['sort_order']],
                'isi'
            );
            foreach (normalized_eval_criteria((int)$section['id']) as $criterion) {
                insert(
                    "INSERT INTO evaluation_version_criteria
                     (evaluation_version_section_id,criterion_code,label,description,weight,sort_order)
                     VALUES(?,?,?,?,?,?)",
                    [
                        $newSectionId,$criterion['criterion_code'],$criterion['label'],$criterion['description'],
                        (float)$criterion['weight'],(int)$criterion['sort_order']
                    ],
                    'isssdi'
                );
            }
        }
        foreach (normalized_eval_rules($versionId) as $rule) {
            insert(
                "INSERT INTO evaluation_version_rating_rules
                 (evaluation_definition_version_id,score_min,score_max,equivalent,description)
                 VALUES(?,?,?,?,?)",
                [
                    $newVersionId,(float)$rule['score_min'],(float)$rule['score_max'],
                    $rule['equivalent']===null?null:(float)$rule['equivalent'],$rule['description']
                ],
                'iddds'
            );
        }

        db()->commit();
        return $newVersionId;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_eval_archive_toggle(int $versionId,int $ownerUserId): string
{
    $form=normalized_eval_form_row($versionId,$ownerUserId);
    if (!$form) throw new DomainException('Form not found.');
    if ($form['status']==='archived') {
        query("UPDATE evaluation_definitions SET status='active' WHERE id=?",[(int)$form['definition_id']],'i');
        $used=(bool)query_one(
            "SELECT id FROM evaluation_requests WHERE evaluation_definition_version_id=? LIMIT 1",
            [$versionId],
            'i'
        );
        query(
            "UPDATE evaluation_definition_versions SET status=? WHERE id=?",
            [$used?'published':'draft',$versionId],
            'si'
        );
        return $used?'active':'draft';
    }
    query("UPDATE evaluation_definitions SET status='archived' WHERE id=?",[(int)$form['definition_id']],'i');
    query("UPDATE evaluation_definition_versions SET status='retired' WHERE id=?",[$versionId],'i');
    return 'archived';
}

function normalized_eval_publish(int $versionId,int $ownerUserId): bool
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    $count=(int)(query_one(
        "SELECT COUNT(*) c
         FROM evaluation_version_criteria c
         JOIN evaluation_version_sections s ON s.id=c.evaluation_version_section_id
         WHERE s.evaluation_definition_version_id=?",
        [$versionId],
        'i'
    )['c'] ?? 0);
    if ($count===0) throw new DomainException('Add at least one section and one criterion before publishing.');
    query(
        "UPDATE evaluation_definition_versions
         SET status='published',published_at=NOW() WHERE id=?",
        [$versionId],
        'i'
    );
    return true;
}

function normalized_eval_add_section(int $versionId,int $ownerUserId,string $title): int
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    $sort=(int)(query_one(
        "SELECT COALESCE(MAX(sort_order),0)+1 n FROM evaluation_version_sections
         WHERE evaluation_definition_version_id=?",
        [$versionId],
        'i'
    )['n'] ?? 1);
    return (int)insert(
        "INSERT INTO evaluation_version_sections(evaluation_definition_version_id,title,sort_order)
         VALUES(?,?,?)",
        [$versionId,$title,$sort],
        'isi'
    );
}

function normalized_eval_section_owned(int $sectionId,int $versionId): bool
{
    return (bool)query_one(
        "SELECT id FROM evaluation_version_sections
         WHERE id=? AND evaluation_definition_version_id=? LIMIT 1",
        [$sectionId,$versionId],
        'ii'
    );
}

function normalized_eval_criterion_owned(int $criterionId,int $versionId): bool
{
    return (bool)query_one(
        "SELECT c.id
         FROM evaluation_version_criteria c
         JOIN evaluation_version_sections s ON s.id=c.evaluation_version_section_id
         WHERE c.id=? AND s.evaluation_definition_version_id=? LIMIT 1",
        [$criterionId,$versionId],
        'ii'
    );
}

function normalized_eval_rename_section(int $versionId,int $ownerUserId,int $sectionId,string $title): void
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    if (!normalized_eval_section_owned($sectionId,$versionId)) throw new DomainException('Section does not belong to this form.');
    query("UPDATE evaluation_version_sections SET title=? WHERE id=?",[$title,$sectionId],'si');
}

function normalized_eval_delete_section(int $versionId,int $ownerUserId,int $sectionId): void
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    if (!normalized_eval_section_owned($sectionId,$versionId)) throw new DomainException('Section does not belong to this form.');
    query("DELETE FROM evaluation_version_sections WHERE id=?",[$sectionId],'i');
}

function normalized_eval_add_criterion(int $versionId,int $ownerUserId,int $sectionId,string $label): int
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    if (!normalized_eval_section_owned($sectionId,$versionId)) throw new DomainException('Section does not belong to this form.');
    $sort=(int)(query_one(
        "SELECT COALESCE(MAX(sort_order),0)+1 n FROM evaluation_version_criteria
         WHERE evaluation_version_section_id=?",
        [$sectionId],
        'i'
    )['n'] ?? 1);
    $code='criterion-'.bin2hex(random_bytes(8));
    return (int)insert(
        "INSERT INTO evaluation_version_criteria
         (evaluation_version_section_id,criterion_code,label,weight,sort_order)
         VALUES(?,?,?,1.000,?)",
        [$sectionId,$code,$label,$sort],
        'issi'
    );
}

function normalized_eval_delete_criterion(int $versionId,int $ownerUserId,int $criterionId): void
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    if (!normalized_eval_criterion_owned($criterionId,$versionId)) throw new DomainException('Criterion does not belong to this form.');
    query("DELETE FROM evaluation_version_criteria WHERE id=?",[$criterionId],'i');
}

function normalized_eval_add_rule(
    int $versionId,int $ownerUserId,float $min,float $max,float $equivalent,string $description
): int {
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    if ($min<0 || $max>100 || $min>$max || $equivalent<0 || $equivalent>99.99) {
        throw new DomainException('Use a valid score range from 0 to 100 and a valid equivalent.');
    }
    if (query_one(
        "SELECT id FROM evaluation_version_rating_rules
         WHERE evaluation_definition_version_id=? AND score_min<=? AND score_max>=? LIMIT 1",
        [$versionId,$max,$min],
        'idd'
    )) throw new DomainException('Rating ranges cannot overlap.');
    return (int)insert(
        "INSERT INTO evaluation_version_rating_rules
         (evaluation_definition_version_id,score_min,score_max,equivalent,description)
         VALUES(?,?,?,?,?)",
        [$versionId,$min,$max,$equivalent,$description],
        'iddds'
    );
}

function normalized_eval_delete_rule(int $versionId,int $ownerUserId,int $ruleId): void
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    query(
        "DELETE FROM evaluation_version_rating_rules
         WHERE id=? AND evaluation_definition_version_id=?",
        [$ruleId,$versionId],
        'ii'
    );
}

function normalized_eval_reorder_sections(int $versionId,int $ownerUserId,array $ids): void
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    foreach ($ids as $i=>$id) {
        if (!normalized_eval_section_owned((int)$id,$versionId)) throw new DomainException('Invalid section order.');
        query("UPDATE evaluation_version_sections SET sort_order=? WHERE id=?",[$i+1,(int)$id],'ii');
    }
}

function normalized_eval_reorder_criteria(int $versionId,int $ownerUserId,array $ids): void
{
    normalized_eval_assert_draft_owned($versionId,$ownerUserId);
    foreach ($ids as $i=>$id) {
        if (!normalized_eval_criterion_owned((int)$id,$versionId)) throw new DomainException('Invalid criterion order.');
        query("UPDATE evaluation_version_criteria SET sort_order=? WHERE id=?",[$i+1,(int)$id],'ii');
    }
}

function normalized_eval_send_form(int $versionId,int $companyId,int $coordinatorId,int $requesterUserId): int
{
    $form=normalized_eval_form_row($versionId,$requesterUserId);
    if (!$form || $form['status']!=='active' || $form['score_mode']!=='percentage') {
        throw new DomainException('Select a published percentage form.');
    }
    $companyUser=normalized_company_user($companyId);
    if (!$companyUser) throw new DomainException('The selected company has no active evaluator account.');

    $termId=normalized_active_term_id();
    $targets=query(
        "SELECT oe.student_id,p.id placement_id,s.user_id,u.name
         FROM placements p
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id AND ec.ended_at IS NULL
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         WHERE oe.academic_term_id=? AND ec.coordinator_id=? AND p.company_id=?
           AND p.status IN ('active','on_hold','planned','completed')
         ORDER BY u.name",
        [$termId,$coordinatorId,$companyId],
        'iii'
    ) ?: [];
    if (!$targets) throw new DomainException('You have no students assigned to that company.');

    $created=0;
    foreach ($targets as $target) {
        if (query_one(
            "SELECT id FROM evaluation_requests
             WHERE evaluation_definition_version_id=? AND placement_id=?
               AND evaluator_company_user_id=? AND status IN ('pending','submitted') LIMIT 1",
            [$versionId,(int)$target['placement_id'],(int)$companyUser['id']],
            'iii'
        )) continue;
        insert(
            "INSERT INTO evaluation_requests
             (evaluation_definition_version_id,placement_id,evaluator_company_user_id,requested_by,
              evaluation_kind,status,created_at)
             VALUES(?,?,?,?,'custom','pending',NOW())",
            [$versionId,(int)$target['placement_id'],(int)$companyUser['id'],$requesterUserId],
            'iiii'
        );
        create_notification(
            (int)$companyUser['user_id'],
            'OJT Coordinator sent the evaluation form "'.$form['title'].'" for '.$target['name'].'.',
            'info',
            '/ojtrack/company/evaluation.php'
        );
        $created++;
    }
    return $created;
}

function normalized_eval_requests_for_company(int $companyId,int $studentId=0): array
{
    $params=[$companyId];
    $types='i';
    $studentSql='';
    if ($studentId>0) {
        $studentSql=' AND oe.student_id=?';
        $params[]=$studentId;
        $types.='i';
    }
    $rows=query(
        "SELECT er.id,er.evaluation_definition_version_id form_id,
                CASE er.status WHEN 'submitted' THEN 'completed' ELSE er.status END status,
                er.evaluation_kind,er.created_at,
                ed.title form_title,ed.description,edv.score_mode,edv.rating_max,
                oe.student_id,s.student_id_no,pgr.code program,oe.status ojt_status,
                u.name student_name,co.company_name,
                es.overall_score,es.overall_equivalent,es.comments,es.submitted_at
         FROM evaluation_requests er
         JOIN evaluation_definition_versions edv ON edv.id=er.evaluation_definition_version_id
         JOIN evaluation_definitions ed ON ed.id=edv.evaluation_definition_id
         JOIN placements p ON p.id=er.placement_id
         JOIN companies co ON co.id=p.company_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN programs pgr ON pgr.id=oe.program_id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         LEFT JOIN evaluation_submissions es ON es.evaluation_request_id=er.id
         WHERE p.company_id=? $studentSql
         ORDER BY er.id DESC",
        $params,
        $types
    ) ?: [];
    return $rows;
}

function normalized_eval_answers_for_request(int $requestId): array
{
    return query(
        "SELECT ea.id,ea.section_title_snapshot section_title,
                ea.criterion_label_snapshot criterion_label,ea.score,ea.equivalent,
                evc.criterion_code
         FROM evaluation_submissions es
         JOIN evaluation_answers ea ON ea.evaluation_submission_id=es.id
         JOIN evaluation_version_criteria evc ON evc.id=ea.evaluation_criterion_id
         WHERE es.evaluation_request_id=?
         ORDER BY ea.id",
        [$requestId],
        'i'
    ) ?: [];
}

function normalized_submit_evaluation(int $companyId,int $requestId,array $scores,string $comments,int $actorUserId): void
{
    db()->begin_transaction();
    try {
        $request=query_one(
            "SELECT er.*,p.company_id,oe.student_id,s.user_id student_user_id,
                    ed.title,edv.score_mode,edv.rating_max,cu.user_id evaluator_user_id
             FROM evaluation_requests er
             JOIN evaluation_definition_versions edv ON edv.id=er.evaluation_definition_version_id
             JOIN evaluation_definitions ed ON ed.id=edv.evaluation_definition_id
             JOIN placements p ON p.id=er.placement_id
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
             JOIN students s ON s.id=oe.student_id
             JOIN company_users cu ON cu.id=er.evaluator_company_user_id
             WHERE er.id=? AND p.company_id=? FOR UPDATE",
            [$requestId,$companyId],
            'ii'
        );
        if (!$request) throw new DomainException('Evaluation not found.');
        if ((int)$request['evaluator_user_id']!==$actorUserId &&
            !query_one("SELECT id FROM company_users WHERE company_id=? AND user_id=? AND status='active'",[$companyId,$actorUserId],'ii')) {
            throw new DomainException('You are not an evaluator for this company.');
        }
        if ($request['status']==='submitted') {
            db()->commit();
            return;
        }
        if ($request['status']!=='pending') throw new DomainException('This evaluation request is not open.');
        if ($request['score_mode']!=='percentage') throw new DomainException('Ask your coordinator to send a supported percentage form.');

        $criteria=query(
            "SELECT c.*,s.title section_title
             FROM evaluation_version_criteria c
             JOIN evaluation_version_sections s ON s.id=c.evaluation_version_section_id
             WHERE s.evaluation_definition_version_id=?
             ORDER BY s.sort_order,c.sort_order,c.id",
            [(int)$request['evaluation_definition_version_id']],
            'i'
        ) ?: [];
        if (!$criteria) throw new DomainException('This evaluation has no criteria. Contact your coordinator.');
        $rules=normalized_eval_rules((int)$request['evaluation_definition_version_id']);

        $answers=[];
        $total=0;
        foreach ($criteria as $criterion) {
            $raw=$scores['criterion_'.(int)$criterion['id']] ?? null;
            if (!is_scalar($raw) || filter_var($raw,FILTER_VALIDATE_INT)===false || (int)$raw<0 || (int)$raw>100) {
                throw new DomainException('Answer every criterion with a whole-number score from 0 to 100.');
            }
            $score=(int)$raw;
            $equivalent=null;
            foreach ($rules as $rule) {
                if ($score>=(float)$rule['score_min'] && $score<=(float)$rule['score_max']) {
                    $equivalent=$rule['equivalent'];
                    break;
                }
            }
            $answers[]=[
                'criterion_id'=>(int)$criterion['id'],
                'section'=>$criterion['section_title'],
                'label'=>$criterion['label'],
                'score'=>$score,
                'equivalent'=>$equivalent,
            ];
            $total+=$score;
        }

        $overall=round($total/count($criteria),2);
        $overallEquivalent=null;
        $bandScore=(int)round($overall);
        foreach ($rules as $rule) {
            if ($bandScore>=(float)$rule['score_min'] && $bandScore<=(float)$rule['score_max']) {
                $overallEquivalent=$rule['equivalent'];
                break;
            }
        }

        $submissionId=(int)insert(
            "INSERT INTO evaluation_submissions
             (evaluation_request_id,submitted_by,overall_score,overall_equivalent,comments,submitted_at)
             VALUES(?,?,?,?,?,NOW())",
            [$requestId,$actorUserId,$overall,$overallEquivalent,$comments],
            'iidds'
        );
        foreach ($answers as $answer) {
            insert(
                "INSERT INTO evaluation_answers
                 (evaluation_submission_id,evaluation_criterion_id,section_title_snapshot,
                  criterion_label_snapshot,score,equivalent)
                 VALUES(?,?,?,?,?,?)",
                [
                    $submissionId,$answer['criterion_id'],$answer['section'],$answer['label'],
                    $answer['score'],$answer['equivalent']
                ],
                'iissdd'
            );
        }
        query("UPDATE evaluation_requests SET status='submitted' WHERE id=?",[$requestId],'i');

        create_notification(
            (int)$request['student_user_id'],
            'Your company submitted an OJT evaluation.',
            'success',
            '/ojtrack/student/evaluation.php'
        );
        if (!empty($request['requested_by'])) {
            create_notification(
                (int)$request['requested_by'],
                'An OJT evaluation has been submitted: '.$request['title'],
                'success',
                '/ojtrack/coordinator/evaluation.php'
            );
        }
        log_activity($actorUserId,'Evaluation Form Submitted',"Request #$requestId, Score: $overall");
        db()->commit();
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_eval_requests_for_student(int $studentId): array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return [];
    return query(
        "SELECT er.id,er.evaluation_definition_version_id form_id,er.evaluation_kind,
                CASE er.status WHEN 'submitted' THEN 'completed' ELSE er.status END status,
                ed.title form_title,ed.description,co.company_name,
                cuu.name evaluator_name,es.overall_score,es.overall_equivalent,
                es.comments,es.submitted_at evaluated_at
         FROM evaluation_requests er
         JOIN evaluation_definition_versions ev ON ev.id=er.evaluation_definition_version_id
         JOIN evaluation_definitions ed ON ed.id=ev.evaluation_definition_id
         JOIN placements p ON p.id=er.placement_id
         JOIN companies co ON co.id=p.company_id
         JOIN company_users cu ON cu.id=er.evaluator_company_user_id
         JOIN users cuu ON cuu.id=cu.user_id
         LEFT JOIN evaluation_submissions es ON es.evaluation_request_id=er.id
         WHERE p.ojt_enrollment_id=?
         ORDER BY FIELD(er.evaluation_kind,'midterm','final','custom'),er.id DESC",
        [(int)$enrollment['id']],
        'i'
    ) ?: [];
}

function normalized_eval_completed_for_coordinator(int $coordinatorId,string $search=''): array
{
    $termId=normalized_active_term_id();
    $params=[$termId,$coordinatorId];
    $types='ii';
    $where="oe.academic_term_id=? AND ec.coordinator_id=? AND ec.ended_at IS NULL AND er.status='submitted'";
    if ($search!=='') {
        $where.=" AND (u.name LIKE ? OR s.student_id_no LIKE ? OR co.company_name LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like,$like);
        $types.='sss';
    }
    $rows=query(
        "SELECT er.id,oe.student_id,u.name student_name,s.student_id_no,pg.code program,
                co.company_name,eu.name evaluator_name,er.evaluation_kind evaluation_type,
                'completed' status,es.overall_score,es.comments,es.submitted_at evaluated_at
         FROM evaluation_requests er
         JOIN evaluation_submissions es ON es.evaluation_request_id=er.id
         JOIN placements pl ON pl.id=er.placement_id
         JOIN companies co ON co.id=pl.company_id
         JOIN ojt_enrollments oe ON oe.id=pl.ojt_enrollment_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id
         JOIN programs pg ON pg.id=oe.program_id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         JOIN company_users cu ON cu.id=er.evaluator_company_user_id
         JOIN users eu ON eu.id=cu.user_id
         WHERE $where
         ORDER BY es.submitted_at DESC",
        $params,
        $types
    ) ?: [];

    foreach ($rows as &$row) {
        $row['technical_skills']=null;
        $row['work_ethic']=null;
        $row['communication']=null;
        $row['teamwork']=null;
        $row['initiative']=null;
        $row['adaptability']=null;
        foreach (normalized_eval_answers_for_request((int)$row['id']) as $answer) {
            $code=$answer['criterion_code'];
            if (array_key_exists($code,$row)) $row[$code]=$answer['score'];
        }
    }
    unset($row);
    return $rows;
}

function normalized_eval_submissions_for_requester(int $requesterUserId): array
{
    return query(
        "SELECT er.id,er.evaluation_definition_version_id form_id,
                CASE er.status WHEN 'submitted' THEN 'completed' ELSE er.status END status,
                ed.title form_title,u.name student_name,co.company_name,
                es.overall_score,es.overall_equivalent,es.comments,es.submitted_at
         FROM evaluation_requests er
         JOIN evaluation_definition_versions ev ON ev.id=er.evaluation_definition_version_id
         JOIN evaluation_definitions ed ON ed.id=ev.evaluation_definition_id
         JOIN placements p ON p.id=er.placement_id
         JOIN companies co ON co.id=p.company_id
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         LEFT JOIN evaluation_submissions es ON es.evaluation_request_id=er.id
         WHERE er.requested_by=?
         ORDER BY er.id DESC",
        [$requesterUserId],
        'i'
    ) ?: [];
}
