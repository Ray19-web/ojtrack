<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_login([], true);

$user = current_user();
$uid = (int)$user['id'];
$afterId = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
$afterId = $afterId === false ? 0 : (int)$afterId;

$unread = (int)(query_one(
    "SELECT COUNT(DISTINCT m.id) AS c
     FROM messages m
     JOIN thread_members tm ON tm.thread_id=m.thread_id AND tm.user_id=?
     WHERE tm.archived_at IS NULL AND m.sender_id!=?
       AND NOT EXISTS (SELECT 1 FROM message_reads mr WHERE mr.message_id=m.id AND mr.user_id=?)",
    [$uid, $uid, $uid],
    'iii'
)['c'] ?? 0);

$messages = query(
    "SELECT m.id, m.thread_id, m.message, m.sent_at, mt.name AS thread_name, u.name AS sender_name
     FROM messages m
     JOIN thread_members tm ON tm.thread_id=m.thread_id AND tm.user_id=? AND tm.archived_at IS NULL
     JOIN message_threads mt ON mt.id=m.thread_id
     JOIN users u ON u.id=m.sender_id
     WHERE m.sender_id!=? AND m.id>?
     ORDER BY m.id ASC LIMIT 20",
    [$uid, $uid, $afterId],
    'iii'
) ?: [];

$formatted = array_map(static function(array $message): array {
    $message['id'] = (int)$message['id'];
    $message['thread_id'] = (int)$message['thread_id'];
    $message['sent_at'] = date('M j, g:i A', strtotime((string)$message['sent_at']));
    return $message;
}, $messages);

echo json_encode([
    'ok' => true,
    'unread' => $unread,
    'messages' => $formatted,
    'cursor' => $formatted ? (int)end($formatted)['id'] : $afterId,
]);
