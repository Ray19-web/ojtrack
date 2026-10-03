<?php
// Shared by login, authenticated pages and API endpoints.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/ojtrack/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function request_error($status, $message, $json = false) {
    http_response_code($status);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="/ojtrack/">Return to OJTrack</a></p>';
    }
    exit;
}
function require_csrf($json = false) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $limit = trim(ini_get('post_max_size'));
    $bytes = (float)$limit;
    $unit = strtolower(substr($limit, -1));
    $bytes *= match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    if ($bytes > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $bytes) {
        request_error(413, 'The upload request is too large. Choose fewer or smaller files.', $json);
    }
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        request_error(403, 'Your form has expired. Reload the page and try again.', $json);
    }
}
function safe_app_redirect($candidate, $fallback) {
    if (!is_string($candidate) || preg_match('/[\x00-\x20\x7f\\\\]/', $candidate)) return $fallback;
    $decoded = rawurldecode($candidate);
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) return $fallback;
    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) return $fallback;
    $path = rawurldecode($parts['path'] ?? '');
    if (!str_starts_with($path, '/ojtrack/') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)) return $fallback;
    return $candidate;
}
