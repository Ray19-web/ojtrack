<?php
function message_recipient_allowed($actor, $recipient_id) {
    $recipient = query_one("SELECT id,role FROM users WHERE id=? AND status='active'", [$recipient_id], 'i');
    if (!$recipient || (int)$recipient_id === (int)$actor['id']) return false;
    if ($actor['role'] === 'student') {
        return (bool)query_one("SELECT s.id FROM students s LEFT JOIN coordinators c ON c.id=s.coordinator_id LEFT JOIN companies co ON co.id=s.company_id WHERE s.user_id=? AND (c.user_id=? OR co.user_id=?)", [$actor['id'],$recipient_id,$recipient_id], 'iii');
    }
    if ($actor['role'] === 'coordinator') {
        return (bool)query_one("SELECT s.id FROM students s JOIN coordinators c ON c.id=s.coordinator_id LEFT JOIN companies co ON co.id=s.company_id WHERE c.user_id=? AND (s.user_id=? OR co.user_id=?)", [$actor['id'],$recipient_id,$recipient_id], 'iii');
    }
    if ($actor['role'] === 'company') {
        return (bool)query_one("SELECT s.id FROM students s JOIN companies co ON co.id=s.company_id LEFT JOIN coordinators c ON c.id=s.coordinator_id WHERE co.user_id=? AND (s.user_id=? OR c.user_id=?)", [$actor['id'],$recipient_id,$recipient_id], 'iii');
    }
    return false;
}
function announcement_scope($user) {
    $uid = (int)$user['id'];
    $active = "a.is_active=1 AND (a.expires_at IS NULL OR a.expires_at>=CURDATE())";
    $admin = "EXISTS (SELECT 1 FROM users au WHERE au.id=a.created_by AND au.role='admin')";
    if ($user['role'] === 'student') {
        return "$active AND a.target_role IN ('all','student') AND ($admin OR EXISTS (SELECT 1 FROM students st LEFT JOIN coordinators c ON c.id=st.coordinator_id LEFT JOIN companies co ON co.id=st.company_id WHERE st.user_id=$uid AND (c.user_id=a.created_by OR co.user_id=a.created_by)))";
    }
    if ($user['role'] === 'coordinator') {
        return "$active AND a.target_role IN ('all','coordinator') AND ($admin OR EXISTS (SELECT 1 FROM companies co JOIN students st ON st.company_id=co.id JOIN coordinators c ON c.id=st.coordinator_id WHERE c.user_id=$uid AND co.user_id=a.created_by))";
    }
    return "$active AND a.target_role IN ('all','company') AND $admin";
}
