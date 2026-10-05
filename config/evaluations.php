<?php
/**
 * Backward-compatible page entrypoint for the normalized evaluation writer.
 * The parameter name remains $submission_id because existing forms post that
 * field, but it now identifies evaluation_requests.id.
 */
function submit_evaluation($company_id, $submission_id, $scores, $comments, $actor_id) {
    normalized_submit_evaluation(
        (int)$company_id,
        (int)$submission_id,
        $scores,
        (string)$comments,
        (int)$actor_id
    );
}
