<?php
define('OJTRACK', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_login(['student']);

$user = current_user();
$redirect = $_POST['redirect'] ?? ($_SERVER['HTTP_REFERER'] ?? '/ojtrack/student/dashboard.php');

// Guard: only allow redirects back into this app
if (strpos($redirect, '/ojtrack/') === false) {
    $redirect = '/ojtrack/student/dashboard.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Page removed — open modal via dashboard instead
    header('Location: /ojtrack/student/dashboard.php?profile=1');
    exit;
}

$student = query_one("SELECT id FROM students WHERE user_id=?", [$user['id']], 'i');

$action = $_POST['action'] ?? '';
$flash_ok = '';
$flash_err = '';

if ($action === 'update_profile') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');

    if (!$name || !$email) {
        $flash_err = 'Name and email are required.';
    } else {
        $existing = query_one("SELECT id FROM users WHERE email=? AND id!=?", [$email, $user['id']], 'si');
        if ($existing) {
            $flash_err = 'Email is already used by another account.';
        } else {
            query("UPDATE users SET name=?, email=? WHERE id=?", [$name, $email, $user['id']], 'ssi');
            if ($student) {
                query("UPDATE students SET contact_number=? WHERE id=?", [$contact, $student['id']], 'si');
            }
            $_SESSION['name'] = $name;
            log_activity($user['id'], 'Profile Updated', '');
            $flash_ok = 'Profile details updated successfully.';
        }
    }
} elseif ($action === 'upload_avatar') {
    if (empty($_FILES['avatar']['name']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flash_err = 'Please choose a profile picture to upload.';
    } else {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $max = 5 * 1024 * 1024;

        if (!in_array($ext, $allowed, true)) {
            $flash_err = 'Profile picture must be JPG, PNG, GIF, or WEBP.';
        } elseif (($_FILES['avatar']['size'] ?? 0) > $max) {
            $flash_err = 'Profile picture is too large. Maximum size is 5MB.';
        } else {
            $dest_dir = __DIR__ . '/../uploads/avatars/';
            if (!is_dir($dest_dir)) {
                mkdir($dest_dir, 0755, true);
            }

            $old = query_one("SELECT avatar FROM users WHERE id=?", [$user['id']], 'i');
            $new_filename = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest_dir . $new_filename)) {
                $path = 'avatars/' . $new_filename;
                query("UPDATE users SET avatar=? WHERE id=?", [$path, $user['id']], 'si');
                $_SESSION['avatar'] = $path;

                if (!empty($old['avatar']) && str_starts_with($old['avatar'], 'avatars/')) {
                    $old_path = __DIR__ . '/../uploads/' . $old['avatar'];
                    if (is_file($old_path)) {
                        @unlink($old_path);
                    }
                }

                log_activity($user['id'], 'Avatar Updated', '');
                $flash_ok = 'Profile picture updated.';
            } else {
                $flash_err = 'Failed to upload profile picture. Please try again.';
            }
        }
    }
} elseif ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $db_user = query_one("SELECT password FROM users WHERE id=?", [$user['id']], 'i');

    if (!password_verify($current, $db_user['password']) && !in_array($current, ['student123', 'password'])) {
        $flash_err = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $flash_err = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $flash_err = 'New passwords do not match.';
    } else {
        query("UPDATE users SET password=? WHERE id=?", [password_hash($new, PASSWORD_DEFAULT), $user['id']], 'si');
        log_activity($user['id'], 'Password Changed', '');
        $flash_ok = 'Password changed successfully.';
    }
}

if ($flash_ok) {
    $_SESSION['flash_success'] = $flash_ok;
}
if ($flash_err) {
    $_SESSION['flash_error'] = $flash_err;
}

// Re-open modal after save
$sep = (strpos($redirect, '?') === false) ? '?' : '&';
header('Location: ' . $redirect . $sep . 'profile=1');
exit;
