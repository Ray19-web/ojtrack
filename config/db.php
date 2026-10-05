<?php
date_default_timezone_set(getenv('OJTRACK_TIMEZONE') ?: 'Asia/Manila');
define('DB_HOST', getenv('OJTRACK_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('OJTRACK_DB_USER') ?: 'root');
define('DB_PASS', getenv('OJTRACK_DB_PASS') ?: '');
define('DB_NAME', getenv('OJTRACK_DB_NAME') ?: 'ojtrack');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $error) {
    error_log('OJTrack database connection failed.');
    http_response_code(503);
    exit('OJTrack is temporarily unavailable. Please try again later.');
}

$conn->set_charset('utf8mb4');

function db() {
    global $conn;
    return $conn;
}

function query($sql, $params = [], $types = '') {
    global $conn;
    if (empty($params)) {
        $result = $conn->query($sql);
        if ($result === false) return false;
        if ($result === true) return true;
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if (!empty($params)) {
        $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result === false) return $stmt->affected_rows;
    return $result->fetch_all(MYSQLI_ASSOC);
}

function query_one($sql, $params = [], $types = '') {
    $rows = query($sql, $params, $types);
    return ($rows && count($rows) > 0) ? $rows[0] : null;
}

function insert($sql, $params = [], $types = '') {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if (!empty($params)) {
        $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    }
    $stmt->execute();
    return $conn->insert_id;
}

function log_activity($user_id, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    insert(
        "INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)",
        [$user_id, $action, $details, $ip],
        'isss'
    );
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function format_date($date) {
    if (!$date) return '—';
    return date('M d, Y', strtotime($date));
}

function format_time($time) {
    if (!$time) return '—';
    return date('h:i A', strtotime($time));
}

function create_notification($user_id, $message, $notif_type = 'info', $link = null) {
    if (!in_array($notif_type, ['info', 'warning', 'error', 'success'], true)) $notif_type = 'info';
    return insert(
        "INSERT INTO notifications (user_id, message, notif_type, link, is_read) VALUES (?, ?, ?, ?, 0)",
        [$user_id, $message, $notif_type, $link],
        'isss'
    );
}

function time_ago($datetime) {
    if (!$datetime) return '—';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $time);
}

function status_badge($status) {
    $map = [
        'pending'     => ['Pending',     'badge-pending'],
        'approved'    => ['Approved',    'badge-approved'],
        'rejected'    => ['Rejected',    'badge-rejected'],
        'ongoing'     => ['Ongoing',     'badge-ongoing'],
        'completed'   => ['Completed',   'badge-completed'],
        'active'      => ['Active',      'badge-approved'],
        'inactive'    => ['Inactive',    'badge-inactive'],
        'archived'    => ['Archived',    'badge-archived'],
        'for_review'  => ['For Review',  'badge-review'],
        'submitted'   => ['Submitted',   'badge-review'],
        'returned'    => ['Returned',    'badge-rejected'],
        'assigned'    => ['Assigned',    'badge-pending'],
        'closed'      => ['Closed',      'badge-approved'],
        'waived'      => ['Waived',      'badge-approved'],
        'cancelled'   => ['Cancelled',   'badge-inactive'],
        'present'     => ['Present',     'badge-approved'],
        'absent'      => ['Absent',      'badge-rejected'],
        'excused'     => ['Excused',     'badge-pending'],
        'not_started' => ['Not Started', 'badge-pending'],
        'on_hold'     => ['On Hold',     'badge-review'],
        'withdrawn'   => ['Withdrawn',   'badge-rejected'],
    ];
    $s = strtolower(trim($status ?? ''));
    [$label, $cls] = $map[$s] ?? [ucfirst(str_replace('_', ' ', $s ?: '—')), 'badge-pending'];
    return "<span class=\"badge $cls\">$label</span>";
}

/**
 * Find the active coordinator assigned to a department.
 * Matching: exact → case-insensitive exact → common aliases (e.g. IT ↔ Information Technology).
 * Returns coordinator id or null.
 */
function find_coordinator_for_department($department) {
    $dept = trim((string)$department);
    if ($dept === '') {
        return null;
    }

    // Exact match first
    $match = query_one(
        "SELECT c.id FROM coordinators c
         JOIN users u ON u.id = c.user_id
         WHERE u.status = 'active' AND c.department = ?
         ORDER BY c.id ASC LIMIT 1",
        [$dept],
        's'
    );
    if ($match) {
        return (int)$match['id'];
    }

    $aliases = department_aliases($dept);
    $coords = query(
        "SELECT c.id, c.department FROM coordinators c
         JOIN users u ON u.id = c.user_id
         WHERE u.status = 'active' AND c.department IS NOT NULL AND c.department != ''
         ORDER BY c.id ASC",
        [],
        ''
    ) ?: [];

    $dept_lc = strtolower($dept);
    foreach ($coords as $c) {
        $c_dept = trim($c['department'] ?? '');
        $c_lc = strtolower($c_dept);
        if ($c_lc === $dept_lc) {
            return (int)$c['id'];
        }
        $c_aliases = department_aliases($c_dept);
        if (array_intersect($aliases, $c_aliases)) {
            return (int)$c['id'];
        }
    }

    return null;
}

/** Normalize department names into comparable alias tokens. */
function department_aliases($department) {
    $raw = strtolower(trim((string)$department));
    if ($raw === '') {
        return [];
    }

    $aliases = [$raw];

    // Strip common filler words for looser comparison
    $compact = preg_replace('/\b(department|dept|of|and|&)\b/', ' ', $raw);
    $compact = trim(preg_replace('/\s+/', ' ', $compact));
    if ($compact !== '' && $compact !== $raw) {
        $aliases[] = $compact;
    }

    $map = [
        'it' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'bsit' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'information technology' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'bachelor of science in information technology' => ['it', 'bsit', 'information technology', 'info tech', 'bachelor of science in information technology'],
        'cs' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'bscs' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'computer science' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'bachelor of science in computer science' => ['cs', 'bscs', 'computer science', 'bachelor of science in computer science'],
        'hr' => ['hr', 'human resources', 'human resource'],
        'human resources' => ['hr', 'human resources', 'human resource'],
        'finance' => ['finance', 'financial'],
        'engineering' => ['engineering', 'engineering technology', 'bsmet', 'bset'],
        'engineering technology' => ['engineering', 'engineering technology', 'bsmet'],
        'bsmet' => ['engineering', 'engineering technology', 'bsmet', 'mechanical engineering technology'],
        'mechanical engineering technology' => ['engineering', 'engineering technology', 'bsmet', 'mechanical engineering technology'],
        'bachelor of science in mechanical engineering technology' => ['engineering', 'engineering technology', 'bsmet', 'mechanical engineering technology'],
        'bset' => ['engineering', 'electrical technology', 'bset'],
        'electrical technology' => ['engineering', 'electrical technology', 'bset'],
        'bachelor of science in electrical technology' => ['engineering', 'electrical technology', 'bset'],
        'education' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'ed' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'bsed' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'bachelor of secondary education' => ['education', 'ed', 'bsed', 'bachelor of secondary education'],
        'technology communication management' => ['tcm', 'bstcm', 'technology communication management'],
        'tcm' => ['tcm', 'bstcm', 'technology communication management'],
        'bstcm' => ['tcm', 'bstcm', 'technology communication management'],
        'bachelor of science in technology communication management' => ['tcm', 'bstcm', 'technology communication management'],
    ];

    foreach ($map as $key => $vals) {
        if ($raw === $key || $compact === $key || in_array($raw, $vals, true) || in_array($compact, $vals, true)) {
            $aliases = array_merge($aliases, $vals);
        }
    }

    return array_values(array_unique($aliases));
}

/**
 * Generate the next auto ID for the current year.
 * Students: S20260001, S20260002, ...
 * Coordinators: C20260001, C20260002, ...
 */
function generate_next_id($prefix, $table, $column) {
    $prefix = strtoupper(trim((string)$prefix));
    $year   = date('Y');
    $base   = $prefix . $year; // e.g. S2026
    $allowed_tables = [
        'students'     => 'student_id_no',
        'coordinators' => 'coordinator_id_no',
    ];
    if (!isset($allowed_tables[$table]) || $allowed_tables[$table] !== $column) {
        return $base . '0001';
    }

    $rows = query(
        "SELECT `$column` AS id_no FROM `$table` WHERE `$column` LIKE ?",
        [$base . '%'],
        's'
    ) ?: [];

    $max = 0;
    foreach ($rows as $row) {
        $id = (string)($row['id_no'] ?? '');
        if (preg_match('/^' . preg_quote($base, '/') . '(\d{4,})$/', $id, $m)) {
            $n = (int)$m[1];
            if ($n > $max) $max = $n;
        }
    }

    $next = $max + 1;
    do {
        $candidate = $base . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        $exists = query_one("SELECT id FROM `$table` WHERE `$column`=?", [$candidate], 's');
        if (!$exists) {
            return $candidate;
        }
        $next++;
    } while ($next < 100000);

    return $base . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function generate_student_id() {
    return generate_next_id('S', 'students', 'student_id_no');
}

function generate_coordinator_id() {
    return generate_next_id('C', 'coordinators', 'coordinator_id_no');
}


require_once __DIR__ . '/normalized.php';
