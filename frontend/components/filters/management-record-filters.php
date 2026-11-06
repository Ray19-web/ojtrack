<?php
function record_filter_text(array $input, string $key, int $length=150): string {
    return is_string($input[$key] ?? null) ? mb_substr(trim($input[$key]),0,$length) : '';
}
function record_filter_date(array $input, string $key): string {
    $value=record_filter_text($input,$key);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) return '';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date && $date->format('Y-m-d')===$value ? $value : '';
}
function record_filter_like(string $search): string {
    return '%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
}
function archived_account_filters(array $input): array {
    $search=record_filter_text($input,'q');
    $role=record_filter_text($input,'role');
    if (!in_array($role,['student','coordinator','company','admin'],true)) $role='';
    $created=record_filter_date($input,'created');
    $where="status='archived'"; $params=[]; $types='';
    if ($search!=='') {
        $where.=" AND (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')";
        $params=[record_filter_like($search),record_filter_like($search)]; $types='ss';
    }
    if ($role!=='') { $where.=' AND role=?'; $params[]=$role; $types.='s'; }
    if ($created!=='') {
        $where.=' AND created_at>=? AND created_at<DATE_ADD(?, INTERVAL 1 DAY)';
        array_push($params,$created,$created); $types.='ss';
    }
    return compact('search','role','created','where','params','types');
}
function issued_certificate_filters(array $input): array {
    $search=record_filter_text($input,'q');
    $from=record_filter_date($input,'from'); $to=record_filter_date($input,'to');
    $error=$from!=='' && $to!=='' && $from>$to ? 'The start date must be on or before the end date.' : '';
    $where=$error!=='' ? '1=0' : '1=1'; $params=[]; $types='';
    if ($search!=='') {
        $where.=" AND (u.name LIKE ? ESCAPE '!' OR s.student_id_no LIKE ? ESCAPE '!' OR c.certificate_no LIKE ? ESCAPE '!')";
        $params=array_fill(0,3,record_filter_like($search)); $types='sss';
    }
    if ($from!=='') { $where.=' AND c.issued_at>=?'; $params[]=$from; $types.='s'; }
    if ($to!=='') { $where.=' AND c.issued_at<DATE_ADD(?, INTERVAL 1 DAY)'; $params[]=$to; $types.='s'; }
    return compact('search','from','to','error','where','params','types');
}
function announcement_management_filters(array $input): array {
    $search=record_filter_text($input,'q'); $tag=record_filter_text($input,'tag',50);
    $audience=record_filter_text($input,'audience');
    if (!in_array($audience,['all','student','coordinator','company','admin'],true)) $audience='';
    $where='1=1'; $params=[]; $types='';
    if ($search!=='') {
        $where.=" AND (ap.title LIKE ? ESCAPE '!' OR ap.body LIKE ? ESCAPE '!' OR ap.tag LIKE ? ESCAPE '!' OR u.name LIKE ? ESCAPE '!')";
        $params=array_fill(0,4,record_filter_like($search)); $types='ssss';
    }
    if ($tag!=='') { $where.=" AND COALESCE(NULLIF(ap.tag,''),'General')=?"; $params[]=$tag; $types.='s'; }
    if ($audience!=='') { $where.=' AND ap.target_role=?'; $params[]=$audience; $types.='s'; }
    return compact('search','tag','audience','where','params','types');
}
