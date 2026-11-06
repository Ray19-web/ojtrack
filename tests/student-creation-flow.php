<?php
// Exercise the real Students controller against memory only; no live accounts.
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/student-id-entry.php';
class StudentCreationRedirect extends RuntimeException {}
function redirect($path) { throw new StudentCreationRedirect($path); }
$source=file_get_contents(__DIR__.'/../admin/students.php');
$controller=substr($source,5,strpos($source,'?>')-5);
$controller=preg_replace("~require_once __DIR__ \. '/../(?:config/(?:db|auth)|includes/header)\.php';~", '', $controller);
$controller=str_replace("define('OJTRACK', true);", '', $controller);
function creation_case(array $overrides=[], string $existing='', bool $conflict=false): array {
    global $controller;
    $GLOBALS['test_db']=new StudentIdTestDatabase(); $GLOBALS['existing_id']=$existing; $GLOBALS['profile_conflict']=$conflict;
    $_SERVER['REQUEST_METHOD']='POST'; $_GET=[]; $_SESSION=[];
    $_POST=array_replace(['action'=>'add_student','first_name'=>'Synthetic','middle_name'=>'','last_name'=>'Student','email'=>'','student_id_mode'=>'manual','student_id_no'=>'00203305242','year_level'=>'4th Year','contact_number'=>'','program_id'=>1],$overrides);
    $destination=null;
    try { eval($controller); } catch (StudentCreationRedirect $redirect) { $destination=$redirect->getMessage(); }
    return ['error'=>$error,'destination'=>$destination,'db'=>db(),'values'=>$add_values ?? [],'failed'=>$add_student_failed ?? false,'flash'=>$_SESSION['student_created'] ?? ''];
}
$checks=0;
$r=creation_case();
id_check($r['destination']==='admin/students.php' && http_response_code()===303 && $r['flash']!=='','successful creation returns to Students with one-time confirmation');
id_check($r['db']->committed && $r['db']->students[0][4]==='00203305242','entered ID saved exactly with leading zeroes');
id_check($r['db']->users[0][3]===null && password_verify('00203305242',$r['db']->users[0][4]),'email optional and ID is initial password');
id_check($r['db']->users[0][0]==='STUDENT, SYNTHETIC' && $r['db']->students[0][1]==='SYNTHETIC' && $r['db']->students[0][3]==='STUDENT','name parts are saved in uppercase and composed for existing readers');
id_check($r['db']->students[0][5]===1 && $r['db']->students[0][6]==='BSIT' && $r['db']->students[0][7]==='4th Year' && $r['db']->students[0][8]===null,'profile retains the selected program and year without inventing a company placement');
$_SERVER['REQUEST_METHOD']='GET'; $_POST=[];
eval($controller);
id_check($success!=='' && !isset($_SESSION['student_created']) && count(db()->students)===1,'refresh consumes confirmation without creating another student');
$r=creation_case(['student_id_mode'=>'auto','student_id_no'=>'forged']);
id_check($r['db']->students[0][4]==='forged','legacy assignment mode does not replace the manually entered ID');
foreach ([['first_name'=>''],['email'=>'invalid'],['student_id_no'=>''],['student_id_no'=>'bad id'],['student_id_no'=>['array']],['year_level'=>'']] as $bad) {
    $r=creation_case($bad);
    id_check($r['destination']===null && $r['error']!=='' && !$r['db']->users && $r['failed'],'invalid fields keep form on Students and create nothing');
}
$r=creation_case([], '00203305242');
id_check($r['error']!=='' && !$r['db']->users && $r['values']['first_name']==='Synthetic','duplicate ID retains entered details without creating account');
$r=creation_case([], '', true);
id_check($r['db']->rolledBack && !$r['db']->users && !$r['db']->students && $r['destination']===null,'profile failure rolls back both records and stays on Students');
id_check(str_contains($source,'onclick="openModal(\'addStudentModal\')"') && !str_contains($source,'users.php?role=student'),'Add Student opens local modal rather than User Accounts');
echo json_encode(['student_creation_checks_passed'=>$checks,'database_access'=>false,'result'=>'PASS']).PHP_EOL;
