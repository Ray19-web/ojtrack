<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/avatars.php';
$checks = 0;
function avatar_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
foreach ([null, '', 'avatars/missing.png', '../config/db.php', 'avatars/../../config/db.php',
          'https://example.com/photo.png', 'avatars/photo.svg', 'avatars/x.png" onerror="alert(1)'] as $path) {
    avatar_check(user_avatar_url($path) === '', 'Unsafe or missing photo must fall back.');
    avatar_check(!str_contains(user_avatar_content($path, '<Sample User>'), '<img'), 'Unexpected image markup.');
}
avatar_check(user_avatar_content(null, '<Sample User>') === '&lt;U', 'Initials must be escaped.');
avatar_check(preg_match('//u', user_avatar_initials('Acuña, Cristine')) === 1, 'Unicode initials must remain valid UTF-8.');
$path = 'avatars/test_' . bin2hex(random_bytes(12)) . '.png';
$file = __DIR__ . '/../uploads/' . $path;
try {
    copy(__DIR__ . '/../assets/images/logo.png', $file);
    avatar_check(user_avatar_url($path) === '/ojtrack/uploads/' . $path, 'Existing uploaded photo must resolve.');
    avatar_check(str_contains(user_avatar_content($path, 'Sample User'), 'class="user-avatar-photo"'), 'Existing photo must render.');
} finally {
    if (is_file($file)) unlink($file);
}
echo "$checks profile photo checks passed.\n";
