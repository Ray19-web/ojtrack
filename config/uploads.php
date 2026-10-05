<?php
// Validate every supplied file before any page handler writes to the database.
// Storage/reference updates remain in the existing handlers.
function upload_policy($field) {
    $images = ['jpg','jpeg','png','gif','webp'];
    return match ($field) {
        'avatar', 'logo', 'proof_image' => [$images, 5 * 1024 * 1024],
        'document', 'documents' => [['pdf','jpg','jpeg','png','doc','docx'], 10 * 1024 * 1024],
        'report_file' => [['pdf','doc','docx'], 10 * 1024 * 1024],
        'attachment' => [array_merge($images, ['pdf','doc','docx','xls','xlsx','ppt','pptx']), 10 * 1024 * 1024],
        default => throw new DomainException('This upload field is not supported.'),
    };
}

function normalize_upload_image($path, $ext) {
    $info = @getimagesize($path);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 12000000) {
        throw new DomainException('Use a valid image no larger than 6000 pixels per side and 12 megapixels.');
    }
    if (!function_exists('imagecreatefromstring')) throw new DomainException('Image uploads are unavailable until the server enables PHP GD.');
    $image = @imagecreatefromstring(file_get_contents($path));
    if (!$image) throw new DomainException('This image cannot be decoded. Export a new copy and try again.');
    try {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        // Re-encoding removes trailing payloads and metadata. Animated GIF becomes one frame.
        $saved = match ($ext) {
            'jpg', 'jpeg' => imagejpeg($image, $path, 90),
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            'webp' => function_exists('imagewebp') && imagewebp($image, $path, 90),
        };
        if (!$saved) throw new DomainException('The server could not normalize this image.');
    } finally {
        imagedestroy($image);
    }
    clearstatcache(true, $path);
}

function validate_office_archive($path, $ext) {
    if (!class_exists('ZipArchive')) throw new DomainException('Office uploads are unavailable until the server enables PHP ZIP.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new DomainException('Invalid Office document.');
    try {
        $part = ['docx'=>'word/document.xml', 'xlsx'=>'xl/workbook.xml', 'pptx'=>'ppt/presentation.xml'][$ext];
        if ($zip->numFiles > 2000 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName($part) === false) {
            throw new DomainException('The Office document does not match its file extension.');
        }
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $item = $zip->statIndex($i);
            if (!$item) throw new DomainException('Invalid Office archive entry.');
            $expanded += $item['size'];
            if ($expanded > 50 * 1024 * 1024 || str_contains(strtolower($item['name']), 'vbaproject') || !empty($item['encryption_method'])) {
                throw new DomainException('Use an unencrypted, macro-free Office document under the expanded-size limit.');
            }
        }
        $types = $zip->getFromName('[Content_Types].xml', 2 * 1024 * 1024);
        $expected = [
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
        ][$ext];
        if (!is_string($types) || !str_contains($types, $expected) || stripos($types, 'macroEnabled') !== false) {
            throw new DomainException('The Office document content type is unsupported.');
        }
    } finally {
        $zip->close();
    }
}

function validate_one_upload($file, $field) {
    foreach (['name','tmp_name','error','size'] as $key) {
        if (!isset($file[$key]) || !is_scalar($file[$key])) throw new DomainException('Malformed upload. Select the file again.');
    }
    $error = (int)$file['error'];
    if ($error === UPLOAD_ERR_NO_FILE) return;
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded. Please retry.',
            default => 'The server could not receive this file. Please retry.',
        });
    }
    [$allowed, $maximum] = upload_policy($field);
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) throw new DomainException('Unsupported file type for this field. SVG and executable files are not accepted.');
    $path = (string)$file['tmp_name'];
    if (!is_uploaded_file($path)) throw new DomainException('The file is not a valid HTTP upload.');
    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > $maximum) throw new DomainException('The file is empty or exceeds the ' . (int)($maximum / 1048576) . ' MB limit.');
    $mime = strtolower((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '');
    $image_mimes = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
    if (isset($image_mimes[$ext])) {
        if ($mime !== $image_mimes[$ext]) throw new DomainException('The image contents do not match its extension.');
        normalize_upload_image($path, $ext);
        if (filesize($path) > $maximum) throw new DomainException('The normalized image exceeds the size limit. Resize it and retry.');
    } elseif ($ext === 'pdf') {
        if ($mime !== 'application/pdf' || file_get_contents($path, false, null, 0, 5) !== '%PDF-') throw new DomainException('The file is not a recognized PDF.');
    } elseif (in_array($ext, ['docx','xlsx','pptx'], true)) {
        validate_office_archive($path, $ext);
    } else {
        $ole_mimes = ['application/msword','application/vnd.ms-excel','application/vnd.ms-powerpoint','application/x-ole-storage','application/cdfv2','application/octet-stream'];
        if (!in_array($mime, $ole_mimes, true) || bin2hex(file_get_contents($path, false, null, 0, 8)) !== 'd0cf11e0a1b11ae1') {
            throw new DomainException('The file is not a recognized legacy Office document. Export PDF or DOCX instead.');
        }
        // Container validation does not inspect macros in legacy Office formats.
    }
}

function validate_request_uploads($json = false) {
    static $validated = false;
    if ($validated || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $validated = true;
    try {
        foreach ($_FILES as $field => $file) {
            if (!is_array($file)) throw new DomainException('Malformed upload.');
            if (isset($file['name']) && is_array($file['name'])) {
                if ($field !== 'documents' || count($file['name']) > 20) throw new DomainException('Invalid upload batch. Select at most 20 requirements.');
                foreach ($file['name'] as $id => $name) {
                    $single = [];
                    foreach (['name','tmp_name','error','size'] as $key) $single[$key] = $file[$key][$id] ?? null;
                    validate_one_upload($single, $field);
                }
            } else {
                validate_one_upload($file, $field);
            }
        }
    } catch (DomainException $error) {
        if (!$json && isset($_FILES['avatar'])) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            if (str_ends_with($path, '/profile.php')) {
                $role = $_SESSION['role'] ?? 'admin';
                $fallback = '/ojtrack/' . (in_array($role, ['admin','student','coordinator','company'], true) ? $role : 'admin') . '/dashboard.php';
                $redirect = safe_app_redirect($_POST['redirect'] ?? '', $fallback);
                $_SESSION['flash_error'] = $error->getMessage();
                $sep = str_contains($redirect, '?') ? '&' : '?';
                header('Location: ' . $redirect . $sep . 'profile=1', true, 303);
                exit;
            }
        }
        request_error(422, $error->getMessage(), $json);
    }
}

function save_user_avatar_upload(int $userId, array $file): string {
    if ($userId <= 0) throw new DomainException('Invalid user account.');
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('Choose a profile picture first.');
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
        throw new DomainException('Profile picture must be JPG, PNG, GIF, or WEBP.');
    }

    $destDir = __DIR__ . '/../uploads/avatars';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('The avatar folder could not be created.');
    }
    if (!is_writable($destDir)) {
        throw new RuntimeException('The avatar folder is not writable.');
    }

    $old = query_one("SELECT avatar FROM users WHERE id=?", [$userId], 'i');
    if (!$old) throw new DomainException('User account not found.');

    $filename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $destDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        throw new RuntimeException('The server could not save the profile picture.');
    }

    $relative = 'avatars/' . $filename;
    try {
        query("UPDATE users SET avatar=? WHERE id=?", [$relative, $userId], 'si');
    } catch (Throwable $error) {
        @unlink($destination);
        throw $error;
    }

    $_SESSION['avatar'] = $relative;

    $oldRelative = (string)($old['avatar'] ?? '');
    if ($oldRelative !== '' && str_starts_with($oldRelative, 'avatars/')) {
        $oldPath = __DIR__ . '/../uploads/' . $oldRelative;
        if (is_file($oldPath) && realpath($oldPath) !== realpath($destination)) {
            @unlink($oldPath);
        }
    }

    return $relative;
}
