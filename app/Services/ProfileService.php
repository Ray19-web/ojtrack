<?php
/** Current-account profile data only. Never accept a viewed user ID from the form. */
function ojtrack_profile_context(array $actor): array
{
    $uid=(int)$actor['id']; $role=(string)$actor['role'];
    $account=query_one('SELECT id,name,email,avatar,role,status,created_at FROM users WHERE id=? AND role=?',[$uid,$role],'is');
    if (!$account) throw new DomainException('Your account profile is unavailable.');
    $context=['account'=>$account,'student'=>[],'coordinator'=>[],'company'=>[],'groups'=>[]];
    $roles=['admin'=>'Administrator','coordinator'=>'OJT Coordinator','student'=>'Student','company'=>'Company'];
    $date=static fn($value) => $value && strtotime($value)!==false ? date('M d, Y',strtotime($value)) : 'Not recorded';
    $label=static fn($value,$fallback='Not assigned') => $value!==null && trim((string)$value)!=='' ? (string)$value : $fallback;
    $status=static fn($value) => ucwords(str_replace('_',' ',(string)$value));
    $context['groups']['Account record']=[
        'Account ID'=>'#'.$uid,'Account role'=>$roles[$role] ?? ucfirst($role),
        'Account status'=>$status($account['status']),'Account created'=>$date($account['created_at']),
    ];
    if ($role==='student') {
        $student=normalized_student_context_by_user($uid) ?? [];
        $context['student']=$student;
        $context['groups']['Student identity']=[
            'Student ID'=>$label($student['student_id_no'] ?? null,'Not recorded'),
            'Sign-in method'=>'Student ID','Archive status'=>!empty($student['is_archived']) ? 'Archived' : 'Not archived',
        ];
        $term=normalized_active_term();
        $enrolled=!empty($student['_enrollment_id']);
        $context['groups']['Academic and OJT assignment']=[
            'Active academic term'=>$term ? $term['academic_year_start'].'–'.$term['academic_year_end'].' · '.$term['semester'].' Semester' : 'No active academic term',
            'Current enrollment'=>$enrolled ? 'Enrolled in active term' : 'No enrollment in active term',
            'Program'=>$label($student['program_name'] ?? $student['program'] ?? null),
            'Program code'=>$label($student['program_code'] ?? $student['program'] ?? null),
            'Department / college'=>$label($student['department'] ?? null),
            'Year level'=>$label($student['year_level'] ?? null),
            'Training status'=>$enrolled ? $status($student['ojt_status']) : 'Not assigned',
            'Assigned coordinator'=>$label($student['coordinator_name'] ?? null),
            'Training company'=>$label($student['company_name'] ?? null),
            'Training starts'=>$date($student['ojt_start_date'] ?? null),
            'Training ends'=>$date($student['ojt_end_date'] ?? null),
            'Required training hours'=>$enrolled ? number_format((float)$student['required_hours'],1).' hours' : 'Not assigned',
            'Official credited hours'=>$enrolled ? number_format((float)$student['rendered_hours'],1).' hours' : 'No current enrollment',
            'Remaining hours'=>$enrolled ? number_format(max(0,(float)$student['required_hours']-(float)$student['rendered_hours']),1).' hours' : 'Not assigned',
            'Onboarding completed'=>$date($student['onboarding_completed_at'] ?? null),
        ];
        if (!empty($student['company_id'])) {
            $company=query_one('SELECT supervisor_name,contact_number,location FROM companies WHERE id=?',[(int)$student['company_id']],'i') ?? [];
            $context['groups']['Assigned company contact']=[
                'Supervisor / contact person'=>$label($company['supervisor_name'] ?? null,'Not recorded'),
                'Company contact number'=>$label($company['contact_number'] ?? null,'Not recorded'),
                'Office / training location'=>$label($company['location'] ?? null,'Not recorded'),
            ];
        }
        if (!empty($student['coordinator_id'])) {
            $coordinator=query_one('SELECT c.contact_number,u.email FROM coordinators c JOIN users u ON u.id=c.user_id WHERE c.id=?',[(int)$student['coordinator_id']],'i') ?? [];
            $context['groups']['Assigned coordinator contact']=[
                'Coordinator email'=>$label($coordinator['email'] ?? null,'Not recorded'),
                'Coordinator contact number'=>$label($coordinator['contact_number'] ?? null,'Not recorded'),
            ];
        }
    } elseif ($role==='coordinator') {
        $coordinator=query_one('SELECT id,coordinator_id_no,department,contact_number FROM coordinators WHERE user_id=?',[$uid],'i') ?? [];
        $context['coordinator']=$coordinator;
        $context['groups']['Coordinator assignment']=[
            'Coordinator ID'=>$label($coordinator['coordinator_id_no'] ?? null,'Not recorded'),
            'Assigned department / program'=>$label($coordinator['department'] ?? null),
            'Sign-in method'=>'Email address',
        ];
    } elseif ($role==='company') {
        $company=query_one('SELECT id,company_name,supervisor_name,supervisor_first_name,supervisor_middle_name,supervisor_last_name,location,contact_number,status FROM companies WHERE user_id=?',[$uid],'i') ?? [];
        $context['company']=$company;
        $context['groups']['Company record']=[
            'Company record ID'=>!empty($company['id']) ? '#'.$company['id'] : 'Not recorded',
            'Partnership status'=>!empty($company['status']) ? $status($company['status']) : 'Not recorded',
            'Current supervisor / contact'=>$label($company['supervisor_name'] ?? null,'Not recorded'),
            'Sign-in method'=>'Email address',
        ];
    } else {
        $context['groups']['Administrator access']=['Access scope'=>'System administration','Sign-in method'=>'Email address'];
    }
    return $context;
}

/** Explicitly editable fields; IDs, roles, status, academic assignments and hours are ignored. */
function ojtrack_update_own_profile(array $actor, array $input): array
{
    $uid=(int)$actor['id']; $role=(string)$actor['role'];
    if (!in_array($role,['admin','coordinator','student','company'],true)) throw new DomainException('This account cannot update a profile.');
    $field=static function($key) use($input): string {
        if (!is_string($input[$key] ?? '')) throw new DomainException('Enter valid profile details.');
        return trim($input[$key] ?? '');
    };
    $email=$field('email'); $contact=$field('contact_number');
    if (($role!=='student' && $email==='') || ($email!=='' && (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>150))) throw new DomainException('Enter a valid email address'.($role==='student' ? ' or leave it blank.' : '.'));
    if (mb_strlen($contact)>20) throw new DomainException('Contact number must be 20 characters or fewer.');
    $extensions=normalize_name_extensions($input['name_extension_type'] ?? [],$input['name_extension_value'] ?? []);
    $company=null; $supervisorParts=[]; $hasParts=false; $supervisorExtensions=[];
    if ($role==='company') {
        $company=query_one('SELECT id,supervisor_name FROM companies WHERE user_id=?',[$uid],'i');
        if (!$company) throw new DomainException('Your company profile is missing. Contact your administrator.');
        $companyName=normalize_person_name($field('company_name')); $location=$field('location');
        if ($companyName==='' || mb_strlen($companyName)>100 || mb_strlen($location)>200) throw new DomainException('Enter a company name of up to 100 characters and a location of up to 200 characters.');
        foreach (['first','middle','last'] as $part) {
            $supervisorParts[$part]=normalize_person_name_part($field('supervisor_'.$part.'_name'));
            if (mb_strlen($supervisorParts[$part])>60) throw new DomainException('Supervisor name parts must be 60 characters or fewer.');
        }
        $hasParts=implode('',$supervisorParts)!=='';
        if ($hasParts && (!$supervisorParts['first'] || !$supervisorParts['last'])) throw new DomainException('Enter both the supervisor first and last names.');
        $supervisorExtensions=normalize_name_extensions($input['supervisor_name_extension_type'] ?? [],$input['supervisor_name_extension_value'] ?? []);
        if ($supervisorExtensions['error']) throw new DomainException($supervisorExtensions['error']);
        $base=$hasParts ? person_name_from_parts(...array_values($supervisorParts)) : base_person_name($company['supervisor_name'] ?? '',company_supervisor_name_extensions((int)$company['id']));
        if ($base==='' && $supervisorExtensions['extensions']) throw new DomainException('Enter a supervisor name before adding extensions.');
        $supervisor=format_person_name($base,$supervisorExtensions['extensions']);
        if (mb_strlen($supervisor)>100) throw new DomainException('Supervisor name must be 100 characters or fewer.');
        $name=$supervisor ?: $companyName;
    } else {
        if ($extensions['error']) throw new DomainException($extensions['error']);
        $base=normalize_person_name($field('name')); $name=format_person_name($base,$extensions['extensions']);
        if ($base==='' || mb_strlen($name)>100) throw new DomainException('Enter a name of up to 100 characters.');
    }
    if ($email!=='' && query_one('SELECT id FROM users WHERE email=? AND id!=?',[$email,$uid],'si')) throw new DomainException('Email is already used by another account.');
    db()->begin_transaction();
    try {
        query('UPDATE users SET name=?,email=? WHERE id=?',[$name,$email ?: null,$uid],'ssi');
        if ($role==='company') {
            if ($hasParts) query('UPDATE companies SET company_name=?,supervisor_name=?,supervisor_first_name=?,supervisor_middle_name=?,supervisor_last_name=?,location=?,contact_number=? WHERE id=?',[$companyName,$supervisor,$supervisorParts['first'],$supervisorParts['middle'] ?: null,$supervisorParts['last'],$location ?: null,$contact ?: null,(int)$company['id']],'sssssssi');
            else query('UPDATE companies SET company_name=?,supervisor_name=?,location=?,contact_number=? WHERE id=?',[$companyName,$supervisor,$location ?: null,$contact ?: null,(int)$company['id']],'ssssi');
            save_company_supervisor_name_extensions((int)$company['id'],$supervisorExtensions['extensions']);
        } else {
            save_user_name_extensions($uid,$extensions['extensions']);
            if ($role==='student' && array_key_exists('contact_number',$input)) query('UPDATE students SET contact_number=? WHERE user_id=?',[$contact ?: null,$uid],'si');
            if ($role==='coordinator' && array_key_exists('contact_number',$input)) query('UPDATE coordinators SET contact_number=? WHERE user_id=?',[$contact ?: null,$uid],'si');
        }
        db()->commit();
    } catch (Throwable $error) { db()->rollback(); throw $error; }
    return ['name'=>$name,'company_name'=>$companyName ?? null];
}
