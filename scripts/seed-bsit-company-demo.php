<?php
declare(strict_types=1);

define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';

// Creates a repeatable demonstration dataset for the five active BSIT students.
// It intentionally does not touch mobile/ or any users outside this scenario.
$studentIds = [15, 16, 17, 18, 19];
$companyId = 5;
$adminId = 1;
$coordinatorId = 3;
$termId = 3;
$programId = 1;

function demo_exec(string $sql, array $params = [], string $types = ''): void
{
    query($sql, $params, $types);
}

function demo_insert(string $sql, array $params = [], string $types = ''): int
{
    return (int)insert($sql, $params, $types);
}

function demo_weekdays(string $start, int $count): array
{
    $dates = [];
    $date = new DateTimeImmutable($start);
    while (count($dates) < $count) {
        if ((int)$date->format('N') <= 5) $dates[] = $date->format('Y-m-d');
        $date = $date->modify('+1 day');
    }
    return $dates;
}

db()->begin_transaction();
try {
    $marks = implode(',', array_fill(0, count($studentIds), '?'));
    $enrollmentRows = query(
        "SELECT id FROM ojt_enrollments WHERE student_id IN ($marks) AND academic_term_id=?",
        array_merge($studentIds, [$termId]),
        str_repeat('i', count($studentIds) + 1)
    ) ?: [];
    $enrollmentIds = array_map(static fn(array $row): int => (int)$row['id'], $enrollmentRows);

    foreach ($enrollmentIds as $enrollmentId) {
        $placementRows = query('SELECT id FROM placements WHERE ojt_enrollment_id=?', [$enrollmentId], 'i') ?: [];
        $placementIds = array_map(static fn(array $row): int => (int)$row['id'], $placementRows);
        foreach ($placementIds as $placementId) {
            $evaluationRows = query('SELECT id FROM evaluation_requests WHERE placement_id=?', [$placementId], 'i') ?: [];
            foreach ($evaluationRows as $evaluation) {
                $requestId = (int)$evaluation['id'];
                $submissionRows = query('SELECT id FROM evaluation_submissions WHERE evaluation_request_id=?', [$requestId], 'i') ?: [];
                foreach ($submissionRows as $submission) {
                    demo_exec('DELETE FROM evaluation_answers WHERE evaluation_submission_id=?', [(int)$submission['id']], 'i');
                }
                demo_exec('DELETE FROM evaluation_submissions WHERE evaluation_request_id=?', [$requestId], 'i');
            }
            demo_exec('DELETE FROM evaluation_requests WHERE placement_id=?', [$placementId], 'i');
            demo_exec('DELETE FROM certificates WHERE placement_id=?', [$placementId], 'i');
            $journalRows = query('SELECT id FROM journal_days WHERE placement_id=?', [$placementId], 'i') ?: [];
            foreach ($journalRows as $journal) {
                demo_exec('DELETE FROM journal_revisions WHERE journal_day_id=?', [(int)$journal['id']], 'i');
            }
            demo_exec('DELETE FROM journal_days WHERE placement_id=?', [$placementId], 'i');
            $attendanceRows = query('SELECT id FROM attendance_days WHERE placement_id=?', [$placementId], 'i') ?: [];
            foreach ($attendanceRows as $attendance) {
                demo_exec('DELETE FROM attendance_sessions WHERE attendance_day_id=?', [(int)$attendance['id']], 'i');
            }
            demo_exec('DELETE FROM attendance_days WHERE placement_id=?', [$placementId], 'i');
        }
        $requirementRows = query('SELECT id FROM requirement_assignments WHERE ojt_enrollment_id=?', [$enrollmentId], 'i') ?: [];
        foreach ($requirementRows as $assignment) {
            $assignmentId = (int)$assignment['id'];
            $submissionRows = query('SELECT id FROM requirement_submissions WHERE requirement_assignment_id=?', [$assignmentId], 'i') ?: [];
            foreach ($submissionRows as $submission) {
                demo_exec('DELETE FROM requirement_submission_files WHERE requirement_submission_id=?', [(int)$submission['id']], 'i');
            }
            demo_exec('DELETE FROM requirement_submissions WHERE requirement_assignment_id=?', [$assignmentId], 'i');
        }
        demo_exec('DELETE FROM requirement_assignments WHERE ojt_enrollment_id=?', [$enrollmentId], 'i');
        $reportRows = query('SELECT id FROM report_assignments WHERE ojt_enrollment_id=?', [$enrollmentId], 'i') ?: [];
        foreach ($reportRows as $assignment) {
            $assignmentId = (int)$assignment['id'];
            $submissionRows = query('SELECT id FROM report_submissions WHERE report_assignment_id=?', [$assignmentId], 'i') ?: [];
            foreach ($submissionRows as $submission) {
                demo_exec('DELETE FROM report_submission_files WHERE report_submission_id=?', [(int)$submission['id']], 'i');
            }
            demo_exec('DELETE FROM report_submissions WHERE report_assignment_id=?', [$assignmentId], 'i');
        }
        demo_exec('DELETE FROM report_assignments WHERE ojt_enrollment_id=?', [$enrollmentId], 'i');
        demo_exec('DELETE FROM placements WHERE ojt_enrollment_id=?', [$enrollmentId], 'i');
        demo_exec('DELETE FROM ojt_enrollments WHERE id=?', [$enrollmentId], 'i');
    }

    $requirementVersions = query("SELECT id FROM requirement_definition_versions WHERE status='published' ORDER BY id") ?: [];
    if (count($requirementVersions) < 2) throw new RuntimeException('At least two published requirement versions are required.');

    $reportTemplates = [
        ['Initial OJT Report', 'initial'],
        ['Midterm OJT Report', 'midterm'],
        ['Final OJT Report', 'final'],
    ];
    $reportVersions = [];
    foreach ($reportTemplates as [$title, $type]) {
        $template = query_one('SELECT id FROM report_templates WHERE title=? LIMIT 1', [$title], 's');
        $templateId = $template ? (int)$template['id'] : demo_insert(
            'INSERT INTO report_templates(created_by,title,status) VALUES(?,?,\'active\')',
            [$adminId, $title], 'is'
        );
        $version = query_one('SELECT id FROM report_template_versions WHERE report_template_id=? AND version_no=1 LIMIT 1', [$templateId], 'i');
        $versionId = $version ? (int)$version['id'] : demo_insert(
            "INSERT INTO report_template_versions(report_template_id,version_no,report_type,instructions,status,published_at,created_at)
             VALUES(?,1,?,?, 'published', NOW(), NOW())",
            [$templateId, $type, "Demo $type report for OJTrack scenario"], 'iss'
        );
        $reportVersions[] = $versionId;
    }

    $profiles = [
        15 => ['status'=>'completed', 'placement'=>'completed', 'days'=>61, 'note'=>'Completed OJT demonstration record.'],
        16 => ['status'=>'ongoing', 'placement'=>'active', 'days'=>56, 'note'=>'Near completion demonstration record.'],
        17 => ['status'=>'ongoing', 'placement'=>'active', 'days'=>30, 'note'=>'Halfway progress demonstration record.'],
        18 => ['status'=>'ongoing', 'placement'=>'active', 'days'=>6, 'note'=>'Early progress demonstration record.'],
        19 => ['status'=>'not_started', 'placement'=>null, 'days'=>0, 'note'=>'Onboarding demonstration record; placement not started.'],
    ];

    foreach ($profiles as $studentId => $profile) {
        $enrollmentId = demo_insert(
            "INSERT INTO ojt_enrollments(student_id,academic_term_id,program_id,coordinator_id,coordinator_assigned_by,coordinator_assigned_at,year_level,required_hours,status,onboarding_completed_at,status_notes,completed_at)
             VALUES(?,?,?,?,?,NOW(),'4th Year',486,?,?,?,?)",
            [$studentId, $termId, $programId, $coordinatorId, $adminId, $profile['status'], $profile['status'] === 'not_started' ? null : date('Y-m-d H:i:s'), $profile['note'], $profile['status'] === 'completed' ? date('Y-m-d H:i:s') : null],
            'iiiiissss'
        );

        $placementId = null;
        if ($profile['placement']) {
            $placementId = demo_insert(
                'INSERT INTO placements(ojt_enrollment_id,company_id,status,starts_on,ends_on,assigned_by,notes) VALUES(?,?,?,\'2026-08-03\',\'2026-11-30\',?,?)',
                [$enrollmentId, $companyId, $profile['placement'], $adminId, 'Demo placement for MCC scenario'],
                'iisis'
            );
        }

        foreach ($requirementVersions as $version) {
            $assignmentId = demo_insert(
                'INSERT INTO requirement_assignments(requirement_definition_version_id,ojt_enrollment_id,assigned_by,due_date,assignment_note,status,assigned_at) VALUES(?,?,?,\'2026-08-15\',?,?,NOW())',
                [(int)$version['id'], $enrollmentId, $coordinatorId, 'Demo requirement assignment', 'assigned'],
                'iiiss'
            );
            if ($profile['status'] !== 'not_started') {
                demo_insert(
                    "INSERT INTO requirement_submissions(requirement_assignment_id,version_no,submitted_by,status,submitted_at,reviewed_by,reviewed_at,review_notes)
                     VALUES(?,1,?,'approved','2026-08-20 10:00:00',?, '2026-08-21 10:00:00','Demo requirement approved')",
                    [$assignmentId, $studentId, $coordinatorId], 'iii'
                );
            }
        }

        $reportStatusCount = $profile['status'] === 'completed' ? 3 : ($profile['status'] === 'ongoing' ? ($profile['days'] > 40 ? 3 : ($profile['days'] > 10 ? 2 : 1)) : 0);
        for ($i = 0; $i < $reportStatusCount; $i++) {
            $assignmentId = demo_insert(
                'INSERT INTO report_assignments(report_template_version_id,ojt_enrollment_id,assigned_by,period_start,period_end,due_date,status,assigned_at) VALUES(?,?,?,\'2026-08-03\',\'2026-11-30\',\'2026-12-05\',\'assigned\',NOW())',
                [$reportVersions[$i], $enrollmentId, $coordinatorId], 'iii'
            );
            $submissionStatus = ($profile['status'] === 'completed' || $i < $reportStatusCount - 1) ? 'approved' : 'submitted';
            demo_insert(
                "INSERT INTO report_submissions(report_assignment_id,version_no,submitted_by,status,submitted_at,reviewed_by,reviewed_at,review_notes,student_note)
                 VALUES(?,1,? ,?,'2026-10-15 10:00:00',?,CASE WHEN ?='approved' THEN '2026-10-16 10:00:00' ELSE NULL END,CASE WHEN ?='approved' THEN 'Demo report approved' ELSE NULL END,'Demo report submission')",
                [$assignmentId, $studentId, $submissionStatus, $coordinatorId, $submissionStatus, $submissionStatus], 'iisiss'
            );
        }

        if ($placementId) {
            $dates = demo_weekdays('2026-08-03', $profile['days']);
            foreach ($dates as $index => $date) {
                $minutes = ($profile['status'] === 'completed' && $index === count($dates) - 1) ? 360 : 480;
                $morningMinutes = 240;
                $afternoonMinutes = $minutes - $morningMinutes;
                $dayId = demo_insert('INSERT INTO attendance_days(placement_id,attendance_date,status,credited_minutes,remarks) VALUES(?,?,\'present\',?,?)', [$placementId, $date, $minutes, 'Demo attendance record'], 'isis');
                demo_insert("INSERT INTO attendance_sessions(attendance_day_id,time_in,time_out,credited_minutes,source,recorded_by) VALUES(?,?,?,?,'company',?)", [$dayId, $date . ' 08:00:00', $date . ' 12:00:00', $morningMinutes, 32], 'issii');
                $afternoonOut = $afternoonMinutes === 240 ? '17:00:00' : '15:00:00';
                demo_insert("INSERT INTO attendance_sessions(attendance_day_id,time_in,time_out,credited_minutes,source,recorded_by) VALUES(?,?,?,?,'company',?)", [$dayId, $date . ' 13:00:00', $date . ' ' . $afternoonOut, $afternoonMinutes, 32], 'issii');
                $journalId = demo_insert('INSERT INTO journal_days(placement_id,entry_date,week_number) VALUES(?,?,?)', [$placementId, $date, (int)floor($index / 5) + 1], 'isi');
                demo_insert("INSERT INTO journal_revisions(journal_day_id,revision_no,activities,learnings,challenges,claimed_minutes,status,submitted_at,reviewed_by,reviewed_at,review_notes) VALUES(?,1,?,?,?,?,'approved',?,?,?,'Demo journal approved')", [$journalId, 'Completed assigned OJT activities.', 'Applied workplace skills and documented progress.', 'No major issues reported.', $minutes, $date . ' 17:00:00', $coordinatorId, $date . ' 18:00:00'], 'isssisis');
            }
        }
    }
    db()->commit();
    echo "BSIT company demo scenario created for students 15-19.\n";
} catch (Throwable $error) {
    db()->rollback();
    fwrite(STDERR, "Scenario failed: " . $error->getMessage() . "\n");
    exit(1);
}
