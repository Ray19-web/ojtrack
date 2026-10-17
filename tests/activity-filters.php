<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/activity-log.php';
function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
$checks = 0;
function activity_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$filters = activity_log_filters(['q'=>'  0  ', 'role'=>'student', 'date'=>'2026-10-10']);
activity_check($filters['search'] === '0' && $filters['params'][0] === '%0%', 'Search for zero was lost.');
activity_check($filters['params'][3] === '2026-10-10 00:00:00' && $filters['params'][4] === '2026-10-11 00:00:00', 'Incorrect date range.');
activity_check(end($filters['params']) === 'student', 'Role was lost when combining filters.');
activity_check(strlen($filters['types']) === count($filters['params']), 'Query bindings mismatch.');
foreach (['2026-02-30', 'bad', "2026-10-10\0", '0000-01-01'] as $date) {
    $invalid = activity_log_filters(['date'=>$date]);
    activity_check($invalid['error'] !== '' && $invalid['date'] === '', 'Invalid date accepted.');
}
activity_check(activity_log_filters(['date'=>'2024-02-29'])['date'] === '2024-02-29', 'Valid leap day rejected.');
activity_check(activity_log_filters(['q'=>[], 'role'=>[], 'date'=>[]])['params'] === [], 'Non-scalar input was not handled.');
activity_check(activity_log_filters(['role'=>'invalid'])['error'] !== '', 'Invalid role accepted.');
activity_check(str_contains(activity_log_filters(['role'=>'system'])['where'], 'u.id IS NULL'), 'System filter is incorrect.');
activity_check(activity_log_filters([])['where'] === '1=1', 'Clear must remove all filters.');
$role = 'admin'; // The shared header uses this variable for the signed-in account.
$role_filter = 'student'; $search = 'Login'; $date = '2026-10-10';
ob_start(); require __DIR__ . '/../includes/activity-filters.php'; $html = ob_get_clean();
activity_check(str_contains($html, 'value="student" selected'), 'Selected role overwritten by the account role.');
activity_check(str_contains($html, 'type="submit"') && str_contains($html, '>Clear</a>'), 'Apply/Clear controls missing.');
echo "$checks activity filter checks passed.\n";
