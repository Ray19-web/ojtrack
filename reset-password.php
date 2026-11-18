<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_csrf();

$raw_token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$parts = explode('.', $raw_token, 2);
$selector = $parts[0] ?? '';
$validator = $parts[1] ?? '';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirmation'] ?? '');
    if ($password === '' || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        $error = 'Your new password must be between 12 and 72 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } elseif (!($reset_user = password_reset_user($selector, $validator))) {
        $error = 'This reset link is invalid or has expired. Request a new one.';
    } else {
        query('UPDATE users SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), (int)$reset_user['id']], 'si');
        query('UPDATE password_reset_tokens SET used_at=NOW() WHERE selector=? AND used_at IS NULL', [$selector], 's');
        query('DELETE FROM auth_remember_tokens WHERE user_id=?', [(int)$reset_user['id']], 'i');
        $success = 'Your password has been reset. You can now sign in with the new password.';
    }
}

$valid_link = !$success && (bool)password_reset_user($selector, $validator);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset Password — OJTrack</title>
  <link rel="stylesheet" href="/ojtrack/assets/css/style.css?v=20261118-auth">
  <style>
    body.password-recovery-page{min-height:100vh;display:grid;place-items:center;margin:0;padding:24px;background:linear-gradient(135deg,#eef4fb,#f9fbfe);font-family:var(--font-body,Inter,sans-serif);color:#17385f}.password-recovery-card{width:min(460px,100%);padding:34px;border:1px solid #dfe8f3;border-radius:20px;background:#fff;box-shadow:0 18px 50px rgba(16,59,120,.12)}.password-recovery-card h1{margin:0 0 8px;color:#103b78;font-family:var(--font-display,Inter,sans-serif);font-size:26px}.password-recovery-card p{margin:0 0 22px;color:#72849d;font-size:13px;line-height:1.6}.password-recovery-card label{display:block;margin:0 0 7px;color:#244d82;font-size:12px;font-weight:750}.password-recovery-card input{width:100%;box-sizing:border-box;min-height:46px;margin-bottom:15px;padding:11px 13px;border:1px solid #d5dfeb;border-radius:11px;font:inherit}.password-recovery-card input:focus{border-color:#6d96ce;box-shadow:0 0 0 3px rgba(16,59,120,.1);outline:0}.password-recovery-card button{width:100%;min-height:46px;border:0;border-radius:11px;background:#103b78;color:#fff;font:700 14px var(--font-body,Inter,sans-serif);cursor:pointer}.password-recovery-card button:hover{background:#0a2a56}.password-recovery-card a{color:#17477e;font-weight:700;text-decoration:none}.password-recovery-card a:hover{text-decoration:underline}.password-recovery-alert{margin:0 0 18px;padding:12px 14px;border-radius:10px;background:#fff0f0;color:#a32626;font-size:12px;line-height:1.5}.password-recovery-alert.success{background:#effaf5;color:#147548}.password-recovery-back{display:block;margin-top:18px;text-align:center;font-size:12px}
  </style>
</head>
<body class="password-recovery-page">
  <main class="password-recovery-card">
    <h1>Reset Password</h1>
    <?php if ($success): ?>
      <div class="password-recovery-alert success"><?= e($success) ?></div>
      <a class="password-recovery-back" href="/ojtrack/login.php">Continue to login</a>
    <?php elseif (!$valid_link): ?>
      <div class="password-recovery-alert"><?= e($error ?: 'This reset link is invalid or has expired. Request a new one.') ?></div>
      <a class="password-recovery-back" href="/ojtrack/forgot-password.php">Request a new reset link</a>
    <?php else: ?>
      <p>Choose a new password for your OJTrack account.</p>
      <?php if ($error): ?><div class="password-recovery-alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="token" value="<?= e($raw_token) ?>">
        <label for="password">New password</label>
        <input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
        <button type="submit">Reset Password</button>
      </form>
      <a class="password-recovery-back" href="/ojtrack/login.php">← Back to login</a>
    <?php endif; ?>
  </main>
</body>
</html>
