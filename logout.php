<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /ojtrack/index.php');
    exit;
}
require_csrf();
revoke_current_remember_token();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time()-3600, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
}
session_destroy();
header('Location: /ojtrack/login.php');
exit;
