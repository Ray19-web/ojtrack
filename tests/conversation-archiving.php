<?php
// Only connection-scoped temporary tables are changed; application records are untouched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/communications.php';
$checks = 0;
function archive_check($ok, $message) {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
query('CREATE TEMPORARY TABLE thread_members (thread_id BIGINT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(thread_id,user_id))');
query('CREATE TEMPORARY TABLE messages (id INT PRIMARY KEY, thread_id BIGINT NOT NULL, message TEXT)');
query("INSERT INTO thread_members(thread_id,user_id) VALUES(1,101),(1,102),(2,101)");
query("INSERT INTO messages VALUES(1,1,'Synthetic message')");
query(file_get_contents(__DIR__ . '/../database/migrations/012_conversation_archiving.sql'));
archive_check((int)query_one('SELECT COUNT(*) c FROM thread_members WHERE archived_at IS NULL')['c'] === 3, 'Migration must leave all existing memberships in Inbox.');
archive_check(set_conversation_archived(101,1,true), 'Member cannot archive.');
archive_check(!empty(query_one('SELECT archived_at FROM thread_members WHERE thread_id=1 AND user_id=101')['archived_at']), 'Archive state was not persisted.');
archive_check(query_one('SELECT archived_at FROM thread_members WHERE thread_id=1 AND user_id=102')['archived_at'] === null, 'Archive changed another member inbox.');
archive_check(!set_conversation_archived(999,1,true), 'Non-member could archive.');
archive_check(!set_conversation_archived(999,1,false), 'Non-member could restore.');
archive_check(!set_conversation_archived(101,0,true), 'Invalid thread was accepted.');
archive_check(set_conversation_archived(101,1,true), 'Repeated archive must be safe.');
archive_check(set_conversation_archived(101,1,false), 'Member cannot restore.');
archive_check(query_one('SELECT archived_at FROM thread_members WHERE thread_id=1 AND user_id=101')['archived_at'] === null, 'Restore did not return thread to Inbox.');
set_conversation_archived(101,1,true); set_conversation_archived(102,1,true); set_conversation_archived(101,2,true);
reopen_conversation_for_new_message(1);
archive_check((int)query_one('SELECT COUNT(*) c FROM thread_members WHERE thread_id=1 AND archived_at IS NULL')['c'] === 2, 'New message must reopen thread for its members.');
archive_check(!empty(query_one('SELECT archived_at FROM thread_members WHERE thread_id=2')['archived_at']), 'New message reopened unrelated threads.');
archive_check((int)query_one('SELECT COUNT(*) c FROM messages')['c'] === 1, 'Archive or restore deleted a message.');
$threads = [['id'=>1,'archived_at'=>null], ['id'=>2,'archived_at'=>'2026-10-10 10:00:00']];
$state = conversation_inbox_state($threads,0,false);
archive_check(array_column($state['threads'],'id') === [1] && $state['counts'] === ['inbox'=>1,'archived'=>1], 'Inbox filtering/counts incorrect.');
$state = conversation_inbox_state($threads,0,true);
archive_check(array_column($state['threads'],'id') === [2], 'Archive filtering incorrect.');
$state = conversation_inbox_state($threads,2,false);
archive_check($state['archive_view'] && $state['active_thread']['id'] === 2, 'Direct archive link must open correct folder.');
archive_check(conversation_inbox_state($threads,999,false)['active_thread'] === null, 'Unknown thread was exposed.');
archive_check(conversation_inbox_state([],0,true)['counts'] === ['inbox'=>0,'archived'=>0], 'Empty folders broke counts.');
archive_check(conversation_archive_view('archived') && !conversation_archive_view(['archived']) && !conversation_archive_view('invalid'), 'Invalid folder input accepted.');
query('DROP TEMPORARY TABLE messages'); query('DROP TEMPORARY TABLE thread_members');
echo "$checks conversation archive checks passed; no application records changed.\n";
