<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_login([], true);

$user = current_user();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send';

    if ($action === 'create_thread') {
        $name        = trim($_POST['name'] ?? '');
        $thread_type = in_array($_POST['thread_type'] ?? '', ['group', 'direct']) ? $_POST['thread_type'] : 'group';
        $desc        = trim($_POST['description'] ?? '');
        $members     = $_POST['members'] ?? [];

        if (is_string($members)) {
            $members = array_filter(array_map('intval', explode(',', $members)));
        } elseif (!is_array($members)) {
            $members = [];
        }


        $members = array_values(array_unique(array_map('intval', $members)));
        $members = array_values(array_filter($members, fn($id) => $id !== $uid));
        if (!$members || ($thread_type === 'direct' && count($members) !== 1) || ($user['role'] !== 'coordinator' && $thread_type !== 'direct')) request_error(422, 'Invalid conversation participants or type.', true);
        foreach ($members as $mid) if (!message_recipient_allowed($user, $mid)) request_error(403, 'A selected person is not an available contact.', true);
        if (empty($name)) {
            echo json_encode(['ok' => false, 'error' => 'Conversation title is required']);
            exit;
        }

        $thread_id = insert(
            "INSERT INTO message_threads (name, thread_type, description, created_by) VALUES (?, ?, ?, ?)",
            [$name, $thread_type, $desc, $uid],
            'sssi'
        );

        if (!$thread_id) {
            echo json_encode(['ok' => false, 'error' => 'Failed to create thread']);
            exit;
        }

        // Add creator
        insert("INSERT IGNORE INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $uid], 'ii');

        // Add participants
        foreach ($members as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                insert("INSERT IGNORE INTO thread_members (thread_id, user_id) VALUES (?, ?)", [$thread_id, $mid], 'ii');
            }
        }

        // If initial message provided
        $init_msg = trim($_POST['initial_message'] ?? '');
        if ($init_msg) {
            insert("INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)", [$thread_id, $uid, $init_msg], 'iis');
        }

        log_activity($uid, 'Thread Created', "Thread: $name (#$thread_id)");
        echo json_encode(['ok' => true, 'thread_id' => $thread_id]);
        exit;
    }

    // Default: send message
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $text      = trim($_POST['message'] ?? $_POST['body'] ?? '');

    if (!$thread_id || $text === '') {
        echo json_encode(['ok' => false, 'error' => 'Thread ID and message text are required']);
        exit;
    }

    // Verify membership
    $member = query_one("SELECT 1 FROM thread_members WHERE thread_id=? AND user_id=?", [$thread_id, $uid], 'ii');
    if (!$member) {
        echo json_encode(['ok' => false, 'error' => 'You are not a member of this conversation']);
        exit;
    }

    $msg_id = insert(
        "INSERT INTO messages (thread_id, sender_id, message) VALUES (?, ?, ?)",
        [$thread_id, $uid, $text],
        'iis'
    );

    if ($msg_id) {
        // Mark own message as read
        insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)", [$msg_id, $uid], 'ii');
        log_activity($uid, 'Message Sent', "Thread: $thread_id");

        echo json_encode([
            'ok'          => true,
            'id'          => $msg_id,
            'message'     => $text,
            'body'        => $text,
            'sender'      => $user['name'],
            'sender_id'   => $uid,
            'sent_at'     => date('M d · h:i A'),
            'time'        => 'Just now'
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Failed to save message']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $thread_id = (int)($_GET['thread_id'] ?? 0);
    $since     = (int)($_GET['since'] ?? 0);

    if (!$thread_id) {
        echo json_encode(['ok' => false, 'error' => 'Missing thread_id']);
        exit;
    }

    $member = query_one("SELECT 1 FROM thread_members WHERE thread_id=? AND user_id=?", [$thread_id, $uid], 'ii');
    if (!$member) {
        echo json_encode(['ok' => false, 'error' => 'Not a member']);
        exit;
    }

    $msgs = query(
        "SELECT m.id, m.message, m.message AS body, m.sent_at, m.sent_at AS created_at,
                u.name AS sender_name, m.sender_id, u.role AS sender_role
         FROM messages m
         JOIN users u ON u.id=m.sender_id
         WHERE m.thread_id=? AND m.id>?
         ORDER BY m.sent_at ASC",
        [$thread_id, $since],
        'ii'
    ) ?: [];

    // Mark as read
    foreach ($msgs as $m) {
        if ($m['sender_id'] != $uid) {
            insert("INSERT IGNORE INTO message_reads (message_id, user_id) VALUES (?, ?)", [$m['id'], $uid], 'ii');
        }
    }

    $formatted = array_map(function($m) use ($uid) {
        $m['mine'] = ($m['sender_id'] == $uid);
        $m['time'] = date('M d · h:i A', strtotime($m['sent_at']));
        return $m;
    }, $msgs);

    echo json_encode([
        'ok'       => true,
        'messages' => $formatted,
        'user_id'  => $uid
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
