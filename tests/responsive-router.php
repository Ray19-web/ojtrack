<?php
// Local fixture-only server. Never serves PHP application routes or uploads.
if (PHP_SAPI !== 'cli-server' || !getenv('OJTRACK_UI_FIXTURE_DIR')) { http_response_code(403); exit; }
$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file=null;
if (preg_match('~^/ojtrack/ui-fixtures/messages-(coordinator|company|student)\.html$~', $path, $match)) {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $fixture_query);
    $archived = ($fixture_query['view'] ?? '') === 'archived';
    $view = $archived ? (isset($fixture_query['thread']) ? 'archived-open' : 'archived') : (isset($fixture_query['thread']) ? 'open' : (isset($fixture_query['empty']) ? 'empty' : 'list'));
    $file=getenv('OJTRACK_UI_FIXTURE_DIR').DIRECTORY_SEPARATOR.'messages-'.$match[1].'-'.$view.'.html';
    header('Content-Type: text/html; charset=UTF-8');
} elseif (preg_match('~^/ojtrack/ui-fixtures/(admin|coordinator|coordinators|company|student|student-detail|users|students|onboarding|onboarding-complete|onboarding-pending|announcements-company|announcements-student|archived-users|announcements-admin|certificates|company-registration)\.html$~', $path, $match)) {
    $file=getenv('OJTRACK_UI_FIXTURE_DIR').DIRECTORY_SEPARATOR.$match[1].'.html';
    header('Content-Type: text/html; charset=UTF-8');
} elseif ($path === '/ojtrack/uploads/avatars/ui_fixture_photo.png') {
    // A synthetic avatar maps to a bundled logo; real uploads remain inaccessible.
    $file=__DIR__.'/../assets/images/logo.png';
    header('Content-Type: image/png');
} elseif (preg_match('~^/ojtrack/assets/(css/[a-z-]+\.css|js/main\.js|images/[a-z]+\.png)$~', $path, $match)) {
    $file=__DIR__.'/../assets/'.$match[1];
    $ext=pathinfo($file, PATHINFO_EXTENSION);
    header('Content-Type: '.(['css'=>'text/css','js'=>'text/javascript','png'=>'image/png'][$ext]));
}
if (!$file || !is_file($file)) { http_response_code(404); exit; }
readfile($file);
