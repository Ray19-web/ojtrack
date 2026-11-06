<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 3) exit("Usage: php bin/create-admin.php 'Full name' email (password on stdin)\n");
$name = trim($argv[1]); $email = trim($argv[2]);
$password = rtrim(fgets(STDIN) ?: '', "\r\n");
if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) exit("Valid name, email and password of at least 12 characters required.\n");
require __DIR__ . '/../config/db.php';
if (query_one("SELECT id FROM users WHERE role='admin' LIMIT 1")) exit("An administrator already exists. Use User Accounts to manage accounts.\n");
db()->begin_transaction();
try {
    $id = insert("INSERT INTO users(name,email,password,role,status) VALUES(?,?,?,'admin','active')", [$name,$email,password_hash($password,PASSWORD_DEFAULT)], 'sss');
    log_activity($id, 'Initial Administrator Created', 'CLI setup');
    db()->commit();
    echo "Administrator created.\n";
} catch (Throwable $error) {
    db()->rollback();
    fwrite(STDERR, "Administrator creation failed; check connection and duplicate email.\n");
    exit(1);
}
