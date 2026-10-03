<?php
require_once __DIR__ . '/config/security.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Sign out — OJTrack</title><form method="post">' . csrf_field() . '<p>Sign out of OJTrack?</p><button type="submit">Sign Out</button></form></html>';
    exit;
}
require_csrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time()-3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
}
session_destroy();
header('Location: /ojtrack/login.php');
exit;
