<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';
$options = getopt('', ['apply', 'backup:']);
$roster = json_decode(file_get_contents(__DIR__ . '/../database/student-roster.json'), true, 512, JSON_THROW_ON_ERROR);
if (!$roster) throw new RuntimeException('Roster must not be empty.');
$term = query_one("SELECT id FROM academic_terms WHERE status='active' ORDER BY id DESC LIMIT 1");
if (!$term) throw new RuntimeException('An active academic term is required.');
$seen = [];
$programs = [];
foreach ($roster as $row) {
    if (!$row['name'] || !preg_match('/^(?:[0-9]{1,20}|S[0-9]{8,19})$/D', $row['student_id_no']) || isset($seen[$row['student_id_no']])) {
        throw new RuntimeException('Every student needs a full name and a unique numeric or system-generated student ID.');
    }
    if (str_starts_with($row['student_id_no'], 'S') && empty($row['provisional_id'])) {
        throw new RuntimeException('System-generated IDs must be marked provisional.');
    }
    $seen[$row['student_id_no']] = true;
    $program = query_one("SELECT id,code,name FROM programs WHERE code=? AND status='active'", [$row['program']], 's');
    if (!$program || !($program['coordinator_id'] = find_coordinator_for_program($program))) {
        throw new RuntimeException('Active program and assigned coordinator required: ' . $row['program']);
    }
    $programs[$row['program']] = $program;
    $existing = query_one('SELECT u.name FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_id_no=?', [$row['student_id_no']], 's');
    if ($existing && $existing['name'] !== $row['name']) {
        throw new RuntimeException('Student ID belongs to a different name: ' . $row['student_id_no']);
    }
    echo $row['student_id_no'] . ': ' . $row['name'] . ' - ' . $row['program'] . PHP_EOL;
}
if (!isset($options['apply'])) exit("Preview only. Replaces the active student roster; use --apply --backup=<new-file-outside-web-root>.\n");
$backup = $options['backup'] ?? '';
$parent = realpath(dirname($backup));
$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
if (!$backup || !$parent || stripos(str_replace('\\', '/', $parent) . '/', $root . '/') === 0) {
    throw new RuntimeException('Choose a backup outside the application directory.');
}
$snapshot = [
    'student_users' => query("SELECT * FROM users WHERE role='student'"),
    'students' => query('SELECT * FROM students'),
    'enrollments' => query('SELECT * FROM ojt_enrollments'),
];
$handle = @fopen($backup, 'x');
if (!$handle) throw new RuntimeException('Backup must be a new writable file.');
$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (fwrite($handle, $json) !== strlen($json)) { fclose($handle); throw new RuntimeException('Backup failed.'); }
fclose($handle);
db()->begin_transaction();
try {
    query('SELECT id FROM students FOR UPDATE');
    $ids = [];
    foreach ($roster as $row) {
        $program = $programs[$row['program']];
        $year = $row['year_level'] ?? null;
        $notes = !empty($row['provisional_id'])
            ? 'Provisional system-generated student ID; official school ID pending. Assigned at user request because the source repeats ' . ($row['source_student_id_no'] ?? 'one ID') . '.'
            : null;
        $existing = query_one('SELECT s.id,s.user_id,u.name,s.program_id FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_id_no=?', [$row['student_id_no']], 's');
        if ($existing) {
            if ($existing['name'] !== $row['name'] || (int)$existing['program_id'] !== (int)$program['id']) {
                throw new RuntimeException('Existing student identity/program differs from the roster.');
            }
            $id = (int)$existing['id'];
            query("UPDATE users SET status='active' WHERE id=?", [(int)$existing['user_id']], 'i');
            query('UPDATE students SET is_archived=0,coordinator_id=?,year_level=COALESCE(?,year_level),status_notes=COALESCE(?,status_notes) WHERE id=?', [$program['coordinator_id'], $year, $notes, $id], 'issi');
        } else {
            $userId = insert("INSERT INTO users(name,email,password,role,status) VALUES(?,NULL,?,'student','active')",
                [$row['name'], password_hash($row['student_id_no'], PASSWORD_DEFAULT)], 'ss');
            $id = insert("INSERT INTO students(user_id,student_id_no,program_id,program,department,year_level,coordinator_id,status_notes,contact_number,company_id,ojt_status)
                          VALUES(?,?,?,?,?,?,?,?,NULL,NULL,'pending')",
                [$userId, $row['student_id_no'], (int)$program['id'], $program['code'], $program['code'], $year, $program['coordinator_id'], $notes], 'isisssis');
        }
        $ids[] = (int)$id;
        $enrollment = query_one('SELECT id,program_id FROM ojt_enrollments WHERE student_id=? AND academic_term_id=?', [$id, (int)$term['id']], 'ii');
        if ($enrollment && (int)$enrollment['program_id'] !== (int)$program['id']) {
            throw new RuntimeException('Existing term enrollment has a different program.');
        }
        if (!$enrollment) {
            insert("INSERT INTO ojt_enrollments(student_id,academic_term_id,program_id,coordinator_id,coordinator_assigned_at,year_level,status_notes,status)
                    VALUES(?,?,?,?,NOW(),?,?,'pending')", [$id, (int)$term['id'], (int)$program['id'], $program['coordinator_id'], $year, $notes], 'iiiiss');
        } else {
            query('UPDATE ojt_enrollments SET coordinator_id=?,year_level=COALESCE(?,year_level),status_notes=COALESCE(?,status_notes) WHERE id=?', [$program['coordinator_id'], $year, $notes, (int)$enrollment['id']], 'issi');
        }
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    query("UPDATE students s JOIN users u ON u.id=s.user_id SET s.is_archived=1,u.status='archived' WHERE s.id NOT IN ($marks)", $ids, $types);
    if ((int)query_one("SELECT COUNT(*) c FROM students s JOIN users u ON u.id=s.user_id WHERE s.is_archived=0 AND u.status!='archived'")['c'] !== count($roster)) {
        throw new RuntimeException('Active roster verification failed.');
    }
    log_activity(null, 'Student Roster Replaced', 'Imported ' . count($roster) . ' student records from the supplied roster. System-generated IDs are provisional where indicated. Archived previous roster; preserved historical training records.');
    db()->commit();
    echo 'Applied ' . count($roster) . ' students; previous students archived. Backup: ' . $backup . PHP_EOL;
} catch (Throwable $error) {
    db()->rollback();
    fwrite(STDERR, 'No roster changes committed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
