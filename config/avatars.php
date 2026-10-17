<?php
// Profile photos are public image uploads; never accept remote URLs or traversal paths.
function user_avatar_url(?string $path): string {
    $path = (string)$path;
    if (!preg_match('~^avatars/[A-Za-z0-9_-]+\.(?:jpe?g|png|gif|webp)$~iD', $path)) return '';
    $root = realpath(__DIR__ . '/../uploads/avatars');
    $file = realpath(__DIR__ . '/../uploads/' . $path);
    if (!$root || !$file || !is_file($file) || !is_readable($file) ||
        !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/')) return '';
    return '/ojtrack/uploads/' . $path;
}

function user_avatar_initials(string $name): string {
    $words = preg_split('/[\s,]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_values(array_filter($words, fn($word) => !in_array(strtolower($word),
        ['prof','prof.','dr','dr.','mr','mr.','mrs','mrs.','ms','ms.','engr','engr.','eng','eng.'], true)));
    if (!$words) return 'U';
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        if (preg_match('/^./us', $word, $match)) $letters .= $match[0];
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($letters, 'UTF-8') : strtoupper($letters);
}

function user_avatar_content(?string $path, string $name, ?string $initials = null): string {
    $url = user_avatar_url($path);
    if ($url !== '') return '<img class="user-avatar-photo" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" decoding="async">';
    return htmlspecialchars($initials ?? user_avatar_initials($name), ENT_QUOTES, 'UTF-8');
}
