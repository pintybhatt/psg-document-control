<?php

/** Socket-only Phase 7 verification. Fail before application boot unless the disposable server matches. */
require_once __DIR__.'/../vendor/autoload.php';

if (PHP_SAPI !== 'cli' || getenv('DCMS_NATIVE_MYSQL_TESTS') !== '1') {
    throw new RuntimeException('Explicit DCMS_NATIVE_MYSQL_TESTS=1 opt-in is required.');
}
$directory = '/tmp/dcms-phase7-mysql';
if (! is_file($directory.'/identity.json')) {
    throw new RuntimeException('Prepare the disposable native MySQL test account first.');
}
$identity = json_decode(file_get_contents($directory.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/^[a-f0-9]{64}$/D', $identity['password']) || ! preg_match('/^[a-f0-9-]{36}$/D', $identity['uuid'])) {
    throw new RuntimeException('Invalid disposable test credentials.');
}
$probe = new PDO('mysql:unix_socket='.$directory.'/mysql.sock;dbname=dcms_phase7_test;charset=utf8mb4', 'phase7_runner', $identity['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actual = $probe->query('SELECT @@server_uuid AS uuid, VERSION() AS version, @@datadir AS datadir, DATABASE() AS db, @@skip_networking AS isolated')->fetch(PDO::FETCH_ASSOC);
if ($actual['uuid'] !== $identity['uuid'] || ! str_starts_with($actual['version'], '8.4.') || $actual['datadir'] !== $directory.'/data/' || $actual['db'] !== 'dcms_phase7_test' || (int) $actual['isolated'] !== 1) {
    throw new RuntimeException('Unexpected disposable MySQL identity.');
}
unset($probe);
foreach ([
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
    'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => 'localhost', 'DB_PORT' => '33087', 'DB_DATABASE' => 'dcms_phase7_test',
    'DB_USERNAME' => 'phase7_runner', 'DB_PASSWORD' => $identity['password'], 'DB_SOCKET' => $directory.'/mysql.sock',
    'DB_CHARSET' => 'utf8mb4', 'DB_COLLATION' => 'utf8mb4_unicode_ci', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'BCRYPT_ROUNDS' => '4', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
unset($identity, $value);
define('DCMS_ISOLATED_MYSQL_TESTS', true);
