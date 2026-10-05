<?php
/**
 * Normalized application data layer used after migrations 001-007.
 *
 * The legacy tables remain present until migration 009, but application code
 * should use these helpers for term-scoped enrollment, coordinator/company
 * assignment history and official attendance-derived progress.
 */

function normalized_cutover_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)query_one(
            "SELECT 1 FROM schema_migrations WHERE version='007_certificates_and_announcements' LIMIT 1"
        );
    } catch (Throwable $error) {
        $ready = false;
    }
    return $ready;
}

function normalized_active_term(): ?array
{
    static $term = false;
    if ($term !== false) return $term ?: null;
    $term = query_one(
        "SELECT *
         FROM academic_terms
         ORDER BY (status='active') DESC, academic_year_start DESC, academic_year_end DESC, id DESC
         LIMIT 1"
    );
    return $term ?: null;
}

function normalized_active_term_id(): int
{
    return (int)(normalized_active_term()['id'] ?? 0);
}

function normalized_enrollment_for_student(int $studentId): ?array
{
    if ($studentId <= 0) return null;
    $termId = normalized_active_term_id();
    if ($termId > 0) {
        $row = query_one(
            "SELECT oe.*, at.academic_year_start,at.academic_year_end,at.semester,
                    p.code program_code,p.name program_name,p.department_name
             FROM ojt_enrollments oe
             JOIN academic_terms at ON at.id=oe.academic_term_id
             JOIN programs p ON p.id=oe.program_id
             WHERE oe.student_id=? AND oe.academic_term_id=?
             LIMIT 1",
            [$studentId,$termId],
            'ii'
        );
        if ($row) return $row;
    }

    return query_one(
        "SELECT oe.*, at.academic_year_start,at.academic_year_end,at.semester,
                p.code program_code,p.name program_name,p.department_name
         FROM ojt_enrollments oe
         JOIN academic_terms at ON at.id=oe.academic_term_id
         JOIN programs p ON p.id=oe.program_id
         WHERE oe.student_id=?
         ORDER BY at.academic_year_start DESC,at.academic_year_end DESC,oe.id DESC
         LIMIT 1",
        [$studentId],
        'i'
    );
}

function normalized_enrollment_for_user(int $userId): ?array
{
    $student = query_one("SELECT id FROM students WHERE user_id=? LIMIT 1",[$userId],'i');
    return $student ? normalized_enrollment_for_student((int)$student['id']) : null;
}

function normalized_current_coordinator_for_enrollment(int $enrollmentId): ?array
{
    if ($enrollmentId <= 0) return null;
    return query_one(
        "SELECT ec.*,c.user_id,c.department,c.coordinator_id_no,u.name,u.email
         FROM enrollment_coordinators ec
         JOIN coordinators c ON c.id=ec.coordinator_id
         JOIN users u ON u.id=c.user_id
         WHERE ec.ojt_enrollment_id=? AND ec.ended_at IS NULL
         ORDER BY ec.id DESC LIMIT 1",
        [$enrollmentId],
        'i'
    );
}

function normalized_current_placement_for_enrollment(int $enrollmentId, ?int $companyId=null): ?array
{
    if ($enrollmentId <= 0) return null;
    $params=[$enrollmentId];
    $types='i';
    $companySql='';
    if ($companyId !== null) {
        $companySql=' AND p.company_id=?';
        $params[]=$companyId;
        $types.='i';
    }
    return query_one(
        "SELECT p.*,co.company_name,co.location,co.contact_number,co.email company_email
         FROM placements p
         JOIN companies co ON co.id=p.company_id
         WHERE p.ojt_enrollment_id=? $companySql
         ORDER BY FIELD(p.status,'active','on_hold','planned','completed','transferred','terminated'),p.id DESC
         LIMIT 1",
        $params,
        $types
    );
}

function normalized_current_placement_for_student(int $studentId, ?int $companyId=null): ?array
{
    $enrollment=normalized_enrollment_for_student($studentId);
    return $enrollment ? normalized_current_placement_for_enrollment((int)$enrollment['id'],$companyId) : null;
}

function normalized_company_user(int $companyId, ?int $userId=null): ?array
{
    if ($companyId <= 0) return null;
    if ($userId !== null) {
        return query_one(
            "SELECT cu.*,u.name,u.email
             FROM company_users cu JOIN users u ON u.id=cu.user_id
             WHERE cu.company_id=? AND cu.user_id=? AND cu.status='active' LIMIT 1",
            [$companyId,$userId],
            'ii'
        );
    }
    return query_one(
        "SELECT cu.*,u.name,u.email
         FROM company_users cu JOIN users u ON u.id=cu.user_id
         WHERE cu.company_id=? AND cu.status='active'
         ORDER BY cu.is_primary DESC,cu.id LIMIT 1",
        [$companyId],
        'i'
    );
}

function normalized_official_minutes_for_enrollment(int $enrollmentId): int
{
    if ($enrollmentId <= 0) return 0;
    return (int)(query_one(
        "SELECT COALESCE(SUM(ad.credited_minutes),0) minutes
         FROM attendance_days ad
         JOIN placements p ON p.id=ad.placement_id
         WHERE p.ojt_enrollment_id=?",
        [$enrollmentId],
        'i'
    )['minutes'] ?? 0);
}

function normalized_official_hours_for_student(int $studentId): float
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return 0.0;
    return normalized_official_minutes_for_enrollment((int)$enrollment['id']) / 60;
}

function normalized_student_context(int $studentId): ?array
{
    $base=query_one(
        "SELECT s.*,u.name,u.email,u.status user_status,u.avatar
         FROM students s JOIN users u ON u.id=s.user_id
         WHERE s.id=? LIMIT 1",
        [$studentId],
        'i'
    );
    if (!$base) return null;

    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return $base;

    $coord=normalized_current_coordinator_for_enrollment((int)$enrollment['id']);
    $placement=normalized_current_placement_for_enrollment((int)$enrollment['id']);
    $hours=normalized_official_minutes_for_enrollment((int)$enrollment['id'])/60;

    $base['program_id']=(int)$enrollment['program_id'];
    $base['program']=$enrollment['program_code'];
    $base['program_name']=$enrollment['program_name'];
    $base['department']=$enrollment['department_name'];
    $base['year_level']=$enrollment['year_level'];
    $base['required_hours']=(int)$enrollment['required_hours'];
    $base['rendered_hours']=$hours;
    $base['ojt_status']=$enrollment['status'];
    $base['status_notes']=$enrollment['status_notes'];
    $base['onboarding_completed_at']=$enrollment['onboarding_completed_at'];
    $base['coordinator_id']=$coord ? (int)$coord['coordinator_id'] : null;
    $base['coordinator_name']=$coord['name'] ?? null;
    $base['company_id']=$placement ? (int)$placement['company_id'] : null;
    $base['company_name']=$placement['company_name'] ?? null;
    $base['ojt_start_date']=$placement['starts_on'] ?? null;
    $base['ojt_end_date']=$placement['ends_on'] ?? null;
    $base['_enrollment_id']=(int)$enrollment['id'];
    $base['_placement_id']=$placement ? (int)$placement['id'] : null;
    $base['_academic_term_id']=(int)$enrollment['academic_term_id'];
    return $base;
}

function normalized_student_context_by_user(int $userId): ?array
{
    $student=query_one("SELECT id FROM students WHERE user_id=? LIMIT 1",[$userId],'i');
    return $student ? normalized_student_context((int)$student['id']) : null;
}

function normalized_students_for_coordinator(int $coordinatorId, string $search='', ?string $status=null): array
{
    $termId=normalized_active_term_id();
    if ($termId<=0) return [];
    $params=[$termId,$coordinatorId];
    $types='ii';
    $where="oe.academic_term_id=? AND ec.coordinator_id=? AND ec.ended_at IS NULL
            AND s.is_archived=0 AND u.status!='archived'";
    if ($status!==null && $status!=='') {
        $where.=" AND oe.status=?";
        $params[]=$status;
        $types.='s';
    }
    if ($search!=='') {
        $where.=" AND (u.name LIKE ? OR s.student_id_no LIKE ? OR p.code LIKE ? OR co.company_name LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like,$like,$like);
        $types.='ssss';
    }

    $rows=query(
        "SELECT s.*,u.name,u.email,p.id program_id,p.code program,p.name program_name,p.department_name department,
                oe.id _enrollment_id,oe.year_level,oe.required_hours,oe.status ojt_status,oe.status_notes,
                oe.onboarding_completed_at,
                pl.id _placement_id,pl.company_id,pl.starts_on ojt_start_date,pl.ends_on ojt_end_date,
                co.company_name,
                COALESCE((SELECT SUM(ad.credited_minutes)/60
                          FROM attendance_days ad
                          JOIN placements hp ON hp.id=ad.placement_id
                          WHERE hp.ojt_enrollment_id=oe.id),0) rendered_hours
         FROM ojt_enrollments oe
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         JOIN programs p ON p.id=oe.program_id
         JOIN enrollment_coordinators ec ON ec.ojt_enrollment_id=oe.id
         LEFT JOIN placements pl ON pl.id=(
            SELECT p2.id FROM placements p2
            WHERE p2.ojt_enrollment_id=oe.id
            ORDER BY FIELD(p2.status,'active','on_hold','planned','completed','transferred','terminated'),p2.id DESC
            LIMIT 1
         )
         LEFT JOIN companies co ON co.id=pl.company_id
         WHERE $where
         ORDER BY u.name",
        $params,
        $types
    );
    return $rows ?: [];
}

function normalized_students_for_company(int $companyId, string $search=''): array
{
    $termId=normalized_active_term_id();
    if ($termId<=0) return [];
    $params=[$termId,$companyId];
    $types='ii';
    $where="oe.academic_term_id=? AND pl.company_id=?
            AND pl.status IN ('active','on_hold','planned','completed')
            AND s.is_archived=0 AND u.status!='archived'";
    if ($search!=='') {
        $where.=" AND (u.name LIKE ? OR s.student_id_no LIKE ?)";
        $like="%$search%";
        array_push($params,$like,$like);
        $types.='ss';
    }

    $rows=query(
        "SELECT s.*,u.name,u.email,p.id program_id,p.code program,p.name program_name,
                oe.id _enrollment_id,oe.year_level,oe.required_hours,oe.status ojt_status,oe.status_notes,
                pl.id _placement_id,pl.company_id,pl.starts_on ojt_start_date,pl.ends_on ojt_end_date,
                COALESCE((SELECT SUM(ad.credited_minutes)/60
                          FROM attendance_days ad
                          JOIN placements hp ON hp.id=ad.placement_id
                          WHERE hp.ojt_enrollment_id=oe.id),0) rendered_hours
         FROM placements pl
         JOIN ojt_enrollments oe ON oe.id=pl.ojt_enrollment_id
         JOIN students s ON s.id=oe.student_id
         JOIN users u ON u.id=s.user_id
         JOIN programs p ON p.id=oe.program_id
         WHERE $where
         ORDER BY u.name",
        $params,
        $types
    );
    return $rows ?: [];
}

function normalized_status_to_placement(string $status): string
{
    return match($status) {
        'ongoing'=>'active',
        'completed'=>'completed',
        'on_hold'=>'on_hold',
        'withdrawn'=>'terminated',
        default=>'planned',
    };
}

function normalized_update_training_assignment(
    int $studentId,
    int $actorUserId,
    ?int $coordinatorId,
    ?int $companyId,
    int $programId,
    string $status,
    int $requiredHours,
    ?string $startDate,
    ?string $endDate,
    ?string $statusNotes=null
): bool {
    if ($studentId<=0 || $programId<=0 || $requiredHours<=0) return false;
    $allowed=['pending','not_started','ongoing','completed','on_hold','withdrawn'];
    if (!in_array($status,$allowed,true)) $status='pending';

    $term=normalized_active_term();
    if (!$term) throw new DomainException('No active academic term is configured.');

    db()->begin_transaction();
    try {
        $enrollment=query_one(
            "SELECT * FROM ojt_enrollments WHERE student_id=? AND academic_term_id=? FOR UPDATE",
            [$studentId,(int)$term['id']],
            'ii'
        );

        if (!$enrollment) {
            $enrollmentId=insert(
                "INSERT INTO ojt_enrollments
                 (student_id,academic_term_id,program_id,year_level,required_hours,status,status_notes,completed_at)
                 VALUES(?,?,?,NULL,?,?,?,?)",
                [
                    $studentId,(int)$term['id'],$programId,$requiredHours,$status,$statusNotes,
                    $status==='completed' ? date('Y-m-d H:i:s') : null
                ],
                'iiiisss'
            );
            $enrollment=query_one("SELECT * FROM ojt_enrollments WHERE id=?",[$enrollmentId],'i');
        } else {
            $enrollmentId=(int)$enrollment['id'];
            query(
                "UPDATE ojt_enrollments
                 SET program_id=?,required_hours=?,status=?,status_notes=?,
                     completed_at=CASE WHEN ?='completed' THEN COALESCE(completed_at,NOW()) ELSE NULL END
                 WHERE id=?",
                [$programId,$requiredHours,$status,$statusNotes,$status,$enrollmentId],
                'iisssi'
            );
        }

        $currentCoord=normalized_current_coordinator_for_enrollment($enrollmentId);
        $currentCoordId=$currentCoord ? (int)$currentCoord['coordinator_id'] : null;
        if ($currentCoordId!==$coordinatorId) {
            if ($currentCoord) {
                query("UPDATE enrollment_coordinators SET ended_at=NOW() WHERE id=?",[(int)$currentCoord['id']],'i');
            }
            if ($coordinatorId) {
                insert(
                    "INSERT INTO enrollment_coordinators(ojt_enrollment_id,coordinator_id,assigned_by)
                     VALUES(?,?,?)",
                    [$enrollmentId,$coordinatorId,$actorUserId],
                    'iii'
                );
            }
        }

        $currentPlacement=normalized_current_placement_for_enrollment($enrollmentId);
        $currentCompanyId=$currentPlacement ? (int)$currentPlacement['company_id'] : null;
        $placementStatus=normalized_status_to_placement($status);

        if ($currentPlacement && $currentCompanyId!==$companyId) {
            $oldStatus=$currentPlacement['status']==='completed' ? 'completed' : ($companyId ? 'transferred' : 'terminated');
            query(
                "UPDATE placements
                 SET status=?,ends_on=COALESCE(ends_on,?)
                 WHERE id=?",
                [$oldStatus,date('Y-m-d'),(int)$currentPlacement['id']],
                'ssi'
            );
            query(
                "UPDATE placement_supervisors SET ended_at=COALESCE(ended_at,NOW())
                 WHERE placement_id=? AND ended_at IS NULL",
                [(int)$currentPlacement['id']],
                'i'
            );
            $currentPlacement=null;
        }

        if ($companyId) {
            if ($currentPlacement && (int)$currentPlacement['company_id']===$companyId) {
                query(
                    "UPDATE placements
                     SET status=?,starts_on=?,ends_on=?,assigned_by=COALESCE(assigned_by,?)
                     WHERE id=?",
                    [$placementStatus,$startDate,$endDate,$actorUserId,(int)$currentPlacement['id']],
                    'sssii'
                );
                $placementId=(int)$currentPlacement['id'];
            } else {
                $placementId=insert(
                    "INSERT INTO placements
                     (ojt_enrollment_id,company_id,status,starts_on,ends_on,assigned_by,notes)
                     VALUES(?,?,?,?,?,?,'Created by normalized application cutover.')",
                    [$enrollmentId,$companyId,$placementStatus,$startDate,$endDate,$actorUserId],
                    'iisssi'
                );
            }

            $primary=normalized_company_user($companyId);
            if ($primary && !query_one(
                "SELECT id FROM placement_supervisors
                 WHERE placement_id=? AND company_user_id=? AND ended_at IS NULL LIMIT 1",
                [$placementId,(int)$primary['id']],
                'ii'
            )) {
                insert(
                    "INSERT INTO placement_supervisors(placement_id,company_user_id) VALUES(?,?)",
                    [$placementId,(int)$primary['id']],
                    'ii'
                );
            }
        }

        db()->commit();
        return true;
    } catch (Throwable $error) {
        db()->rollback();
        throw $error;
    }
}

function normalized_set_onboarding_complete_if_ready(int $studentId): bool
{
    $enrollment=normalized_enrollment_for_student($studentId);
    if (!$enrollment) return false;

    $total=(int)(query_one(
        "SELECT COUNT(*) c FROM requirement_assignments
         WHERE ojt_enrollment_id=? AND status!='waived'",
        [(int)$enrollment['id']],
        'i'
    )['c'] ?? 0);
    if ($total===0) return false;

    $unfinished=(int)(query_one(
        "SELECT COUNT(*) c
         FROM requirement_assignments ra
         LEFT JOIN requirement_submissions rs ON rs.id=(
            SELECT rs2.id FROM requirement_submissions rs2
            WHERE rs2.requirement_assignment_id=ra.id
            ORDER BY rs2.version_no DESC,rs2.id DESC LIMIT 1
         )
         WHERE ra.ojt_enrollment_id=? AND ra.status!='waived'
           AND (ra.status!='closed' OR rs.status!='approved')",
        [(int)$enrollment['id']],
        'i'
    )['c'] ?? 0);
    if ($unfinished>0) return false;

    query(
        "UPDATE ojt_enrollments
         SET onboarding_completed_at=COALESCE(onboarding_completed_at,NOW())
         WHERE id=?",
        [(int)$enrollment['id']],
        'i'
    );
    return true;
}

require_once __DIR__ . '/normalized_training.php';
require_once __DIR__ . '/normalized_evaluations.php';
require_once __DIR__ . '/normalized_communications.php';
