<?php

/** Fail closed before Laravel can migrate anything; never read normal development DB credentials. */
require_once __DIR__.'/../vendor/autoload.php';

if (PHP_SAPI !== 'cli' || getenv('DCMS_MYSQL_TESTING') !== '1') {
    throw new RuntimeException('Explicit DCMS_MYSQL_TESTING=1 opt-in is required.');
}
$directory = __DIR__.'/../storage/framework/testing/mysql84';
$password = trim((string) @file_get_contents($directory.'/password'));
$expectedServer = trim((string) @file_get_contents($directory.'/server-uuid'));
if (! preg_match('/^[a-f0-9]{64}$/D', $password) || ! preg_match('/^[a-f0-9-]{36}$/D', $expectedServer)) {
    throw new RuntimeException('Prepare the dedicated Compose secrets and server identity first.');
}
try {
    $probe = new PDO('mysql:host=127.0.0.1;port=33084;dbname=dcms_mysql_test;charset=utf8mb4', 'dcms_test_runner', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $probe->query('SELECT @@server_uuid AS server_uuid, VERSION() AS version, @@version_comment AS edition, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
} catch (PDOException) {
    throw new RuntimeException('The isolated DCMS MySQL server is unavailable; no fallback connection is permitted.');
}
if ($identity['server_uuid'] !== $expectedServer || $identity['database_name'] !== 'dcms_mysql_test'
    || ! str_starts_with($identity['version'], '8.4.') || ! str_contains($identity['edition'], 'Community')) {
    throw new RuntimeException('The database identity is not the approved isolated MySQL 8.4 Community server.');
}
unset($probe);

foreach ([
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_CONFIG_CACHE' => $directory.'/unused-config-cache.php',
    'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33084',
    'DB_DATABASE' => 'dcms_mysql_test', 'DB_USERNAME' => 'dcms_test_runner', 'DB_PASSWORD' => $password,
    'DB_SOCKET' => '', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATION' => 'utf8mb4_unicode_ci',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BCRYPT_ROUNDS' => '4', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
unset($password, $value);
define('DCMS_ISOLATED_MYSQL_TESTS', true);
