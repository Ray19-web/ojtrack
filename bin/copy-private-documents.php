<?php
// Copy only database-referenced documents. Never delete sources or change DB references.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (isset($argv[1]) && !in_array($argv[1], ['--dry-run','--copy','--help'], true)) {
    fwrite(STDERR, "Use --dry-run (default), --copy, or --help.\n"); exit(2);
}
if (($argv[1] ?? '') === '--help') {
    echo "Set database environment, OJTRACK_PRIVATE_UPLOAD_DIR and OJTRACK_PUBLIC_ROOT.\n";
    echo "Run --dry-run first, then --copy. Existing files are compared, never overwritten. Sources and DB references remain unchanged.\n";
    exit;
}
$copy = ($argv[1] ?? '--dry-run') === '--copy';
require __DIR__ . '/../config/storage.php';
try {
    $root = private_storage_root();
    $legacy = realpath(__DIR__ . '/../uploads');
    if (!$legacy) throw new RuntimeException('Legacy upload directory not found.');
    require __DIR__ . '/../config/db.php';
    $references = query("SELECT file_path AS path FROM ojt_requirements WHERE file_path IS NOT NULL AND file_path!=''
        UNION SELECT file_path FROM reports WHERE file_path IS NOT NULL AND file_path!=''
        UNION SELECT proof_image FROM journal_entries WHERE proof_image IS NOT NULL AND proof_image!=''");
    $summary = ['mode'=>$copy ? 'copy' : 'dry-run', 'referenced'=>count($references), 'would_copy'=>0, 'copied'=>0, 'verified_existing'=>0, 'private_only'=>0, 'errors'=>[]];
    foreach ($references as $reference) {
        $relative = $reference['path'];
        try {
            if (!document_path_valid($relative)) throw new RuntimeException('Invalid database document path.');
            $source = existing_document_in_root($legacy, $relative);
            $existing = existing_document_in_root($root, $relative);
            if (!$source) {
                if ($existing) { $summary['private_only']++; continue; }
                throw new RuntimeException('Referenced document is missing from both locations.');
            }
            if ($existing) {
                if (!hash_equals(hash_file('sha256', $source), hash_file('sha256', $existing))) throw new RuntimeException('Destination differs from source; refusing overwrite.');
                $summary['verified_existing']++;
                continue;
            }
            if (!$copy) { $summary['would_copy']++; continue; }
            $directory = private_document_directory(dirname($relative));
            $destination = $directory . DIRECTORY_SEPARATOR . basename($relative);
            $input = @fopen($source, 'rb');
            if (!$input) throw new RuntimeException('Source cannot be opened.');
            $output = @fopen($destination, 'x+b');
            if (!$output) { fclose($input); throw new RuntimeException('Destination exists or cannot be created.'); }
            $ok = false;
            try {
                if (!@chmod($destination, 0600)) throw new RuntimeException('Cannot restrict destination permissions.');
                $bytes = stream_copy_to_stream($input, $output);
                if ($bytes === false || !fflush($output)) throw new RuntimeException('Copy failed.');
                fclose($output); $output = null;
                fclose($input); $input = null;
                if (!hash_equals(hash_file('sha256', $source), hash_file('sha256', $destination))) throw new RuntimeException('Copy verification failed.');
                $ok = true;
            } finally {
                if (is_resource($output)) fclose($output);
                if (is_resource($input)) fclose($input);
                // Only our newly-created failed copy can be removed, never a pre-existing file.
                if (!$ok) @unlink($destination);
            }
            $summary['copied']++;
        } catch (RuntimeException $error) {
            $summary['errors'][] = ['path'=>$relative, 'error'=>$error->getMessage()];
        }
    }
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($summary['errors'] ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, "Private-document copy failed: " . $error->getMessage() . "\n");
    exit(1);
}
