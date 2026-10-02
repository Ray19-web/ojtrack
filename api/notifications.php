<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = current_user();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $notifications = query(
        "SELECT id, message, notif_type, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20",
        [$uid],
        'i'
    ) ?: [];

    $unread = (int)(query_one(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0",
        [$uid],
        'i'
    )['c'] ?? 0);

    $formatted = array_map(function($n) {
        $n['time_ago'] = time_ago($n['created_at']);
        $n['date_str'] = format_date($n['created_at']);
        return $n;
    }, $notifications);

    echo json_encode([
        'ok'            => true,
        'notifications' => $formatted,
        'unread'        => $unread
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? $_GET['action'] ?? '';
    $notif_id = (int)($_POST['id'] ?? 0);

    if ($action === 'mark_read') {
        if ($notif_id > 0) {
            query("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?", [$notif_id, $uid], 'ii');
        } else {
            query("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid], 'i');
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'clear') {
        query("DELETE FROM notifications WHERE user_id=?", [$uid], 'i');
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
