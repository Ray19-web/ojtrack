<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/communications.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/storage.php';

function is_logged_in() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['auth_fingerprint'])) return false;
    $account = query_one("SELECT id, name, role, status, password, avatar FROM users WHERE id=?", [(int)$_SESSION['user_id']], 'i');
    $expired = time() - (int)($_SESSION['last_activity'] ?? 0) > 1800;
    if (!$account || $account['status'] !== 'active' || $account['role'] !== ($_SESSION['role'] ?? '') || $expired ||
        !hash_equals($_SESSION['auth_fingerprint'], hash('sha256', $account['password']))) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    $_SESSION['last_activity'] = time();
    $_SESSION['name'] = $account['name'];
    $_SESSION['avatar'] = $account['avatar'] ?? '';
    return true;
}

function require_login($allowed_roles = [], $json = false) {
    if (!is_logged_in()) {
        if ($json) request_error(401, 'Please sign in again.', true);
        header('Location: ' . base_url('login.php'));
        exit;
    }
    if ($allowed_roles && !in_array($_SESSION['role'], $allowed_roles, true)) {
        request_error(403, 'You do not have access to this page.', $json);
    }
    require_csrf($json);
    if ($_SESSION['role'] === 'student') {
        $profile = query_one("SELECT id FROM students WHERE user_id=?", [(int)$_SESSION['user_id']], 'i');
        if (!$profile) request_error(403, 'Your student profile is missing. Contact your administrator.', $json);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!in_array($path, ['/ojtrack/student/onboarding.php', '/ojtrack/download.php'], true) && student_onboarding_required($_SESSION['user_id'])) {
            if ($json || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                request_error(403, 'Complete your OJT onboarding before using this action.', $json);
            }
            redirect('student/onboarding.php');
        }
    } elseif (in_array($_SESSION['role'], ['company', 'coordinator'], true)) {
        $table = $_SESSION['role'] === 'company' ? 'companies' : 'coordinators';
        if (!query_one("SELECT id FROM $table WHERE user_id=?", [(int)$_SESSION['user_id']], 'i')) {
            request_error(403, 'Your role profile is missing. Contact your administrator.', $json);
        }
    }
    validate_request_uploads($json);
}

function current_user() {
    return [
        'id'   => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'sub'  => $_SESSION['sub'] ?? '',
    ];
}

function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $clean    = ltrim($path, '/');
    return "$protocol://$host/ojtrack/$clean";
}

function redirect($path) {
    header('Location: ' . base_url($path));
    exit;
}

/**
 * New student accounts are held in an activation flow until every
 * coordinator-assigned OJT requirement has been approved.
 */
function student_onboarding_required($user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;
    $student = normalized_student_context_by_user($user_id);
    if (!$student) return false;
    return empty($student['onboarding_completed_at']);
}

function student_onboarding_complete($student_id) {
    return normalized_set_onboarding_complete_if_ready((int)$student_id);
}

function role_home() {
    $map = [
        'student'     => 'student/dashboard.php',
        'coordinator' => 'coordinator/dashboard.php',
        'company'     => 'company/dashboard.php',
        'admin'       => 'admin/dashboard.php',
    ];
    return $map[$_SESSION['role'] ?? ''] ?? 'login.php';
}
