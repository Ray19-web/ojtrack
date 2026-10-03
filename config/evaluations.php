<?php
function submit_evaluation($company_id, $submission_id, $scores, $comments, $actor_id) {
    db()->begin_transaction();
    try {
        $sub = query_one("SELECT * FROM eval_submissions WHERE id=? AND company_id=? FOR UPDATE", [$submission_id, $company_id], 'ii');
        if (!$sub) throw new DomainException('Evaluation not found.');
        if ($sub['status'] === 'completed') { db()->commit(); return; }
        $form = query_one("SELECT * FROM evaluation_forms WHERE id=?", [$sub['form_id']], 'i');
        if (!$form || $form['score_mode'] !== 'percentage') throw new DomainException('Ask your coordinator to send a supported percentage form.');
        $criteria = query("SELECT c.*, s.title AS section_title FROM eval_criteria c JOIN eval_sections s ON s.id=c.section_id WHERE s.form_id=? ORDER BY s.sort_order,c.sort_order,c.id", [$sub['form_id']], 'i');
        if (!$criteria) throw new DomainException('This evaluation has no criteria. Contact your coordinator.');
        $rules = query("SELECT * FROM eval_rating_rules WHERE form_id=? ORDER BY score_min", [$sub['form_id']], 'i');
        $answers = []; $total = 0;
        foreach ($criteria as $criterion) {
            $raw = $scores['criterion_' . $criterion['id']] ?? null;
            if (!is_scalar($raw) || filter_var($raw, FILTER_VALIDATE_INT) === false || (int)$raw < 0 || (int)$raw > 100) throw new DomainException('Answer every criterion with a whole-number score from 0 to 100.');
            $score = (int)$raw; $equivalent = null;
            foreach ($rules as $rule) if ($score >= $rule['score_min'] && $score <= $rule['score_max']) { $equivalent = $rule['equivalent']; break; }
            $answers[] = [$submission_id, $criterion['section_title'], $criterion['label'], $score, $equivalent];
            $total += $score;
        }
        if (query_one("SELECT id FROM eval_answers WHERE submission_id=? LIMIT 1", [$submission_id], 'i')) throw new DomainException('This request contains an incomplete older submission. Contact your coordinator before retrying.');
        foreach ($answers as $answer) insert("INSERT INTO eval_answers (submission_id,section_title,criterion_label,score,equivalent) VALUES (?,?,?,?,?)", $answer, 'issdd');
        $overall = round($total / count($criteria), 2); $overall_eq = null;
        // Bands are inclusive integer ranges. Only the band lookup rounds the mean.
        $band_score = (int)round($overall);
        foreach ($rules as $rule) if ($band_score >= $rule['score_min'] && $band_score <= $rule['score_max']) { $overall_eq = $rule['equivalent']; break; }
        query("UPDATE eval_submissions SET status='completed',overall_score=?,overall_equivalent=?,comments=?,submitted_at=NOW() WHERE id=?", [$overall,$overall_eq,$comments,$submission_id], 'ddsi');
        $student = query_one("SELECT user_id FROM students WHERE id=?", [$sub['student_id']], 'i');
        if ($student) create_notification($student['user_id'], 'Your company submitted an OJT evaluation.', 'success', '/ojtrack/student/evaluation.php');
        create_notification($sub['requested_by'], 'An OJT evaluation has been submitted: ' . $form['title'], 'success', '/ojtrack/coordinator/evaluation.php');
        log_activity($actor_id, 'Evaluation Form Submitted', "Submission #$submission_id, Score: $overall");
        db()->commit();
    } catch (Throwable $error) { db()->rollback(); throw $error; }
}
