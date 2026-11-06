<?php
// Explicit CLI catalog setup. Never runs during normal page requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';
$options = getopt('', ['apply', 'backup:']);
$defaults = json_decode(file_get_contents(__DIR__ . '/../database/default-programs.json'), true, 512, JSON_THROW_ON_ERROR);
$programs = query('SELECT * FROM programs ORDER BY id');
$hasEnrollments = (bool)query_one("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ojt_enrollments'");
$references = function (array $program) use ($hasEnrollments): int {
    return (int)query_one('SELECT COUNT(*) c FROM students WHERE program_id=? OR program=?', [(int)$program['id'], $program['code']], 'is')['c']
        + ($hasEnrollments ? (int)query_one('SELECT COUNT(*) c FROM ojt_enrollments WHERE program_id=?', [(int)$program['id']], 'i')['c'] : 0)
        + (int)query_one('SELECT COUNT(*) c FROM coordinators WHERE department=? OR department=?', [$program['code'], $program['name']], 'ss')['c'];
};
$codes = array_column($defaults, 'code');
foreach ($programs as $program) {
    $index = array_search(strtoupper($program['code']), $codes, true);
    if ($index !== false) {
        // A different degree using the same code must not be relabeled in historical records.
        if ($program['code'] === 'BSMET' && stripos($program['name'], 'mechanical') !== false && $references($program) > 0) {
            fwrite(STDERR, "Cannot relabel referenced Mechanical Engineering Technology records as Marine Engineering Technology.\n");
            exit(1);
        }
        echo 'SET ACTIVE: ' . $defaults[$index]['code'] . ' - ' . $defaults[$index]['name'] . PHP_EOL;
    } else {
        echo ($references($program) > 0 ? 'RETIRE (preserve linked records): ' : 'REMOVE UNUSED: ') . $program['code'] . PHP_EOL;
    }
}
foreach ($defaults as $program) {
    if (!in_array($program['code'], array_map('strtoupper', array_column($programs, 'code')), true)) {
        echo 'ADD: ' . $program['code'] . ' - ' . $program['name'] . PHP_EOL;
    }
}
if (!isset($options['apply'])) exit("Preview only. Use --apply --backup=<new-file-outside-web-root>.\n");
$backup = $options['backup'] ?? '';
$backupParent = realpath(dirname($backup));
$webRoot = realpath(__DIR__ . '/..');
$normalizedParent = str_replace('\\', '/', (string)$backupParent);
$normalizedRoot = str_replace('\\', '/', $webRoot);
if ($backup === '' || !$backupParent || strcasecmp($normalizedParent, $normalizedRoot) === 0
    || stripos($normalizedParent . '/', $normalizedRoot . '/') === 0) {
    exit("Provide a backup file outside the application directory.\n");
}
$handle = @fopen($backup, 'x');
if (!$handle) exit("Backup file must be new and writable.\n");
$snapshot = json_encode($programs, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (fwrite($handle, $snapshot) !== strlen($snapshot)) { fclose($handle); exit("Backup failed; no data changed.\n"); }
fclose($handle);
db()->begin_transaction();
try {
    // Lock the catalog before checking references again and applying changes.
    $programs = query('SELECT * FROM programs ORDER BY id FOR UPDATE');
    foreach ($programs as $program) {
        if (in_array(strtoupper($program['code']), $codes, true)) {
            if ($program['code'] === 'BSMET' && stripos($program['name'], 'mechanical') !== false && $references($program) > 0) {
                throw new RuntimeException('Referenced mechanical degree cannot be renamed.');
            }
            continue;
        }
        if ($references($program) > 0) {
            query("UPDATE programs SET status='inactive' WHERE id=?", [(int)$program['id']], 'i');
        } else {
            query('DELETE FROM programs WHERE id=?', [(int)$program['id']], 'i');
        }
    }
    foreach ($defaults as $program) {
        query("INSERT INTO programs(code,name,status) VALUES(?,?,'active')
               ON DUPLICATE KEY UPDATE code=VALUES(code),name=VALUES(name),status='active'",
            [$program['code'], $program['name']], 'ss');
    }
    $active = query("SELECT code,name FROM programs WHERE status='active' ORDER BY code");
    $expected = $defaults;
    usort($expected, fn($a, $b) => strcmp($a['code'], $b['code']));
    if ($active !== $expected) throw new RuntimeException('Active catalog verification failed.');
    db()->commit();
    echo 'Applied and verified five default programs. Backup: ' . $backup . PHP_EOL;
} catch (Throwable $error) {
    db()->rollback();
    fwrite(STDERR, 'No changes committed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
