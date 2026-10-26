<?php

/** Prepare only a fresh, disposable, socket-only MySQL 8.4 server. Never load application configuration. */
require_once __DIR__.'/../vendor/autoload.php';

if (PHP_SAPI !== 'cli' || getenv('DCMS_NATIVE_MYSQL_TESTS') !== '1') {
    throw new RuntimeException('Explicit DCMS_NATIVE_MYSQL_TESTS=1 opt-in is required.');
}
$directory = '/tmp/dcms-phase7-mysql';
$probe = new PDO('mysql:unix_socket='.$directory.'/mysql.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actual = $probe->query('SELECT @@server_uuid AS uuid, VERSION() AS version, @@datadir AS datadir, @@skip_networking AS isolated')->fetch(PDO::FETCH_ASSOC);
if (! str_starts_with($actual['version'], '8.4.') || $actual['datadir'] !== $directory.'/data/' || (int) $actual['isolated'] !== 1) {
    throw new RuntimeException('Unexpected disposable MySQL identity.');
}
if (is_file($directory.'/identity.json')) {
    $identity = json_decode(file_get_contents($directory.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($identity['uuid'] !== $actual['uuid']) {
        throw new RuntimeException('Existing credentials belong to a different server.');
    }
    fwrite(STDOUT, "Disposable test credentials already exist; no changes made.\n");
    exit(0);
}
$password = bin2hex(random_bytes(32));
$probe->exec('CREATE DATABASE dcms_phase7_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$probe->exec("CREATE USER 'phase7_runner'@'localhost' IDENTIFIED BY '{$password}'");
$probe->exec("GRANT ALL ON dcms_phase7_test.* TO 'phase7_runner'@'localhost'");
file_put_contents($directory.'/identity.json', json_encode(['uuid' => $actual['uuid'], 'password' => $password], JSON_THROW_ON_ERROR));
chmod($directory.'/identity.json', 0600);
fwrite(STDOUT, "Disposable test account prepared; credentials remain private.\n");
