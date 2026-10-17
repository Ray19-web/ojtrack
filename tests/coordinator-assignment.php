<?php
// Database-free checks with synthetic coordinator records.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../config/coordinator-assignment.php';

$fixtures = [];
$checks = 0;
function query($sql, $params = [], $types = '') {
    global $fixtures;
    return array_values(array_filter($fixtures, fn($c) => $c['status'] === 'active'));
}
function query_one($sql, $params = [], $types = '') {
    foreach (query($sql) as $c) {
        if (strtolower(trim($c['department'])) === strtolower(trim($params[0]))) return $c;
    }
    return null;
}
function check_assignment($expected, $program, $label) {
    global $checks;
    $actual = find_coordinator_for_program($program);
    if ($actual !== $expected) throw new RuntimeException("FAIL: $label");
    $checks++;
}
function coordinator($id, $department, $status = 'active') {
    return compact('id', 'department', 'status');
}

$it = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology'];
$cs = ['code' => 'BSCS', 'name' => 'Bachelor of Science in Computer Science'];
$met = ['code' => 'BSMECH', 'name' => 'Bachelor of Science in Mechanical Engineering Technology'];
$et = ['code' => 'BSET', 'name' => 'Bachelor of Science in Electrical Technology'];
$fixtures = [coordinator(1, 'Information Technology'), coordinator(2, 'Computer Science')];
check_assignment(1, $it, 'IT legacy department');
check_assignment(2, $cs, 'program change from IT to CS chooses CS');
$fixtures = [coordinator(1, 'Information Technology'), coordinator(2, 'BSIT')];
check_assignment(2, $it, 'exact program code wins over legacy alias');
$fixtures = [coordinator(1, 'Information Technology'), coordinator(2, $it['name'])];
check_assignment(2, $it, 'exact program name wins over legacy alias');
$fixtures = [coordinator(1, ' bsit ')];
check_assignment(1, $it, 'case and surrounding spaces');
$fixtures = [coordinator(1, 'BSIT', 'inactive'), coordinator(2, 'BSIT', 'archived')];
check_assignment(null, $it, 'inactive and archived coordinators excluded');
$fixtures = [coordinator(1, 'Computer Science')];
check_assignment(null, $it, 'unrelated program stays unassigned');
$fixtures = [coordinator(1, 'Mechanical Engineering Technology')];
check_assignment(1, $met, 'mechanical legacy department matches');
check_assignment(null, $et, 'electrical does not match mechanical');
$fixtures = [coordinator(1, 'Electrical Technology')];
check_assignment(1, $et, 'electrical legacy department matches');
check_assignment(null, $met, 'mechanical does not match electrical');
$fixtures = [coordinator(1, 'Engineering')];
check_assignment(null, $met, 'ambiguous engineering department rejected for mechanical');
check_assignment(null, $et, 'ambiguous engineering department rejected for electrical');
$fixtures = [coordinator(1, 'BSNEW')];
check_assignment(1, ['code' => 'BSNEW', 'name' => 'New Academic Program'], 'custom program code supported');
$fixtures = [coordinator(1, 'New Academic Program')];
check_assignment(1, ['code' => 'BSNEW', 'name' => 'New Academic Program'], 'custom program name supported');
$defaults = json_decode(file_get_contents(__DIR__ . '/../database/default-programs.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($defaults as $program) {
    $department = str_replace('Bachelor of Science in ', '', $program['name']);
    $fixtures = [coordinator(1, $department)];
    check_assignment(1, $program, $program['code'] . ' matches its department');
}
$marine = $defaults[2];
$fixtures = [coordinator(1, 'Mechanical Engineering Technology')];
check_assignment(null, $marine, 'marine must not match mechanical');
$fixtures = [coordinator(1, 'Naval Architecture and Marine Engineering')];
check_assignment(null, $marine, 'marine technology must not match naval architecture');
echo "$checks coordinator assignment checks passed.\n";
