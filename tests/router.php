<?php
// Local synthetic server only; real deployments must enforce Apache/Nginx deny rules.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~^/ojtrack/(config|scratch|database|bin|tests|[.][^/]+)/|^/ojtrack/uploads/(requirements|reports|journal_proofs)/|[.](sql|md|log)$~i', $path)) {
    http_response_code(403); exit;
}
return false;
