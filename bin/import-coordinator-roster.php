<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';
$options = getopt('', ['apply', 'backup:']);
$roster = json_decode(file_get_contents(__DIR__ . '/../database/coordinator-roster.json'), true, 512, JSON_THROW_ON_ERROR);
$codes = [];
foreach ($roster as $row) {
    if (!$row['name'] || in_array($row['program'], $codes, true)
        || !query_one("SELECT id FROM programs WHERE code=? AND status='active'", [$row['program']], 's')
        || ($row['email'] !== null && !filter_var($row['email'], FILTER_VALIDATE_EMAIL))) {
        throw new RuntimeException('Invalid roster entry or inactive program.');
    }
    $codes[] = $row['program'];
    echo $row['program'] . ': ' . $row['name'] . ' (contact details ' . ($row['email'] ? 'provided' : 'not provided') . ')' . PHP_EOL;
}
if (!isset($options['apply'])) exit("Preview only. Apply with --apply --backup=<new-file-outside-web-root>.\n");
$backup = $options['backup'] ?? '';
$parent = realpath(dirname($backup));
$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$normalizedParent = str_replace('\\', '/', (string)$parent);
if (!$backup || !$parent || stripos($normalizedParent . '/', $root . '/') === 0) {
    throw new RuntimeException('Choose a backup outside the application directory.');
}
$snapshot = [
    'coordinators' => query('SELECT * FROM coordinators'),
    'coordinator_users' => query("SELECT * FROM users WHERE role='coordinator'"),
    'students' => query('SELECT id,coordinator_id FROM students'),
    'enrollments' => query('SELECT id,coordinator_id,coordinator_assigned_by,coordinator_assigned_at FROM ojt_enrollments'),
];
$handle = @fopen($backup, 'x');
if (!$handle) throw new RuntimeException('Backup must be a new writable file.');
$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (fwrite($handle, $json) !== strlen($json)) { fclose($handle); throw new RuntimeException('Backup failed.'); }
fclose($handle);

// DDL is deliberately separate from the data transaction (MySQL implicitly commits DDL).
$emailColumn = query_one("SHOW COLUMNS FROM users LIKE 'email'");
if ($emailColumn['Null'] !== 'YES') {
    query('ALTER TABLE users MODIFY COLUMN email VARCHAR(150) NULL DEFAULT NULL');
}
db()->begin_transaction();
try {
    query("SELECT id FROM users WHERE role='coordinator' FOR UPDATE");
    $ids = [];
    foreach ($roster as $row) {
        $existing = query_one("SELECT c.id,c.user_id FROM coordinators c JOIN users u ON u.id=c.user_id
                               WHERE u.name=? AND c.department=? AND u.role='coordinator'",
            [$row['name'], $row['program']], 'ss');
        if ($existing) {
            $id = (int)$existing['id'];
            query("UPDATE users SET status='active',email=COALESCE(?,email) WHERE id=?", [$row['email'], (int)$existing['user_id']], 'si');
            query('UPDATE coordinators SET contact_number=COALESCE(?,contact_number) WHERE id=?', [$row['contact_number'], $id], 'si');
        } else {
            // No old account credentials are reused and no temporary password is distributed.
            $userId = insert("INSERT INTO users(name,email,password,role,status) VALUES(?,?,?,'coordinator','active')",
                [$row['name'], $row['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)], 'sss');
            $id = insert('INSERT INTO coordinators(user_id,coordinator_id_no,department,contact_number) VALUES(?,?,?,?)',
                [$userId, generate_coordinator_id(), $row['program'], $row['contact_number']], 'isss');
        }
        $ids[] = (int)$id;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    query("UPDATE users u JOIN coordinators c ON c.user_id=u.id SET u.status='archived' WHERE c.id NOT IN ($marks)", $ids, $types);
    query("UPDATE students s JOIN users u ON u.id=s.user_id
           LEFT JOIN programs p ON p.id=s.program_id OR (s.program_id IS NULL AND p.code=s.program)
           LEFT JOIN coordinators c ON c.department=p.code AND c.id IN ($marks)
           SET s.coordinator_id=c.id WHERE s.is_archived=0 AND u.status!='archived'", $ids, $types);
    $termId = normalized_active_term_id();
    query("UPDATE ojt_enrollments oe JOIN students s ON s.id=oe.student_id JOIN users u ON u.id=s.user_id
           JOIN programs p ON p.id=oe.program_id
           LEFT JOIN coordinators c ON c.department=p.code AND c.id IN ($marks)
           SET oe.coordinator_id=c.id,oe.coordinator_assigned_by=NULL,oe.coordinator_assigned_at=NOW()
           WHERE oe.academic_term_id=? AND s.is_archived=0 AND u.status!='archived'
             AND NOT (oe.coordinator_id <=> c.id)", [...$ids, $termId], $types . 'i');
    if ((int)query_one("SELECT COUNT(*) c FROM coordinators c JOIN users u ON u.id=c.user_id WHERE u.status!='archived'")['c'] !== count($roster)) {
        throw new RuntimeException('Roster verification failed.');
    }
    log_activity(null, 'Coordinator Roster Replaced', 'Imported five program coordinators; retired previous accounts and updated current assignments. Contact details pending.');
    query("INSERT INTO schema_migrations(version,description) VALUES('011_optional_user_email','Allow missing coordinator email addresses')
           ON DUPLICATE KEY UPDATE description=VALUES(description)");
    db()->commit();
    echo 'Applied five coordinators. Previous accounts archived; historical authors and archived student records preserved.' . PHP_EOL;
    echo 'Backup: ' . $backup . PHP_EOL;
} catch (Throwable $error) {
    db()->rollback();
    fwrite(STDERR, 'Roster data was not committed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
