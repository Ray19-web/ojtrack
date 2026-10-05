<?php
// Database paths stay relative (requirements/name.pdf); physical storage is configurable.
function document_path_valid($relative) {
    return is_string($relative) && preg_match('~^(requirements|reports|journal_proofs)/[a-zA-Z0-9][a-zA-Z0-9_.-]*$~D', $relative);
}
function storage_path_within($path, $root) {
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . '/');
}
function private_storage_root($required = true) {
    $setting = getenv('OJTRACK_PRIVATE_UPLOAD_DIR');
    if ($setting === false || trim($setting) === '') {
        if (!$required) return null;
        throw new RuntimeException('Configure OJTRACK_PRIVATE_UPLOAD_DIR before accepting private documents.');
    }
    if (!preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $setting)) throw new RuntimeException('Private storage must use an absolute path.');
    $root = realpath($setting);
    if (!$root || !is_dir($root)) throw new RuntimeException('The configured private storage directory must already exist.');
    $public_setting = PHP_SAPI === 'cli' ? getenv('OJTRACK_PUBLIC_ROOT') : ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $public = $public_setting ? realpath($public_setting) : false;
    if (!$public || !is_dir($public)) throw new RuntimeException('The public document root is unknown. CLI commands require OJTRACK_PUBLIC_ROOT.');
    foreach ([$public, realpath(__DIR__ . '/..')] as $protected) {
        if (!$protected || storage_path_within($root, $protected) || storage_path_within($protected, $root)) {
            throw new RuntimeException('Private storage must be outside and separate from the web root and application directory.');
        }
    }
    return $root;
}
function private_document_directory($category) {
    if (!in_array($category, ['requirements','reports','journal_proofs'], true)) throw new RuntimeException('Invalid private document category.');
    $root = private_storage_root();
    $directory = $root . DIRECTORY_SEPARATOR . $category;
    if (is_link($directory)) throw new RuntimeException('Private document directories cannot be symbolic links.');
    if (!is_dir($directory) && !@mkdir($directory, 0700)) {
        // Permit another request to have created the same directory.
        if (!is_dir($directory)) throw new RuntimeException('Private storage is not writable.');
    }
    $resolved = realpath($directory);
    if (!$resolved || !storage_path_within($resolved, $root) || !is_writable($resolved)) throw new RuntimeException('Invalid or unwritable private document directory.');
    return $resolved;
}
function existing_document_in_root($root, $relative) {
    $category = dirname($relative);
    $candidate = $root . DIRECTORY_SEPARATOR . $relative;
    if (is_link($root . DIRECTORY_SEPARATOR . $category) || is_link($candidate)) throw new RuntimeException('Document symlinks are not supported.');
    if (!file_exists($candidate)) return null;
    $path = realpath($candidate);
    if (!$path || !storage_path_within($path, $root) || !is_file($path) || !is_readable($path)) throw new RuntimeException('Invalid or unreadable document.');
    return $path;
}
function resolve_private_document($relative) {
    if (!document_path_valid($relative)) return null;
    $root = private_storage_root(false);
    if ($root) {
        $path = existing_document_in_root($root, $relative);
        if ($path) return $path;
    }
    // Compatibility for existing installations; keep server-level direct-upload denies.
    $legacy = realpath(__DIR__ . '/../uploads');
    return $legacy ? existing_document_in_root($legacy, $relative) : null;
}
function store_private_upload($temporary, $category, $filename) {
    $relative = $category . '/' . $filename;
    if (!document_path_valid($relative) || !is_uploaded_file($temporary)) return false;
    try {
        $directory = private_document_directory($category);
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        // Reserve exclusively: never replace a prior document even if a filename collides.
        $reserved = @fopen($destination, 'x+b');
        if ($reserved === false) return false;
        fclose($reserved);
        if (!@chmod($destination, 0600)) { @unlink($destination); return false; }
        if (!move_uploaded_file($temporary, $destination)) { @unlink($destination); return false; }
        if (!@chmod($destination, 0600)) { @unlink($destination); return false; }
        return true;
    } catch (RuntimeException $error) {
        error_log('OJTrack private upload storage unavailable.');
        request_error(503, 'Document storage is unavailable. Ask your administrator to check the private upload directory.');
    }
}
