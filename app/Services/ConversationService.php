<?php
/** Start a direct or group discussion with the student's assigned contacts only. */
function student_start_conversation(array $actor, array $student, $rawMembers, $rawMessage): int
{
    if (($actor['role'] ?? '') !== 'student') throw new DomainException('Only students can use this conversation form.');
    if (!is_array($rawMembers) || !is_string($rawMessage)) throw new DomainException('Choose valid participants and enter a message.');
    $members = [];
    foreach ($rawMembers as $member) {
        if ((!is_string($member) && !is_int($member)) || !ctype_digit((string)$member) || (int)$member < 1) throw new DomainException('Choose valid participants.');
        $members[(int)$member] = (int)$member;
    }
    $members = array_values($members);
    $message = trim($rawMessage);
    if (!$members || $message === '') throw new DomainException('Select at least one participant and enter a message.');
    $allowed = array_filter([(int)($student['coord_user_id'] ?? 0), (int)($student['company_user_id'] ?? 0)]);
    $recipients = [];
    foreach ($members as $member) {
        if (!in_array($member, $allowed, true) || !message_recipient_allowed($actor, $member)) throw new DomainException('A selected person is no longer an available contact. Refresh and try again.');
        $recipient = query_one("SELECT id,name,role FROM users WHERE id=? AND status='active'", [$member], 'i');
        if (!$recipient) throw new DomainException('A selected participant is no longer available.');
        $recipients[] = $recipient;
    }
    $uid = (int)$actor['id'];
    db()->begin_transaction();
    try {
        $existing = count($members) === 1 ? query_one(
            "SELECT mt.id FROM message_threads mt
             JOIN thread_members tm1 ON tm1.thread_id=mt.id AND tm1.user_id=?
             JOIN thread_members tm2 ON tm2.thread_id=mt.id AND tm2.user_id=?
             WHERE mt.thread_type='direct' LIMIT 1", [$uid,$members[0]], 'ii'
        ) : null;
        if ($existing) $threadId = (int)$existing['id'];
        else {
            $direct = count($members) === 1;
            $name = mb_substr($direct ? $recipients[0]['name'].' & '.$actor['name'] : $actor['name'].' - Training Discussion', 0, 150);
            $threadId = (int)insert(
                "INSERT INTO message_threads (name,thread_type,description,created_by) VALUES (?,?,?,?)",
                [$name,$direct ? 'direct' : 'group',$direct ? 'Direct Conversation' : 'Student training discussion',$uid], 'sssi'
            );
            foreach (array_merge([$uid],$members) as $member) insert('INSERT INTO thread_members (thread_id,user_id) VALUES (?,?)', [$threadId,$member], 'ii');
        }
        $messageId = (int)insert('INSERT INTO messages (thread_id,sender_id,message) VALUES (?,?,?)', [$threadId,$uid,$message], 'iis');
        reopen_conversation_for_new_message($threadId);
        insert('INSERT IGNORE INTO message_reads (message_id,user_id) VALUES (?,?)', [$messageId,$uid], 'ii');
        foreach ($recipients as $recipient) create_notification((int)$recipient['id'], 'New message from '.$actor['name'], 'info', '/ojtrack/'.$recipient['role'].'/messages.php?thread='.$threadId);
        db()->commit();
        return $threadId;
    } catch (Throwable $exception) {
        db()->rollback();
        throw $exception;
    }
}
