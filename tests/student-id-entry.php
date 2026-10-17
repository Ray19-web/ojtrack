<?php
// Real account-creation controller, synthetic in-memory persistence. No database access.
if (PHP_SAPI !== 'cli') exit;
class StudentIdTestDatabase {
    public array $users=[];
    public array $students=[];
    public array $snapshot=[];
    public bool $committed=false;
    public bool $rolledBack=false;
    function begin_transaction() { $this->snapshot=[$this->users,$this->students]; }
    function commit() { $this->committed=true; }
    function rollback() { [$this->users,$this->students]=$this->snapshot; $this->rolledBack=true; }
}
function db() { return $GLOBALS['test_db']; }
function require_login($roles) {}
function current_user() { return ['id'=>99]; }
function request_error($code,$message) { throw new RuntimeException($message); }
function generate_student_id() { return 'S20990001'; }
function generate_coordinator_id() { return 'C20990001'; }
function find_coordinator_for_program($program) { return $GLOBALS['test_coordinator_id'] ?? null; }
function log_activity(...$args) {}
function query($sql,$params=[],$types='') { return []; }
function query_one($sql,$params=[],$types='') {
    if (str_contains($sql,'FROM students WHERE student_id_no')) return strcasecmp($params[0],$GLOBALS['existing_id'])===0 ? ['id'=>50] : null;
    if (str_contains($sql,'FROM users WHERE email')) return null;
    if (str_contains($sql,'FROM programs')) return ($params[0] ?? 0)==1 ? ['id'=>1,'code'=>'BSIT','name'=>'Information Technology'] : null;
    if (str_contains($sql,'COUNT(*)')) return ['c'=>0];
    return null;
}
function insert($sql,$params=[],$types='') {
    if (str_contains($sql,'INSERT INTO users')) { db()->users[]=$params; return 1; }
    if (str_contains($sql,'INSERT INTO students')) {
        if ($GLOBALS['profile_conflict']) throw new mysqli_sql_exception('Synthetic duplicate student ID',1062);
        db()->students[]=$params; return 1;
    }
    return 1;
}
$source=file_get_contents(__DIR__.'/../admin/users.php');
$controller=substr($source,5,strpos($source,'?>')-5);
$controller=preg_replace("~require_once __DIR__ \. '/../(?:config/(?:db|auth)|includes/header)\.php';~", '', $controller);
$controller=str_replace("define('OJTRACK', true);", '', $controller);
function student_id_case(array $overrides=[],string $existing='',bool $conflict=false): array {
    global $controller;
    $GLOBALS['test_db']=new StudentIdTestDatabase(); $GLOBALS['existing_id']=$existing; $GLOBALS['profile_conflict']=$conflict;
    $_SERVER['REQUEST_METHOD']='POST'; $_GET=[];
    $_POST=array_replace(['action'=>'add_user','role'=>'student','name'=>'Synthetic Student','email'=>'','student_id_mode'=>'manual','student_id_no'=>'2023305242','program_id'=>1,'year_level'=>'4th Year','required_hours'=>486],$overrides);
    eval($controller);
    return ['error'=>$error,'success'=>$success,'db'=>db(),'values'=>$add_values,'failed'=>$add_user_failed];
}
$checks=0;
function id_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
$r=student_id_case();
id_check($r['error']==='' && $r['db']->committed && $r['db']->students[0][1]==='2023305242','official numeric ID saved exactly');
id_check(password_verify('2023305242',$r['db']->users[0][3]),'manual ID is initial student password');
$r=student_id_case(['student_id_no'=>'00203305242']);
id_check($r['db']->students[0][1]==='00203305242' && password_verify('00203305242',$r['db']->users[0][3]),'leading zeroes preserved');
$r=student_id_case(['student_id_no'=>'2023-005242']);
id_check($r['error']==='' && $r['db']->students[0][1]==='2023-005242','hyphenated ID accepted');
$r=student_id_case(['student_id_mode'=>'auto','student_id_no'=>'forged-preview']);
id_check($r['error']==='' && $r['db']->students[0][1]==='S20990001' && password_verify('S20990001',$r['db']->users[0][3]),'automatic option ignores submitted preview');
foreach (['','ID WITH SPACES','<script>','2023/123',str_repeat('1',21),['malformed']] as $bad) {
    $r=student_id_case(['student_id_no'=>$bad]);
    id_check($r['error']!=='' && !$r['db']->users && !$r['db']->students,'invalid or missing manual ID creates nothing');
}
$r=student_id_case([], '2023305242');
id_check($r['error']!=='' && !$r['db']->users,'duplicate IDs rejected including archived students');
$r=student_id_case(['student_id_no'=>'abc123'],'ABC123');
id_check($r['error']!=='' && !$r['db']->users,'ID uniqueness follows case-insensitive database matching');
$r=student_id_case(['student_id_mode'=>'unknown']);
id_check($r['error']!=='' && !$r['db']->users,'unknown generation mode rejected');
$r=student_id_case([], '', true);
id_check($r['error']!=='' && $r['db']->rolledBack && !$r['db']->users && !$r['db']->students,'concurrent duplicate rolls back whole account');
id_check($r['failed'] && $r['values']['student_id_no']==='2023305242','failed form preserves entered ID');
$r=student_id_case(['program_id'=>999]);
id_check($r['error']!=='' && !$r['db']->users,'invalid program rejected before account creation');
$r=student_id_case(['role'=>'company','email'=>'fixture@example.invalid','password'=>'Synthetic-test-password']);
id_check($r['error']==='' && $r['db']->committed && !$r['db']->students,'other roles unaffected by student ID fields');
echo json_encode(['student_id_checks_passed'=>$checks,'database_access'=>false,'result'=>'PASS']).PHP_EOL;
