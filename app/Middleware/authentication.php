<?php
require_once __DIR__ . '/../Support/avatars.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../Services/CommunicationService.php';
require_once __DIR__ . '/../Services/UploadService.php';
require_once __DIR__ . '/../Services/StorageService.php';

const OJTRACK_REMEMBER_COOKIE = 'ojtrack_remember';
const OJTRACK_REMEMBER_DAYS = 30;

function auth_token_tables_ready(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $remember = query_one("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='auth_remember_tokens' LIMIT 1");
    $reset = query_one("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='password_reset_tokens' LIMIT 1");
    return $ready = (bool)$remember && (bool)$reset;
}

function auth_cookie_options(int $expires): array {
    return ['expires' => $expires, 'path' => '/ojtrack/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax'];
}

function clear_remember_cookie(): void {
    setcookie(OJTRACK_REMEMBER_COOKIE, '', auth_cookie_options(time() - 3600));
    unset($_COOKIE[OJTRACK_REMEMBER_COOKIE]);
}

function set_authenticated_session(array $user): void {
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['auth_fingerprint'] = hash('sha256', $user['password']);
    $_SESSION['last_activity'] = time();
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['avatar'] = $user['avatar'] ?? '';
    $_SESSION['sub'] = match ($user['role']) {
        'student' => ($user['program'] ?? 'BSIT') . ' · ' . ($user['student_id_no'] ?? ''),
        'coordinator' => $user['department'] ?? 'OJT Coordinator',
        'company' => $user['company_name'] ?? 'Company',
        'admin' => 'All Access',
        default => '',
    };
}

function auth_user_context(int $user_id): ?array {
    return query_one(
        "SELECT u.*, s.student_id_no, s.program, s.year_level, s.rendered_hours, s.required_hours, c.department, co.company_name
         FROM users u
         LEFT JOIN students s ON s.user_id=u.id
         LEFT JOIN coordinators c ON c.user_id=u.id
         LEFT JOIN companies co ON co.user_id=u.id
         WHERE u.id=? AND u.status='active'",
        [$user_id], 'i'
    );
}

function issue_remember_token(int $user_id): void {
    if (!auth_token_tables_ready() || $user_id < 1) return;
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + OJTRACK_REMEMBER_DAYS * 86400);
    query('DELETE FROM auth_remember_tokens WHERE user_id=? OR expires_at<NOW()', [$user_id], 'i');
    insert('INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at, user_agent) VALUES (?,?,?,?,?)',
        [$user_id, $selector, hash('sha256', $validator), $expires, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)], 'issss');
    setcookie(OJTRACK_REMEMBER_COOKIE, $selector . ':' . $validator, auth_cookie_options(time() + OJTRACK_REMEMBER_DAYS * 86400));
    $_COOKIE[OJTRACK_REMEMBER_COOKIE] = $selector . ':' . $validator;
}

function restore_remembered_session(): bool {
    static $attempted = false;
    if ($attempted || !auth_token_tables_ready()) return false;
    $attempted = true;
    $raw = $_COOKIE[OJTRACK_REMEMBER_COOKIE] ?? '';
    if (!is_string($raw) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) return false;
    $row = query_one("SELECT t.id,t.user_id,t.validator_hash FROM auth_remember_tokens t JOIN users u ON u.id=t.user_id WHERE t.selector=? AND t.expires_at>NOW() AND u.status='active' LIMIT 1", [$m[1]], 's');
    if (!$row || !hash_equals((string)$row['validator_hash'], hash('sha256', $m[2]))) {
        if ($row) query('DELETE FROM auth_remember_tokens WHERE id=?', [(int)$row['id']], 'i');
        clear_remember_cookie();
        return false;
    }
    $user = auth_user_context((int)$row['user_id']);
    if (!$user) { clear_remember_cookie(); return false; }
    query('DELETE FROM auth_remember_tokens WHERE id=?', [(int)$row['id']], 'i');
    set_authenticated_session($user);
    issue_remember_token((int)$user['id']);
    return true;
}

function remember_user_login(int $user_id, bool $remember): void {
    if ($remember) {
        issue_remember_token($user_id);
        return;
    }
    if (auth_token_tables_ready()) query('DELETE FROM auth_remember_tokens WHERE user_id=?', [$user_id], 'i');
    clear_remember_cookie();
}

function revoke_current_remember_token(): void {
    $raw = $_COOKIE[OJTRACK_REMEMBER_COOKIE] ?? '';
    if (auth_token_tables_ready() && is_string($raw) && preg_match('/^([a-f0-9]{24}):[a-f0-9]{64}$/', $raw, $m)) {
        query('DELETE FROM auth_remember_tokens WHERE selector=?', [$m[1]], 's');
    }
    clear_remember_cookie();
}

function create_password_reset_token(int $user_id): ?string {
    if (!auth_token_tables_ready() || $user_id < 1) return null;
    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 30 * 60);
    query('DELETE FROM password_reset_tokens WHERE user_id=? OR expires_at<NOW()', [$user_id], 'i');
    insert('INSERT INTO password_reset_tokens (user_id, selector, token_hash, expires_at, request_ip) VALUES (?,?,?,?,?)',
        [$user_id, $selector, hash('sha256', $token), $expires, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)], 'issss');
    return $selector . '.' . $token;
}

function password_reset_user(string $selector, string $token): ?array {
    if (!auth_token_tables_ready() || !preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $row = query_one("SELECT id,user_id,token_hash FROM password_reset_tokens WHERE selector=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1", [$selector], 's');
    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) return null;
    return auth_user_context((int)$row['user_id']);
}

function consume_password_reset_token(string $selector, string $token): ?array {
    $user = password_reset_user($selector, $token);
    if (!$user) return null;
    query('UPDATE password_reset_tokens SET used_at=NOW() WHERE selector=? AND used_at IS NULL', [$selector], 's');
    query('DELETE FROM auth_remember_tokens WHERE user_id=?', [(int)$user['id']], 'i');
    return $user;
}

function is_logged_in() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['auth_fingerprint'])) restore_remembered_session();
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
