<?php
// Real controller, in-memory transactions. Never creates real accounts.
if (PHP_SAPI!=='cli') exit;
class CompanyRegistrationDB {
    public array $users=[], $companies=[], $snapshot=[];
    public bool $committed=false, $rolledBack=false;
    function begin_transaction() { $this->snapshot=[$this->users,$this->companies]; }
    function commit() { $this->committed=true; }
    function rollback() { [$this->users,$this->companies]=$this->snapshot; $this->rolledBack=true; }
}
class CompanyRegistrationRedirect extends RuntimeException {}
function db() { return $GLOBALS['test_db']; }
function require_login($roles) {}
function current_user() { return ['id'=>99]; }
function redirect($path) { throw new CompanyRegistrationRedirect($path); }
function log_activity(...$args) {}
function query(...$args) { return []; }
function query_one($sql,$params=[],$types='') { return str_contains($sql,'FROM users WHERE email') && $GLOBALS['duplicate_email'] ? ['id'=>1] : null; }
function insert($sql,$params=[],$types='') {
    if (str_contains($sql,'INSERT INTO users')) { db()->users[]=$params; return 1; }
    if ($GLOBALS['profile_failure']) throw new mysqli_sql_exception('Synthetic failure',$GLOBALS['profile_failure']);
    db()->companies[]=$params; return 1;
}
$source=file_get_contents(__DIR__.'/../admin/companies.php');
$controller=substr($source,5,strpos($source,'?>')-5);
$controller=preg_replace("~require_once __DIR__ \. '/../(?:config/(?:db|auth)|includes/header)\.php';~",'',$controller);
$controller=str_replace("define('OJTRACK', true);",'',$controller);
function registration_case($overrides=[],$duplicate=false,$failure=0) {
    global $controller;
    $GLOBALS['test_db']=new CompanyRegistrationDB(); $GLOBALS['duplicate_email']=$duplicate; $GLOBALS['profile_failure']=$failure;
    $_SESSION=[]; $_GET=[]; $_SERVER['REQUEST_METHOD']='POST';
    $_POST=array_replace(['action'=>'add_company','company_name'=>'Synthetic Partner','location'=>'Sample Location','supervisor_name'=>'','contact_number'=>'','email'=>'partner@example.invalid','password'=>'Synthetic-Password-123'],$overrides);
    $destination=null; $add_values=[]; $add_company_failed=false;
    try { eval($controller); } catch (CompanyRegistrationRedirect $redirect) { $destination=$redirect->getMessage(); }
    return compact('error','add_values','add_company_failed','destination')+['db'=>db()];
}
$checks=0;
function company_check($ok,$label) { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
$r=registration_case();
company_check($r['destination']==='admin/companies.php' && http_response_code()===303 && $r['db']->committed,'successful registration returns to Companies');
company_check(count($r['db']->users)===1 && count($r['db']->companies)===1,'account and company created together');
company_check(password_verify('Synthetic-Password-123',$r['db']->users[0][2]),'initial password hashed correctly');
$_SERVER['REQUEST_METHOD']='GET'; $_POST=[]; eval($controller);
company_check($success!=='' && !isset($_SESSION['company_created']) && count(db()->users)===1,'refresh consumes success without repeating creation');
$r=registration_case(['password'=>'short123']);
company_check(str_contains($r['error'],'12') && $r['add_company_failed'] && !$r['db']->users,'short password reports exact requirement and reopens form');
company_check($r['add_values']['company_name']==='Synthetic Partner' && $r['add_values']['location']==='Sample Location' && $r['add_values']['email']==='partner@example.invalid','non-sensitive entered details preserved');
company_check(!isset($r['add_values']['password']),'password never reflected back into HTML');
foreach ([['password'=>''],['password'=>str_repeat('a',73)],['password'=>['malformed']],['company_name'=>''],['email'=>'invalid'],['email'=>['malformed']],['company_name'=>str_repeat('a',101)],['contact_number'=>str_repeat('1',21)]] as $bad) {
    $r=registration_case($bad); company_check($r['error']!=='' && !$r['db']->users && !$r['db']->companies,'invalid input creates nothing');
}
$r=registration_case([],true); company_check(str_contains($r['error'],'already registered') && !$r['db']->users,'duplicate email rejected');
foreach ([1062,9999] as $failure) {
    $r=registration_case([],false,$failure);
    company_check($r['db']->rolledBack && !$r['db']->users && !$r['db']->companies && $r['add_company_failed'],'profile failure rolls back account and reports error inside form');
}
$r=registration_case(['password'=>'123456789012']); company_check($r['db']->committed,'minimum-length password accepted');
$r=registration_case(['password'=>'  Synthetic-Password  ']); company_check(password_verify('  Synthetic-Password  ',$r['db']->users[0][2]),'password spaces preserved exactly');
company_check(str_contains($source,'minlength="12"') && str_contains($source,'id="companyPasswordHelp"'),'client validation and visible password guidance');
echo json_encode(['company_registration_checks_passed'=>$checks,'database_access'=>false,'result'=>'PASS']).PHP_EOL;
