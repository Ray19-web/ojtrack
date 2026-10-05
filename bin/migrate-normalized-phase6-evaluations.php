<?php
/**
 * OJTrack normalized migration 006: evaluations.
 *
 * Preserves both legacy evaluation systems:
 * 1) fixed evaluations table (six hard-coded percentage criteria)
 * 2) dynamic evaluation_forms / eval_* builder and submissions
 *
 * The transitional evaluation_assignments table is not used by the current
 * application. If it contains rows, preflight blocks instead of guessing.
 *
 * Usage:
 * php bin/migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --dry-run
 * php bin/migrate-normalized-phase6-evaluations.php --academic-year=2026-2027 --semester=1st --apply
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

function p6_scalar(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    if (!$r) return 0;
    return (int)reset($r);
}
function p6_ddl(mysqli $conn,string $path): void {
    $sql=file_get_contents($path);
    if ($sql===false || trim($sql)==='') throw new RuntimeException("Missing migration DDL: $path");
    if (!$conn->multi_query($sql)) throw new RuntimeException('DDL failed: '.$conn->error);
    do {
        if ($r=$conn->store_result()) $r->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
    if ($conn->errno) throw new RuntimeException('DDL failed: '.$conn->error);
}
function p6_hash(array $data): string {
    return hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
}
function p6_form_version_status(string $status): string {
    return match($status) {
        'draft'=>'draft',
        'archived'=>'retired',
        default=>'published',
    };
}
function p6_request_status(string $status): string {
    return $status==='completed' ? 'submitted' : 'pending';
}
function p6_resolve_placement(int $studentId,int $companyId,int $termId,?string $eventDate): array {
    if ($eventDate) {
        $date=substr($eventDate,0,10);
        $dated=query(
            "SELECT p.id
             FROM placements p
             JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
             WHERE oe.student_id=? AND oe.academic_term_id=? AND p.company_id=?
               AND (p.starts_on IS NULL OR p.starts_on<=?)
               AND (p.ends_on IS NULL OR p.ends_on>=?)
             ORDER BY p.id",
            [$studentId,$termId,$companyId,$date,$date],
            'iiiss'
        ) ?: [];
        if (count($dated)===1) return ['id'=>(int)$dated[0]['id'],'count'=>1,'method'=>'company_date'];
        if (count($dated)>1) return ['id'=>0,'count'=>count($dated),'method'=>'ambiguous_company_date'];
    }
    $all=query(
        "SELECT p.id
         FROM placements p
         JOIN ojt_enrollments oe ON oe.id=p.ojt_enrollment_id
         WHERE oe.student_id=? AND oe.academic_term_id=? AND p.company_id=?
         ORDER BY p.id",
        [$studentId,$termId,$companyId],
        'iii'
    ) ?: [];
    if (count($all)===1) return ['id'=>(int)$all[0]['id'],'count'=>1,'method'=>'only_company_placement'];
    return ['id'=>0,'count'=>count($all),'method'=>'unresolved'];
}
function p6_company_account(int $companyId): ?array {
    return query_one(
        "SELECT cu.id company_user_id,cu.user_id
         FROM companies c
         JOIN company_users cu ON cu.company_id=c.id AND cu.user_id=c.user_id
         WHERE c.id=? LIMIT 1",
        [$companyId],
        'i'
    );
}
function p6_fallback_criteria($raw): ?array {
    if ($raw===null || trim((string)$raw)==='') return [];
    $decoded=json_decode((string)$raw,true);
    if (!is_array($decoded)) return null;
    $labels=[];
    foreach ($decoded as $item) {
        if (!is_scalar($item) || trim((string)$item)==='') return null;
        $labels[]=trim((string)$item);
    }
    return $labels;
}
function p6_fixed_criteria(): array {
    return [
        ['code'=>'technical_skills','label'=>'Technical Skills'],
        ['code'=>'work_ethic','label'=>'Work Ethic & Punctuality'],
        ['code'=>'communication','label'=>'Communication Skills'],
        ['code'=>'teamwork','label'=>'Teamwork & Collaboration'],
        ['code'=>'initiative','label'=>'Initiative & Problem Solving'],
        ['code'=>'adaptability','label'=>'Adaptability'],
    ];
}

foreach ([
    '001_core_identity_and_terms',
    '002_attendance',
    '003_journals_and_attachments',
    '004_requirements',
    '005_reports'
] as $required) {
    if (!query_one("SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1",[$required],'s')) {
        echo json_encode([
            'migration'=>'006_evaluations',
            'mode'=>$dryRun?'dry-run':'apply',
            'result'=>'BLOCKED',
            'preflight_problems'=>['missing_required_migration'=>$required],
        ],JSON_PRETTY_PRINT).PHP_EOL;
        exit(1);
    }
}

$already=query_one("SELECT applied_at FROM schema_migrations WHERE version='006_evaluations' LIMIT 1");
if ($already) {
    echo json_encode([
        'migration'=>'006_evaluations',
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
        'migration'=>'006_evaluations',
        'mode'=>$dryRun?'dry-run':'apply',
        'result'=>'BLOCKED',
        'preflight_problems'=>['academic_term_not_found'=>1],
    ],JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
$termId=(int)$term['id'];

$legacyForms=query("SELECT * FROM evaluation_forms ORDER BY id") ?: [];
$legacySections=query("SELECT * FROM eval_sections ORDER BY form_id,sort_order,id") ?: [];
$legacyCriteria=query(
    "SELECT c.*,s.form_id,s.title section_title,s.sort_order section_sort
     FROM eval_criteria c
     JOIN eval_sections s ON s.id=c.section_id
     ORDER BY s.form_id,s.sort_order,c.sort_order,c.id"
) ?: [];
$legacyRules=query("SELECT * FROM eval_rating_rules ORDER BY form_id,score_min,score_max,id") ?: [];
$dynamicSubs=query("SELECT * FROM eval_submissions ORDER BY id") ?: [];
$dynamicAnswers=query("SELECT * FROM eval_answers ORDER BY id") ?: [];
$fixedEvals=query("SELECT * FROM evaluations ORDER BY id") ?: [];
$transitionAssignments=query("SELECT * FROM evaluation_assignments ORDER BY id") ?: [];

$sourceSnapshot=p6_hash([
    'forms'=>$legacyForms,
    'sections'=>$legacySections,
    'criteria'=>$legacyCriteria,
    'rules'=>$legacyRules,
    'dynamic_submissions'=>$dynamicSubs,
    'dynamic_answers'=>$dynamicAnswers,
    'fixed_evaluations'=>$fixedEvals,
    'transition_assignments'=>$transitionAssignments,
]);

$formsById=[];
$sectionsByForm=[];
$criteriaByForm=[];
$criteriaBySection=[];
$rulesByForm=[];
foreach ($legacyForms as $f) $formsById[(int)$f['id']]=$f;
foreach ($legacySections as $s) {
    $sectionsByForm[(int)$s['form_id']][]=$s;
}
foreach ($legacyCriteria as $cr) {
    $criteriaByForm[(int)$cr['form_id']][]=$cr;
    $criteriaBySection[(int)$cr['section_id']][]=$cr;
}
foreach ($legacyRules as $rule) $rulesByForm[(int)$rule['form_id']][]=$rule;

$invalidForms=[];
$invalidFallbackCriteria=[];
$duplicateAnswerKeys=[];
$formSpecs=[];

foreach ($legacyForms as $form) {
    $fid=(int)$form['id'];
    if (!query_one("SELECT id FROM users WHERE id=? LIMIT 1",[(int)$form['created_by']],'i')) {
        $invalidForms[]=['form_id'=>$fid,'reason'=>'creator_user_missing'];
    }
    if (!in_array($form['status'],['draft','active','archived'],true)) {
        $invalidForms[]=['form_id'=>$fid,'reason'=>'invalid_status','value'=>$form['status']];
    }
    if ($form['score_mode']!=='percentage') {
        $invalidForms[]=['form_id'=>$fid,'reason'=>'unsupported_score_mode','value'=>$form['score_mode']];
    }
    if ((int)$form['rating_max']<=0) {
        $invalidForms[]=['form_id'=>$fid,'reason'=>'invalid_rating_max','value'=>$form['rating_max']];
    }

    $structured=$criteriaByForm[$fid] ?? [];
    $fallback=[];
    if (!$structured) {
        $fallback=p6_fallback_criteria($form['criteria']);
        if ($fallback===null) {
            $invalidFallbackCriteria[]=['form_id'=>$fid,'criteria'=>$form['criteria']];
            $fallback=[];
        }
    }

    $answerKeyCounts=[];
    foreach ($structured as $cr) {
        $key=mb_strtolower(trim((string)$cr['section_title']))."\0".mb_strtolower(trim((string)$cr['label']));
        $answerKeyCounts[$key]=($answerKeyCounts[$key] ?? 0)+1;
    }
    foreach ($answerKeyCounts as $key=>$count) {
        if ($count>1) {
            [$sec,$label]=explode("\0",$key,2);
            $duplicateAnswerKeys[]=[
                'form_id'=>$fid,
                'section_title'=>$sec,
                'criterion_label'=>$label,
                'count'=>$count,
            ];
        }
    }
    $formSpecs[$fid]=[
        'structured'=>$structured,
        'fallback'=>$fallback,
        'criterion_count'=>count($structured)+count($fallback),
    ];
}

$answersBySubmission=[];
foreach ($dynamicAnswers as $answer) {
    $answersBySubmission[(int)$answer['submission_id']][]=$answer;
}

$unmappedDynamic=[];
$invalidDynamicStates=[];
$dynamicRows=[];
foreach ($dynamicSubs as $sub) {
    $sid=(int)$sub['id'];
    $fid=(int)$sub['form_id'];
    $form=$formsById[$fid] ?? null;
    if (!$form) {
        $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'form_missing','form_id'=>$fid];
        continue;
    }
    if ((int)$sub['form_version']!==(int)$form['version']) {
        $invalidDynamicStates[]=[
            'submission_id'=>$sid,
            'reason'=>'form_version_snapshot_mismatch',
            'submission_version'=>(int)$sub['form_version'],
            'current_form_version'=>(int)$form['version'],
        ];
    }
    if (!query_one("SELECT id FROM users WHERE id=? LIMIT 1",[(int)$sub['requested_by']],'i')) {
        $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'requester_user_missing','user_id'=>(int)$sub['requested_by']];
    }

    $companyAccount=p6_company_account((int)$sub['company_id']);
    if (!$companyAccount) {
        $unmappedDynamic[]=['submission_id'=>$sid,'reason'=>'legacy_company_account_not_mapped','company_id'=>(int)$sub['company_id']];
        continue;
    }

    $eventDate=$sub['submitted_at'] ?: $sub['created_at'];
    $placement=p6_resolve_placement((int)$sub['student_id'],(int)$sub['company_id'],$termId,$eventDate);
    if ($placement['id']<=0) {
        $unmappedDynamic[]=[
            'submission_id'=>$sid,
            'student_id'=>(int)$sub['student_id'],
            'company_id'=>(int)$sub['company_id'],
            'placement_count'=>$placement['count'],
            'resolution'=>$placement['method'],
        ];
        continue;
    }

    $answers=$answersBySubmission[$sid] ?? [];
    if ($sub['status']==='completed') {
        if (empty($sub['submitted_at'])) {
            $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'completed_without_submitted_at'];
        }
        if ($sub['overall_score']===null) {
            $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'completed_without_overall_score'];
        }
        if (($formSpecs[$fid]['criterion_count'] ?? 0)<=0) {
            $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'completed_form_has_no_criteria'];
        }
        if (count($answers)!==($formSpecs[$fid]['criterion_count'] ?? 0)) {
            $invalidDynamicStates[]=[
                'submission_id'=>$sid,
                'reason'=>'answer_count_mismatch',
                'answers'=>count($answers),
                'criteria'=>$formSpecs[$fid]['criterion_count'] ?? 0,
            ];
        }
    } elseif ($sub['status']==='pending') {
        if (!empty($sub['submitted_at']) || $answers) {
            $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'pending_contains_submission_data'];
        }
    } else {
        $invalidDynamicStates[]=['submission_id'=>$sid,'reason'=>'invalid_status','status'=>$sub['status']];
    }

    $resolvedAnswers=[];
    if ($answers) {
        $structured=$formSpecs[$fid]['structured'] ?? [];
        foreach ($answers as $answer) {
            $matches=array_values(array_filter($structured,function($cr) use ($answer) {
                return trim((string)$cr['section_title'])===trim((string)$answer['section_title'])
                    && trim((string)$cr['label'])===trim((string)$answer['criterion_label']);
            }));
            if (count($matches)!==1) {
                $invalidDynamicStates[]=[
                    'submission_id'=>$sid,
                    'answer_id'=>(int)$answer['id'],
                    'reason'=>'answer_criterion_not_uniquely_resolved',
                    'section_title'=>$answer['section_title'],
                    'criterion_label'=>$answer['criterion_label'],
                    'matches'=>count($matches),
                ];
            } else {
                $resolvedAnswers[]=['answer'=>$answer,'legacy_criterion_id'=>(int)$matches[0]['id']];
            }
        }
    }

    $sub['_placement_id']=$placement['id'];
    $sub['_company_user_id']=(int)$companyAccount['company_user_id'];
    $sub['_company_user_user_id']=(int)$companyAccount['user_id'];
    $sub['_resolved_answers']=$resolvedAnswers;
    $dynamicRows[]=$sub;
}

$unmappedFixed=[];
$invalidFixedStates=[];
$fixedRows=[];
$fixedColumns=['technical_skills','work_ethic','communication','teamwork','initiative','adaptability'];

foreach ($fixedEvals as $ev) {
    $eid=(int)$ev['id'];

    $companyUser=query_one(
        "SELECT cu.id company_user_id,cu.user_id
         FROM company_users cu
         WHERE cu.company_id=? AND cu.user_id=? LIMIT 1",
        [(int)$ev['company_id'],(int)$ev['evaluator_id']],
        'ii'
    );
    if (!$companyUser) {
        $unmappedFixed[]=[
            'evaluation_id'=>$eid,
            'reason'=>'evaluator_not_mapped_to_company_user',
            'company_id'=>(int)$ev['company_id'],
            'evaluator_id'=>(int)$ev['evaluator_id'],
        ];
        continue;
    }

    if (!empty($ev['requested_by']) &&
        !query_one("SELECT id FROM users WHERE id=? LIMIT 1",[(int)$ev['requested_by']],'i')) {
        $invalidFixedStates[]=['evaluation_id'=>$eid,'reason'=>'requester_user_missing','user_id'=>(int)$ev['requested_by']];
    }

    $placement=p6_resolve_placement(
        (int)$ev['student_id'],(int)$ev['company_id'],$termId,$ev['evaluated_at']
    );
    if ($placement['id']<=0) {
        $unmappedFixed[]=[
            'evaluation_id'=>$eid,
            'student_id'=>(int)$ev['student_id'],
            'company_id'=>(int)$ev['company_id'],
            'placement_count'=>$placement['count'],
            'resolution'=>$placement['method'],
        ];
        continue;
    }

    if (!in_array($ev['evaluation_type'],['midterm','final'],true)) {
        $invalidFixedStates[]=['evaluation_id'=>$eid,'reason'=>'invalid_evaluation_type','value'=>$ev['evaluation_type']];
    }

    if ($ev['status']==='completed') {
        if (empty($ev['evaluated_at']) || $ev['overall_score']===null) {
            $invalidFixedStates[]=['evaluation_id'=>$eid,'reason'=>'completed_without_timestamp_or_score'];
        }
        foreach ($fixedColumns as $column) {
            if ($ev[$column]===null || (float)$ev[$column]<0 || (float)$ev[$column]>100) {
                $invalidFixedStates[]=[
                    'evaluation_id'=>$eid,'reason'=>'invalid_fixed_score','criterion'=>$column,'value'=>$ev[$column]
                ];
            }
        }
    } elseif ($ev['status']==='pending') {
        $hasScores=false;
        foreach ($fixedColumns as $column) if ($ev[$column]!==null) $hasScores=true;
        if ($hasScores || $ev['overall_score']!==null || !empty($ev['evaluated_at'])) {
            $invalidFixedStates[]=['evaluation_id'=>$eid,'reason'=>'pending_contains_completed_data'];
        }
    } else {
        $invalidFixedStates[]=['evaluation_id'=>$eid,'reason'=>'invalid_status','status'=>$ev['status']];
    }

    $ev['_placement_id']=$placement['id'];
    $ev['_company_user_id']=(int)$companyUser['company_user_id'];
    $fixedRows[]=$ev;
}

$problems=[];
if ($transitionAssignments) $problems['legacy_evaluation_assignments_need_manual_review']=count($transitionAssignments);
if ($invalidForms) $problems['invalid_dynamic_forms']=count($invalidForms);
if ($invalidFallbackCriteria) $problems['invalid_legacy_criteria_json']=count($invalidFallbackCriteria);
if ($duplicateAnswerKeys) $problems['duplicate_dynamic_criterion_labels']=count($duplicateAnswerKeys);
if ($unmappedDynamic) $problems['dynamic_evaluation_without_exact_placement_or_evaluator']=count($unmappedDynamic);
if ($invalidDynamicStates) $problems['invalid_dynamic_evaluation_state']=count($invalidDynamicStates);
if ($unmappedFixed) $problems['fixed_evaluation_without_exact_placement_or_evaluator']=count($unmappedFixed);
if ($invalidFixedStates) $problems['invalid_fixed_evaluation_state']=count($invalidFixedStates);

$dynamicCompleted=count(array_filter($dynamicSubs,fn($s)=>$s['status']==='completed'));
$fixedCompleted=count(array_filter($fixedEvals,fn($e)=>$e['status']==='completed'));

$summary=[
    'migration'=>'006_evaluations',
    'mode'=>$dryRun?'dry-run':'apply',
    'academic_year'=>$academicYear,
    'semester'=>$semester,
    'academic_term_id'=>$termId,
    'legacy'=>[
        'dynamic_forms'=>count($legacyForms),
        'dynamic_sections'=>count($legacySections),
        'dynamic_criteria'=>count($legacyCriteria),
        'dynamic_rating_rules'=>count($legacyRules),
        'dynamic_requests'=>count($dynamicSubs),
        'dynamic_completed_submissions'=>$dynamicCompleted,
        'dynamic_answers'=>count($dynamicAnswers),
        'fixed_evaluations'=>count($fixedEvals),
        'fixed_completed_evaluations'=>$fixedCompleted,
        'transitional_evaluation_assignments'=>count($transitionAssignments),
    ],
    'preflight_problems'=>$problems,
];

if ($transitionAssignments) $summary['transitional_assignments']=$transitionAssignments;
if ($invalidForms) $summary['invalid_forms']=$invalidForms;
if ($invalidFallbackCriteria) $summary['invalid_criteria_json']=$invalidFallbackCriteria;
if ($duplicateAnswerKeys) $summary['duplicate_criterion_labels']=$duplicateAnswerKeys;
if ($unmappedDynamic) $summary['unmapped_dynamic_evaluations']=$unmappedDynamic;
if ($invalidDynamicStates) $summary['invalid_dynamic_states']=$invalidDynamicStates;
if ($unmappedFixed) $summary['unmapped_fixed_evaluations']=$unmappedFixed;
if ($invalidFixedStates) $summary['invalid_fixed_states']=$invalidFixedStates;

if ($problems) {
    $summary['result']='BLOCKED';
    $summary['message']='No evaluation data was changed. Resolve the listed issues first.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

if ($dryRun) {
    $summary['result']='READY';
    $summary['message']='Preflight passed. Re-run with --apply to normalize evaluations.';
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

try {
    p6_ddl($conn,__DIR__.'/../database/migrations/006_evaluations.sql');

    foreach ([
        'legacy_evaluation_form_migration_map',
        'legacy_eval_submission_migration_map',
        'legacy_fixed_evaluation_migration_map'
    ] as $mapTable) {
        if (p6_scalar("SELECT COUNT(*) FROM $mapTable")>0) {
            throw new RuntimeException("$mapTable already contains rows but migration 006 is not recorded.");
        }
    }

    $conn->begin_transaction();

    $formMap=[];
    $legacyCriterionMap=[];
    $definitionsCreated=0;
    $versionsCreated=0;
    $sectionsCreated=0;
    $criteriaCreated=0;
    $ratingRulesCreated=0;
    $requestsCreated=0;
    $submissionsCreated=0;
    $answersCreated=0;

    foreach ($legacyForms as $form) {
        $fid=(int)$form['id'];
        $definitionStatus=$form['status']==='archived' ? 'archived' : 'active';
        $definitionId=insert(
            "INSERT INTO evaluation_definitions(created_by,title,description,status,created_at)
             VALUES(?,?,?,?,?)",
            [(int)$form['created_by'],$form['title'],$form['description'],$definitionStatus,$form['created_at']],
            'issss'
        );
        $definitionsCreated++;

        $versionNo=max(1,(int)$form['version']);
        $versionStatus=p6_form_version_status($form['status']);
        $publishedAt=$form['published_at'];
        $versionId=insert(
            "INSERT INTO evaluation_definition_versions
             (evaluation_definition_id,version_no,score_mode,rating_max,status,published_at,created_at)
             VALUES(?,?,'percentage',?,?,?,?)",
            [$definitionId,$versionNo,(int)$form['rating_max'],$versionStatus,$publishedAt,$form['created_at']],
            'iiisss'
        );
        $versionsCreated++;

        $structuredSections=$sectionsByForm[$fid] ?? [];
        if ($structuredSections) {
            foreach ($structuredSections as $section) {
                $sectionId=insert(
                    "INSERT INTO evaluation_version_sections
                     (evaluation_definition_version_id,title,sort_order)
                     VALUES(?,?,?)",
                    [$versionId,$section['title'],(int)$section['sort_order']],
                    'isi'
                );
                $sectionsCreated++;

                foreach ($criteriaBySection[(int)$section['id']] ?? [] as $criterion) {
                    $criterionId=insert(
                        "INSERT INTO evaluation_version_criteria
                         (evaluation_version_section_id,criterion_code,label,description,weight,sort_order)
                         VALUES(?,?,?,NULL,1.000,?)",
                        [
                            $sectionId,
                            'legacy-criterion-'.(int)$criterion['id'],
                            $criterion['label'],
                            (int)$criterion['sort_order']
                        ],
                        'issi'
                    );
                    $criteriaCreated++;
                    $legacyCriterionMap[(int)$criterion['id']]=$criterionId;
                }
            }
        } elseif ($formSpecs[$fid]['fallback']) {
            $sectionId=insert(
                "INSERT INTO evaluation_version_sections
                 (evaluation_definition_version_id,title,sort_order)
                 VALUES(?,'Criteria',1)",
                [$versionId],
                'i'
            );
            $sectionsCreated++;
            foreach ($formSpecs[$fid]['fallback'] as $index=>$label) {
                insert(
                    "INSERT INTO evaluation_version_criteria
                     (evaluation_version_section_id,criterion_code,label,description,weight,sort_order)
                     VALUES(?,?,?,NULL,1.000,?)",
                    [$sectionId,'legacy-form-'.$fid.'-criterion-'.($index+1),$label,$index+1],
                    'issi'
                );
                $criteriaCreated++;
            }
        }

        foreach ($rulesByForm[$fid] ?? [] as $rule) {
            insert(
                "INSERT INTO evaluation_version_rating_rules
                 (evaluation_definition_version_id,score_min,score_max,equivalent,description)
                 VALUES(?,?,?,?,?)",
                [
                    $versionId,(float)$rule['score_min'],(float)$rule['score_max'],
                    $rule['equivalent']===null?null:(float)$rule['equivalent'],$rule['description']
                ],
                'iddds'
            );
            $ratingRulesCreated++;
        }

        insert(
            "INSERT INTO legacy_evaluation_form_migration_map
             (legacy_form_id,evaluation_definition_id,evaluation_definition_version_id)
             VALUES(?,?,?)",
            [$fid,$definitionId,$versionId],
            'iii'
        );
        $formMap[$fid]=['definition_id'=>$definitionId,'version_id'=>$versionId];
    }

    $fixedDefinitionId=null;
    $fixedVersionId=null;
    $fixedCriterionIds=[];
    if ($fixedRows) {
        $fixedDefinitionId=insert(
            "INSERT INTO evaluation_definitions
             (created_by,title,description,status,created_at)
             VALUES(NULL,'Legacy OJT Performance Evaluation',
                    'Migrated fixed six-criterion OJT evaluation format.','archived',NULL)"
        );
        $definitionsCreated++;
        $fixedVersionId=insert(
            "INSERT INTO evaluation_definition_versions
             (evaluation_definition_id,version_no,score_mode,rating_max,status,published_at,created_at)
             VALUES(?,1,'percentage',100,'retired',NULL,NULL)",
            [$fixedDefinitionId],
            'i'
        );
        $versionsCreated++;
        $fixedSectionId=insert(
            "INSERT INTO evaluation_version_sections
             (evaluation_definition_version_id,title,sort_order)
             VALUES(?,'Performance Criteria',1)",
            [$fixedVersionId],
            'i'
        );
        $sectionsCreated++;
        foreach (p6_fixed_criteria() as $index=>$criterion) {
            $cid=insert(
                "INSERT INTO evaluation_version_criteria
                 (evaluation_version_section_id,criterion_code,label,description,weight,sort_order)
                 VALUES(?,?,?,NULL,1.000,?)",
                [$fixedSectionId,$criterion['code'],$criterion['label'],$index+1],
                'issi'
            );
            $criteriaCreated++;
            $fixedCriterionIds[$criterion['code']]=$cid;
        }
    }

    foreach ($dynamicRows as $sub) {
        $fid=(int)$sub['form_id'];
        $versionId=(int)$formMap[$fid]['version_id'];
        $requestStatus=p6_request_status($sub['status']);
        $requestedBy=(int)$sub['requested_by'];
        $placementId=(int)$sub['_placement_id'];
        $companyUserId=(int)$sub['_company_user_id'];
        $requestId=insert(
            "INSERT INTO evaluation_requests
             (evaluation_definition_version_id,placement_id,evaluator_company_user_id,requested_by,
              evaluation_kind,due_date,status,created_at)
             VALUES(?,?,?,?,'custom',NULL,?,?)",
            [$versionId,$placementId,$companyUserId,$requestedBy,$requestStatus,$sub['created_at']],
            'iiiiss'
        );
        $requestsCreated++;

        $submissionId=null;
        if ($sub['status']==='completed') {
            $submissionId=insert(
                "INSERT INTO evaluation_submissions
                 (evaluation_request_id,submitted_by,overall_score,overall_equivalent,comments,submitted_at)
                 VALUES(?,?,?,?,?,?)",
                [
                    $requestId,(int)$sub['_company_user_user_id'],(float)$sub['overall_score'],
                    $sub['overall_equivalent']===null?null:(float)$sub['overall_equivalent'],
                    $sub['comments'],$sub['submitted_at']
                ],
                'iiddss'
            );
            $submissionsCreated++;

            foreach ($sub['_resolved_answers'] as $resolved) {
                $answer=$resolved['answer'];
                $criterionId=$legacyCriterionMap[(int)$resolved['legacy_criterion_id']] ?? 0;
                if (!$criterionId) throw new RuntimeException("Normalized criterion missing for answer #{$answer['id']}.");
                insert(
                    "INSERT INTO evaluation_answers
                     (evaluation_submission_id,evaluation_criterion_id,section_title_snapshot,
                      criterion_label_snapshot,score,equivalent)
                     VALUES(?,?,?,?,?,?)",
                    [
                        $submissionId,$criterionId,$answer['section_title'],$answer['criterion_label'],
                        (float)$answer['score'],$answer['equivalent']===null?null:(float)$answer['equivalent']
                    ],
                    'iissdd'
                );
                $answersCreated++;
            }
        }

        $legacySubmissionId=(int)$sub['id'];
        $stmt=$conn->prepare(
            "INSERT INTO legacy_eval_submission_migration_map
             (legacy_submission_id,evaluation_request_id,evaluation_submission_id)
             VALUES(?,?,?)"
        );
        $stmt->bind_param('iii',$legacySubmissionId,$requestId,$submissionId);
        $stmt->execute();
    }

    $fixedCriteria=p6_fixed_criteria();
    foreach ($fixedRows as $ev) {
        $requestStatus=p6_request_status($ev['status']);
        $requester=empty($ev['requested_by']) ? null : (int)$ev['requested_by'];
        $requestId=insert(
            "INSERT INTO evaluation_requests
             (evaluation_definition_version_id,placement_id,evaluator_company_user_id,requested_by,
              evaluation_kind,due_date,status,created_at)
             VALUES(?,?,?,?,?,NULL,?,?)",
            [
                $fixedVersionId,(int)$ev['_placement_id'],(int)$ev['_company_user_id'],$requester,
                $ev['evaluation_type'],$requestStatus,$ev['evaluated_at']
            ],
            'iiiisss'
        );
        $requestsCreated++;

        $submissionId=null;
        if ($ev['status']==='completed') {
            $submissionId=insert(
                "INSERT INTO evaluation_submissions
                 (evaluation_request_id,submitted_by,overall_score,overall_equivalent,comments,submitted_at)
                 VALUES(?,?,?,NULL,?,?)",
                [
                    $requestId,(int)$ev['evaluator_id'],(float)$ev['overall_score'],
                    $ev['comments'],$ev['evaluated_at']
                ],
                'iidss'
            );
            $submissionsCreated++;

            foreach ($fixedCriteria as $criterion) {
                $column=$criterion['code'];
                insert(
                    "INSERT INTO evaluation_answers
                     (evaluation_submission_id,evaluation_criterion_id,section_title_snapshot,
                      criterion_label_snapshot,score,equivalent)
                     VALUES(?,?,'Performance Criteria',?,?,NULL)",
                    [
                        $submissionId,$fixedCriterionIds[$column],$criterion['label'],(float)$ev[$column]
                    ],
                    'iisd'
                );
                $answersCreated++;
            }
        }

        $legacyEvaluationId=(int)$ev['id'];
        $stmt=$conn->prepare(
            "INSERT INTO legacy_fixed_evaluation_migration_map
             (legacy_evaluation_id,evaluation_request_id,evaluation_submission_id)
             VALUES(?,?,?)"
        );
        $stmt->bind_param('iii',$legacyEvaluationId,$requestId,$submissionId);
        $stmt->execute();
    }

    $expectedRequests=count($dynamicSubs)+count($fixedEvals);
    $expectedSubmissions=$dynamicCompleted+$fixedCompleted;
    $expectedAnswers=count($dynamicAnswers)+($fixedCompleted*6);

    $verification=[
        'dynamic_form_map_mismatch'=>abs(
            count($legacyForms)-p6_scalar("SELECT COUNT(*) FROM legacy_evaluation_form_migration_map")
        ),
        'dynamic_request_map_mismatch'=>abs(
            count($dynamicSubs)-p6_scalar("SELECT COUNT(*) FROM legacy_eval_submission_migration_map")
        ),
        'fixed_request_map_mismatch'=>abs(
            count($fixedEvals)-p6_scalar("SELECT COUNT(*) FROM legacy_fixed_evaluation_migration_map")
        ),
        'request_count_mismatch'=>abs($expectedRequests-$requestsCreated),
        'submission_count_mismatch'=>abs($expectedSubmissions-$submissionsCreated),
        'answer_count_mismatch'=>abs($expectedAnswers-$answersCreated),
        'status_or_score_mismatches'=>0,
        'answer_snapshot_mismatches'=>0,
    ];

    foreach ($dynamicSubs as $legacy) {
        $mapped=query_one(
            "SELECT er.status request_status,es.overall_score,es.overall_equivalent,es.comments,es.submitted_at
             FROM legacy_eval_submission_migration_map m
             JOIN evaluation_requests er ON er.id=m.evaluation_request_id
             LEFT JOIN evaluation_submissions es ON es.id=m.evaluation_submission_id
             WHERE m.legacy_submission_id=?",
            [(int)$legacy['id']],
            'i'
        );
        if (!$mapped || $mapped['request_status']!==p6_request_status($legacy['status'])) {
            $verification['status_or_score_mismatches']++;
            continue;
        }
        if ($legacy['status']==='completed') {
            if ($mapped['overall_score']===null ||
                abs((float)$mapped['overall_score']-(float)$legacy['overall_score'])>0.001 ||
                ($mapped['comments'] ?? null)!==($legacy['comments'] ?? null) ||
                $mapped['submitted_at']!==$legacy['submitted_at']) {
                $verification['status_or_score_mismatches']++;
            }
        } elseif ($mapped['overall_score']!==null) {
            $verification['status_or_score_mismatches']++;
        }
    }

    foreach ($dynamicAnswers as $legacyAnswer) {
        $matched=p6_scalar(
            "SELECT COUNT(*)
             FROM legacy_eval_submission_migration_map m
             JOIN evaluation_answers ea ON ea.evaluation_submission_id=m.evaluation_submission_id
             WHERE m.legacy_submission_id=?
               AND ea.section_title_snapshot<=>
                   ?
               AND ea.criterion_label_snapshot=?
               AND ABS(ea.score-?)<0.001",
            [
                (int)$legacyAnswer['submission_id'],$legacyAnswer['section_title'],
                $legacyAnswer['criterion_label'],(float)$legacyAnswer['score']
            ],
            'issd'
        );
        if ($matched!==1) $verification['answer_snapshot_mismatches']++;
    }

    foreach ($fixedEvals as $legacy) {
        $mapped=query_one(
            "SELECT er.status request_status,es.overall_score,es.comments,es.submitted_at
             FROM legacy_fixed_evaluation_migration_map m
             JOIN evaluation_requests er ON er.id=m.evaluation_request_id
             LEFT JOIN evaluation_submissions es ON es.id=m.evaluation_submission_id
             WHERE m.legacy_evaluation_id=?",
            [(int)$legacy['id']],
            'i'
        );
        if (!$mapped || $mapped['request_status']!==p6_request_status($legacy['status'])) {
            $verification['status_or_score_mismatches']++;
            continue;
        }
        if ($legacy['status']==='completed') {
            if ($mapped['overall_score']===null ||
                abs((float)$mapped['overall_score']-(float)$legacy['overall_score'])>0.001 ||
                ($mapped['comments'] ?? null)!==($legacy['comments'] ?? null) ||
                $mapped['submitted_at']!==$legacy['evaluated_at']) {
                $verification['status_or_score_mismatches']++;
            }
        }
    }

    foreach ($verification as $label=>$count) {
        if ($count!==0) throw new RuntimeException("Verification failed: $label=$count");
    }

    $afterSnapshot=p6_hash([
        'forms'=>query("SELECT * FROM evaluation_forms ORDER BY id") ?: [],
        'sections'=>query("SELECT * FROM eval_sections ORDER BY form_id,sort_order,id") ?: [],
        'criteria'=>query(
            "SELECT c.*,s.form_id,s.title section_title,s.sort_order section_sort
             FROM eval_criteria c JOIN eval_sections s ON s.id=c.section_id
             ORDER BY s.form_id,s.sort_order,c.sort_order,c.id"
        ) ?: [],
        'rules'=>query("SELECT * FROM eval_rating_rules ORDER BY form_id,score_min,score_max,id") ?: [],
        'dynamic_submissions'=>query("SELECT * FROM eval_submissions ORDER BY id") ?: [],
        'dynamic_answers'=>query("SELECT * FROM eval_answers ORDER BY id") ?: [],
        'fixed_evaluations'=>query("SELECT * FROM evaluations ORDER BY id") ?: [],
        'transition_assignments'=>query("SELECT * FROM evaluation_assignments ORDER BY id") ?: [],
    ]);
    if ($afterSnapshot!==$sourceSnapshot) {
        throw new RuntimeException('Legacy evaluation tables changed during migration.');
    }

    $version='006_evaluations';
    $description='Normalize fixed and dynamic evaluation forms, requests, submissions, criteria and answer snapshots';
    $stmt=$conn->prepare("INSERT INTO schema_migrations(version,description) VALUES(?,?)");
    $stmt->bind_param('ss',$version,$description);
    $stmt->execute();

    $conn->commit();

    $summary['result']='PASS';
    $summary['normalized']=[
        'evaluation_definitions'=>$definitionsCreated,
        'evaluation_definition_versions'=>$versionsCreated,
        'evaluation_sections'=>$sectionsCreated,
        'evaluation_criteria'=>$criteriaCreated,
        'evaluation_rating_rules'=>$ratingRulesCreated,
        'evaluation_requests'=>$requestsCreated,
        'evaluation_submissions'=>$submissionsCreated,
        'evaluation_answers'=>$answersCreated,
    ];
    $summary['verification']=$verification;
    $summary['legacy_evaluation_tables_preserved']=true;

    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR,"Migration 006 failed: ".$e->getMessage().PHP_EOL);
    exit(1);
}
