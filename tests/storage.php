<?php
// Pure filesystem checks; isolated files only. No application database.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../config/storage.php';
$fixture = sys_get_temp_dir() . '/ojtrack-storage-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700); mkdir($fixture . '/public', 0700); mkdir($fixture . '/private', 0700);
putenv('OJTRACK_PUBLIC_ROOT=' . $fixture . '/public');
putenv('OJTRACK_PRIVATE_UPLOAD_DIR=' . $fixture . '/private');
$checks = 0;
function expect_storage($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException('Test failed: ' . $message);
    $checks++;
}
function expect_storage_failure($callback, $message) {
    try { $callback(); } catch (RuntimeException $error) { expect_storage(true, $message); return; }
    expect_storage(false, $message);
}
try {
    expect_storage(private_storage_root() === realpath($fixture . '/private'), 'separate private directory accepted');
    foreach (['requirements/a.pdf','reports/a.docx','journal_proofs/a.png'] as $path) expect_storage(document_path_valid($path), 'relative document path');
    foreach (['../secret','requirements/../secret','requirements/.','requirements/..','/requirements/a.pdf','avatars/a.png','requirements/a/b.pdf'] as $path) expect_storage(!document_path_valid($path), 'bad path denied');
    $dir = private_document_directory('requirements');
    expect_storage(is_dir($dir), 'category created');
    file_put_contents($dir . '/synthetic.txt', 'private');
    expect_storage(resolve_private_document('requirements/synthetic.txt') === realpath($dir . '/synthetic.txt'), 'private document resolved');
    expect_storage(!store_private_upload($dir . '/synthetic.txt', 'requirements', 'copied.txt'), 'local file cannot masquerade as HTTP upload');
    expect_storage_failure(fn()=>private_document_directory('avatars'), 'invalid category');
    if (PHP_OS_FAMILY !== 'Windows') {
        symlink($fixture . '/public', $fixture . '/private/reports');
        expect_storage_failure(fn()=>private_document_directory('reports'), 'category symlink refused');
        unlink($fixture . '/private/reports');
        file_put_contents($fixture . '/public/secret.txt', 'synthetic');
        symlink($fixture . '/public/secret.txt', $dir . '/link.txt');
        expect_storage_failure(fn()=>resolve_private_document('requirements/link.txt'), 'file symlink refused');
        unlink($dir . '/link.txt');
    }
    foreach ([$fixture . '/public', $fixture . '/public/child', $fixture, __DIR__ . '/..'] as $bad) {
        if (!is_dir($bad)) mkdir($bad, 0700);
        putenv('OJTRACK_PRIVATE_UPLOAD_DIR=' . $bad);
        expect_storage_failure(fn()=>private_storage_root(), 'overlapping root refused');
    }
    putenv('OJTRACK_PRIVATE_UPLOAD_DIR=relative/path');
    expect_storage_failure(fn()=>private_storage_root(), 'relative root refused');
    putenv('OJTRACK_PRIVATE_UPLOAD_DIR=');
    expect_storage(private_storage_root(false) === null, 'legacy-only read allowed');
    expect_storage_failure(fn()=>private_storage_root(), 'new upload requires configured storage');
    expect_storage(storage_path_within('/private/file', '/private') && !storage_path_within('/private-other/file', '/private'), 'directory boundary');
    echo json_encode(['storage_checks_passed'=>$checks,'result'=>'PASS']) . "\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($fixture);
}
