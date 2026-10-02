<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function require_login($allowed_roles = []) {
    if (!is_logged_in()) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        header('Location: ' . base_url('login.php?error=unauthorized'));
        exit;
    }
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
    if ($user_id <= 0) {
        return false;
    }

    $student = query_one(
        "SELECT id, onboarding_completed_at FROM students WHERE user_id=? LIMIT 1",
        [$user_id],
        'i'
    );

    if (!$student || !empty($student['onboarding_completed_at'])) {
        return false;
    }

    return true;
}

function student_onboarding_complete($student_id) {
    $student_id = (int)$student_id;
    if ($student_id <= 0) {
        return false;
    }

    $total = (int)(query_one(
        "SELECT COUNT(*) AS c FROM ojt_requirements WHERE student_id=?",
        [$student_id],
        'i'
    )['c'] ?? 0);

    if ($total === 0) {
        return false;
    }

    $pending = (int)(query_one(
        "SELECT COUNT(*) AS c FROM ojt_requirements WHERE student_id=? AND status!='approved'",
        [$student_id],
        'i'
    )['c'] ?? 0);

    if ($pending > 0) {
        return false;
    }

    query(
        "UPDATE students SET onboarding_completed_at=COALESCE(onboarding_completed_at, NOW()) WHERE id=?",
        [$student_id],
        'i'
    );

    return true;
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
