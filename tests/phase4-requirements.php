<?php
if (PHP_SAPI !== 'cli' || getenv('OJTRACK_DB_NAME') !== 'ojtrack_test') {
    exit("Use isolated ojtrack_test database.\n");
}
require __DIR__ . '/../config/db.php';

$checks=0;
function p4check(bool $ok,string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR,"FAIL: $label\n"); exit(1); }
    $checks++;
}
function p4count(string $sql,array $params=[],string $types=''): int {
    $r=query_one($sql,$params,$types);
    return (int)($r['n'] ?? 0);
}

p4check(p4count("SELECT COUNT(*) n FROM requirement_templates")===3,'legacy template library preserved');
p4check(p4count("SELECT COUNT(*) n FROM ojt_requirements")===7,'legacy requirement rows preserved');
p4check(p4count("SELECT COUNT(*) n FROM schema_migrations WHERE version='004_requirements'")===1,'phase 4 recorded');

p4check(p4count("SELECT COUNT(*) n FROM legacy_requirement_template_migration_map")===3,'all legacy templates mapped');
p4check(p4count("SELECT COUNT(*) n FROM legacy_requirement_migration_map")===7,'all legacy requirement rows mapped');
p4check(p4count("SELECT COUNT(*) n FROM requirement_definitions")===7,'templates plus synthetic definitions preserved');
p4check(p4count("SELECT COUNT(*) n FROM requirement_definition_versions")===7,'one version per migrated definition');
p4check(p4count("SELECT COUNT(*) n FROM requirement_assignments")===7,'one assignment per legacy requirement');
p4check(p4count("SELECT COUNT(*) n FROM requirement_submissions")===4,'only submitted legacy rows became submissions');
p4check(p4count("SELECT COUNT(*) n FROM requirement_submission_attachments")===3,'all real files linked');

$template=query_one(
    "SELECT rd.title,rdv.description
     FROM legacy_requirement_template_migration_map lm
     JOIN requirement_definitions rd ON rd.id=lm.requirement_definition_id
     JOIN requirement_definition_versions rdv ON rdv.id=lm.requirement_definition_version_id
     WHERE lm.legacy_template_id=1"
);
p4check($template && $template['title']==='Test file','legacy template title preserved');
p4check($template && $template['description']==='Template instructions','legacy template description preserved');

$pending=query_one(
    "SELECT ra.status assignment_status,rs.status submission_status
     FROM legacy_requirement_migration_map lm
     JOIN requirement_assignments ra ON ra.id=lm.requirement_assignment_id
     LEFT JOIN requirement_submissions rs ON rs.id=lm.requirement_submission_id
     WHERE lm.legacy_requirement_id=1"
);
p4check($pending && $pending['assignment_status']==='assigned','pending submitted assignment stays open');
p4check($pending && $pending['submission_status']==='submitted','pending legacy submission maps to submitted');

$returned=query_one(
    "SELECT ra.status assignment_status,rs.status submission_status,rs.review_notes,rs.reviewed_by
     FROM legacy_requirement_migration_map lm
     JOIN requirement_assignments ra ON ra.id=lm.requirement_assignment_id
     JOIN requirement_submissions rs ON rs.id=lm.requirement_submission_id
     WHERE lm.legacy_requirement_id=4"
);
p4check($returned && $returned['assignment_status']==='assigned','returned assignment remains open');
p4check($returned && $returned['submission_status']==='returned','rejected maps to returned submission');
p4check($returned && $returned['review_notes']==='Replace this file' && (int)$returned['reviewed_by']===2,'review metadata preserved');

$waived=query_one(
    "SELECT ra.status,ra.assignment_note,lm.requirement_submission_id
     FROM legacy_requirement_migration_map lm
     JOIN requirement_assignments ra ON ra.id=lm.requirement_assignment_id
     WHERE lm.legacy_requirement_id=5"
);
p4check($waived && $waived['status']==='waived','approved-without-submission maps to waived');
p4check($waived && $waived['requirement_submission_id']===null,'no fake submission created for waiver');
p4check($waived && $waived['assignment_note']==='Legacy manual approval','waiver note preserved');

$approved=query_one(
    "SELECT ra.status assignment_status,rs.status submission_status
     FROM legacy_requirement_migration_map lm
     JOIN requirement_assignments ra ON ra.id=lm.requirement_assignment_id
     JOIN requirement_submissions rs ON rs.id=lm.requirement_submission_id
     WHERE lm.legacy_requirement_id=6"
);
p4check($approved && $approved['assignment_status']==='closed','approved submission closes assignment');
p4check($approved && $approved['submission_status']==='approved','approved submission status preserved');

p4check(
    p4count(
        "SELECT COUNT(*) n
         FROM legacy_requirement_migration_map lm
         JOIN requirement_assignments ra ON ra.id=lm.requirement_assignment_id
         JOIN requirement_definition_versions rv ON rv.id=ra.requirement_definition_version_id
         JOIN requirement_definitions rd ON rd.id=rv.requirement_definition_id
         WHERE lm.legacy_requirement_id IN (6,7) AND rd.title='Duplicate Requirement'"
    )===2,
    'duplicate historical assignments are not merged'
);

$attachment=query_one(
    "SELECT a.storage_key,a.detected_mime,a.size_bytes,a.sha256
     FROM legacy_requirement_migration_map lm
     JOIN requirement_submission_attachments rsa ON rsa.requirement_submission_id=lm.requirement_submission_id
     JOIN attachments a ON a.id=rsa.attachment_id
     WHERE lm.legacy_requirement_id=4"
);
p4check($attachment && $attachment['storage_key']==='requirements/returned.pdf','requirement attachment storage key preserved');
p4check($attachment && $attachment['detected_mime']==='application/pdf','requirement attachment MIME recorded');
p4check($attachment && (int)$attachment['size_bytes']>0 && strlen($attachment['sha256'])===64,'requirement attachment hash and size recorded');

p4check(
    p4count("SELECT COUNT(*) n FROM ojt_requirements WHERE id=4 AND status='rejected' AND remarks='Replace this file'")===1,
    'legacy returned requirement unchanged'
);
p4check(
    p4count("SELECT COUNT(*) n FROM ojt_requirements WHERE id=5 AND status='approved' AND submitted_at IS NULL")===1,
    'legacy manual approval unchanged'
);

echo json_encode(['phase4_requirement_checks_passed'=>$checks,'result'=>'PASS']) . PHP_EOL;
