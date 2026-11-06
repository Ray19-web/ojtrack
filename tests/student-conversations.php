<?php
// In-memory messaging checks: no real accounts, messages, or notifications are written.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../config/student-conversations.php';
class StudentConversationTestDb {
    public array $writes=[], $notifications=[], $snapshot=[];
    public bool $committed=false, $rolledBack=false;
    function begin_transaction() { $this->snapshot=[$this->writes,$this->notifications]; }
    function commit() { $this->committed=true; }
    function rollback() { [$this->writes,$this->notifications]=$this->snapshot; $this->rolledBack=true; }
}
function db() { return $GLOBALS['test_db']; }
function message_recipient_allowed($actor,$id) { return !in_array($id,$GLOBALS['unavailable'],true); }
function query_one($sql,$params=[],$types='') {
    if (str_contains($sql,'FROM message_threads')) return $GLOBALS['existing'] ? ['id'=>55] : null;
    return ['id'=>$params[0],'name'=>'Synthetic Contact','role'=>$params[0]===2 ? 'coordinator' : 'company'];
}
function insert($sql,$params=[],$types='') {
    if ($GLOBALS['fail_write'] && str_contains($sql,'INSERT INTO messages ')) throw new RuntimeException('Injected failure');
    db()->writes[]=[$sql,$params];
    return str_contains($sql,'INSERT INTO message_threads ') ? 11 : 22;
}
function reopen_conversation_for_new_message($id) {}
function create_notification(...$args) { db()->notifications[]=$args; }
function reset_case($existing=false,$failure=false) { $GLOBALS['test_db']=new StudentConversationTestDb; $GLOBALS['existing']=$existing; $GLOBALS['fail_write']=$failure; $GLOBALS['unavailable']=[]; }
$actor=['id'=>1,'name'=>'Synthetic Student','role'=>'student'];
$student=['coord_user_id'=>2,'company_user_id'=>3];
$checks=0;
function check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
reset_case();
check(student_start_conversation($actor,$student,['2'],' Hello ')==11,'Direct thread created');
check(db()->writes[0][1][1]==='direct' && db()->committed,'Direct type and transaction commit');
check(count(db()->notifications)===1,'Notify direct recipient only');
reset_case(true);
check(student_start_conversation($actor,$student,['2'],'Hello')===55,'Reuse existing direct thread');
check(!str_contains(db()->writes[0][0],'message_threads'),'Reused thread is not recreated');
reset_case();
student_start_conversation($actor,$student,['2','3','2'],'Both contacts');
check(db()->writes[0][1][1]==='group','Both contacts create a group');
check(count(array_filter(db()->writes,fn($row)=>str_contains($row[0],'INTO thread_members')))===3,'Actor and distinct assigned contacts only');
check(count(db()->notifications)===2,'Notify both selected contacts');
foreach ([[[], 'hello'], [['999'],'hello'], [[['2']],'hello'], [['2'],'  '], ['2','hello']] as [$members,$message]) {
    reset_case();
    try { student_start_conversation($actor,$student,$members,$message); check(false,'Invalid request rejected'); }
    catch (DomainException $error) { check(db()->writes===[] && db()->notifications===[],'Invalid request makes no writes'); }
}
reset_case(); $GLOBALS['unavailable']=[3];
try { student_start_conversation($actor,$student,['2','3'],'hello'); check(false,'Unavailable participant rejected'); }
catch (DomainException $error) { check(db()->writes===[],'Validate every participant before any writes'); }
reset_case(false,true);
try { student_start_conversation($actor,$student,['2','3'],'hello'); check(false,'Injected failure propagates'); }
catch (RuntimeException $error) { check(db()->rolledBack && db()->writes===[] && db()->notifications===[],'Failure rolls back the whole conversation'); }
echo "$checks student conversation checks passed.\n";
