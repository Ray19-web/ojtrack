<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_csrf();

if (is_logged_in()) {
    redirect(role_home());
}

$notice = '';
$error = '';
$dev_reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    if ($identifier === '') {
        $error = 'Enter your email address or student ID.';
    } elseif (!auth_token_tables_ready()) {
        $error = 'Password recovery is not available yet. Ask the administrator to apply the latest database update.';
    } else {
        $account = query_one(
            "SELECT u.id,u.email
             FROM users u
             LEFT JOIN students s ON s.user_id=u.id
             WHERE u.status='active'
               AND ((u.email IS NOT NULL AND u.email=?) OR (u.role='student' AND s.student_id_no=? AND s.is_archived=0))
             LIMIT 1",
            [$identifier, $identifier], 'ss'
        );
        $notice = 'If an active account matches that information, a password reset link has been prepared.';
        if ($account) {
            $token = create_password_reset_token((int)$account['id']);
            $reset_url = base_url('reset-password.php') . '?token=' . rawurlencode((string)$token);
            $sent = false;
            if (!empty($account['email']) && function_exists('mail')) {
                $subject = 'OJTrack password reset';
                $message = "Use this link to reset your OJTrack password:\n\n$reset_url\n\nThis link expires in 30 minutes and can only be used once.";
                $sent = @mail($account['email'], $subject, $message, "Content-Type: text/plain; charset=UTF-8\r\n");
            }
            $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            if (!$sent && (str_contains($host, 'localhost') || str_starts_with($host, '127.0.0.1'))) {
                $dev_reset_link = $reset_url;
            }
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Forgot Password — OJTrack</title>
  <link rel="stylesheet" href="/ojtrack/assets/css/style.css?v=20261118-auth">
  <style>
    body.password-recovery-page{min-height:100vh;display:grid;place-items:center;margin:0;padding:24px;background:linear-gradient(135deg,#eef4fb,#f9fbfe);font-family:var(--font-body,Inter,sans-serif);color:#17385f}
    .password-recovery-card{width:min(460px,100%);padding:34px;border:1px solid #dfe8f3;border-radius:20px;background:#fff;box-shadow:0 18px 50px rgba(16,59,120,.12)}
    .password-recovery-card h1{margin:0 0 8px;color:#103b78;font-family:var(--font-display,Inter,sans-serif);font-size:26px}.password-recovery-card p{margin:0 0 22px;color:#72849d;font-size:13px;line-height:1.6}
    .password-recovery-card label{display:block;margin:0 0 7px;color:#244d82;font-size:12px;font-weight:750}.password-recovery-card input{width:100%;box-sizing:border-box;min-height:46px;margin-bottom:15px;padding:11px 13px;border:1px solid #d5dfeb;border-radius:11px;font:inherit}.password-recovery-card input:focus{border-color:#6d96ce;box-shadow:0 0 0 3px rgba(16,59,120,.1);outline:0}.password-recovery-card button{width:100%;min-height:46px;border:0;border-radius:11px;background:#103b78;color:#fff;font:700 14px var(--font-body,Inter,sans-serif);cursor:pointer}.password-recovery-card button:hover{background:#0a2a56}.password-recovery-card a{color:#17477e;font-weight:700;text-decoration:none}.password-recovery-card a:hover{text-decoration:underline}.password-recovery-alert{margin:0 0 18px;padding:12px 14px;border-radius:10px;background:#fff7e6;color:#8b5a00;font-size:12px;line-height:1.5}.password-recovery-alert.error{background:#fff0f0;color:#a32626}.password-recovery-dev{margin-top:16px;padding:12px 14px;border:1px dashed #b8cbe2;border-radius:10px;background:#f4f8fd;font-size:11px;line-height:1.5;overflow-wrap:anywhere}.password-recovery-back{display:block;margin-top:18px;text-align:center;font-size:12px}
  </style>
</head>
<body class="password-recovery-page">
  <main class="password-recovery-card">
    <h1>Forgot Password?</h1>
    <p>Enter your registered email address or student ID. We will prepare a secure, one-time reset link.</p>
    <?php if ($error): ?><div class="password-recovery-alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="password-recovery-alert"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($dev_reset_link): ?><div class="password-recovery-dev"><strong>Local development reset link:</strong><br><a href="<?= e($dev_reset_link) ?>"><?= e($dev_reset_link) ?></a></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label for="identifier">Email address or student ID</label>
      <input id="identifier" name="identifier" type="text" autocomplete="username" required value="<?= e($_POST['identifier'] ?? '') ?>">
      <button type="submit">Send Reset Link</button>
    </form>
    <a class="password-recovery-back" href="/ojtrack/login.php">← Back to login</a>
  </main>
</body>
</html>
