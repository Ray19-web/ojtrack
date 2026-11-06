<?php
require_once __DIR__ . '/../Support/avatars.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../Services/CommunicationService.php';
require_once __DIR__ . '/../Services/UploadService.php';
require_once __DIR__ . '/../Services/StorageService.php';

function is_logged_in() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['auth_fingerprint'])) return false;
    $account = query_one("SELECT id, name, email, role, status, password, avatar FROM users WHERE id=?", [(int)$_SESSION['user_id']], 'i');
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
    $GLOBALS['ojtrack_authenticated_account'] = $account;
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
        $student_state = student_onboarding_state((int)$_SESSION['user_id']);
        if (!$student_state) request_error(403, 'Your student profile is missing. Contact your administrator.', $json);
        // .htaccess exposes extensionless URLs while internally loading the
        // matching PHP file. Keep both forms on the onboarding allowlist or an
        // incomplete student bounces between /onboarding and /onboarding.php.
        $path = rtrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/');
        $student_setup_paths = [
            '/ojtrack/student/onboarding',
            '/ojtrack/student/onboarding.php',
            '/ojtrack/download',
            '/ojtrack/download.php',
        ];
        if (!in_array($path, $student_setup_paths, true) && empty($student_state['onboarding_completed_at'])) {
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
function student_onboarding_state($user_id): ?array {
    static $cache = [];
    $user_id = (int)$user_id;
    if ($user_id <= 0) return null;
    if (array_key_exists($user_id, $cache)) return $cache[$user_id];

    // Onboarding belongs to the active term enrollment. Older terms must not
    // make a student appear onboarded for a newly opened term.
    $onboardingField = 'oe.onboarding_completed_at';
    $cache[$user_id] = query_one(
        "SELECT s.id AS student_id,
                $onboardingField AS onboarding_completed_at,
                oe.id AS enrollment_id,
                (SELECT COUNT(*) FROM requirement_assignments ra WHERE ra.ojt_enrollment_id=oe.id) AS requirement_count
         FROM students s
         LEFT JOIN ojt_enrollments oe ON oe.student_id=s.id AND oe.academic_term_id=(
             SELECT id FROM academic_terms WHERE status='active' ORDER BY id DESC LIMIT 1
         )
         WHERE s.user_id=?
         LIMIT 1",
        [$user_id],
        'i'
    );
    return $cache[$user_id];
}

function student_onboarding_required($user_id) {
    $student = student_onboarding_state($user_id);
    return $student !== null && !empty($student['enrollment_id'])
        && (int)($student['requirement_count'] ?? 0) > 0
        && empty($student['onboarding_completed_at']);
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
