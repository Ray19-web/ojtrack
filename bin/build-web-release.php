<?php
/** Explicit allowlist: build a web-only deployable ZIP without runtime/personal data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !extension_loaded('zip')) {
    fwrite(STDERR,"Usage: php bin/build-web-release.php <new-absolute-zip-path>; enable PHP ZIP first.\n");
    exit(1);
}
$target = $argv[1]; $project = realpath(__DIR__.'/..');
if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $target)) exit("Use an absolute output path.\n");
$parent = realpath(dirname($target));
if (!$parent || is_file($target)) exit("Output directory must exist, and the archive must not exist.\n");
$normalize = static fn($path) => strtolower(str_replace('\\','/', rtrim($path,'/\\')));
if (str_starts_with($normalize($parent).'/', $normalize($project).'/')) exit("Write release archives outside the project.\n");
$files = [];
foreach (['admin','api','app','assets','company','config','coordinator','frontend','includes','storage','student'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlinks are not release inputs.');
        if (!$file->isFile()) continue;
        $relative = str_replace('\\','/', substr($file->getPathname(),strlen($project)+1));
        if ($relative === 'config/storage.local.php' || preg_match('~(?:^|/)(?:\.env|node_modules|__pycache__)(?:/|$)|\.(?:log|pyc|bak|tmp)$~i', $relative)) continue;
        $files[$relative] = $file->getPathname();
    }
}
foreach (['.htaccess','index.php','login.php','logout.php','download.php','certificate.php','README.md','docs/SYSTEM_DOCUMENTATION.md','docs/RELEASE_NOTES.md','docs/DEPLOYMENT.md','docs/VERIFICATION.md','docs/architecture/README.md','database/.htaccess','database/install.sql','database/default-programs.json','database/bsit-ojt-evaluation.json','bin/.htaccess','bin/create-admin.php','bin/preflight.php','bin/set-default-programs.php'] as $relative) {
    if (!is_file($project.'/'.$relative)) throw new RuntimeException('Missing release input: '.$relative);
    $files[$relative] = $project.'/'.$relative;
}
// Preserve only protective access rules; never include existing uploaded media/documents.
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project.'/uploads', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) if (!$file->isLink() && $file->isFile() && $file->getFilename() === '.htaccess') {
    $relative = str_replace('\\','/', substr($file->getPathname(),strlen($project)+1)); $files[$relative] = $file->getPathname();
}
ksort($files); $manifest=[];
$zip = new ZipArchive;
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create archive.');
foreach ($files as $relative=>$source) {
    if (!$zip->addFile($source,'ojtrack/'.$relative)) throw new RuntimeException('Could not add '.$relative);
    $manifest[$relative] = ['bytes'=>filesize($source),'sha256'=>hash_file('sha256',$source)];
}
$zip->addFromString('ojtrack/RELEASE_MANIFEST.json', json_encode(['product'=>'OJTRACK Web','created_utc'=>gmdate('c'),'files'=>$manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
if (!$zip->close()) throw new RuntimeException('Could not finalize archive.');
echo json_encode(['archive'=>$target,'files'=>count($files),'sha256'=>hash_file('sha256',$target)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
