<?php
session_start();
if (isset($_SESSION['user_id'])) {
    $map = ['student'=>'student/dashboard.php','coordinator'=>'coordinator/dashboard.php','company'=>'company/dashboard.php','admin'=>'admin/dashboard.php'];
    header('Location: /ojtrack/' . ($map[$_SESSION['role']] ?? 'login.php'));
} else {
    header('Location: /ojtrack/login.php');
}
exit;