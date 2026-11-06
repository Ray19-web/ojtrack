<?php
if (!defined('OJTRACK')) die('Direct access not allowed.');
$all_threads = query(
    "SELECT mt.*, tm.archived_at,
            (SELECT m.message FROM messages m WHERE m.thread_id=mt.id ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_message,
            (SELECT m.sent_at FROM messages m WHERE m.thread_id=mt.id ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_msg_time,
            (SELECT COUNT(*) FROM messages m WHERE m.thread_id=mt.id AND m.sender_id != ?
             AND NOT EXISTS(SELECT 1 FROM message_reads mr WHERE mr.message_id=m.id AND mr.user_id=?)) AS unread
     FROM message_threads mt
     JOIN thread_members tm ON tm.thread_id=mt.id AND tm.user_id=?
     ORDER BY COALESCE(last_msg_time, mt.created_at) DESC, mt.id DESC",
    [$uid, $uid, $uid], 'iii'
) ?: [];
$all_threads = threads_with_profile_photos($all_threads, $uid);
$active_id = filter_var($_GET['thread'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) ?: 0;
$state = conversation_inbox_state($all_threads, $active_id, conversation_archive_view($_GET['view'] ?? null));
$threads = $state['threads'];
$active_thread = $state['active_thread'];
$archive_view = $state['archive_view'];
$conversation_counts = $state['counts'];
$messages_list = [];
if ($active_thread) {
    $messages_list = query(
        "SELECT m.*, u.name AS sender_name, u.role AS sender_role, u.avatar AS sender_avatar
         FROM messages m JOIN users u ON u.id=m.sender_id
         WHERE m.thread_id=? ORDER BY m.sent_at ASC, m.id ASC", [$active_id], 'i'
    ) ?: [];
    foreach ($messages_list as $msg) {
        if ((int)$msg['sender_id'] !== $uid) {
            insert('INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)', [(int)$msg['id'], $uid], 'ii');
        }
    }
}
