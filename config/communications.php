<?php
// Call only with the actor's already-authorized thread rows.
function threads_with_profile_photos(array $threads, int $viewerId): array {
    if (!$threads) return [];
    $ids = array_map(fn($thread) => (int)$thread['id'], $threads);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $members = query("SELECT tm.thread_id,u.avatar FROM thread_members tm JOIN users u ON u.id=tm.user_id
                      WHERE tm.thread_id IN ($marks) AND tm.user_id<>?", [...$ids, $viewerId], str_repeat('i', count($ids) + 1));
    $others = [];
    foreach ($members as $member) $others[(int)$member['thread_id']][] = $member['avatar'];
    foreach ($threads as &$thread) {
        $people = $others[(int)$thread['id']] ?? [];
        $thread['avatar'] = $thread['thread_type'] === 'direct' && count($people) === 1 ? $people[0] : null;
    }
    unset($thread);
    return $threads;
}
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

// Archive is an inbox preference belonging to one conversation member.
function conversation_archive_view($value): bool {
    return is_string($value) && $value === 'archived';
}
function conversation_inbox_state(array $allThreads, int $selectedId, bool $archived): array {
    $selected = null;
    $counts = ['inbox'=>0, 'archived'=>0];
    foreach ($allThreads as $thread) {
        $counts[empty($thread['archived_at']) ? 'inbox' : 'archived']++;
        if ((int)$thread['id'] === $selectedId) $selected = $thread;
    }
    if ($selected) $archived = !empty($selected['archived_at']);
    return [
        'active_thread'=>$selected,
        'archive_view'=>$archived,
        'counts'=>$counts,
        'threads'=>array_values(array_filter($allThreads, fn($thread) => !empty($thread['archived_at']) === $archived)),
    ];
}
function set_conversation_archived(int $userId, int $threadId, bool $archived): bool {
    if ($threadId < 1 || $userId < 1 || !query_one('SELECT 1 FROM thread_members WHERE thread_id=? AND user_id=?', [$threadId, $userId], 'ii')) return false;
    $value = $archived ? 'CURRENT_TIMESTAMP' : 'NULL';
    query("UPDATE thread_members SET archived_at=$value WHERE thread_id=? AND user_id=?", [$threadId, $userId], 'ii');
    return true;
}
function reopen_conversation_for_new_message(int $threadId): void {
    query('UPDATE thread_members SET archived_at=NULL WHERE thread_id=? AND archived_at IS NOT NULL', [$threadId], 'i');
}
