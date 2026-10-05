<?php
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require_once __DIR__ . '/../config/uploads.php';

$checks = 0;
function image_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    $checks++;
}
function image_case(string $ext, string $bytes, string $tail): void {
    $path = tempnam(sys_get_temp_dir(), 'ojtrack-img-');
    file_put_contents($path, $bytes . $tail);
    $before = filesize($path);
    strip_image_trailing_payload($path, $ext);
    $afterBytes = file_get_contents($path);
    @unlink($path);
    image_check($before > strlen($bytes), "$ext fixture contains trailing payload");
    image_check($afterBytes === $bytes, "$ext trailing payload stripped exactly");
}

$png = "\x89PNG\r\n\x1a\n" .
       pack('N', 0) . "IEND" . "\xAE\x42\x60\x82";
image_case('png', $png, '<?php trailing_png ?>');

$jpeg = "\xFF\xD8" .
        "\xFF\xE0" . pack('n', 2) .
        "\xFF\xDA" . pack('n', 2) .
        "\x11\x22\x33\xFF\x00\x44\xFF\xD9";
image_case('jpg', $jpeg, '<?php trailing_jpeg ?>');

$gif = "GIF89a" .
       "\x01\x00\x01\x00" .
       "\x00\x00\x00" .
       "\x3B";
image_case('gif', $gif, '<?php trailing_gif ?>');

$webp = "RIFF" . pack('V', 4) . "WEBP";
image_case('webp', $webp, '<?php trailing_webp ?>');

echo json_encode([
    'image_sanitizer_checks_passed' => $checks,
    'result' => 'PASS'
]) . PHP_EOL;
