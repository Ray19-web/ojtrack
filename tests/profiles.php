<?php
// Real profile read/write helpers on explicitly disposable synthetic release fixtures.
if (PHP_SAPI !== 'cli') exit;
if (!preg_match('/^ojtrack_release_verify_[a-z0-9_]+$/D',getenv('OJTRACK_DB_NAME') ?: '')) exit("Use a disposable ojtrack_release_verify_* database, seeded by release-monthly-report.php and create-admin.php.\n");
require __DIR__.'/../config/db.php';
require __DIR__.'/../config/profile.php';
$checks=0;
function profile_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
$actors=[];
foreach (['admin','coordinator','student','company'] as $role) {
    $actor=query_one('SELECT id,name,role FROM users WHERE role=? ORDER BY id LIMIT 1',[$role],'s');
    if (!$actor) throw new RuntimeException('Missing synthetic '.$role.' fixture.');
    $actors[$role]=$actor;
    $context=ojtrack_profile_context($actor);
    profile_check((int)$context['account']['id']===(int)$actor['id'],'Read only the authenticated '.$role.' account');
    profile_check(!array_key_exists('password',$context['account']),'Do not expose password hashes');
    profile_check(isset($context['groups']['Account record']['Account created']),'Display account creation date');
}
$context=ojtrack_profile_context($actors['student']);
profile_check($context['groups']['Student identity']['Student ID']==='RELEASE-STU','Display official student ID');
profile_check($context['groups']['Academic and OJT assignment']['Official credited hours']==='8.0 hours','Use official attendance hours');
profile_check($context['groups']['Academic and OJT assignment']['Required training hours']==='486.0 hours','Display enrolled training target');
profile_check(isset($context['groups']['Assigned company contact']) && isset($context['groups']['Assigned coordinator contact']),'Display assigned contacts');
$original=query_one('SELECT * FROM ojt_enrollments WHERE student_id=?',[(int)$context['student']['id']],'i');
ojtrack_update_own_profile($actors['student'],['name'=>'Release Student Updated','email'=>'','contact_number'=>'09123456789','role'=>'admin','status'=>'archived','student_id_no'=>'FORGED','program_id'=>999,'year_level'=>'FORGED','required_hours'=>1,'rendered_hours'=>999,'coordinator_id'=>999,'company_id'=>999]);
$student=query_one('SELECT * FROM students WHERE user_id=?',[(int)$actors['student']['id']],'i');
$enrollment=query_one('SELECT * FROM ojt_enrollments WHERE id=?',[(int)$original['id']],'i');
$account=query_one('SELECT * FROM users WHERE id=?',[(int)$actors['student']['id']],'i');
profile_check($student['student_id_no']==='RELEASE-STU' && $enrollment===$original,'Ignore forged student identity, academic assignments, and hours');
profile_check($account['role']==='student' && $account['status']==='active','Ignore forged account role/status');
profile_check($account['email']===null && $student['contact_number']==='09123456789','Student contact email can be optional');
$coordBefore=query_one('SELECT * FROM coordinators WHERE user_id=?',[(int)$actors['coordinator']['id']],'i');
ojtrack_update_own_profile($actors['coordinator'],['name'=>'Release Coordinator Updated','email'=>'profile-coordinator@example.invalid','contact_number'=>'09000000002','department'=>'FORGED','coordinator_id_no'=>'FORGED']);
$coord=query_one('SELECT * FROM coordinators WHERE user_id=?',[(int)$actors['coordinator']['id']],'i');
profile_check($coord['department']===$coordBefore['department'] && $coord['coordinator_id_no']===$coordBefore['coordinator_id_no'],'Coordinator ID and department stay protected');
profile_check($coord['contact_number']==='09000000002','Coordinator can update their contact number');
ojtrack_update_own_profile($actors['coordinator'],['name'=>'Release Coordinator Updated','email'=>'profile-coordinator@example.invalid','department'=>'FORGED']);
profile_check(query_one('SELECT contact_number FROM coordinators WHERE id=?',[(int)$coord['id']],'i')['contact_number']==='09000000002','Omitted phone field does not clear an existing contact');
ojtrack_update_own_profile($actors['admin'],['name'=>'Release Admin Updated','email'=>'profile-admin@example.invalid','role'=>'student','status'=>'inactive']);
profile_check(query_one('SELECT role FROM users WHERE id=?',[(int)$actors['admin']['id']],'i')['role']==='admin','Administrator self-edit does not alter access role');
ojtrack_update_own_profile($actors['company'],['company_name'=>'Updated Partner','email'=>'profile-company@example.invalid','supervisor_first_name'=>'Example','supervisor_middle_name'=>'','supervisor_last_name'=>'Supervisor','location'=>'Example site','contact_number'=>'09000000003','status'=>'inactive','user_id'=>999]);
$company=query_one('SELECT * FROM companies WHERE user_id=?',[(int)$actors['company']['id']],'i');
profile_check($company['company_name']==='UPDATED PARTNER' && $company['supervisor_first_name']==='EXAMPLE' && $company['supervisor_last_name']==='SUPERVISOR','Save company and structured supervisor details');
profile_check($company['status']==='active' && (int)$company['user_id']===(int)$actors['company']['id'],'Company partnership status/ownership stay protected');
foreach ([['email'=>'bad'],['email'=>['bad']],['email'=>'profile-admin@example.invalid'],['contact_number'=>str_repeat('1',21)]] as $bad) {
    try { ojtrack_update_own_profile($actors['coordinator'],array_replace(['name'=>'Release Coordinator Updated','email'=>'profile-coordinator@example.invalid','contact_number'=>'09000000002'],$bad)); profile_check(false,'Reject invalid details'); }
    catch (DomainException $expected) { profile_check(true,'Reject invalid or duplicate contact details'); }
}
$uid=(int)$actors['coordinator']['id'];
$before=query_one('SELECT name,email FROM users WHERE id=?',[$uid],'i');
query("CREATE TRIGGER profile_fixture_failure BEFORE UPDATE ON coordinators FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic profile failure'");
try {
    try { ojtrack_update_own_profile($actors['coordinator'],['name'=>'Must Roll Back','email'=>'rollback@example.invalid','contact_number'=>'123']); profile_check(false,'Injected persistence failure'); }
    catch (mysqli_sql_exception $expected) { profile_check(query_one('SELECT name,email FROM users WHERE id=?',[$uid],'i')===$before,'Role-record failure rolls back the account update'); }
} finally { query('DROP TRIGGER profile_fixture_failure'); }
$existing=query_one("SELECT id FROM users WHERE email='no-enrollment@example.invalid' AND role='student'");
$newUser=$existing ? (int)$existing['id'] : insert("INSERT INTO users(name,email,password,role,status) VALUES('No Enrollment','no-enrollment@example.invalid','synthetic','student','active')");
if (!$existing) insert("INSERT INTO students(user_id,student_id_no) VALUES(?,'NO-ENROLL')",[$newUser],'i');
$context=ojtrack_profile_context(['id'=>$newUser,'role'=>'student']);
profile_check($context['groups']['Academic and OJT assignment']['Current enrollment']==='No enrollment in active term','Missing enrollment is described accurately');
profile_check($context['groups']['Academic and OJT assignment']['Required training hours']==='Not assigned','Do not invent training targets for an unenrolled student');
echo "$checks profile read/write checks passed.\n";
